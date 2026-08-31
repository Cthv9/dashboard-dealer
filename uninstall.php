<?php
/**
 * Eseguito da WordPress quando il plugin viene disinstallato (non solo disattivato).
 *
 * Rimuove: pagine create, ruoli, tabella log, opzioni, eventi cron, richieste di
 * accesso e i meta utente introdotti dal plugin.
 *
 * NON rimuove i documenti (`documento_dealer`) né i file allegati: sono
 * l'archivio documentale del cliente, non dati di servizio del plugin.
 * Cancellarli qui renderebbe una disinstallazione accidentale irreversibile.
 * Gli utenti con i ruoli dealer non vengono eliminati: perdono solo il ruolo.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { exit; }

global $wpdb;

// ── Elimina le pagine create automaticamente all'attivazione ─────────────────
$page_options = [
	'dealer_portal_dashboard_page_id',
	'dealer_portal_search_page_id',
	'dealer_portal_team_page_id',
	'dealer_portal_am_page_id',
];
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
remove_role( 'area_manager' );

// ── Toglie le capability del plugin all'amministratore ────────────────────────
// I ruoli custom se ne vanno con remove_role(); l'amministratore invece resta e
// senza questo si porterebbe dietro le capability di un plugin che non c'è più.
// Le costanti non sono definite qui (il plugin non è caricato): valori letterali.
$admin_role = get_role( 'administrator' );
if ( $admin_role ) {
	$dealer_caps = [
		'manage_dealer_portal',
		'upload_dealer_docs',
		'view_dealer_logs',
		'manage_dealer_orgs',
		// Capability proprie del CPT documenti (Dealer_CPT::primitive_caps()),
		// ripetute qui come letterali perché il plugin non è caricato.
		'edit_documenti_dealer',
		'edit_others_documenti_dealer',
		'edit_private_documenti_dealer',
		'edit_published_documenti_dealer',
		'publish_documenti_dealer',
		'read_private_documenti_dealer',
		'delete_documenti_dealer',
		'delete_others_documenti_dealer',
		'delete_private_documenti_dealer',
		'delete_published_documenti_dealer',
	];
	foreach ( $dealer_caps as $dealer_cap ) {
		$admin_role->remove_cap( $dealer_cap );
	}
}

// ── Rimuove gli eventi cron delle notifiche ───────────────────────────────────
// Senza questo WordPress continuerebbe a richiamare hook di classi non più
// caricate a ogni visita, finché la pianificazione non scade.
$cron_hooks = [
	'dealer_portal_process_mail_queue',
	'dealer_portal_dealer_expiry_digest',
	'dealer_portal_admin_expiry_report',
];
foreach ( $cron_hooks as $hook ) {
	if ( function_exists( 'wp_unschedule_hook' ) ) {
		wp_unschedule_hook( $hook ); // rimuove anche gli eventi singoli con argomenti
	} else {
		wp_clear_scheduled_hook( $hook );
	}
}

// ── Elimina le richieste di accesso ───────────────────────────────────────────
// Sono record di workflow: dopo la disinstallazione il CPT non è più registrato
// e resterebbero orfani e invisibili nel database.
$access_requests = get_posts( [
	'post_type'        => 'dealer_access_req',
	'post_status'      => 'any',
	'numberposts'      => -1,
	'fields'           => 'ids',
	'suppress_filters' => false,
] );
foreach ( $access_requests as $request_id ) {
	wp_delete_post( (int) $request_id, true );
}

// ── Elimina le organizzazioni ──────────────────────────────────────────────────
// Stessa logica delle richieste di accesso: sono un CPT del plugin, non
// documenti del cliente. A differenza di documento_dealer non hanno un file
// allegato da rimuovere: solo il post.
$organizations = get_posts( [
	'post_type'        => 'dealer_org',
	'post_status'      => 'any',
	'numberposts'      => -1,
	'fields'           => 'ids',
	'suppress_filters' => false,
] );
foreach ( $organizations as $org_id ) {
	wp_delete_post( (int) $org_id, true );
}

// ── Elimina tabella log download ──────────────────────────────────────────────
$table = $wpdb->prefix . 'dealer_download_log';
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );

// ── Pulisce le opzioni del plugin ─────────────────────────────────────────────
$options = [
	'dealer_portal_version',
	'dealer_portal_caps_revision',
	'dealer_portal_schema_revision',
	'dealer_portal_pages_revision',
	'dealer_portal_notifications',
	'dealer_portal_notification_queue',
];
foreach ( $options as $option ) {
	delete_option( $option );
}

// ── Pulisce i meta utente introdotti dal plugin ───────────────────────────────
// Gli utenti restano, ma non devono conservare assegnazioni di linee prodotto,
// preferiti e preferenze di un plugin che non c'è più.
$user_meta_keys = [
	'_dealer_lines',
	'_dealer_last_login',
	'_dealer_favorites',
	'_dealer_notify_new',
	'_dealer_notify_expiring',
	'_referente_nome',
	'_referente_email',
	'_referente_telefono',
	'_dar_company',
	'_dar_vat',
	// Modello organizzativo: appartenenza, funzione, restrizione individuale,
	// perimetro dell'area manager, tracciamento di invito e disattivazione.
	'_dealer_org',
	'_dealer_function',
	'_dealer_line_limit',
	'_am_orgs',
	'_am_lines',
	'_dealer_invited_by',
	'_dealer_invited_at',
	'_dealer_deactivated_by',
	'_dealer_deactivated_at',
];
foreach ( $user_meta_keys as $meta_key ) {
	delete_metadata( 'user', 0, $meta_key, '', true ); // true = per tutti gli utenti
}
