<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Dealer_Search {

	private static array $dealer_roles = [ 'dealer', 'top_dealer', 'part_center' ];

	// ─── Constructor ─────────────────────────────────────────────────────────

	public function __construct() {
		add_shortcode( 'dealer_search', [ $this, 'render' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'wp_ajax_dealer_log_download', [ $this, 'ajax_log_download' ] );
		// Utenti non loggati ricevono 403 senza informazioni sull'esistenza del documento.
		add_action( 'wp_ajax_nopriv_dealer_log_download', static function () {
			wp_send_json_error( [ 'message' => 'Authentication required.' ], 403 );
		} );
		add_filter( 'query_vars', [ $this, 'add_query_vars' ] );
		add_action( 'template_redirect', [ $this, 'handle_download' ] );
	}

	// ─── Assets ──────────────────────────────────────────────────────────────

	public function enqueue_assets(): void {
		global $post;
		if ( ! is_a( $post, 'WP_Post' ) || ! has_shortcode( $post->post_content, 'dealer_search' ) ) {
			return;
		}
		wp_enqueue_style( 'dealer-portal-dealer', DEALER_PORTAL_URL . 'assets/css/dealer.css', [], DEALER_PORTAL_VERSION );
		wp_enqueue_script(
			'dealer-portal-search',
			DEALER_PORTAL_URL . 'assets/js/dealer-search.js',
			[ 'jquery' ],
			DEALER_PORTAL_VERSION,
			true
		);
		wp_localize_script( 'dealer-portal-search', 'dealerSearch', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'dealer_search_nonce' ),
		] );
	}

	// ─── Shortcode render ────────────────────────────────────────────────────

	public function render( $atts ): string {
		if ( ! is_user_logged_in() ) {
			return '<p class="dealer-notice"><a href="' . esc_url( wp_login_url( get_permalink() ) ) . '">Accedi</a> per cercare i documenti.</p>';
		}
		$user = wp_get_current_user();
		if ( ! self::user_is_dealer( $user ) ) {
			return '<p class="dealer-notice">Accesso non autorizzato.</p>';
		}

		$search_term  = sanitize_text_field( wp_unslash( $_GET['s']            ?? '' ) );
		$filter_brand = sanitize_text_field( wp_unslash( $_GET['filter_brand'] ?? '' ) );
		$filter_type  = sanitize_key( $_GET['filter_type']  ?? '' );
		$filter_year  = absint( $_GET['filter_year'] ?? 0 );
		$filter_line  = sanitize_text_field( wp_unslash( $_GET['filter_line']  ?? '' ) );

		$user_lines = get_user_meta( $user->ID, '_dealer_lines', true );
		if ( ! is_array( $user_lines ) ) { $user_lines = []; }

		// Esegue la ricerca solo se c'è almeno un criterio.
		$results = [];
		$did_search = ( $search_term || $filter_brand || $filter_type || $filter_year || $filter_line );
		if ( $did_search ) {
			$results = $this->query_documents( $user, $user_lines, $search_term, $filter_brand, $filter_type, $filter_year, $filter_line );
		}

		// Calcola le linee disponibili per il dealer (per popolare il filtro linee).
		$available_lines = $user_lines;

		ob_start();
		require DEALER_PORTAL_PATH . 'templates/dealer-search.php';
		return ob_get_clean();
	}

	// ─── Query documenti ─────────────────────────────────────────────────────

	private function query_documents(
		\WP_User $user,
		array $user_lines,
		string $search_term,
		string $filter_brand,
		string $filter_type,
		int $filter_year,
		string $filter_line
	): array {
		$meta_query = [
			'relation' => 'AND',
			// Solo documenti attivi.
			[
				'relation' => 'OR',
				[ 'key' => '_doc_status', 'value' => 'obsoleto', 'compare' => '!=' ],
				[ 'key' => '_doc_status', 'compare' => 'NOT EXISTS' ],
			],
		];

		if ( $filter_brand ) {
			$meta_query[] = [ 'key' => '_doc_brand', 'value' => $filter_brand, 'compare' => '=' ];
		}
		if ( $filter_type ) {
			$meta_query[] = [ 'key' => '_doc_type', 'value' => $filter_type, 'compare' => '=' ];
		}
		if ( $filter_year ) {
			$meta_query[] = [ 'key' => '_doc_year', 'value' => $filter_year, 'compare' => '=', 'type' => 'NUMERIC' ];
		}

		$query_args = [
			'post_type'      => 'documento_dealer',
			'post_status'    => 'publish',
			'posts_per_page' => 60,
			'meta_query'     => $meta_query,
		];

		// Usa SearchWP per ricerca full-text se disponibile.
		if ( $search_term && class_exists( '\SWP_Query' ) ) {
			$swp = new \SWP_Query( [
				'engine'   => 'default',
				'keywords' => $search_term,
				'fields'   => 'ids',
			] );
			$ids = array_filter( array_map( static function ( $p ) {
				return is_object( $p ) ? (int) $p->ID : (int) $p;
			}, $swp->posts ?? [] ) );

			if ( empty( $ids ) ) {
				return []; // SearchWP non ha trovato nulla.
			}
			$query_args['post__in'] = $ids;
			$query_args['orderby']  = 'post__in';
		} elseif ( $search_term ) {
			$query_args['s'] = $search_term;
		}

		$posts = ( new WP_Query( $query_args ) )->posts;

		// Filtro linea aggiuntivo.
		if ( $filter_line ) {
			$posts = array_values( array_filter( $posts, static function ( $post ) use ( $filter_line ) {
				$lines = get_post_meta( $post->ID, '_doc_lines', true );
				return ! is_array( $lines ) || empty( $lines ) || in_array( $filter_line, $lines, true );
			} ) );
		}

		// Controllo accesso doppio: ruolo E linea assegnata al dealer.
		$posts = array_values( array_filter( $posts, static function ( $post ) use ( $user, $user_lines ) {
			return self::user_can_access_post( $user, $user_lines, $post->ID );
		} ) );

		return $posts;
	}

	// ─── Controllo accesso (statico, riusato da Dashboard) ───────────────────

	public static function user_is_dealer( \WP_User $user ): bool {
		foreach ( self::$dealer_roles as $role ) {
			if ( in_array( $role, (array) $user->roles, true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Regola di accesso doppio:
	 * 1. Il ruolo dell'utente deve essere nella lista _doc_roles del documento.
	 * 2. Se il documento ha _doc_lines impostato, almeno una delle linee dell'utente
	 *    deve corrispondere (formato "Brand|Linea" in entrambi i lati).
	 */
	public static function user_can_access_post( \WP_User $user, array $user_lines, int $post_id ): bool {
		$doc_roles = get_post_meta( $post_id, '_doc_roles', true );
		if ( ! is_array( $doc_roles ) || empty( $doc_roles ) ) {
			return false; // Nessun ruolo impostato = nessuno può vederlo.
		}

		$user_roles = (array) $user->roles;
		if ( empty( array_intersect( $user_roles, $doc_roles ) ) ) {
			return false; // Ruolo non autorizzato.
		}

		$doc_lines = get_post_meta( $post_id, '_doc_lines', true );
		if ( ! is_array( $doc_lines ) || empty( $doc_lines ) ) {
			return true; // Nessuna restrizione per linea: accesso garantito dal solo ruolo.
		}

		return ! empty( array_intersect( $user_lines, $doc_lines ) );
	}

	// ─── Download file ───────────────────────────────────────────────────────

	public function add_query_vars( array $vars ): array {
		$vars[] = 'dealer_download';
		return $vars;
	}

	public function handle_download(): void {
		$post_id = (int) get_query_var( 'dealer_download' );
		if ( ! $post_id ) {
			return;
		}

		// Autenticazione.
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url() );
			exit;
		}

		$user = wp_get_current_user();

		// Verifica nonce (scade in 12h, impedisce link diretti permanenti).
		$nonce = sanitize_text_field( wp_unslash( $_GET['nonce'] ?? '' ) );
		if ( ! wp_verify_nonce( $nonce, 'dealer_download_' . $post_id ) ) {
			wp_die( esc_html__( 'Link di download scaduto o non valido. Torna alla ricerca e riprova.', 'dealer-portal' ), '', [ 'response' => 403 ] );
		}

		// Verifica tipo post.
		if ( get_post_type( $post_id ) !== 'documento_dealer' ) {
			wp_die( esc_html__( 'Documento non trovato.', 'dealer-portal' ), '', [ 'response' => 404 ] );
		}

		// Controllo accesso doppio.
		$user_lines = get_user_meta( $user->ID, '_dealer_lines', true );
		if ( ! is_array( $user_lines ) ) { $user_lines = []; }

		if ( ! self::user_is_dealer( $user ) || ! self::user_can_access_post( $user, $user_lines, $post_id ) ) {
			wp_die( esc_html__( 'Non hai i permessi per scaricare questo documento.', 'dealer-portal' ), '', [ 'response' => 403 ] );
		}

		// Recupera il file.
		$attachment_id = (int) get_post_meta( $post_id, '_doc_file_id', true );
		if ( ! $attachment_id ) {
			wp_die( esc_html__( 'File non trovato.', 'dealer-portal' ), '', [ 'response' => 404 ] );
		}

		$file_path = get_attached_file( $attachment_id );
		if ( ! $file_path || ! file_exists( $file_path ) ) {
			wp_die( esc_html__( 'File non trovato sul server.', 'dealer-portal' ), '', [ 'response' => 404 ] );
		}

		// ── Path traversal prevention ──────────────────────────────────────────
		// Verifica che il file si trovi nella cartella uploads di WP.
		$uploads   = wp_upload_dir();
		$real_file = realpath( $file_path );
		$real_base = realpath( $uploads['basedir'] );

		if ( ! $real_file || ! $real_base || strpos( $real_file, $real_base . DIRECTORY_SEPARATOR ) !== 0 ) {
			wp_die( esc_html__( 'Accesso al file non consentito.', 'dealer-portal' ), '', [ 'response' => 403 ] );
		}

		// Registra il download nel log.
		Dealer_DB::log_download( $post_id );

		// Serve il file con header di sicurezza.
		$filename  = get_post_meta( $post_id, '_doc_filename', true ) ?: basename( $real_file );
		$mime_type = get_post_mime_type( $attachment_id ) ?: 'application/octet-stream';
		$file_size = filesize( $real_file );

		// Svuota tutti i buffer di output prima di inviare il file.
		while ( ob_get_level() ) {
			ob_end_clean();
		}

		header( 'Content-Type: '        . $mime_type );
		header( 'Content-Disposition: attachment; filename="' . rawurlencode( $filename ) . '"' );
		header( 'Content-Length: '      . $file_size );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		readfile( $real_file );
		exit;
	}

	// ─── AJAX: log download da JS ─────────────────────────────────────────────

	public function ajax_log_download(): void {
		check_ajax_referer( 'dealer_search_nonce', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( [ 'message' => 'Authentication required.' ], 403 );
		}

		$post_id = absint( $_POST['post_id'] ?? 0 );
		if ( $post_id && get_post_type( $post_id ) === 'documento_dealer' ) {
			// Verifica accesso prima di loggare.
			$user       = wp_get_current_user();
			$user_lines = get_user_meta( $user->ID, '_dealer_lines', true );
			if ( ! is_array( $user_lines ) ) { $user_lines = []; }
			if ( self::user_can_access_post( $user, $user_lines, $post_id ) ) {
				Dealer_DB::log_download( $post_id );
			}
		}

		wp_send_json_success();
	}

	// ─── Helpers statici (usati dai template) ────────────────────────────────

	public static function get_download_url( int $post_id ): string {
		return add_query_arg( [
			'dealer_download' => $post_id,
			'nonce'           => wp_create_nonce( 'dealer_download_' . $post_id ),
		], home_url( '/' ) );
	}

	public static function get_file_icon_svg( string $filename ): string {
		$ext = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
		switch ( $ext ) {
			case 'pdf':
				return '<svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48" width="44" height="44"><rect width="48" height="48" rx="8" fill="#e53935"/><text x="24" y="30" text-anchor="middle" fill="#fff" font-weight="700" font-size="13" font-family="sans-serif">PDF</text></svg>';
			case 'xlsx':
			case 'xls':
				return '<svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48" width="44" height="44"><rect width="48" height="48" rx="8" fill="#43a047"/><text x="24" y="30" text-anchor="middle" fill="#fff" font-weight="700" font-size="13" font-family="sans-serif">XLS</text></svg>';
			case 'docx':
			case 'doc':
				return '<svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48" width="44" height="44"><rect width="48" height="48" rx="8" fill="#1565c0"/><text x="24" y="30" text-anchor="middle" fill="#fff" font-weight="700" font-size="13" font-family="sans-serif">DOC</text></svg>';
			default:
				return '<svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48" width="44" height="44"><rect width="48" height="48" rx="8" fill="#546e7a"/><text x="24" y="30" text-anchor="middle" fill="#fff" font-weight="700" font-size="12" font-family="sans-serif">FILE</text></svg>';
		}
	}

	public static function is_new_doc( int $post_id ): bool {
		$post = get_post( $post_id );
		if ( ! $post ) { return false; }
		return ( time() - strtotime( $post->post_date_gmt ) ) < ( 30 * DAY_IN_SECONDS );
	}

	public static function is_expiring_doc( int $post_id ): bool {
		$expiry = get_post_meta( $post_id, '_doc_expiry', true );
		if ( ! $expiry ) { return false; }
		$diff = strtotime( $expiry ) - time();
		return $diff > 0 && $diff < ( 30 * DAY_IN_SECONDS );
	}
}
