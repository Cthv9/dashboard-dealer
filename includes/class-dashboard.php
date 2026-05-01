<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Dealer_Dashboard {

	public function __construct() {
		add_shortcode( 'dealer_dashboard', [ $this, 'render' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'wp_login', [ $this, 'track_login' ], 10, 2 );
	}

	// ─── Assets ──────────────────────────────────────────────────────────────

	public function enqueue_assets(): void {
		global $post;
		if ( ! is_a( $post, 'WP_Post' ) || ! has_shortcode( $post->post_content, 'dealer_dashboard' ) ) {
			return;
		}
		wp_enqueue_style( 'dealer-portal-dealer', DEALER_PORTAL_URL . 'assets/css/dealer.css', [], DEALER_PORTAL_VERSION );
	}

	// ─── Track login ─────────────────────────────────────────────────────────

	public function track_login( string $user_login, \WP_User $user ): void {
		update_user_meta( $user->ID, '_dealer_last_login', current_time( 'mysql' ) );
	}

	// ─── Shortcode render ────────────────────────────────────────────────────

	public function render( $atts ): string {
		// Reindirizza al login se non autenticato.
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( get_permalink() ) );
			exit;
		}

		$user       = wp_get_current_user();
		$user_roles = (array) $user->roles;

		// Permetti anche agli admin di vedere la dashboard (per test).
		$dealer_roles = [ 'dealer', 'top_dealer', 'part_center' ];
		$is_dealer    = ! empty( array_intersect( $user_roles, $dealer_roles ) );

		if ( ! $is_dealer && ! current_user_can( 'manage_options' ) ) {
			return '<p class="dealer-notice">Accesso non autorizzato.</p>';
		}

		$user_lines = get_user_meta( $user->ID, '_dealer_lines', true );
		if ( ! is_array( $user_lines ) ) { $user_lines = []; }

		$last_login = get_user_meta( $user->ID, '_dealer_last_login', true );

		$ref_nome     = get_user_meta( $user->ID, '_referente_nome',     true );
		$ref_email    = get_user_meta( $user->ID, '_referente_email',    true );
		$ref_telefono = get_user_meta( $user->ID, '_referente_telefono', true );

		$recent_docs   = $this->get_recent_docs( $user, $user_lines, 5 );
		$expiring_docs = $this->get_expiring_docs( $user, $user_lines, 5 );

		// Ruolo da mostrare nel badge (il primo trovato nella lista ordinata).
		$display_role = '';
		foreach ( $dealer_roles as $role ) {
			if ( in_array( $role, $user_roles, true ) ) {
				$display_role = $role;
				break;
			}
		}

		$role_labels = [
			'dealer'      => 'Dealer',
			'top_dealer'  => 'Top Dealer',
			'part_center' => 'Part Center',
		];

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
}
