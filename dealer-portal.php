<?php
/**
 * Plugin Name:       Dealer Portal
 * Description:       Area riservata dealer: upload documenti, ricerca full-text, dashboard personalizzata. SearchWP supportato (opzionale).
 * Version:           1.3.2
 * Author:            —
 * Text Domain:       dealer-portal
 * Requires PHP:      7.4
 * Requires at least: 5.8
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'DEALER_PORTAL_VERSION', '1.3.2' );
define( 'DEALER_PORTAL_PATH',    plugin_dir_path( __FILE__ ) );
define( 'DEALER_PORTAL_URL',     plugin_dir_url( __FILE__ ) );
// ─── Capability ──────────────────────────────────────────────────────────────
// Una sola capability significava "sei amministratore". Con l'area manager i
// poteri vanno separati: chi carica documenti non deve poter creare utenti né
// cancellare file dal server.
//
// ATTENZIONE: nessuna di queste implica le altre. L'amministratore le riceve
// tutte esplicitamente in Dealer_DB::setup_capability(); ogni punto di controllo
// interroga la capability specifica dell'azione, mai una "capability madre".
define( 'DEALER_PORTAL_CAP',        'manage_dealer_portal' ); // Controllo completo (solo amministratore).
define( 'DEALER_PORTAL_CAP_UPLOAD', 'upload_dealer_docs' );   // Wizard, salvataggio, versionamento, archivio.
define( 'DEALER_PORTAL_CAP_LOGS',   'view_dealer_logs' );     // Log download, export CSV, statistiche.
define( 'DEALER_PORTAL_CAP_ORGS',   'manage_dealer_orgs' );   // Organizzazioni e assegnazione utenti (solo amministratore).

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
require_once DEALER_PORTAL_PATH . 'includes/class-org-admin.php';
require_once DEALER_PORTAL_PATH . 'includes/class-team.php';
require_once DEALER_PORTAL_PATH . 'includes/class-area-manager.php';
require_once DEALER_PORTAL_PATH . 'includes/class-favorites.php';
require_once DEALER_PORTAL_PATH . 'includes/class-access-guard.php';

// Full setup on first activation.
register_activation_hook( __FILE__, [ 'Dealer_DB', 'install' ] );

// Alla disattivazione vanno rimossi gli eventi cron delle notifiche: senza
// questo WP continuerebbe a richiamare hook di una classe non più caricata.
register_deactivation_hook( __FILE__, [ 'Dealer_Notifications', 'clear_scheduled_events' ] );

// Re-apply idempotent upgrade steps (capability, protected dir) on updates without reactivation.
add_action( 'plugins_loaded', [ 'Dealer_DB', 'maybe_upgrade' ] );

// ─── Login Redirect ──────────────────────────────────────────────────────────
// Ogni utente del portale finisce nella propria area operativa, mai in
// wp-admin: i dealer e i titolari sulla dashboard, gli area manager sulla loro
// area di lavoro. Gli amministratori seguono il flusso standard di WordPress.
add_filter( 'login_redirect', function ( string $redirect_to, string $request, $user ): string {
	if ( is_wp_error( $user ) || ! isset( $user->roles ) || ! is_array( $user->roles ) ) {
		return $redirect_to;
	}
	if ( ! Dealer_Access_Guard::is_portal_user( $user ) ) {
		return $redirect_to;
	}
	// Risolto dall'ID pagina reale, non da un percorso fisso: vedi
	// Dealer_DB::dashboard_url() per il perché.
	return Dealer_Access_Guard::home_url_for( $user );
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

	// Moduli attivi su entrambi i contesti: cron/email, richieste di accesso e
	// i moduli con delega devono rispondere sia in admin sia sul front-end,
	// perché admin-ajax.php gira in contesto admin (is_admin() === true) pur
	// essendo l'endpoint che il front-end chiama. Istanziare un modulo con
	// endpoint AJAX solo nel ramo front-end lo renderebbe irraggiungibile da
	// admin-ajax.php — è la stessa causa già trovata per Dealer_Search.
	new Dealer_Notifications();
	new Dealer_Access_Request();
	new Dealer_Org_Admin();
	new Dealer_Team();
	new Dealer_Area_Manager();
	new Dealer_Favorites();

	// Deve girare in entrambi i contesti: blocca wp-admin e nasconde la barra
	// di amministrazione agli utenti del portale.
	new Dealer_Access_Guard();
}, 10 );
