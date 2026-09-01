<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Dealer_Dashboard {

	/** Numero di voci mostrate nelle sezioni "I miei documenti". */
	const MY_DOCS_LIMIT = 8;

	public function __construct() {
		add_shortcode( 'dealer_dashboard', [ $this, 'render' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'wp_login', [ $this, 'track_login' ], 10, 2 );
	}

	// ─── Assets ──────────────────────────────────────────────────────────────

	/**
	 * Aggancio su wp_enqueue_scripts: caso normale, asset già nell'head.
	 *
	 * Non ci si può basare sul solo has_shortcode(): i page builder salvano il
	 * contenuto nei postmeta, non in post_content, e lì il controllo
	 * fallirebbe lasciando la dashboard senza stile e senza alcun errore.
	 */
	public function enqueue_assets(): void {
		global $post;

		$has_shortcode = is_a( $post, 'WP_Post' )
			&& has_shortcode( (string) $post->post_content, 'dealer_dashboard' );

		$is_dashboard_page = is_a( $post, 'WP_Post' )
			&& (int) $post->ID === (int) get_option( 'dealer_portal_dashboard_page_id' );

		if ( ! $has_shortcode && ! $is_dashboard_page ) {
			return;
		}

		self::enqueue_dashboard_assets();
	}

	/**
	 * Idempotente, richiamata anche dallo shortcode durante il rendering: è la
	 * rete di sicurezza che rende il caricamento indipendente da come la
	 * pagina è stata costruita.
	 */
	public static function enqueue_dashboard_assets(): void {
		wp_enqueue_style( 'dashicons' );
		wp_enqueue_style( 'dealer-portal-dealer', DEALER_PORTAL_URL . 'assets/css/dealer.css', [], DEALER_PORTAL_VERSION );
	}

	// ─── Track login ─────────────────────────────────────────────────────────

	public function track_login( string $user_login, \WP_User $user ): void {
		update_user_meta( $user->ID, '_dealer_last_login', current_time( 'mysql' ) );
	}

	// ─── Shortcode render ────────────────────────────────────────────────────

	public function render( $atts ): string {
		// Rete di sicurezza sugli asset: se lo shortcode gira, la pagina ha
		// bisogno del CSS, qualunque sia il sistema che l'ha costruita.
		self::enqueue_dashboard_assets();

		// Da qui in poi non si reindirizza mai: uno shortcode gira dentro
		// the_content, quando gli header sono già partiti e mezza pagina è già
		// stata stampata. Un wp_safe_redirect() qui non reindirizzerebbe nulla
		// e l'exit che lo accompagna troncherebbe la pagina a metà, footer e
		// link di logout compresi. Il redirect vero vive in
		// Dealer_Access_Guard::route_dashboard(), su template_redirect; questi
		// sono le reti di sicurezza per quando lo shortcode è stato messo su
		// una pagina che il plugin non riconosce come la propria.
		if ( ! is_user_logged_in() ) {
			return '<p class="dealer-notice"><a href="' . esc_url( wp_login_url( get_permalink() ) ) . '">Accedi</a> per entrare nella tua area riservata.</p>';
		}

		$user       = wp_get_current_user();
		$user_roles = (array) $user->roles;

		// Permetti anche agli admin di vedere la dashboard (per test).
		$dealer_roles = Dealer_Roles::dealer_slugs();
		$is_dealer    = ! empty( array_intersect( $user_roles, $dealer_roles ) );

		if ( ! $is_dealer && ! current_user_can( 'manage_options' ) ) {
			// Un area manager che finisce qui non deve sbattere contro un
			// vicolo cieco: questa non è la sua area, ma sappiamo qual è.
			// Normalmente route_dashboard() lo ha già portato lì prima che la
			// pagina cominciasse; questo è il caso in cui lo shortcode sta su
			// una pagina che non è quella registrata come Dashboard.
			if ( Dealer_Identity::is_area_manager( $user ) ) {
				return '<p class="dealer-notice">Questa è l’area dei dealer. '
					. '<a href="' . esc_url( Dealer_DB::area_manager_url() ) . '">Vai alla tua area di lavoro</a>.</p>';
			}
			return '<p class="dealer-notice">Accesso non autorizzato.</p>';
		}

		// Organizzazione sospesa: stesso messaggio e stessa regola della pagina
		// di ricerca. Senza questo controllo la dashboard proverebbe comunque a
		// costruire le sue liste, e get_accessible_favorites() — non trovando
		// più accessibile nessun preferito — li cancellerebbe tutti dal user
		// meta. Una sospensione temporanea distruggerebbe la libreria personale
		// del dealer in modo irreversibile.
		if ( ! Dealer_Identity::is_active( $user ) ) {
			return '<p class="dealer-notice">L’accesso della tua azienda ai documenti è attualmente sospeso. '
				. 'Contatta il tuo referente commerciale per maggiori informazioni.</p>';
		}

		// Dal risolutore di identità, non dal meta storico: per un utente del
		// modello a organizzazioni quel meta può essere assente o superato.
		$user_lines = Dealer_Identity::get_effective_lines( $user );

		$last_login = get_user_meta( $user->ID, '_dealer_last_login', true );

		$ref_nome     = get_user_meta( $user->ID, '_referente_nome',     true );
		$ref_email    = get_user_meta( $user->ID, '_referente_email',    true );
		$ref_telefono = get_user_meta( $user->ID, '_referente_telefono', true );

		$recent_docs   = $this->get_recent_docs( $user, $user_lines, 5 );
		$expiring_docs = $this->get_expiring_docs( $user, $user_lines, 5 );

		// "I miei documenti": preferiti e cronologia di download. Entrambe le
		// liste ripassano dal controllo accessi — un documento può essere stato
		// revocato o scaduto dopo essere stato salvato o scaricato.
		// I preferiti si risolvono per intero e si troncano dopo: il template
		// decide con il totale se lo ZIP dei preferiti rientra nel limite, e
		// contare la lista già troncata risponderebbe sempre di sì. Una sola
		// chiamata anche perché ognuna riscrive il user meta ripulito.
		$all_favorites   = $this->get_favorite_docs( $user, $user_lines );
		$favorite_total  = count( $all_favorites );
		$favorite_docs   = array_slice( $all_favorites, 0, self::MY_DOCS_LIMIT );
		$downloaded_docs = $this->get_downloaded_docs( $user, $user_lines, self::MY_DOCS_LIMIT );

		// Ruolo da mostrare nel badge (il primo trovato nella lista ordinata).
		$display_role = '';
		foreach ( $dealer_roles as $role ) {
			if ( in_array( $role, $user_roles, true ) ) {
				$display_role = $role;
				break;
			}
		}

		$role_labels = Dealer_Roles::labels();

		// Questa pagina ha già il proprio link di logout nell'header: evita
		// che Dealer_Access_Guard ne aggiunga un secondo in fondo alla pagina.
		Dealer_Access_Guard::suppress_floating_logout();

		ob_start();
		require DEALER_PORTAL_PATH . 'templates/dealer-dashboard.php';
		return ob_get_clean();
	}

	// ─── Query helper: documenti recenti ─────────────────────────────────────

	private function get_recent_docs( \WP_User $user, array $user_lines, int $limit ): array {
		$posts = get_posts( [
			'post_type'      => 'documento_dealer',
			'post_status'    => 'publish',
			'numberposts'    => $limit * 4,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'meta_query'     => [
				'relation' => 'OR',
				[ 'key' => '_doc_status', 'value' => 'obsoleto', 'compare' => '!=' ],
				[ 'key' => '_doc_status', 'compare' => 'NOT EXISTS' ],
			],
		] );

		$filtered = array_values( array_filter( $posts, static function ( $post ) use ( $user, $user_lines ) {
			return Dealer_Search::user_can_access_post( $user, $user_lines, $post->ID );
		} ) );

		return array_slice( $filtered, 0, $limit );
	}

	// ─── Query helper: documenti in scadenza ─────────────────────────────────

	private function get_expiring_docs( \WP_User $user, array $user_lines, int $limit ): array {
		$today = gmdate( 'Y-m-d' );
		$in30  = gmdate( 'Y-m-d', strtotime( '+30 days' ) );

		$posts = get_posts( [
			'post_type'   => 'documento_dealer',
			'post_status' => 'publish',
			'numberposts' => $limit * 4,
			'meta_query'  => [
				'relation' => 'AND',
				[ 'key' => '_doc_expiry', 'value' => '',     'compare' => '!=' ],
				[ 'key' => '_doc_expiry', 'value' => $today, 'compare' => '>=' ],
				[ 'key' => '_doc_expiry', 'value' => $in30,  'compare' => '<=' ],
				[
					'relation' => 'OR',
					[ 'key' => '_doc_status', 'value' => 'obsoleto', 'compare' => '!=' ],
					[ 'key' => '_doc_status', 'compare' => 'NOT EXISTS' ],
				],
			],
			'orderby'  => 'meta_value',
			'meta_key' => '_doc_expiry',
			'order'    => 'ASC',
		] );

		$filtered = array_values( array_filter( $posts, static function ( $post ) use ( $user, $user_lines ) {
			return Dealer_Search::user_can_access_post( $user, $user_lines, $post->ID );
		} ) );

		return array_slice( $filtered, 0, $limit );
	}

	// ─── Query helper: preferiti ─────────────────────────────────────────────

	/**
	 * Documenti preferiti ancora visibili al dealer.
	 *
	 * Ogni ID ripassa dal controllo accessi: un preferito su cui l'accesso è
	 * stato revocato (o il cui documento è stato eliminato) non viene mostrato
	 * in nessuna forma — nemmeno con un avviso che ne riveli il titolo — e viene
	 * rimosso silenziosamente dal user meta.
	 *
	 * I documenti solo scaduti o marcati obsoleti restano memorizzati (la
	 * situazione può cambiare) ma non compaiono nell'elenco.
	 *
	 * @return \WP_Post[]
	 */
	private function get_favorite_docs( \WP_User $user, array $user_lines ): array {
		// Delegato a Dealer_Search: stesso criterio usato dal modulo Preferiti,
		// niente logica duplicata che potrebbe divergere nel tempo. Senza
		// limite: tronca il chiamante, che ha bisogno anche del totale.
		return Dealer_Search::get_accessible_favorites( $user, $user_lines );
	}

	// ─── Query helper: cronologia download ───────────────────────────────────

	/**
	 * Ultimi documenti scaricati dal dealer, filtrati sul controllo accessi
	 * corrente: il log è storico, i permessi no.
	 *
	 * @return array[] voci con post, last_download, download_count.
	 */
	private function get_downloaded_docs( \WP_User $user, array $user_lines, int $limit ): array {
		if ( ! class_exists( 'Dealer_DB' ) || ! method_exists( 'Dealer_DB', 'get_user_downloads' ) ) {
			return [];
		}

		// Si chiede più del necessario: parte delle righe verrà scartata dal
		// controllo accessi e dai documenti nel frattempo scaduti o obsoleti.
		$rows = Dealer_DB::get_user_downloads( $user->ID, $limit * 4 );

		$out = [];
		foreach ( (array) $rows as $row ) {
			$post_id = absint( $row->post_id ?? 0 );
			if ( ! $post_id || ! Dealer_Search::user_can_download_post( $user, $user_lines, $post_id ) ) {
				continue;
			}

			$post = get_post( $post_id );
			if ( ! $post ) {
				continue;
			}

			$out[] = [
				'post'           => $post,
				'last_download'  => (string) ( $row->last_download ?? '' ),
				'download_count' => (int) ( $row->download_count ?? 0 ),
			];

			if ( count( $out ) >= $limit ) {
				break;
			}
		}

		return $out;
	}
}
