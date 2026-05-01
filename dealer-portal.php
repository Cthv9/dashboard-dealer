<?php
/**
 * Plugin Name:       Dealer Portal
 * Description:       Area riservata dealer: upload documenti, ricerca full-text, dashboard personalizzata. Richiede WP Customer Area 8.3 e SearchWP (opzionale).
 * Version:           1.0.0
 * Author:            —
 * Text Domain:       dealer-portal
 * Requires PHP:      7.4
 * Requires at least: 5.8
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'DEALER_PORTAL_VERSION', '1.0.0' );
define( 'DEALER_PORTAL_PATH',    plugin_dir_path( __FILE__ ) );
define( 'DEALER_PORTAL_URL',     plugin_dir_url( __FILE__ ) );

require_once DEALER_PORTAL_PATH . 'includes/class-cpt.php';
require_once DEALER_PORTAL_PATH . 'includes/class-roles.php';
require_once DEALER_PORTAL_PATH . 'includes/class-db.php';
require_once DEALER_PORTAL_PATH . 'includes/class-admin.php';
require_once DEALER_PORTAL_PATH . 'includes/class-search.php';
require_once DEALER_PORTAL_PATH . 'includes/class-dashboard.php';
require_once DEALER_PORTAL_PATH . 'includes/class-searchwp.php';

// Crea la tabella del log download all'attivazione del plugin.
register_activation_hook( __FILE__, [ 'Dealer_DB', 'install' ] );

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
add_action( 'plugins_loaded', static function (): void {
	new Dealer_SearchWP();
} );

add_action( 'init', static function (): void {
	new Dealer_CPT();
	new Dealer_Roles();
	if ( is_admin() ) {
		new Dealer_Admin();
	} else {
		new Dealer_Search();
		new Dealer_Dashboard();
	}
}, 10 );
