<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Risolutore di identita': unico punto in cui si stabilisce *cosa* un utente
 * puo' vedere e con *quale titolo*.
 *
 * Prima esistevano tre informazioni confuse in una sola: a chi appartieni
 * (nulla), cosa puoi fare (ruolo WP) e cosa puoi vedere (_dealer_lines per
 * utente). Qui sono separate:
 *
 *   - l'ORGANIZZAZIONE detiene i diritti (livello commerciale + linee)
 *   - l'UTENTE ha una funzione dentro l'organizzazione e, facoltativamente,
 *     una restrizione a un sottoinsieme delle linee aziendali
 *   - l'AREA MANAGER ha un perimetro su due assi distinti: le organizzazioni
 *     che segue e le linee su cui puo' pubblicare
 *
 * REGOLA FONDANTE — intersezione, mai unione:
 *
 *     linee effettive = linee dell'organizzazione ∩ restrizione dell'utente
 *
 * Un utente non puo' in nessun caso vedere piu' di quanto la sua azienda abbia
 * diritto. Revocare una linea all'organizzazione la revoca istantaneamente a
 * tutti i suoi utenti, senza dover ripassare i profili uno per uno.
 *
 * RETROCOMPATIBILITA': un utente senza organizzazione ricade sui meta storici
 * (_dealer_lines + ruolo WP). Il vecchio modello resta un percorso valido, cosi'
 * l'introduzione delle organizzazioni non e' un'amputazione ma un'aggiunta.
 */
class Dealer_Identity {

	/** Organizzazione di appartenenza dell'utente. */
	const META_ORG = '_dealer_org';

	/** Funzione dentro l'organizzazione. */
	const META_FUNCTION = '_dealer_function';

	/** Restrizione facoltativa a un sottoinsieme delle linee aziendali. */
	const META_LINE_LIMIT = '_dealer_line_limit';

	/** Perimetro area manager: organizzazioni seguite (radici di sottoalberi). */
	const META_AM_ORGS = '_am_orgs';

	/** Perimetro area manager: linee su cui puo' pubblicare. */
	const META_AM_LINES = '_am_lines';

	/** Meta storico, usato come fallback per gli utenti non ancora migrati. */
	const META_LEGACY_LINES = '_dealer_lines';

	const FUNCTION_TITOLARE     = 'titolare';
	const FUNCTION_COLLABORATORE = 'collaboratore';

	const FUNCTIONS = [ self::FUNCTION_TITOLARE, self::FUNCTION_COLLABORATORE ];

	/** Ruolo dell'area manager. */
	const ROLE_AREA_MANAGER = 'area_manager';

	/** Ruoli dealer, allineati a Dealer_Search. */
	const DEALER_ROLES = [ 'dealer', 'top_dealer', 'part_center' ];

	/** Titolo con cui avviene un accesso: finisce nel log dei download. */
	const CONTEXT_ADMIN        = 'admin';
	const CONTEXT_AREA_MANAGER = 'area_manager';
	const CONTEXT_DEALER       = 'dealer';

	// ─── Appartenenza ─────────────────────────────────────────────────────────

	public static function get_org_id( \WP_User $user ): int {
		$org_id = (int) get_user_meta( $user->ID, self::META_ORG, true );
		return Dealer_Organization::exists( $org_id ) ? $org_id : 0;
	}

	public static function has_org( \WP_User $user ): bool {
		return self::get_org_id( $user ) > 0;
	}

	public static function get_function( \WP_User $user ): string {
		$fn = (string) get_user_meta( $user->ID, self::META_FUNCTION, true );
		return in_array( $fn, self::FUNCTIONS, true ) ? $fn : self::FUNCTION_COLLABORATORE;
	}

	public static function is_titolare( \WP_User $user ): bool {
		return self::has_org( $user ) && self::FUNCTION_TITOLARE === self::get_function( $user );
	}

	// ─── Diritti effettivi ────────────────────────────────────────────────────

	/**
	 * Linee effettivamente accessibili all'utente.
	 *
	 * @return string[] formato "Brand|Linea"
	 */
	public static function get_effective_lines( \WP_User $user ): array {
		$org_id = self::get_org_id( $user );

		// Nessuna organizzazione: modello storico.
		if ( ! $org_id ) {
			$legacy = get_user_meta( $user->ID, self::META_LEGACY_LINES, true );
			return is_array( $legacy ) ? array_values( array_filter( $legacy ) ) : [];
		}

		// Organizzazione sospesa: nessun accesso, nemmeno ai documenti senza
		// restrizione di linea (vedi is_active()).
		if ( ! Dealer_Organization::is_active( $org_id ) ) {
			return [];
		}

		$lines = Dealer_Organization::get_effective_lines( $org_id );

		// Restrizione individuale: intersezione, mai ampliamento.
		$limit = get_user_meta( $user->ID, self::META_LINE_LIMIT, true );
		if ( is_array( $limit ) && ! empty( $limit ) ) {
			$lines = array_values( array_intersect( $lines, $limit ) );
		}

		return $lines;
	}

	/**
	 * Livello commerciale effettivo. Con un'organizzazione e' un attributo
	 * dell'azienda; senza, si ricade sul ruolo WordPress storico.
	 */
	public static function get_effective_tier( \WP_User $user ): string {
		$org_id = self::get_org_id( $user );
		if ( $org_id ) {
			return Dealer_Organization::get_tier( $org_id );
		}

		foreach ( self::DEALER_ROLES as $role ) {
			if ( in_array( $role, (array) $user->roles, true ) ) {
				return $role;
			}
		}

		return '';
	}

	/**
	 * Un utente e' attivo se non appartiene a un'organizzazione sospesa.
	 * Gli utenti non ancora migrati sono sempre attivi.
	 */
	public static function is_active( \WP_User $user ): bool {
		$org_id = self::get_org_id( $user );
		return $org_id ? Dealer_Organization::is_active( $org_id ) : true;
	}

	// ─── Area manager ─────────────────────────────────────────────────────────

	public static function is_area_manager( \WP_User $user ): bool {
		return in_array( self::ROLE_AREA_MANAGER, (array) $user->roles, true );
	}

	/**
	 * Organizzazioni nel perimetro, sottoalberi espansi: seguire un gruppo
	 * significa seguirne anche le sedi.
	 *
	 * @return int[]
	 */
	public static function get_scope_orgs( \WP_User $user ): array {
		if ( ! self::is_area_manager( $user ) ) {
			return [];
		}

		$roots = get_user_meta( $user->ID, self::META_AM_ORGS, true );
		if ( ! is_array( $roots ) ) {
			return [];
		}

		$orgs = [];
		foreach ( $roots as $root ) {
			$root = absint( $root );
			if ( $root ) {
				$orgs = array_merge( $orgs, Dealer_Organization::get_subtree( $root ) );
			}
		}

		return array_values( array_unique( $orgs ) );
	}

	/**
	 * Linee su cui l'area manager puo' pubblicare e intervenire.
	 *
	 * @return string[]
	 */
	public static function get_scope_lines( \WP_User $user ): array {
		if ( ! self::is_area_manager( $user ) ) {
			return [];
		}

		$lines = get_user_meta( $user->ID, self::META_AM_LINES, true );
		if ( ! is_array( $lines ) ) {
			return [];
		}

		$valid = class_exists( 'Dealer_Admin' ) ? Dealer_Admin::get_valid_lines() : [];
		return array_values( array_intersect( array_map( 'sanitize_text_field', $lines ), $valid ) );
	}

	/**
	 * Scrive il perimetro di un area manager: organizzazioni radice seguite
	 * (get_scope_orgs() ne espande da sola i sottoalberi) e linee su cui puo'
	 * pubblicare.
	 *
	 * Unico punto di scrittura per META_AM_ORGS/META_AM_LINES, sullo stesso
	 * principio di set_line_limit(): il chiamante (Dealer_Org_Admin) passa
	 * dati grezzi dal POST, qui si valida — un'organizzazione cancellata nel
	 * frattempo o una linea fuori whitelist non finiscono mai nel meta.
	 *
	 * @param int[]    $org_ids Radici seguite, grezze dal POST.
	 * @param string[] $lines   Linee "Brand|Linea", grezze dal POST.
	 */
	public static function set_am_scope( int $user_id, array $org_ids, array $lines ): void {
		$org_ids = array_values( array_unique( array_filter(
			array_map( 'absint', $org_ids ),
			[ 'Dealer_Organization', 'exists' ]
		) ) );

		$valid = class_exists( 'Dealer_Admin' ) ? Dealer_Admin::get_valid_lines() : [];
		$lines = array_values( array_unique( array_intersect( array_map( 'sanitize_text_field', $lines ), $valid ) ) );

		update_user_meta( $user_id, self::META_AM_ORGS, $org_ids );
		update_user_meta( $user_id, self::META_AM_LINES, $lines );
	}

	/**
	 * Puo' pubblicare un documento su queste linee?
	 *
	 * Tre condizioni, tutte necessarie per un area manager:
	 *  1. almeno una linea deve essere indicata — un documento senza restrizione
	 *     di linea e' visibile a TUTTA la rete, e sarebbe la scorciatoia per
	 *     pubblicare fuori dal proprio perimetro;
	 *  2. ogni linea deve stare nel perimetro;
	 *  3. il perimetro non deve essere vuoto.
	 *
	 * L'amministratore non e' soggetto a nessuna delle tre.
	 */
	public static function can_publish_to_lines( \WP_User $user, array $lines ): bool {
		if ( user_can( $user, 'manage_options' ) || user_can( $user, DEALER_PORTAL_CAP ) ) {
			return true;
		}

		if ( ! self::is_area_manager( $user ) ) {
			return false;
		}

		$scope = self::get_scope_lines( $user );
		if ( empty( $scope ) || empty( $lines ) ) {
			return false;
		}

		// Nessuna linea fuori perimetro.
		return empty( array_diff( $lines, $scope ) );
	}

	/**
	 * Puo' modificare un documento esistente?
	 * Solo se TUTTE le sue linee stanno nel perimetro: un documento che spazia
	 * anche su linee altrui resta dell'amministratore.
	 */
	public static function can_edit_document( \WP_User $user, int $post_id ): bool {
		if ( user_can( $user, 'manage_options' ) || user_can( $user, DEALER_PORTAL_CAP ) ) {
			return true;
		}

		if ( ! self::is_area_manager( $user ) ) {
			return false;
		}

		$doc_lines = get_post_meta( $post_id, '_doc_lines', true );
		if ( ! is_array( $doc_lines ) || empty( $doc_lines ) ) {
			return false; // Documento senza restrizione: solo amministratore.
		}

		return self::can_publish_to_lines( $user, $doc_lines );
	}

	// ─── Titolo di accesso (per il log) ───────────────────────────────────────

	/**
	 * Con quale titolo questo utente sta accedendo. Registrarlo nel log rende
	 * distinguibile il download di un area manager da quello di un dealer,
	 * cosi' la delega non annacqua il valore di audit del registro.
	 */
	public static function get_access_context( \WP_User $user ): string {
		if ( user_can( $user, 'manage_options' ) || user_can( $user, DEALER_PORTAL_CAP ) ) {
			return self::CONTEXT_ADMIN;
		}
		if ( self::is_area_manager( $user ) ) {
			return self::CONTEXT_AREA_MANAGER;
		}
		return self::CONTEXT_DEALER;
	}

	// ─── Scrittura ────────────────────────────────────────────────────────────

	/** Assegna un utente a un'organizzazione con una funzione. */
	public static function set_org( int $user_id, int $org_id, string $function = self::FUNCTION_COLLABORATORE ): bool {
		if ( ! Dealer_Organization::exists( $org_id ) ) {
			return false;
		}
		if ( ! in_array( $function, self::FUNCTIONS, true ) ) {
			$function = self::FUNCTION_COLLABORATORE;
		}

		update_user_meta( $user_id, self::META_ORG, $org_id );
		update_user_meta( $user_id, self::META_FUNCTION, $function );
		return true;
	}

	/**
	 * Restrizione individuale. Filtrata contro le linee dell'organizzazione:
	 * un titolare non puo' concedere a un collaboratore piu' di quanto l'azienda
	 * possieda, nemmeno manipolando la richiesta.
	 */
	public static function set_line_limit( int $user_id, array $lines ): void {
		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			return;
		}

		$org_id = self::get_org_id( $user );
		if ( ! $org_id ) {
			return;
		}

		$org_lines = Dealer_Organization::get_effective_lines( $org_id );
		$clean     = array_values( array_intersect(
			array_map( 'sanitize_text_field', $lines ),
			$org_lines
		) );

		update_user_meta( $user_id, self::META_LINE_LIMIT, $clean );
	}
}
