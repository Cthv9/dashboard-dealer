<?php
/**
 * Plugin Name:       Dealer Portal
 * Description:       Area riservata dealer: upload documenti, ricerca full-text, dashboard personalizzata. SearchWP supportato (opzionale).
 * Version:           1.2.0
 * Author:            —
 * Text Domain:       dealer-portal
 * Requires PHP:      7.4
 * Requires at least: 5.8
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'DEALER_PORTAL_VERSION', '1.2.0' );
define( 'DEALER_PORTAL_PATH',    plugin_dir_path( __FILE__ ) );
define( 'DEALER_PORTAL_URL',     plugin_dir_url( __FILE__ ) );
define( 'DEALER_PORTAL_CAP',     'manage_dealer_portal' );

require_once DEALER_PORTAL_PATH . 'includes/class-cpt.php';
require_once DEALER_PORTAL_PATH . 'includes/class-roles.php';
require_once DEALER_PORTAL_PATH . 'includes/class-db.php';
require_once DEALER_PORTAL_PATH . 'includes/class-versioning.php';
require_once DEALER_PORTAL_PATH . 'includes/class-organization.php';
require_once DEALER_PORTAL_PATH . 'includes/class-identity.php';
require_once DEALER_PORTAL_PATH . 'includes/class-admin.php';
require_once DEALER_PORTAL_PATH . 'includes/class-search.php';
require_once DEALER_PORTAL_PATH . 'includes/class-dashboard.php';
require_once DEALER_PORTAL_PATH . 'includes/class-searchwp.php';
require_once DEALER_PORTAL_PATH . 'includes/class-notifications.php';
require_once DEALER_PORTAL_PATH . 'includes/class-access-request.php';

// Full setup on first activation.
register_activation_hook( __FILE__, [ 'Dealer_DB', 'install' ] );

// Alla disattivazione vanno rimossi gli eventi cron delle notifiche: senza
// questo WP continuerebbe a richiamare hook di una classe non più caricata.
register_deactivation_hook( __FILE__, [ 'Dealer_Notifications', 'clear_scheduled_events' ] );

// Re-apply idempotent upgrade steps (capability, protected dir) on updates without reactivation.
add_action( 'plugins_loaded', [ 'Dealer_DB', 'maybe_upgrade' ] );

// ─── Login Redirect ──────────────────────────────────────────────────────────
// I dealer vengono rimandati alla dashboard dealer; gli admin al flusso standard.
add_filter( 'login_redirect', function ( string $redirect_to, string $request, $user ): string {
	if ( is_wp_error( $user ) || ! isset( $user->roles ) || ! is_array( $user->roles ) ) {
		return $redirect_to;
	}
	$dealer_roles = [ 'dealer', 'top_dealer', 'part_center' ];
	foreach ( $dealer_roles as $role ) {
		if ( in_array( $role, $user->roles, true ) ) {
			return site_url( '/dashboard-dealer/' );
		}
	}
	return $redirect_to;
}, 10, 3 );

// ─── Boot ────────────────────────────────────────────────────────────────────
// Dealer_CPT deve aggiungere il suo hook 'init' prima che 'init' scatti.
add_action( 'plugins_loaded', static function (): void {
	new Dealer_CPT();
	new Dealer_SearchWP();
	new Dealer_Organization();
} );

add_action( 'init', static function (): void {
	new Dealer_Roles();
	if ( is_admin() ) {
		new Dealer_Admin();
	} else {
		new Dealer_Search();
		new Dealer_Dashboard();
	}

	// Moduli attivi su entrambi i contesti: cron/email e richieste di accesso
	// devono rispondere sia in admin sia sul front-end.
	new Dealer_Notifications();
	new Dealer_Access_Request();
}, 10 );
