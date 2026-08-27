<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Dealer_Admin {

	// ─── Brand / Linee / Tipi ─────────────────────────────────────────────────

	const PRODUCT_LINES = [
		'Mercury'          => [ 'FourStroke', 'SeaPro', 'Racing', 'Jet', 'Ricambi & Accessori' ],
		'Yamaha'           => [ 'F-Series', 'T-Series High Thrust', 'Jet Drive', 'V-MAX SHO', 'Ricambi & Accessori' ],
		'Honda'            => [ 'BF-Series', 'BFP-Series', 'Ricambi & Accessori' ],
		'Suzuki'           => [ 'DF-Series', 'Ricambi & Accessori' ],
		'Tohatsu'          => [ 'MFS-Series', 'BFT-Series', 'Ricambi & Accessori' ],
		'Evinrude'         => [ 'E-TEC G2', 'E-TEC', 'Ricambi & Accessori' ],
		'BRP'              => [ 'Rotax Marine', 'Sea-Doo', 'Accessori' ],
		'Yanmar'           => [ 'SD-Series', 'JH-Series', 'Ricambi & Accessori' ],
		'Volvo Penta'      => [ 'IPS', 'D-Series', 'AQ-Series', 'Ricambi & Accessori' ],
		'Mercruiser'       => [ 'Alpha', 'Bravo', 'CMD', 'TRS', 'Ricambi & Accessori' ],
		'ZF'               => [ 'Pod Drive', 'Stern Drive', 'Transmission', 'Propulsion' ],
		'Ultraflex'        => [ 'Sistemi Sterzo', 'Cavi', 'Pompe Timone', 'Sterzo Idraulico' ],
		'Garmin'           => [ 'ECHOMAP', 'STRIKER', 'GPSMAP', 'VHF', 'Autopilota', 'Trasduttori' ],
		'Lowrance'         => [ 'HDS LIVE', 'HOOK Reveal', 'ELITE', 'Accessori' ],
		'Simrad'           => [ 'GO Series', 'NSS evo', 'NSX', 'AP Series', 'VHF' ],
		'Raymarine'        => [ 'Axiom', 'Element', 'Lighthouse', 'Autopilota', 'VHF' ],
		'Furuno'           => [ 'GP-Series', 'FCV-Series', 'FAR-Series', 'NavNet TZtouch' ],
		'B&G'              => [ 'Zeus', 'Vulcan', 'Triton', 'Autopilota' ],
		'Navionics'        => [ 'Navionics+', 'Gold Charts', 'Platinum+ Charts' ],
		'Humminbird'       => [ 'HELIX', 'SOLIX', 'ION', 'Accessori' ],
		'Standard Horizon' => [ 'Matrix', 'Explorer', 'Eclipse', 'VHF' ],
		'Icom'             => [ 'M-Series VHF', 'IC-AH Series', 'AIS' ],
		'Cobra'            => [ 'HH-Series', 'MR-Series', 'F-Series' ],
		'Torqeedo'         => [ 'Travel', 'Cruise', 'Deep Blue', 'Accessori' ],
		'ePropulsion'      => [ 'Spirit', 'Navy', 'Evo', 'Accessori' ],
		'Beneteau'         => [ 'Oceanis', 'Antares', 'Swift Trawler', 'Flyer' ],
		'Quicksilver'      => [ 'Activ', 'Weekend', 'Captur', 'Pilothouse' ],
		'Sealine'          => [ 'C-Series', 'F-Series', 'S-Series', 'SC-Series' ],
	];

	const DOC_TYPES = [
		'scheda_tecnica'  => 'Scheda Tecnica',
		'listino'         => 'Listino',
		'manuale'         => 'Manuale',
		'certificazione'  => 'Certificazione',
		'marketing'       => 'Marketing',
		'altro'           => 'Altro',
	];

	// ─── Constructor ─────────────────────────────────────────────────────────

	public function __construct() {
		add_action( 'admin_menu',             [ $this, 'register_menus' ] );
		add_action( 'admin_enqueue_scripts',  [ $this, 'enqueue_assets' ] );
		add_action( 'wp_ajax_dealer_save_document',    [ $this, 'ajax_save_document' ] );
		add_action( 'wp_ajax_dealer_get_lines',        [ $this, 'ajax_get_lines' ] );
		add_action( 'wp_ajax_dealer_mark_obsolete',    [ $this, 'ajax_mark_obsolete' ] );
		add_action( 'wp_ajax_dealer_delete_document',  [ $this, 'ajax_delete_document' ] );
		add_action( 'wp_ajax_dealer_bulk_action',      [ $this, 'ajax_bulk_action' ] );
		// Export CSV: handler admin_post (non AJAX) — deve poter scrivere header
		// e corpo del file senza che nulla venga emesso prima.
		add_action( 'admin_post_dealer_export_logs',   [ $this, 'handle_export_logs' ] );
		add_action( 'show_user_profile',      [ $this, 'render_user_fields' ] );
		add_action( 'edit_user_profile',      [ $this, 'render_user_fields' ] );
		add_action( 'personal_options_update',   [ $this, 'save_user_fields' ] );
		add_action( 'edit_user_profile_update',  [ $this, 'save_user_fields' ] );
	}

	// ─── Menu ────────────────────────────────────────────────────────────────

	public function register_menus(): void {
		add_menu_page(
			'Dealer Portal',
			'Dealer Portal',
			DEALER_PORTAL_CAP,
			'dealer-portal',
			[ $this, 'render_upload' ],
			'dashicons-portfolio',
			30
		);
		add_submenu_page( 'dealer-portal', 'Carica Documento',   'Carica Documento',   DEALER_PORTAL_CAP, 'dealer-portal',          [ $this, 'render_upload' ] );
		add_submenu_page( 'dealer-portal', 'Archivio Documenti', 'Archivio Documenti', DEALER_PORTAL_CAP, 'dealer-portal-archive',  [ $this, 'render_archive' ] );
		add_submenu_page( 'dealer-portal', 'Log Download',       'Log Download',       DEALER_PORTAL_CAP, 'dealer-portal-logs',     [ $this, 'render_logs' ] );
		add_submenu_page( 'dealer-portal', 'Statistiche',       'Statistiche',        DEALER_PORTAL_CAP, 'dealer-portal-stats',    [ $this, 'render_stats' ] );
	}

	// ─── Assets ──────────────────────────────────────────────────────────────

	public function enqueue_assets( string $hook ): void {
		$plugin_hooks = [
			'toplevel_page_dealer-portal',
			'dealer-portal_page_dealer-portal-archive',
			'dealer-portal_page_dealer-portal-logs',
			'dealer-portal_page_dealer-portal-stats',
		];
		if ( ! in_array( $hook, $plugin_hooks, true ) ) {
			return;
		}

		wp_enqueue_style(
			'dealer-portal-admin',
			DEALER_PORTAL_URL . 'assets/css/admin.css',
			[],
			DEALER_PORTAL_VERSION
		);

		wp_enqueue_script(
			'dealer-portal-admin',
			DEALER_PORTAL_URL . 'assets/js/admin-upload.js',
			[ 'jquery' ],
			DEALER_PORTAL_VERSION,
			true
		);

		// Dati passati in modo sicuro via wp_localize_script (nessun inline JS con dati grezzi).
		wp_localize_script( 'dealer-portal-admin', 'dealerAdmin', [
			'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
			'nonce'        => wp_create_nonce( 'dealer_upload_nonce' ),
			'archiveNonce' => wp_create_nonce( 'dealer_archive_nonce' ),
			'bulkNonce'    => wp_create_nonce( 'dealer_bulk_nonce' ),
			'currentYear'  => (int) gmdate( 'Y' ),
			'productLines' => self::PRODUCT_LINES,
			'docTypes'     => self::DOC_TYPES,
			'i18n'         => [
				'confirmDelete'   => 'Eliminare definitivamente questo documento? Il file verrà cancellato dal server.',
				'confirmObsolete' => 'Segnare questo documento come obsoleto? Non sarà più visibile ai dealer.',
				'saveSuccess'     => 'Documento salvato con successo.',
				'saveError'       => 'Errore durante il salvataggio. Controlla i campi e riprova.',
				'uploading'       => 'Caricamento in corso…',
				'noPrevious'      => 'Nessuno — verrà creato un nuovo documento',
				'docsAvailable'   => 'documenti disponibili',
				'noMatch'         => 'Nessun documento corrisponde al filtro.',
				'selectedPrefix'  => 'Selezionato:',
				// Operazioni bulk (archivio). %d viene sostituito lato JS.
				'bulkNoAction'    => 'Seleziona prima un\'azione di gruppo.',
				'bulkNoSelection' => 'Seleziona almeno un documento.',
				'bulkConfirmObs'  => 'Segnare come obsoleti %d documenti? Non saranno più visibili ai dealer '
					. 'e usciranno dalla ricerca; dove esiste uno storico, la versione precedente tornerà corrente.',
				'bulkConfirmDel'  => 'Eliminare definitivamente %d documenti? L\'operazione è IRREVERSIBILE: '
					. 'i documenti e i relativi file verranno cancellati dal server e non potranno essere recuperati.',
				'bulkWorking'     => 'Operazione in corso…',
				'bulkApply'       => 'Applica',
				'bulkError'       => 'Errore durante l\'operazione di gruppo.',
			],
		] );
	}

	// ─── Page renderers ──────────────────────────────────────────────────────

	public function render_upload(): void {
		if ( ! current_user_can( DEALER_PORTAL_CAP ) ) {
			wp_die( esc_html__( 'Accesso non consentito.', 'dealer-portal' ) );
		}
		require DEALER_PORTAL_PATH . 'templates/admin-upload.php';
	}

	public function render_archive(): void {
		if ( ! current_user_can( DEALER_PORTAL_CAP ) ) {
			wp_die( esc_html__( 'Accesso non consentito.', 'dealer-portal' ) );
		}

		// Filtri dalla GET (tutti sanitizzati).
		$filter_brand  = sanitize_text_field( wp_unslash( $_GET['filter_brand']  ?? '' ) );
		$filter_type   = sanitize_key( $_GET['filter_type']   ?? '' );
		$filter_role   = sanitize_key( $_GET['filter_role']   ?? '' );
		$filter_line   = sanitize_text_field( wp_unslash( $_GET['filter_line']   ?? '' ) );
		$filter_status = sanitize_key( $_GET['filter_status'] ?? '' );
		$paged         = max( 1, absint( $_GET['paged'] ?? 1 ) );

		// Filtro versioni: dimensione indipendente da _doc_status (obsoleto ≠ superato).
		// Default = solo versioni correnti; 'all' include anche le versioni superate.
		$filter_versions = sanitize_key( $_GET['filter_versions'] ?? '' );
		if ( 'all' !== $filter_versions ) {
			$filter_versions = '';
		}

		$meta_query = [ 'relation' => 'AND' ];
		if ( $filter_brand ) {
			$meta_query[] = [ 'key' => '_doc_brand', 'value' => $filter_brand, 'compare' => '=' ];
		}
		if ( $filter_type ) {
			$meta_query[] = [ 'key' => '_doc_type', 'value' => $filter_type, 'compare' => '=' ];
		}
		if ( $filter_status === 'obsoleto' ) {
			$meta_query[] = [ 'key' => '_doc_status', 'value' => 'obsoleto', 'compare' => '=' ];
			$post_status  = [ 'publish', 'draft' ];
		} else {
			$meta_query[] = [
				'relation' => 'OR',
				[ 'key' => '_doc_status', 'value' => 'obsoleto', 'compare' => '!=' ],
				[ 'key' => '_doc_status', 'compare' => 'NOT EXISTS' ],
			];
			$post_status = 'publish';
		}

		// Versioni superate: escluse salvo richiesta esplicita.
		if ( 'all' !== $filter_versions ) {
			$meta_query[] = Dealer_Versioning::meta_query_current_only();
		}

		$wp_query  = new WP_Query( [
			'post_type'      => 'documento_dealer',
			'post_status'    => $post_status,
			'posts_per_page' => 20,
			'paged'          => $paged,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'meta_query'     => $meta_query,
		] );
		$documents = $wp_query->posts;

		// Filtro per ruolo e linea in PHP (dati serializzati in meta).
		if ( $filter_role ) {
			$documents = array_values( array_filter( $documents, static function ( $post ) use ( $filter_role ) {
				$roles = get_post_meta( $post->ID, '_doc_roles', true );
				return is_array( $roles ) && in_array( $filter_role, $roles, true );
			} ) );
		}
		if ( $filter_line ) {
			$documents = array_values( array_filter( $documents, static function ( $post ) use ( $filter_line ) {
				$lines = get_post_meta( $post->ID, '_doc_lines', true );
				return is_array( $lines ) && in_array( $filter_line, $lines, true );
			} ) );
		}

		$total_pages = (int) $wp_query->max_num_pages;

		// Riepilogo dell'ultima operazione di gruppo: il JS ricarica la pagina
		// con questi parametri (solo interi + una chiave whitelistata) così la
		// notice è renderizzata lato server con il markup WordPress nativo.
		$bulk_summary = self::parse_bulk_summary();

		require DEALER_PORTAL_PATH . 'templates/admin-archive.php';
	}

	/**
	 * Legge dalla query string il riepilogo dell'ultima operazione bulk.
	 *
	 * @return array|null null se non c'è nessun riepilogo da mostrare.
	 */
	private static function parse_bulk_summary(): ?array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET['bulk_done'] ) ) {
			return null;
		}

		$op = sanitize_key( $_GET['bulk_op'] ?? '' );
		if ( ! in_array( $op, [ 'obsolete', 'delete' ], true ) ) {
			return null;
		}

		return [
			'op'        => $op,
			'processed' => absint( $_GET['bulk_ok']      ?? 0 ),
			'invalid'   => absint( $_GET['bulk_invalid'] ?? 0 ),
			'already'   => absint( $_GET['bulk_already'] ?? 0 ),
			'no_succ'   => absint( $_GET['bulk_nosucc']  ?? 0 ),
			'failed'    => absint( $_GET['bulk_failed']  ?? 0 ),
			'promoted'  => absint( $_GET['bulk_promo']   ?? 0 ),
		];
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	public function render_logs(): void {
		if ( ! current_user_can( DEALER_PORTAL_CAP ) ) {
			wp_die( esc_html__( 'Accesso non consentito.', 'dealer-portal' ) );
		}

		$filters  = self::get_log_filters();
		$per_page = 50;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$paged    = max( 1, absint( $_GET['paged'] ?? 1 ) );

		$total_logs  = Dealer_DB::count_logs( $filters['args'] );
		$total_pages = (int) ceil( $total_logs / $per_page );
		if ( $total_pages > 0 && $paged > $total_pages ) {
			$paged = $total_pages;
		}

		$logs = Dealer_DB::get_all_logs( array_merge( $filters['args'], [
			'limit'  => $per_page,
			'offset' => ( $paged - 1 ) * $per_page,
		] ) );

		// Opzioni per i menu a tendina dei filtri.
		$log_documents = self::get_filterable_documents();
		$log_dealers   = self::get_filterable_dealers();

		// URL di export: stessi filtri della vista + nonce dedicato.
		$export_url = self::build_export_url( $filters );

		require DEALER_PORTAL_PATH . 'templates/admin-logs.php';
	}

	public function render_stats(): void {
		if ( ! current_user_can( DEALER_PORTAL_CAP ) ) {
			wp_die( esc_html__( 'Accesso non consentito.', 'dealer-portal' ) );
		}

		// ─── Periodo selezionato ─────────────────────────────────────────────
		$periods = [
			'30'  => 'Ultimi 30 giorni',
			'90'  => 'Ultimi 90 giorni',
			'365' => 'Ultimo anno',
			'all' => 'Sempre',
		];
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$period = sanitize_key( $_GET['periodo'] ?? '30' );
		if ( ! isset( $periods[ $period ] ) ) {
			$period = '30';
		}

		$since = ( 'all' === $period ) ? '' : self::days_ago_mysql( (int) $period );

		// ─── Riquadri riassuntivi ────────────────────────────────────────────
		$total_downloads  = Dealer_DB::get_total_downloads();
		$downloads_30     = Dealer_DB::get_total_downloads( self::days_ago_mysql( 30 ) );
		$downloads_period = ( 'all' === $period ) ? $total_downloads : Dealer_DB::get_total_downloads( $since );

		// ─── Documenti attivi (correnti, non obsoleti) ───────────────────────
		$docs_query = new WP_Query( [
			'post_type'      => 'documento_dealer',
			'post_status'    => 'publish',
			'posts_per_page' => 1000,
			'fields'         => 'ids',
			'no_found_rows'  => false,
			'meta_query'     => [
				'relation' => 'AND',
				[
					'relation' => 'OR',
					[ 'key' => '_doc_status', 'value' => 'obsoleto', 'compare' => '!=' ],
					[ 'key' => '_doc_status', 'compare' => 'NOT EXISTS' ],
				],
				Dealer_Versioning::meta_query_current_only(),
			],
		] );
		$active_doc_ids   = array_map( 'absint', (array) $docs_query->posts );
		$active_docs      = (int) $docs_query->found_posts;
		$docs_truncated   = $active_docs > count( $active_doc_ids );

		// ─── Dealer registrati ───────────────────────────────────────────────
		$dealer_roles  = [ 'dealer', 'top_dealer', 'part_center' ];
		$dealer_query  = new WP_User_Query( [
			'role__in' => $dealer_roles,
			'number'   => 1000,
			'orderby'  => 'display_name',
			'order'    => 'ASC',
			'fields'   => [ 'ID', 'display_name', 'user_email' ],
		] );
		$dealer_users       = (array) $dealer_query->get_results();
		$registered_dealers = (int) $dealer_query->get_total();

		// ─── Classifiche ─────────────────────────────────────────────────────
		$top_documents = Dealer_DB::get_top_documents( 10, $since );
		$top_users     = Dealer_DB::get_top_users( 10, $since );

		// ─── Documenti mai scaricati ─────────────────────────────────────────
		// Confronta i documenti pubblicati con quelli presenti nel log.
		$download_counts  = Dealer_DB::get_download_counts_for_posts( $active_doc_ids );
		$never_downloaded = [];
		foreach ( $active_doc_ids as $doc_id ) {
			if ( empty( $download_counts[ $doc_id ] ) ) {
				$never_downloaded[] = $doc_id;
			}
		}
		$never_downloaded_total = count( $never_downloaded );
		$never_downloaded       = array_slice( $never_downloaded, 0, 25 );

		// ─── Dealer inattivi (>90 giorni o mai) ──────────────────────────────
		$last_downloads = Dealer_DB::get_last_download_per_user();
		$threshold      = self::days_ago_mysql( 90 );
		$inactive_dealers = [];
		foreach ( $dealer_users as $dealer ) {
			$uid  = (int) $dealer->ID;
			$last = $last_downloads[ $uid ] ?? '';
			if ( $last && $last >= $threshold ) {
				continue;
			}
			$inactive_dealers[] = [
				'id'            => $uid,
				'name'          => (string) $dealer->display_name,
				'email'         => (string) $dealer->user_email,
				'last_download' => $last,
				'last_login'    => (string) get_user_meta( $uid, '_dealer_last_login', true ),
			];
		}
		// I "mai scaricato" per primi, poi dal più vecchio al più recente.
		usort( $inactive_dealers, static function ( array $a, array $b ): int {
			return strcmp( $a['last_download'], $b['last_download'] );
		} );
		$inactive_total   = count( $inactive_dealers );
		$inactive_dealers = array_slice( $inactive_dealers, 0, 25 );

		// "Attivi" = hanno scaricato almeno una volta negli ultimi 90 giorni.
		$active_dealers = max( 0, count( $dealer_users ) - $inactive_total );

		require DEALER_PORTAL_PATH . 'templates/admin-stats.php';
	}

	/**
	 * Data MySQL di N giorni fa nel fuso orario del sito (i log usano
	 * current_time( 'mysql' ), quindi il confronto deve restare in ora locale).
	 */
	private static function days_ago_mysql( int $days ): string {
		$now = (int) current_time( 'timestamp' );
		return gmdate( 'Y-m-d H:i:s', $now - ( max( 0, $days ) * DAY_IN_SECONDS ) );
	}

	// ─── Log download: filtri, opzioni, URL di export ────────────────────────

	/**
	 * Legge e valida i filtri della pagina Log Download.
	 * Restituisce sia i valori grezzi (per ripopolare il form e costruire gli
	 * URL) sia gli argomenti pronti per Dealer_DB::get_all_logs()/count_logs().
	 *
	 * @param array|null $source Sorgente da cui leggere (default: $_GET).
	 * @return array{doc:int,user:int,from:string,to:string,args:array}
	 */
	private static function get_log_filters( ?array $source = null ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$source = ( null === $source ) ? $_GET : $source;

		$doc  = absint( $source['filter_doc']  ?? 0 );
		$user = absint( $source['filter_user'] ?? 0 );
		$from = sanitize_text_field( wp_unslash( (string) ( $source['filter_from'] ?? '' ) ) );
		$to   = sanitize_text_field( wp_unslash( (string) ( $source['filter_to']   ?? '' ) ) );

		if ( ! self::is_valid_date( $from ) ) { $from = ''; }
		if ( ! self::is_valid_date( $to ) )   { $to   = ''; }

		// Intervallo invertito: si scambiano invece di restituire zero righe.
		if ( $from && $to && $from > $to ) {
			$swap = $from;
			$from = $to;
			$to   = $swap;
		}

		$args = [];
		if ( $doc )  { $args['post_id']   = $doc; }
		if ( $user ) { $args['user_id']   = $user; }
		if ( $from ) { $args['date_from'] = $from . ' 00:00:00'; }
		if ( $to )   { $args['date_to']   = $to . ' 23:59:59'; }

		return [
			'doc'  => $doc,
			'user' => $user,
			'from' => $from,
			'to'   => $to,
			'args' => $args,
		];
	}

	/** Valida una data nel formato Y-m-d (giorno realmente esistente). */
	private static function is_valid_date( string $date ): bool {
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return false;
		}
		$dt = DateTime::createFromFormat( 'Y-m-d', $date );
		return ( $dt && $dt->format( 'Y-m-d' ) === $date );
	}

	/**
	 * Documenti selezionabili nel filtro della pagina log.
	 *
	 * @return \WP_Post[]
	 */
	private static function get_filterable_documents(): array {
		return get_posts( [
			'post_type'      => 'documento_dealer',
			'post_status'    => [ 'publish', 'draft' ],
			'numberposts'    => 300,
			'orderby'        => 'title',
			'order'          => 'ASC',
		] );
	}

	/**
	 * Dealer selezionabili nel filtro della pagina log.
	 *
	 * @return array<int,object>
	 */
	private static function get_filterable_dealers(): array {
		$query = new WP_User_Query( [
			'role__in' => [ 'dealer', 'top_dealer', 'part_center' ],
			'number'   => 500,
			'orderby'  => 'display_name',
			'order'    => 'ASC',
			'fields'   => [ 'ID', 'display_name', 'user_email' ],
		] );
		return (array) $query->get_results();
	}

	/** URL dell'export CSV con i filtri correnti e il nonce dedicato. */
	private static function build_export_url( array $filters ): string {
		$url = admin_url( 'admin-post.php?action=dealer_export_logs' );

		if ( $filters['doc'] )  { $url = add_query_arg( 'filter_doc',  $filters['doc'],  $url ); }
		if ( $filters['user'] ) { $url = add_query_arg( 'filter_user', $filters['user'], $url ); }
		if ( $filters['from'] ) { $url = add_query_arg( 'filter_from', $filters['from'], $url ); }
		if ( $filters['to'] )   { $url = add_query_arg( 'filter_to',   $filters['to'],   $url ); }

		return wp_nonce_url( $url, 'dealer_export_logs', 'dealer_export_nonce' );
	}

	// ─── Export CSV dei log download (admin_post, non AJAX) ──────────────────

	/**
	 * Scrive il CSV direttamente sull'output.
	 *
	 * Nessun byte viene emesso prima della verifica di capability e nonce e
	 * prima degli header: qualunque output anticipato romperebbe il download.
	 * Le righe sono lette a blocchi via limit/offset per non caricare in
	 * memoria un archivio di log arbitrariamente grande.
	 */
	public function handle_export_logs(): void {
		if ( ! current_user_can( DEALER_PORTAL_CAP ) ) {
			wp_die( esc_html__( 'Accesso non consentito.', 'dealer-portal' ), '', [ 'response' => 403 ] );
		}

		// Nonce dedicato all'export (non riusa quelli di upload/archivio).
		check_admin_referer( 'dealer_export_logs', 'dealer_export_nonce' );

		$filters = self::get_log_filters();

		// Congela il set esportato all'id più alto presente adesso: i download
		// registrati mentre l'export gira sposterebbero altrimenti le righe fra
		// un blocco e il successivo (ORDER BY data + OFFSET), con il rischio di
		// saltarne o duplicarne.
		$filters['args']['max_id'] = Dealer_DB::get_max_log_id();

		$total = Dealer_DB::count_logs( $filters['args'] );

		$filename = 'log-download-dealer-' . gmdate( 'Ymd-His', (int) current_time( 'timestamp' ) ) . '.csv';

		// Svuota eventuali buffer aperti: nulla deve precedere gli header.
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		$out = fopen( 'php://output', 'w' );

		// BOM UTF-8: senza, Excel interpreta il file come ANSI e rovina gli accenti.
		fwrite( $out, "\xEF\xBB\xBF" );

		// Separatore ';' — è quello atteso da Excel con impostazioni italiane.
		$sep = ';';

		fputcsv( $out, [ 'Documento', 'ID documento', 'Dealer', 'Email dealer', 'Data e ora', 'Indirizzo IP' ], $sep );

		$chunk  = 1000;
		$offset = 0;

		while ( $offset < $total ) {
			$rows = Dealer_DB::get_all_logs( array_merge( $filters['args'], [
				'limit'  => $chunk,
				'offset' => $offset,
			] ) );

			if ( empty( $rows ) ) {
				break;
			}

			foreach ( $rows as $row ) {
				$title = $row->post_title ? $row->post_title : 'Documento eliminato (ID ' . (int) $row->post_id . ')';
				$name  = $row->display_name ? $row->display_name : 'Utente #' . (int) $row->user_id;

				fputcsv( $out, [
					self::csv_cell( $title ),
					(int) $row->post_id,
					self::csv_cell( $name ),
					self::csv_cell( (string) $row->user_email ),
					self::csv_cell( gmdate( 'd/m/Y H:i:s', strtotime( (string) $row->download_date ) ) ),
					self::csv_cell( (string) $row->ip_address ),
				], $sep );
			}

			$offset += $chunk;

			// Svuota il buffer di rete: export lunghi non gonfiano la memoria.
			if ( ob_get_level() > 0 ) {
				ob_flush();
			}
			flush();
		}

		fclose( $out );
		exit;
	}

	/**
	 * Neutralizza la CSV injection.
	 *
	 * Un valore che inizia con '=', '+', '-' o '@' viene eseguito come formula
	 * da Excel/LibreOffice all'apertura del file: il prefisso con apostrofo lo
	 * forza a testo. Vale per ogni campo di testo, anche quelli "innocui":
	 * titoli, nomi utente ed email sono controllati (anche) dall'esterno.
	 */
	private static function csv_cell( string $value ): string {
		// Rimuove i caratteri di controllo che spezzerebbero la cella.
		$value = str_replace( [ "\r", "\n", "\t" ], ' ', $value );
		$value = trim( $value );

		if ( '' === $value ) {
			return '';
		}

		if ( in_array( $value[0], [ '=', '+', '-', '@' ], true ) ) {
			return "'" . $value;
		}

		return $value;
	}

	// ─── AJAX: operazioni di gruppo sull'archivio ────────────────────────────

	/**
	 * Applica un'azione bulk a una selezione di documenti.
	 *
	 * La conferma è già stata data una volta sola lato client per l'intera
	 * selezione: qui non c'è il flusso interattivo a due fasi di
	 * ajax_mark_obsolete(), che non avrebbe senso documento per documento.
	 */
	public function ajax_bulk_action(): void {
		check_ajax_referer( 'dealer_bulk_nonce', 'nonce' );

		if ( ! current_user_can( DEALER_PORTAL_CAP ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
		}

		$bulk_action = sanitize_key( $_POST['bulk_action'] ?? '' );
		if ( ! in_array( $bulk_action, [ 'obsolete', 'delete' ], true ) ) {
			wp_send_json_error( [ 'message' => 'Azione di gruppo non valida.' ] );
		}

		// Ogni ID viene validato singolarmente: quelli non validi vengono
		// ignorati e conteggiati, non fanno fallire l'intera operazione.
		$raw_ids = isset( $_POST['post_ids'] ) ? (array) wp_unslash( $_POST['post_ids'] ) : [];
		$ids     = [];
		$invalid = 0;

		foreach ( $raw_ids as $raw ) {
			$id = absint( $raw );
			if ( ! $id || get_post_type( $id ) !== 'documento_dealer' ) {
				$invalid++;
				continue;
			}
			if ( ! in_array( $id, $ids, true ) ) {
				$ids[] = $id;
			}
		}

		if ( empty( $ids ) ) {
			wp_send_json_error( [
				'message' => $invalid
					? 'Nessun documento valido nella selezione.'
					: 'Nessun documento selezionato.',
			] );
		}

		$summary = ( 'delete' === $bulk_action )
			? $this->bulk_delete( $ids )
			: $this->bulk_mark_obsolete( $ids );

		$summary['invalid'] = $invalid;
		$summary['op']      = $bulk_action;

		wp_send_json_success( $summary );
	}

	/**
	 * Segna obsoleti più documenti rispettando la catena di versioni.
	 *
	 * @param int[] $ids
	 * @return array
	 */
	private function bulk_mark_obsolete( array $ids ): array {
		$processed = 0;
		$already   = 0;
		$no_succ   = 0;
		$promoted  = 0;
		$reindex   = [];

		foreach ( $ids as $id ) {
			if ( get_post_meta( $id, '_doc_status', true ) === 'obsoleto' ) {
				$already++;
				continue;
			}

			if ( Dealer_Versioning::is_current( $id ) ) {
				$successor = self::preview_successor( $id );

				// demote() promuove sempre la versione con seq più alta: la si
				// declassa solo se quel candidato è davvero pubblicabile, cioè
				// non è a sua volta nella selezione né già obsoleto. Altrimenti
				// si finirebbe per rendere "corrente" un documento obsoleto.
				$viable = $successor
					&& ! in_array( $successor, $ids, true )
					&& get_post_meta( $successor, '_doc_status', true ) !== 'obsoleto';

				if ( $successor && $viable ) {
					$chain = Dealer_Versioning::get_chain( $id );
					if ( Dealer_Versioning::demote( $id ) ) {
						$promoted++;
						foreach ( $chain as $chain_post ) {
							if ( (int) $chain_post->ID !== $id ) {
								$reindex[ (int) $chain_post->ID ] = true;
							}
						}
					}
				} elseif ( $successor ) {
					// Catena senza successore promuovibile: il documento viene
					// comunque marcato obsoleto, ma nessuno prende il suo posto.
					$no_succ++;
				}
			}

			update_post_meta( $id, '_doc_status', 'obsoleto' );
			wp_update_post( [ 'ID' => $id, 'post_status' => 'draft' ] );
			$reindex[ $id ] = true;
			$processed++;
		}

		foreach ( array_keys( $reindex ) as $reindex_id ) {
			do_action( 'searchwp\index\post', $reindex_id );
		}

		return [
			'processed' => $processed,
			'already'   => $already,
			'no_succ'   => $no_succ,
			'promoted'  => $promoted,
			'failed'    => 0,
		];
	}

	/**
	 * Elimina definitivamente più documenti (post + file) mantenendo coerenti
	 * le catene di versioni.
	 *
	 * @param int[] $ids
	 * @return array
	 */
	private function bulk_delete( array $ids ): array {
		$processed = 0;
		$failed    = 0;
		$promoted  = 0;
		$reindex   = [];

		foreach ( $ids as $id ) {
			$chain    = Dealer_Versioning::get_chain( $id );
			$in_chain = count( $chain ) > 1;
			$promotes = $in_chain && Dealer_Versioning::is_current( $id );

			// Sempre PRIMA di wp_delete_post(): sgancia dalla catena e, se era
			// la versione corrente, promuove la più recente fra le rimaste.
			Dealer_Versioning::detach( $id );

			$attachment_id = (int) get_post_meta( $id, '_doc_file_id', true );
			if ( $attachment_id ) {
				wp_delete_attachment( $attachment_id, true );
			}

			if ( ! wp_delete_post( $id, true ) ) {
				$failed++;
				continue;
			}

			$processed++;
			if ( $promotes ) {
				$promoted++;
			}
			if ( $in_chain ) {
				foreach ( $chain as $chain_post ) {
					if ( (int) $chain_post->ID !== $id ) {
						$reindex[ (int) $chain_post->ID ] = true;
					}
				}
			}
		}

		// I documenti eliminati non vanno reindicizzati.
		foreach ( array_keys( $reindex ) as $reindex_id ) {
			if ( in_array( (int) $reindex_id, $ids, true ) ) {
				continue;
			}
			do_action( 'searchwp\index\post', (int) $reindex_id );
		}

		return [
			'processed' => $processed,
			'already'   => 0,
			'no_succ'   => 0,
			'promoted'  => $promoted,
			'failed'    => $failed,
		];
	}

	/**
	 * Anticipa quale versione verrebbe promossa da Dealer_Versioning::demote():
	 * la sequenza più alta della catena, escluso il documento stesso.
	 * Replica la stessa logica di selezione, in sola lettura.
	 */
	private static function preview_successor( int $post_id ): int {
		$best_id  = 0;
		$best_seq = 0;

		foreach ( Dealer_Versioning::get_chain( $post_id ) as $chain_post ) {
			$id = (int) $chain_post->ID;
			if ( $id === $post_id ) {
				continue;
			}
			$seq = Dealer_Versioning::get_sequence( $id );
			if ( $seq >= $best_seq ) {
				$best_seq = $seq;
				$best_id  = $id;
			}
		}

		return $best_id;
	}

	// ─── AJAX: linee prodotto per brand ──────────────────────────────────────

	public function ajax_get_lines(): void {
		check_ajax_referer( 'dealer_upload_nonce', 'nonce' );

		if ( ! current_user_can( DEALER_PORTAL_CAP ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
		}

		$brand = sanitize_text_field( wp_unslash( $_POST['brand'] ?? '' ) );
		$lines = self::PRODUCT_LINES[ $brand ] ?? [];

		wp_send_json_success( [ 'lines' => array_values( $lines ) ] );
	}

	// ─── AJAX: salva documento ────────────────────────────────────────────────

	public function ajax_save_document(): void {
		check_ajax_referer( 'dealer_upload_nonce', 'nonce' );

		if ( ! current_user_can( DEALER_PORTAL_CAP ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
		}

		// Verifica file caricato.
		if ( empty( $_FILES['doc_file'] ) || (int) $_FILES['doc_file']['error'] !== UPLOAD_ERR_OK ) {
			wp_send_json_error( [ 'message' => 'Nessun file valido caricato. Verifica che il file sia stato selezionato.' ] );
		}

		// Verifica che sia un upload HTTP genuino (non un path locale arbitrario).
		if ( ! is_uploaded_file( $_FILES['doc_file']['tmp_name'] ) ) {
			wp_send_json_error( [ 'message' => 'File non valido.' ] );
		}

		// Limite dimensione server-side (50 MB).
		if ( $_FILES['doc_file']['size'] > 50 * MB_IN_BYTES ) {
			wp_send_json_error( [ 'message' => 'File troppo grande (max 50 MB).' ] );
		}

		// Whitelist MIME esplicita: solo PDF, XLSX, DOCX.
		$allowed_mimes = [
			'pdf'  => 'application/pdf',
			'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
			'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		];

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		// Valida estensione e contenuto reale prima dell'upload.
		$check = wp_check_filetype_and_ext(
			$_FILES['doc_file']['tmp_name'],
			$_FILES['doc_file']['name'],
			$allowed_mimes
		);
		if ( empty( $check['type'] ) || ! array_search( $check['type'], $allowed_mimes, true ) ) {
			wp_send_json_error( [ 'message' => 'Tipo di file non consentito. Sono accettati solo PDF, XLSX e DOCX.' ] );
		}

		$keep_original = ! empty( $_POST['keep_original_name'] );

		// Rename del file se richiesto.
		if ( ! $keep_original && ! empty( $_POST['doc_custom_name'] ) ) {
			$custom_name = sanitize_text_field( wp_unslash( $_POST['doc_custom_name'] ) );
			$ext         = strtolower( pathinfo( $_FILES['doc_file']['name'], PATHINFO_EXTENSION ) );
			// Solo caratteri sicuri nel nome.
			$clean_name = preg_replace( '/[^a-zA-Z0-9._\-]/', '_', $custom_name );
			$clean_name = trim( $clean_name, '_' );
			if ( $clean_name ) {
				$_FILES['doc_file']['name'] = $clean_name . '.' . $ext;
			}
		}

		// Redirige l'upload nella sottocartella protetta dealer-docs/.
		$upload_dir_filter = static function ( array $dirs ): array {
			$dirs['subdir'] = '/dealer-docs';
			$dirs['path']   = $dirs['basedir'] . '/dealer-docs';
			$dirs['url']    = $dirs['baseurl'] . '/dealer-docs';
			return $dirs;
		};
		add_filter( 'upload_dir', $upload_dir_filter );

		$uploaded = wp_handle_upload( $_FILES['doc_file'], [
			'test_form' => false,
			'mimes'     => $allowed_mimes,
		] );

		remove_filter( 'upload_dir', $upload_dir_filter );

		if ( isset( $uploaded['error'] ) ) {
			wp_send_json_error( [ 'message' => $uploaded['error'] ] );
		}

		// Sanitizza e valida tutti i campi meta.
		$brand    = sanitize_text_field( wp_unslash( $_POST['doc_brand']        ?? '' ) );
		$line     = sanitize_text_field( wp_unslash( $_POST['doc_product_line'] ?? '' ) );
		$type     = sanitize_key( $_POST['doc_type']    ?? 'altro' );
		$year     = absint( $_POST['doc_year']     ?? gmdate( 'Y' ) );
		$version  = sanitize_text_field( wp_unslash( $_POST['doc_version']      ?? '' ) );
		$keywords = sanitize_textarea_field( wp_unslash( $_POST['doc_keywords']       ?? '' ) );
		$notes    = sanitize_textarea_field( wp_unslash( $_POST['doc_internal_notes'] ?? '' ) );
		$expiry   = sanitize_text_field( wp_unslash( $_POST['doc_expiry'] ?? '' ) );

		// Valida type contro lista consentita.
		if ( ! array_key_exists( $type, self::DOC_TYPES ) ) {
			$type = 'altro';
		}

		// Valida anno in range ragionevole.
		$current_year = (int) gmdate( 'Y' );
		if ( $year < 1990 || $year > $current_year + 1 ) {
			$year = $current_year;
		}

		// Valida ruoli contro lista consentita.
		$raw_roles     = isset( $_POST['doc_roles'] ) ? (array) $_POST['doc_roles'] : [];
		$allowed_roles = [ 'dealer', 'top_dealer', 'part_center' ];
		$roles         = array_values( array_intersect( array_map( 'sanitize_key', $raw_roles ), $allowed_roles ) );

		// Valida linee contro la whitelist delle combinazioni Brand|Linea ammesse.
		$raw_lines = isset( $_POST['doc_lines'] ) ? (array) wp_unslash( $_POST['doc_lines'] ) : [];
		$lines     = array_values( array_intersect(
			array_map( 'sanitize_text_field', $raw_lines ),
			self::get_valid_lines()
		) );

		// Valida formato e valore della data di scadenza.
		if ( $expiry ) {
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $expiry ) ) {
				$expiry = '';
			} else {
				$dt = DateTime::createFromFormat( 'Y-m-d', $expiry );
				if ( ! $dt || $dt->format( 'Y-m-d' ) !== $expiry ) {
					$expiry = '';
				}
			}
		}

		// Crea il post documento_dealer.
		$post_title = $brand;
		if ( $line ) { $post_title .= ' – ' . $line; }
		if ( isset( self::DOC_TYPES[ $type ] ) ) { $post_title .= ' – ' . self::DOC_TYPES[ $type ]; }
		if ( $year ) { $post_title .= ' ' . $year; }

		$post_id = wp_insert_post( [
			'post_title'  => $post_title,
			'post_type'   => 'documento_dealer',
			'post_status' => 'publish',
			'post_author' => get_current_user_id(),
		], true );

		if ( is_wp_error( $post_id ) ) {
			// Rimuovi il file caricato se il post non viene creato.
			wp_delete_file( $uploaded['file'] );
			wp_send_json_error( [ 'message' => $post_id->get_error_message() ] );
		}

		// Inserisce il file nella Media Library collegato al post.
		$wp_filetype   = wp_check_filetype( basename( $uploaded['file'] ) );
		$attachment_id = wp_insert_attachment( [
			'guid'           => $uploaded['url'],
			'post_mime_type' => $wp_filetype['type'],
			'post_title'     => preg_replace( '/\.[^.]+$/', '', basename( $uploaded['file'] ) ),
			'post_content'   => '',
			'post_status'    => 'inherit',
		], $uploaded['file'], $post_id );

		if ( ! is_wp_error( $attachment_id ) ) {
			wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $uploaded['file'] ) );
		} else {
			$attachment_id = 0;
		}

		// Salva tutti i meta.
		$meta = [
			'_doc_brand'              => $brand,
			'_doc_product_line'       => $line,
			'_doc_type'               => $type,
			'_doc_year'               => $year,
			'_doc_version'            => $version,
			'_doc_roles'              => $roles,
			'_doc_lines'              => $lines,
			'_doc_expiry'             => $expiry,
			'_doc_keywords'           => $keywords,
			'_doc_internal_notes'     => $notes,
			'_doc_file_id'            => $attachment_id,
			'_doc_keep_original_name' => $keep_original ? 1 : 0,
			'_doc_status'             => 'active',
			'_doc_filename'           => basename( $uploaded['file'] ),
		];
		foreach ( $meta as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}

		// ─── Versionamento ───────────────────────────────────────────────────
		// Va eseguito DOPO il salvataggio di tutti gli altri meta: l'action
		// 'dealer_portal_new_version' deve vedere un documento completo.
		$previous_id = absint( $_POST['doc_previous_version'] ?? 0 );

		// Non fidarsi del client: il predecessore deve essere un documento reale.
		if ( $previous_id && get_post_type( $previous_id ) !== 'documento_dealer' ) {
			$previous_id = 0;
		}

		$attached = false;
		if ( $previous_id && $previous_id !== (int) $post_id ) {
			$attached = Dealer_Versioning::attach_as_new_version( (int) $post_id, $previous_id );
		}

		if ( ! $attached ) {
			// Fallback: il documento non resta mai senza meta di versione.
			$previous_id = 0;
			Dealer_Versioning::init_root( (int) $post_id );
		}

		// Trigger reindex SearchWP (no-op se SearchWP non è attivo).
		do_action( 'searchwp\index\post', $post_id );
		if ( $attached ) {
			// La versione precedente è uscita dalla ricerca: va reindicizzata.
			do_action( 'searchwp\index\post', $previous_id );
		}

		wp_send_json_success( [
			'message'     => $attached
				? 'Documento salvato come nuova versione.'
				: 'Documento salvato con successo.',
			'post_id'     => $post_id,
			'version_seq' => Dealer_Versioning::get_sequence( (int) $post_id ),
			'supersedes'  => $previous_id,
		] );
	}

	// ─── AJAX: segna obsoleto ─────────────────────────────────────────────────

	public function ajax_mark_obsolete(): void {
		check_ajax_referer( 'dealer_archive_nonce', 'nonce' );

		if ( ! current_user_can( DEALER_PORTAL_CAP ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
		}

		$post_id = absint( $_POST['post_id'] ?? 0 );
		if ( ! $post_id || get_post_type( $post_id ) !== 'documento_dealer' ) {
			wp_send_json_error( [ 'message' => 'Post non valido.' ] );
		}

		// Marcare obsoleta la versione corrente di una catena con storico
		// lascerebbe l'intera catena senza nessun documento visibile in ricerca.
		// L'admin deve confermare esplicitamente: alla conferma la versione
		// precedente torna corrente e il documento resta nello storico.
		$previous_count = Dealer_Versioning::count_previous_versions( $post_id );
		$is_current     = Dealer_Versioning::is_current( $post_id );
		$breaks_chain   = ( $is_current && $previous_count > 0 );
		$confirmed      = ! empty( $_POST['confirm_chain'] );

		if ( $breaks_chain && ! $confirmed ) {
			wp_send_json_success( [
				'needs_confirm' => true,
				'message'       => sprintf(
					'Questo documento è la versione corrente di una catena con %d version%s precedent%s. '
					. 'Se lo segni obsoleto la versione precedente tornerà corrente e visibile ai dealer; '
					. 'questo documento resterà nello storico della catena. Procedere?',
					$previous_count,
					$previous_count === 1 ? 'e' : 'i',
					$previous_count === 1 ? 'e' : 'i'
				),
			] );
		}

		if ( $breaks_chain ) {
			// demote() (non detach()): promuove la precedente ma tiene il
			// documento obsoleto nella catena, così lo storico resta leggibile.
			$chain = Dealer_Versioning::get_chain( $post_id );
			Dealer_Versioning::demote( $post_id );
			foreach ( $chain as $chain_post ) {
				if ( (int) $chain_post->ID !== $post_id ) {
					do_action( 'searchwp\index\post', (int) $chain_post->ID );
				}
			}
		}

		update_post_meta( $post_id, '_doc_status', 'obsoleto' );
		wp_update_post( [ 'ID' => $post_id, 'post_status' => 'draft' ] );

		wp_send_json_success( [
			'message' => $breaks_chain
				? 'Documento segnato come obsoleto. La versione precedente è tornata corrente.'
				: 'Documento segnato come obsoleto.',
			'reload'  => $breaks_chain,
		] );
	}

	// ─── AJAX: elimina documento ──────────────────────────────────────────────

	public function ajax_delete_document(): void {
		check_ajax_referer( 'dealer_archive_nonce', 'nonce' );

		if ( ! current_user_can( DEALER_PORTAL_CAP ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
		}

		$post_id = absint( $_POST['post_id'] ?? 0 );
		if ( ! $post_id || get_post_type( $post_id ) !== 'documento_dealer' ) {
			wp_send_json_error( [ 'message' => 'Post non valido.' ] );
		}

		// Sgancia dalla catena PRIMA di eliminare: se era la versione corrente,
		// promuove la precedente. Altrimenti la catena resterebbe senza nessun
		// documento visibile in ricerca.
		$chain    = Dealer_Versioning::get_chain( $post_id );
		$in_chain = count( $chain ) > 1;
		$promotes = $in_chain && Dealer_Versioning::is_current( $post_id );
		Dealer_Versioning::detach( $post_id );

		$attachment_id = (int) get_post_meta( $post_id, '_doc_file_id', true );
		if ( $attachment_id ) {
			wp_delete_attachment( $attachment_id, true ); // true = force delete (non va nel cestino)
		}

		wp_delete_post( $post_id, true );

		// Reindicizza i superstiti: la promozione ne cambia la visibilità.
		if ( $in_chain ) {
			foreach ( $chain as $chain_post ) {
				if ( (int) $chain_post->ID !== $post_id ) {
					do_action( 'searchwp\index\post', (int) $chain_post->ID );
				}
			}
		}

		wp_send_json_success( [
			'message' => $promotes
				? 'Documento eliminato. La versione precedente è tornata corrente.'
				: 'Documento eliminato.',
			'reload'  => $in_chain,
		] );
	}

	// ─── User profile: linee prodotto + referente ────────────────────────────

	public function render_user_fields( \WP_User $user ): void {
		if ( ! current_user_can( 'edit_users' ) ) {
			return;
		}

		$dealer_lines = get_user_meta( $user->ID, '_dealer_lines', true );
		if ( ! is_array( $dealer_lines ) ) { $dealer_lines = []; }

		$ref_nome     = get_user_meta( $user->ID, '_referente_nome',     true );
		$ref_email    = get_user_meta( $user->ID, '_referente_email',    true );
		$ref_telefono = get_user_meta( $user->ID, '_referente_telefono', true );

		// Costruisce l'elenco completo "Brand|Linea" da assegnare al dealer.
		$all_lines = [];
		foreach ( self::PRODUCT_LINES as $brand => $lines ) {
			foreach ( $lines as $line ) {
				$all_lines[] = $brand . '|' . $line;
			}
		}
		?>
		<h2>Impostazioni Dealer Portal</h2>
		<?php wp_nonce_field( 'dealer_user_meta_nonce', 'dealer_user_meta_nonce_field' ); ?>
		<table class="form-table">
			<tr>
				<th><label for="dealer_lines">Linee Prodotto Assegnate</label></th>
				<td>
					<select name="dealer_lines[]" id="dealer_lines" multiple style="min-width:320px;min-height:130px;">
						<?php foreach ( $all_lines as $key ) :
							$label = str_replace( '|', ' › ', $key );
							?>
							<option value="<?php echo esc_attr( $key ); ?>"<?php echo in_array( $key, $dealer_lines, true ) ? ' selected' : ''; ?>>
								<?php echo esc_html( $label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<p class="description">Tieni premuto <kbd>Ctrl</kbd> (Windows) o <kbd>Cmd</kbd> (Mac) per selezionare più linee.</p>
				</td>
			</tr>
			<tr>
				<th><label for="referente_nome">Referente – Nome</label></th>
				<td><input type="text" id="referente_nome" name="referente_nome" value="<?php echo esc_attr( $ref_nome ); ?>" class="regular-text"></td>
			</tr>
			<tr>
				<th><label for="referente_email">Referente – Email</label></th>
				<td><input type="email" id="referente_email" name="referente_email" value="<?php echo esc_attr( $ref_email ); ?>" class="regular-text"></td>
			</tr>
			<tr>
				<th><label for="referente_telefono">Referente – Telefono</label></th>
				<td><input type="tel" id="referente_telefono" name="referente_telefono" value="<?php echo esc_attr( $ref_telefono ); ?>" class="regular-text"></td>
			</tr>
		</table>
		<?php
	}

	public function save_user_fields( int $user_id ): void {
		// Verifica nonce.
		if ( ! isset( $_POST['dealer_user_meta_nonce_field'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['dealer_user_meta_nonce_field'] ) ), 'dealer_user_meta_nonce' ) ) {
			return;
		}
		// Verifica capacità.
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}

		$lines = isset( $_POST['dealer_lines'] )
			? array_values( array_intersect(
				array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['dealer_lines'] ) ),
				self::get_valid_lines()
			) )
			: [];

		update_user_meta( $user_id, '_dealer_lines',      $lines );
		update_user_meta( $user_id, '_referente_nome',     sanitize_text_field( wp_unslash( $_POST['referente_nome']     ?? '' ) ) );
		update_user_meta( $user_id, '_referente_email',    sanitize_email(      wp_unslash( $_POST['referente_email']    ?? '' ) ) );
		update_user_meta( $user_id, '_referente_telefono', sanitize_text_field( wp_unslash( $_POST['referente_telefono'] ?? '' ) ) );
	}

	// ─── Static helpers (usati dai template) ─────────────────────────────────

	public static function get_brands(): array {
		return array_keys( self::PRODUCT_LINES );
	}

	public static function get_product_lines(): array {
		return self::PRODUCT_LINES;
	}

	public static function get_doc_types(): array {
		return self::DOC_TYPES;
	}

	public static function get_valid_lines(): array {
		$valid = [];
		foreach ( self::PRODUCT_LINES as $brand => $brand_lines ) {
			foreach ( $brand_lines as $line ) {
				$valid[] = $brand . '|' . $line;
			}
		}
		return $valid;
	}
}
