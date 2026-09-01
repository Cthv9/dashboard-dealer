<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Catena di versioni per i documenti dealer.
 *
 * Modello: ogni documento appartiene a un "gruppo" identificato dall'ID del
 * primo documento della catena (la radice). Una sola versione per gruppo è
 * quella corrente (_doc_is_current = '1'); le precedenti restano pubblicate e
 * scaricabili come storico, ma sono escluse dalla ricerca.
 *
 * I documenti creati prima dell'introduzione del versionamento non hanno
 * alcuno di questi meta: sono trattati ovunque come "correnti" e senza storico
 * (per questo ogni confronto usa sempre anche NOT EXISTS).
 */
class Dealer_Versioning {

	/** ID del post radice della catena (il primo caricato). */
	const META_GROUP = '_doc_group_id';

	/** '1' se è la versione corrente del gruppo, '0' se superata. */
	const META_IS_CURRENT = '_doc_is_current';

	/** ID del post che questa versione sostituisce (0 per la radice). */
	const META_SUPERSEDES = '_doc_supersedes';

	/** Progressivo 1-based della versione all'interno del gruppo. */
	const META_SEQ = '_doc_version_seq';

	// ─── Query helpers ────────────────────────────────────────────────────────

	/**
	 * Clausola meta_query da innestare per mostrare solo le versioni correnti.
	 * Include NOT EXISTS per i documenti legacy privi del meta.
	 */
	public static function meta_query_current_only(): array {
		return [
			'relation' => 'OR',
			[ 'key' => self::META_IS_CURRENT, 'compare' => 'NOT EXISTS' ],
			[ 'key' => self::META_IS_CURRENT, 'value' => '1', 'compare' => '=' ],
		];
	}

	// ─── Lettura ──────────────────────────────────────────────────────────────

	/**
	 * ID del gruppo di appartenenza. Per i documenti legacy (nessun meta) il
	 * gruppo coincide con il documento stesso.
	 */
	public static function get_group_id( int $post_id ): int {
		$group = (int) get_post_meta( $post_id, self::META_GROUP, true );
		return $group ?: $post_id;
	}

	public static function is_current( int $post_id ): bool {
		$value = get_post_meta( $post_id, self::META_IS_CURRENT, true );
		// Meta assente = documento legacy, considerato corrente.
		return ( '' === $value || null === $value ) ? true : ( '1' === (string) $value );
	}

	public static function get_sequence( int $post_id ): int {
		$seq = (int) get_post_meta( $post_id, self::META_SEQ, true );
		return $seq ?: 1;
	}

	/**
	 * Tutti i post della catena, dal più recente al più vecchio.
	 *
	 * @return \WP_Post[]
	 */
	public static function get_chain( int $post_id ): array {
		$group = self::get_group_id( $post_id );

		$posts = get_posts( [
			'post_type'      => 'documento_dealer',
			'post_status'    => [ 'publish', 'draft' ],
			'numberposts'    => 50,
			'orderby'        => 'meta_value_num',
			'meta_key'       => self::META_SEQ,
			'order'          => 'DESC',
			'meta_query'     => [
				[ 'key' => self::META_GROUP, 'value' => $group, 'compare' => '=' ],
			],
		] );

		// Documento legacy senza catena: restituisce se stesso.
		if ( empty( $posts ) ) {
			$self = get_post( $post_id );
			return $self ? [ $self ] : [];
		}

		return $posts;
	}

	/**
	 * Versioni precedenti a quella indicata (esclusa quella corrente).
	 *
	 * @return \WP_Post[]
	 */
	public static function get_previous_versions( int $post_id ): array {
		$chain = self::get_chain( $post_id );

		return array_values( array_filter( $chain, static function ( $post ) use ( $post_id ) {
			return (int) $post->ID !== $post_id && ! self::is_current( (int) $post->ID );
		} ) );
	}

	public static function count_previous_versions( int $post_id ): int {
		return count( self::get_previous_versions( $post_id ) );
	}

	/**
	 * ID della versione corrente del gruppo a cui appartiene $post_id.
	 * Restituisce $post_id stesso se non esiste una catena.
	 */
	public static function get_current_id( int $post_id ): int {
		foreach ( self::get_chain( $post_id ) as $post ) {
			if ( self::is_current( (int) $post->ID ) ) {
				return (int) $post->ID;
			}
		}
		return $post_id;
	}

	/**
	 * Documenti candidabili come "versione precedente" in fase di upload:
	 * solo le versioni correnti, ordinate per titolo.
	 *
	 * @return \WP_Post[]
	 */
	public static function get_versionable_documents( int $limit = 300 ): array {
		return get_posts( [
			'post_type'      => 'documento_dealer',
			'post_status'    => 'publish',
			'numberposts'    => $limit,
			'orderby'        => 'title',
			'order'          => 'ASC',
			'meta_query'     => [ self::meta_query_current_only() ],
		] );
	}

	// ─── Scrittura ────────────────────────────────────────────────────────────

	/**
	 * Inizializza un documento come radice di una nuova catena.
	 * Idempotente: non tocca un documento già inserito in una catena.
	 */
	public static function init_root( int $post_id ): void {
		if ( (int) get_post_meta( $post_id, self::META_GROUP, true ) ) {
			return;
		}
		update_post_meta( $post_id, self::META_GROUP,      $post_id );
		update_post_meta( $post_id, self::META_IS_CURRENT, '1' );
		update_post_meta( $post_id, self::META_SUPERSEDES, 0 );
		update_post_meta( $post_id, self::META_SEQ,        1 );
	}

	/**
	 * Aggancia $new_post_id come nuova versione corrente di $previous_post_id.
	 * La versione precedente resta pubblicata e scaricabile, ma esce dalla ricerca.
	 *
	 * @return bool false se il documento precedente non è valido.
	 */
	public static function attach_as_new_version( int $new_post_id, int $previous_post_id ): bool {
		if ( ! $previous_post_id || get_post_type( $previous_post_id ) !== 'documento_dealer' ) {
			return false;
		}
		if ( $new_post_id === $previous_post_id ) {
			return false;
		}

		// Il nuovo documento entra sempre in coda alla catena della versione indicata.
		$group = self::get_group_id( $previous_post_id );

		// Assicura che la radice abbia i meta anche se creata prima del versionamento.
		if ( ! (int) get_post_meta( $group, self::META_GROUP, true ) ) {
			update_post_meta( $group, self::META_GROUP,      $group );
			update_post_meta( $group, self::META_SUPERSEDES, 0 );
			update_post_meta( $group, self::META_SEQ,        1 );
		}

		// Tutte le versioni esistenti del gruppo perdono lo stato di corrente.
		$next_seq = 1;
		foreach ( self::get_chain( $previous_post_id ) as $post ) {
			$id = (int) $post->ID;
			if ( $id === $new_post_id ) {
				continue;
			}
			update_post_meta( $id, self::META_IS_CURRENT, '0' );
			$next_seq = max( $next_seq, self::get_sequence( $id ) + 1 );
		}

		update_post_meta( $new_post_id, self::META_GROUP,      $group );
		update_post_meta( $new_post_id, self::META_IS_CURRENT, '1' );
		update_post_meta( $new_post_id, self::META_SUPERSEDES, $previous_post_id );
		update_post_meta( $new_post_id, self::META_SEQ,        $next_seq );

		/**
		 * Consente ad altri moduli (es. notifiche) di reagire a una nuova versione.
		 */
		do_action( 'dealer_portal_new_version', $new_post_id, $previous_post_id, $group );

		return true;
	}

	/**
	 * Declassa la versione corrente promuovendo la più recente fra le altre,
	 * senza sciogliere il legame con la catena: il documento resta nello
	 * storico, semplicemente non è più quello mostrato in ricerca.
	 *
	 * Da usare quando il documento continua a esistere (es. viene marcato
	 * obsoleto); usa detach() solo quando sta per essere eliminato.
	 *
	 * @param int[] $exclude ID da non promuovere, oltre ai filtri di
	 *                       find_best_successor(): serve alle azioni massive,
	 *                       dove gli altri documenti selezionati stanno per
	 *                       diventare obsoleti a loro volta e non possono
	 *                       prendere questo posto.
	 * @return int ID della versione promossa, 0 se non c'era nessun candidato
	 *             (in quel caso non viene modificato nulla).
	 */
	public static function demote( int $post_id, array $exclude = [] ): int {
		if ( ! self::is_current( $post_id ) ) {
			return 0;
		}

		$promoted = self::find_best_successor( $post_id, $exclude );
		if ( ! $promoted ) {
			// Nessun candidato promuovibile: declassare renderebbe il documento
			// invisibile senza che nulla prenda il suo posto.
			return 0;
		}

		update_post_meta( $post_id, self::META_IS_CURRENT, '0' );
		update_post_meta( $promoted, self::META_IS_CURRENT, '1' );

		return $promoted;
	}

	/**
	 * Versione promuovibile con sequenza più alta della catena, escluso $post_id.
	 *
	 * "Promuovibile" non è solo "la più recente": un documento obsoleto o non
	 * pubblicato, promosso a versione corrente, lascerebbe la catena con una
	 * corrente che nessun dealer vede in ricerca — peggio che non promuovere
	 * nulla, perché il documento appena declassato sparisce comunque.
	 *
	 * Il filtro vive qui e non nei chiamanti: demote() e detach() devono
	 * comportarsi allo stesso modo ovunque, e una copia della regola per ogni
	 * punto di chiamata è esattamente ciò che prima o poi diverge.
	 *
	 * @param int[] $exclude ID da scartare a prescindere dal loro stato.
	 */
	public static function find_best_successor( int $post_id, array $exclude = [] ): int {
		$best_id  = 0;
		$best_seq = 0;
		$exclude  = array_map( 'intval', $exclude );

		foreach ( self::get_chain( $post_id ) as $post ) {
			$id = (int) $post->ID;
			if ( $id === $post_id || in_array( $id, $exclude, true ) ) {
				continue;
			}
			// get_chain() include le bozze (lo storico resta leggibile
			// all'admin), ma solo un documento pubblicato e non obsoleto può
			// diventare la versione corrente.
			if ( 'publish' !== get_post_status( $id ) ) {
				continue;
			}
			if ( 'obsoleto' === get_post_meta( $id, '_doc_status', true ) ) {
				continue;
			}
			$seq = self::get_sequence( $id );
			if ( $seq >= $best_seq ) {
				$best_seq = $seq;
				$best_id  = $id;
			}
		}

		return $best_id;
	}

	/**
	 * Rimuove un documento dalla catena mantenendola coerente: se era la
	 * versione corrente, promuove la più recente tra quelle rimaste.
	 * Da chiamare prima di eliminare definitivamente un documento.
	 *
	 * @return int ID della versione promossa, 0 se non è stato promosso nulla
	 *             (documento non corrente, catena di un solo elemento, o
	 *             nessun candidato promuovibile secondo find_best_successor()).
	 *             Il chiamante non può dedurlo da solo: "era corrente e la
	 *             catena aveva altri elementi" non implica che uno di quelli
	 *             fosse pubblicato e non obsoleto.
	 */
	public static function detach( int $post_id ): int {
		// Il successore va individuato prima di cancellare i meta: dopo, il
		// documento non appartiene più a nessuna catena.
		$was_current = self::is_current( $post_id );
		$successor   = $was_current ? self::find_best_successor( $post_id ) : 0;

		delete_post_meta( $post_id, self::META_GROUP );
		delete_post_meta( $post_id, self::META_IS_CURRENT );
		delete_post_meta( $post_id, self::META_SUPERSEDES );
		delete_post_meta( $post_id, self::META_SEQ );

		if ( $successor ) {
			update_post_meta( $successor, self::META_IS_CURRENT, '1' );
		}

		return $successor;
	}
}
