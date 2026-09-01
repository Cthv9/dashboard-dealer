<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Pagina "I miei preferiti": etichette personali del dealer.
 *
 * Fino a ieri i preferiti comparivano solo nella dashboard, senza modo di
 * organizzarli. Qui il dealer ha una pagina dedicata (shortcode
 * [dealer_favorites]) dove può creare etichette proprie (es. "Urgenti",
 * "Da ristampare"), assegnarle ai documenti che ha già messo fra i preferiti
 * e filtrare l'elenco per etichetta.
 *
 * Le etichette sono un'organizzazione PERSONALE: non toccano in alcun modo
 * l'accesso ai documenti né sono visibili ad altri dealer. Vivono solo nello
 * user meta di chi le crea.
 *
 * REGOLE DI CONTENIMENTO:
 *
 *  1. Ogni lettura/scrittura di etichette e assegnazioni parte SEMPRE da
 *     get_current_user_id() / wp_get_current_user(), mai da un ID letto dal
 *     POST: non esiste un percorso in cui un dealer possa toccare le
 *     etichette di un altro.
 *  2. Prima di assegnare un'etichetta a un documento si verifica che quel
 *     documento sia davvero nei preferiti correnti dell'utente
 *     (Dealer_Search::is_favorite()): niente tag su un post_id arbitrario.
 *  3. L'elenco dei documenti preferiti passa sempre da
 *     Dealer_Search::get_accessible_favorites(), che fa già il controllo
 *     accessi e la pulizia silenziosa dei preferiti non più validi. Questa
 *     classe non legge mai i preferiti altrove.
 *  4. Ogni valore letto da _dealer_fav_tags / _dealer_fav_doc_tags viene
 *     ri-sanificato a ogni lettura, non solo in scrittura: sono etichette di
 *     testo libero scritte da un utente non amministratore, quindi vanno
 *     trattate come XSS di prima classe anche quando arrivano dal proprio
 *     database.
 *  5. Eliminare un'etichetta la toglie SEMPRE anche da ogni documento a cui
 *     era assegnata: _dealer_fav_doc_tags non contiene mai un id che non
 *     esista (più) in _dealer_fav_tags. La sanificazione della mappa lo
 *     garantisce a ogni lettura, non solo al momento dell'eliminazione.
 *
 * Progressive enhancement: form POST classici con pattern PRG (come
 * Dealer_Team), nessun JavaScript necessario né per la consultazione né per
 * la gestione delle etichette.
 */
class Dealer_Favorites {

	// ─── Costanti ─────────────────────────────────────────────────────────────

	/** Shortcode della pagina. */
	const SHORTCODE = 'dealer_favorites';

	/** User meta: etichette personali del dealer — [ 'id' => string, 'label' => string ]. */
	const META_TAGS = '_dealer_fav_tags';

	/** User meta: mappa post_id (string) => [ tag_id, tag_id, ... ]. */
	const META_DOC_TAGS = '_dealer_fav_doc_tags';

	/** Tetto alle etichette personali per utente: testo libero salvato nel DB. */
	const MAX_TAGS = 30;

	/** Tetto alla lunghezza (caratteri) di un'etichetta. */
	const MAX_TAG_LABEL = 40;

	/** Azioni nonce. Quelle sul bersaglio includono l'id nel contesto. */
	const NONCE_CREATE = 'dealer_fav_tag_create';
	const NONCE_RENAME = 'dealer_fav_tag_rename';
	const NONCE_DELETE = 'dealer_fav_tag_delete';
	const NONCE_ASSIGN = 'dealer_fav_tag_assign';

	/** Ordinamenti disponibili nella pagina. */
	const SORT_OPTIONS = [
		'recent' => 'Più recenti',
		'title'  => 'Titolo A-Z',
	];

	// ─── Constructor ──────────────────────────────────────────────────────────

	public function __construct() {
		add_shortcode( self::SHORTCODE, [ $this, 'render' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );

		// Front-end: gli invii si intercettano prima di qualsiasi output, così
		// ogni operazione si chiude con un redirect (PRG) e un refresh non la
		// ripete.
		add_action( 'template_redirect', [ $this, 'maybe_handle_submission' ] );
	}

	// ─── Assets ──────────────────────────────────────────────────────────────

	/** Aggancio su wp_enqueue_scripts: caso normale, CSS già nell'head. */
	public function enqueue_assets(): void {
		global $post;

		$has_shortcode = is_a( $post, 'WP_Post' )
			&& has_shortcode( (string) $post->post_content, self::SHORTCODE );

		$is_fav_page = is_a( $post, 'WP_Post' )
			&& (int) $post->ID === (int) get_option( 'dealer_portal_fav_page_id' );

		if ( ! $has_shortcode && ! $is_fav_page ) {
			return;
		}

		self::enqueue_favorites_assets();
	}

	/**
	 * Carica gli asset della pagina. Idempotente: wp_enqueue_style ignora una
	 * seconda registrazione dello stesso handle. Si riusa volutamente lo
	 * stesso handle/foglio di stile della ricerca: le classi condivise
	 * (.dealer-back-link, .dealer-empty, .dealer-btn, .dealer-doc-chip, …) e
	 * le regole aggiunte in coda da questo modulo vivono nello stesso file.
	 * Nessun JavaScript viene caricato: questa pagina non ne ha bisogno.
	 */
	public static function enqueue_favorites_assets(): void {
		wp_enqueue_style( 'dashicons' );
		wp_enqueue_style( 'dealer-portal-dealer', DEALER_PORTAL_URL . 'assets/css/dealer.css', [], DEALER_PORTAL_VERSION );
	}

	// ─── Shortcode render ──────────────────────────────────────────────────────

	public function render( $atts ): string {
		self::enqueue_favorites_assets();

		if ( ! is_user_logged_in() ) {
			return $this->notice(
				'Per accedere ai tuoi preferiti devi prima effettuare l’accesso.',
				wp_login_url( $this->current_url() ),
				'Accedi'
			);
		}

		$user = wp_get_current_user();
		if ( ! Dealer_Search::user_is_dealer( $user ) ) {
			return $this->notice( 'Accesso non autorizzato.' );
		}

		// Organizzazione sospesa: stesso messaggio della ricerca, per coerenza.
		if ( ! Dealer_Identity::is_active( $user ) ) {
			return $this->notice(
				'L’accesso della tua azienda ai documenti è attualmente sospeso. '
				. 'Contatta il tuo referente commerciale per maggiori informazioni.'
			);
		}

		$feedback = $this->consume_feedback();

		// Le linee "vere" per il controllo accessi passano sempre dal
		// risolutore di identità, mai dal meta storico _dealer_lines.
		$user_lines = Dealer_Identity::get_effective_lines( $user );

		// Unico punto di verità sui preferiti mostrabili: già ripulito da
		// documenti revocati, scaduti o eliminati, senza rivelarne il titolo.
		$docs = Dealer_Search::get_accessible_favorites( $user, $user_lines, 0 );

		$tags         = self::get_tags( $user->ID );
		$doc_tags_map = self::get_doc_tags_map( $user->ID, $tags );

		// Filtro e ordinamento: solo query string, nessuna azione distruttiva,
		// nonce non necessario in lettura.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$filter_tag    = sanitize_key( wp_unslash( $_GET['tag'] ?? '' ) );
		$valid_tag_ids = wp_list_pluck( $tags, 'id' );
		if ( '' !== $filter_tag && ! in_array( $filter_tag, $valid_tag_ids, true ) ) {
			$filter_tag = ''; // Etichetta sconosciuta/eliminata: si ricade su "Tutte".
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$orderby = sanitize_key( wp_unslash( $_GET['orderby'] ?? '' ) );
		if ( ! array_key_exists( $orderby, self::SORT_OPTIONS ) ) {
			$orderby = 'recent';
		}

		$items = [];
		foreach ( $docs as $doc ) {
			$item             = self::build_item( $doc );
			$item['tag_ids']  = $doc_tags_map[ (string) $item['id'] ] ?? [];
			$items[]          = $item;
		}
		$items = self::sort_items( $items, $orderby );

		$base_url = Dealer_DB::favorites_url();
		if ( ! $base_url ) {
			$base_url = home_url( '/' );
		}

		// Raggruppamento: con il filtro "Tutte" i preferiti sono organizzati
		// per etichetta, con un gruppo dedicato a chi non ne ha nessuna. Con
		// un'etichetta selezionata l'elenco resta piatto.
		$groups     = [];
		$flat_items = [];

		if ( '' !== $filter_tag ) {
			foreach ( $items as $item ) {
				if ( in_array( $filter_tag, $item['tag_ids'], true ) ) {
					$flat_items[] = $item;
				}
			}
		} else {
			foreach ( $tags as $tag ) {
				$tag_items = array_values( array_filter( $items, static function ( array $item ) use ( $tag ): bool {
					return in_array( $tag['id'], $item['tag_ids'], true );
				} ) );
				if ( ! empty( $tag_items ) ) {
					$groups[] = [ 'tag' => $tag, 'items' => $tag_items ];
				}
			}
			$untagged = array_values( array_filter( $items, static function ( array $item ): bool {
				return empty( $item['tag_ids'] );
			} ) );
			if ( ! empty( $untagged ) ) {
				$groups[] = [ 'tag' => null, 'items' => $untagged ];
			}
		}

		// La dashboard è la home dell'area riservata: questa pagina ne è un
		// ramo e deve poterci tornare senza affidarsi al tasto "indietro".
		$dashboard_url = Dealer_DB::dashboard_url();
		$search_url    = Dealer_DB::search_url();
		$form_action   = $this->current_url();
		$can_add_tag   = count( $tags ) < self::MAX_TAGS;
		$total_count   = count( $items );

		ob_start();
		require DEALER_PORTAL_PATH . 'templates/dealer-favorites.php';
		return (string) ob_get_clean();
	}

	/** Voce pronta per il template: stessi meta usati dalla dashboard. */
	private static function build_item( \WP_Post $doc ): array {
		$brand    = (string) get_post_meta( $doc->ID, '_doc_brand', true );
		$line     = (string) get_post_meta( $doc->ID, '_doc_product_line', true );
		$type_key = (string) get_post_meta( $doc->ID, '_doc_type', true );
		$filename = (string) get_post_meta( $doc->ID, '_doc_filename', true );
		$type_lbl = (string) ( Dealer_Admin::get_doc_types()[ $type_key ] ?? $type_key );

		return [
			'id'           => (int) $doc->ID,
			'title'        => (string) $doc->post_title,
			'date'         => (string) $doc->post_date,
			'brand'        => $brand,
			'line'         => $line,
			'type'         => $type_lbl,
			'icon'         => Dealer_Search::get_file_icon_svg( $filename ),
			'filename'     => $filename,
			'download_url' => Dealer_Search::get_download_url( (int) $doc->ID ),
			'remove_url'   => Dealer_Search::get_favorite_url( (int) $doc->ID, false ),
			'tag_ids'      => [],
		];
	}

	private static function sort_items( array $items, string $orderby ): array {
		if ( 'title' === $orderby ) {
			usort( $items, static function ( array $a, array $b ): int {
				return strnatcasecmp( $a['title'], $b['title'] );
			} );
		} else {
			usort( $items, static function ( array $a, array $b ): int {
				return strcmp( $b['date'], $a['date'] );
			} );
		}
		return $items;
	}

	/** URL della pagina con lo stato di filtro/ordinamento indicato. */
	public static function state_url( string $base_url, string $tag, string $orderby ): string {
		$args = [];
		if ( '' !== $tag ) {
			$args['tag'] = $tag;
		}
		if ( 'recent' !== $orderby ) {
			$args['orderby'] = $orderby;
		}
		if ( empty( $args ) ) {
			return $base_url;
		}
		$separator = ( false === strpos( $base_url, '?' ) ) ? '?' : '&';
		return $base_url . $separator . http_build_query( $args );
	}

	// ─── Etichette: lettura sanificata ─────────────────────────────────────────

	/**
	 * Etichette personali dell'utente, sempre ri-sanificate in lettura: sono
	 * testo libero scritto da un utente non amministratore, non ci si fida
	 * mai del valore già salvato.
	 *
	 * @return array<int,array{id:string,label:string}>
	 */
	public static function get_tags( int $user_id ): array {
		$raw = get_user_meta( $user_id, self::META_TAGS, true );
		return self::sanitize_tags( is_array( $raw ) ? $raw : [] );
	}

	private static function sanitize_tags( array $raw ): array {
		$clean = [];
		$seen  = [];
		foreach ( $raw as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$id = isset( $entry['id'] ) && is_scalar( $entry['id'] ) ? sanitize_key( (string) $entry['id'] ) : '';
			if ( '' === $id || isset( $seen[ $id ] ) ) {
				continue; // Id mancante o duplicato: la voce viene scartata.
			}
			$label = isset( $entry['label'] ) && is_scalar( $entry['label'] )
				? self::truncate_label( sanitize_text_field( (string) $entry['label'] ) )
				: '';
			if ( '' === $label ) {
				continue;
			}
			$seen[ $id ] = true;
			$clean[]     = [ 'id' => $id, 'label' => $label ];
			if ( count( $clean ) >= self::MAX_TAGS ) {
				break; // Tetto anche in lettura: un valore scritto altrove non lo aggira.
			}
		}
		return $clean;
	}

	/** Scrive le etichette (già sanificate) sul user meta. */
	private static function set_tags( int $user_id, array $tags ): array {
		$clean = self::sanitize_tags( $tags );
		update_user_meta( $user_id, self::META_TAGS, $clean );
		return $clean;
	}

	private static function truncate_label( string $label ): string {
		$label = trim( $label );
		return function_exists( 'mb_substr' )
			? mb_substr( $label, 0, self::MAX_TAG_LABEL )
			: substr( $label, 0, self::MAX_TAG_LABEL );
	}

	/** Confronto case-insensitive robusto anche senza estensione mbstring. */
	private static function normalize_for_compare( string $s ): string {
		$s = trim( $s );
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $s ) : strtolower( $s );
	}

	/** Id opaco e stabile: non deriva dal label, così un rename non rompe nulla. */
	private static function generate_tag_id( array $existing_tags ): string {
		$existing_ids = wp_list_pluck( $existing_tags, 'id' );
		for ( $i = 0; $i < 20; $i++ ) {
			$id = 't' . substr( md5( wp_generate_password( 12, true, true ) . microtime( true ) . $i ), 0, 9 );
			if ( ! in_array( $id, $existing_ids, true ) ) {
				return $id;
			}
		}
		return 't' . substr( md5( uniqid( '', true ) ), 0, 9 );
	}

	// ─── Assegnazioni etichetta ⇄ documento: lettura sanificata ────────────────

	/**
	 * Mappa post_id (string) => [ tag_id, ... ], sempre filtrata contro le
	 * etichette che esistono DAVVERO per l'utente: un id di un'etichetta
	 * eliminata non può ricomparire, nemmeno se il meta grezzo lo contenesse
	 * ancora per qualche motivo.
	 *
	 * @param array<int,array{id:string,label:string}>|null $tags Etichette
	 *        già risolte, per evitare una seconda query dentro un ciclo.
	 */
	public static function get_doc_tags_map( int $user_id, ?array $tags = null ): array {
		if ( null === $tags ) {
			$tags = self::get_tags( $user_id );
		}
		$valid_ids = wp_list_pluck( $tags, 'id' );

		$raw = get_user_meta( $user_id, self::META_DOC_TAGS, true );
		return self::sanitize_doc_tags( is_array( $raw ) ? $raw : [], $valid_ids );
	}

	private static function sanitize_doc_tags( array $raw, array $valid_tag_ids ): array {
		$clean = [];
		foreach ( $raw as $post_id => $tag_ids ) {
			$post_id = absint( $post_id );
			if ( ! $post_id || ! is_array( $tag_ids ) ) {
				continue;
			}
			$ids = [];
			foreach ( $tag_ids as $tag_id ) {
				if ( ! is_scalar( $tag_id ) ) {
					continue;
				}
				$tag_id = sanitize_key( (string) $tag_id );
				if ( '' === $tag_id || ! in_array( $tag_id, $valid_tag_ids, true ) || in_array( $tag_id, $ids, true ) ) {
					continue; // Id sconosciuto (etichetta eliminata) o duplicato: scartato.
				}
				$ids[] = $tag_id;
			}
			if ( ! empty( $ids ) ) {
				$clean[ (string) $post_id ] = $ids;
			}
		}
		return $clean;
	}

	/** Scrive la mappa (già sanificata contro le etichette valide) sul user meta. */
	private static function set_doc_tags_map( int $user_id, array $map, array $valid_tag_ids ): array {
		$clean = self::sanitize_doc_tags( $map, $valid_tag_ids );
		update_user_meta( $user_id, self::META_DOC_TAGS, $clean );
		return $clean;
	}

	// ─── Front-end: gestione invii (PRG) ────────────────────────────────────────

	/**
	 * Intercetta i POST della pagina Preferiti. Nessun output: si conclude
	 * sempre con un redirect.
	 */
	public function maybe_handle_submission(): void {
		if ( empty( $_POST['df_action'] ) ) {
			return;
		}

		$action = sanitize_key( self::post_string( 'df_action' ) );
		if ( ! in_array( $action, [ 'create_tag', 'rename_tag', 'delete_tag', 'assign_tags' ], true ) ) {
			return;
		}

		$redirect = $this->current_url();

		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( $redirect ) );
			exit;
		}

		$user = wp_get_current_user();

		// Autorizzazione: dealer, azienda non sospesa. Nessuna delega qui:
		// l'area è strettamente personale.
		if ( ! Dealer_Search::user_is_dealer( $user ) || ! Dealer_Identity::is_active( $user ) ) {
			$this->redirect_with_feedback( $redirect, 'error', 'Operazione non consentita.' );
		}

		switch ( $action ) {
			case 'create_tag':
				$this->handle_create_tag( $user, $redirect );
				break;
			case 'rename_tag':
				$this->handle_rename_tag( $user, $redirect );
				break;
			case 'delete_tag':
				$this->handle_delete_tag( $user, $redirect );
				break;
			case 'assign_tags':
				$this->handle_assign_tags( $user, $redirect );
				break;
		}
	}

	// ─── Azione: creare un'etichetta ────────────────────────────────────────────

	private function handle_create_tag( \WP_User $user, string $redirect ): void {
		if ( ! wp_verify_nonce( self::post_string( 'df_nonce' ), self::NONCE_CREATE ) ) {
			$this->redirect_with_feedback( $redirect, 'error', 'La sessione del modulo è scaduta. Ricarica la pagina e riprova.' );
		}

		$label = self::truncate_label( sanitize_text_field( self::post_string( 'df_label' ) ) );
		if ( '' === $label ) {
			$this->redirect_with_feedback( $redirect, 'error', 'Indica un nome per l’etichetta.' );
		}

		$tags = self::get_tags( $user->ID );
		if ( count( $tags ) >= self::MAX_TAGS ) {
			$this->redirect_with_feedback(
				$redirect,
				'error',
				sprintf( 'Hai raggiunto il numero massimo di %d etichette.', self::MAX_TAGS )
			);
		}

		// Deduplica case-insensitive: due etichette non possono differire solo
		// per maiuscole/minuscole.
		$norm = self::normalize_for_compare( $label );
		foreach ( $tags as $t ) {
			if ( self::normalize_for_compare( $t['label'] ) === $norm ) {
				$this->redirect_with_feedback(
					$redirect,
					'error',
					sprintf( 'Hai già un’etichetta chiamata “%s”.', $t['label'] )
				);
			}
		}

		$tags[] = [ 'id' => self::generate_tag_id( $tags ), 'label' => $label ];
		self::set_tags( $user->ID, $tags );

		$this->redirect_with_feedback( $redirect, 'success', sprintf( 'Etichetta “%s” creata.', $label ) );
	}

	// ─── Azione: rinominare un'etichetta ────────────────────────────────────────

	/** L'id resta lo stesso: rinominare non sposta nessuna assegnazione. */
	private function handle_rename_tag( \WP_User $user, string $redirect ): void {
		$tag_id = sanitize_key( self::post_string( 'df_tag' ) );

		if ( ! wp_verify_nonce( self::post_string( 'df_nonce' ), self::NONCE_RENAME . '_' . $tag_id ) ) {
			$this->redirect_with_feedback( $redirect, 'error', 'La sessione del modulo è scaduta. Ricarica la pagina e riprova.' );
		}

		$tags = self::get_tags( $user->ID );
		$idx  = null;
		foreach ( $tags as $i => $t ) {
			if ( $t['id'] === $tag_id ) {
				$idx = $i;
				break;
			}
		}
		if ( null === $idx ) {
			$this->redirect_with_feedback( $redirect, 'error', 'Etichetta non valida.' );
		}

		$label = self::truncate_label( sanitize_text_field( self::post_string( 'df_label' ) ) );
		if ( '' === $label ) {
			$this->redirect_with_feedback( $redirect, 'error', 'Indica un nome per l’etichetta.' );
		}

		$norm = self::normalize_for_compare( $label );
		foreach ( $tags as $i => $t ) {
			if ( $i !== $idx && self::normalize_for_compare( $t['label'] ) === $norm ) {
				$this->redirect_with_feedback(
					$redirect,
					'error',
					sprintf( 'Hai già un’etichetta chiamata “%s”.', $t['label'] )
				);
			}
		}

		$tags[ $idx ]['label'] = $label;
		self::set_tags( $user->ID, $tags );

		$this->redirect_with_feedback( $redirect, 'success', sprintf( 'Etichetta rinominata in “%s”.', $label ) );
	}

	// ─── Azione: eliminare un'etichetta ─────────────────────────────────────────

	/**
	 * Elimina l'etichetta e la toglie da ogni documento a cui era assegnata:
	 * nessun id orfano deve restare in _dealer_fav_doc_tags.
	 */
	private function handle_delete_tag( \WP_User $user, string $redirect ): void {
		$tag_id = sanitize_key( self::post_string( 'df_tag' ) );

		if ( ! wp_verify_nonce( self::post_string( 'df_nonce' ), self::NONCE_DELETE . '_' . $tag_id ) ) {
			$this->redirect_with_feedback( $redirect, 'error', 'La sessione del modulo è scaduta. Ricarica la pagina e riprova.' );
		}

		$tags      = self::get_tags( $user->ID );
		$found     = false;
		$remaining = [];
		foreach ( $tags as $t ) {
			if ( $t['id'] === $tag_id ) {
				$found = true;
				continue;
			}
			$remaining[] = $t;
		}
		if ( ! $found ) {
			$this->redirect_with_feedback( $redirect, 'error', 'Etichetta non valida.' );
		}

		self::set_tags( $user->ID, $remaining );

		// La mappa viene riletta e ri-sanificata contro le sole etichette
		// rimaste: l'id appena eliminato sparisce da ogni documento da solo.
		$valid_ids = wp_list_pluck( $remaining, 'id' );
		$map       = self::get_doc_tags_map( $user->ID, $remaining );
		self::set_doc_tags_map( $user->ID, $map, $valid_ids );

		$this->redirect_with_feedback( $redirect, 'success', 'Etichetta eliminata.' );
	}

	// ─── Azione: assegnare/togliere etichette a un documento ───────────────────

	/**
	 * Sostituisce l'intero insieme di etichette di un documento con quello
	 * inviato dal form (stesso pattern "set completo" di Dealer_Team::
	 * handle_set_lines()): nessuna casella selezionata = nessuna etichetta.
	 */
	private function handle_assign_tags( \WP_User $user, string $redirect ): void {
		$post_id = absint( self::post_string( 'df_post' ) );

		if ( ! wp_verify_nonce( self::post_string( 'df_nonce' ), self::NONCE_ASSIGN . '_' . $post_id ) ) {
			$this->redirect_with_feedback( $redirect, 'error', 'La sessione del modulo è scaduta. Ricarica la pagina e riprova.' );
		}

		// Solo un documento REALMENTE nei preferiti correnti dell'utente può
		// essere taggato: niente tag su un post_id arbitrario.
		if ( ! $post_id || ! Dealer_Search::is_favorite( $user->ID, $post_id ) ) {
			$this->redirect_with_feedback( $redirect, 'error', 'Documento non valido.' );
		}

		$tags      = self::get_tags( $user->ID );
		$valid_ids = wp_list_pluck( $tags, 'id' );

		$posted = [];
		if ( isset( $_POST['df_tags'] ) && is_array( $_POST['df_tags'] ) ) {
			$posted = array_map( 'sanitize_key', (array) wp_unslash( $_POST['df_tags'] ) );
		}
		$posted = array_values( array_intersect( array_unique( $posted ), $valid_ids ) );

		$map = self::get_doc_tags_map( $user->ID, $tags );
		if ( empty( $posted ) ) {
			unset( $map[ (string) $post_id ] );
		} else {
			$map[ (string) $post_id ] = $posted;
		}
		self::set_doc_tags_map( $user->ID, $map, $valid_ids );

		$this->redirect_with_feedback( $redirect, 'success', 'Etichette aggiornate.' );
	}

	// ─── PRG: feedback via transient monouso ────────────────────────────────────

	/**
	 * Salva il feedback in un transient e redirige: in query string finisce
	 * solo un token opaco, mai un id o un testo libero.
	 */
	private function redirect_with_feedback( string $url, string $status, string $message ): void {
		$token = md5( wp_generate_password( 32, true, true ) . microtime( true ) );
		set_transient( 'df_fb_' . $token, [
			'status'  => ( 'success' === $status ) ? 'success' : 'error',
			'message' => $message,
		], 10 * MINUTE_IN_SECONDS );

		wp_safe_redirect( add_query_arg( 'df_ref', $token, $url ) );
		exit;
	}

	/** Legge e cancella il feedback (monouso). */
	private function consume_feedback(): array {
		$token = sanitize_key( self::get_string( 'df_ref' ) );
		if ( '' === $token ) {
			return [];
		}
		$feedback = get_transient( 'df_fb_' . $token );
		delete_transient( 'df_fb_' . $token );

		return is_array( $feedback ) ? $feedback : [];
	}

	/**
	 * URL corrente, filtro e ordinamento compresi (così un redirect PRG non fa
	 * perdere lo stato della pagina), senza il token di feedback già consumato.
	 */
	private function current_url(): string {
		$scheme = is_ssl() ? 'https://' : 'http://';
		$host   = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
		$uri    = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
		$url    = '' !== $host ? esc_url_raw( $scheme . $host . $uri ) : Dealer_DB::favorites_url();

		return remove_query_arg( 'df_ref', $url );
	}

	// ─── Utility ──────────────────────────────────────────────────────────────

	private static function post_string( string $key ): string {
		return isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] )
			? (string) wp_unslash( $_POST[ $key ] )
			: '';
	}

	private static function get_string( string $key ): string {
		return isset( $_GET[ $key ] ) && is_scalar( $_GET[ $key ] )
			? (string) wp_unslash( $_GET[ $key ] )
			: '';
	}

	/** Avviso semplice (login richiesto, accesso negato, azienda sospesa). */
	private function notice( string $message, string $url = '', string $label = '' ): string {
		self::enqueue_favorites_assets();

		$html = '<div class="dealer-favorites-wrap"><p class="dealer-notice">' . esc_html( $message );
		if ( '' !== $url && '' !== $label ) {
			$html .= ' <a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
		}
		$html .= '</p></div>';

		return $html;
	}
}
