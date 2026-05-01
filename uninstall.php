<?php
/**
 * Eseguito da WordPress quando il plugin viene disinstallato (non solo disattivato).
 * Rimuove ruoli, tabella log e opzioni del plugin.
 * Gli utenti con quei ruoli NON vengono eliminati: perdono solo l'assegnazione del ruolo.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { exit; }

remove_role( 'dealer' );
remove_role( 'top_dealer' );
remove_role( 'part_center' );

global $wpdb;
$table = $wpdb->prefix . 'dealer_download_log';
// Utilizziamo il prefisso trusted di WP, nessun input utente nella query.
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );

delete_option( 'dealer_portal_version' );
