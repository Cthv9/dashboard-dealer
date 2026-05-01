<?php
/**
 * Eseguito da WordPress quando il plugin viene disinstallato (non solo disattivato).
 * Rimuove: pagine create, ruoli, tabella log, opzioni del plugin.
 * Gli utenti con quei ruoli NON vengono eliminati: perdono solo l'assegnazione del ruolo.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { exit; }

// ── Elimina le pagine create automaticamente all'attivazione ─────────────────
$page_options = [ 'dealer_portal_dashboard_page_id', 'dealer_portal_search_page_id' ];
foreach ( $page_options as $option ) {
	$page_id = (int) get_option( $option );
	if ( $page_id ) {
		wp_delete_post( $page_id, true ); // true = bypass cestino, elimina definitivamente
	}
	delete_option( $option );
}

// ── Rimuove i ruoli custom ────────────────────────────────────────────────────
remove_role( 'dealer' );
remove_role( 'top_dealer' );
remove_role( 'part_center' );

// ── Elimina tabella log download ──────────────────────────────────────────────
global $wpdb;
$table = $wpdb->prefix . 'dealer_download_log';
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );

// ── Pulisce le opzioni del plugin ─────────────────────────────────────────────
delete_option( 'dealer_portal_version' );
