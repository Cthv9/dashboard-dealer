<?php
/**
 * Elenco degli area manager con lo stato del loro perimetro.
 * Dealer Portal → Area Manager
 *
 * Variabili fornite da Dealer_Org_Admin::render_area_managers():
 * @var array  $rows          id, name, email, root_names, root_count, line_count, operational
 * @var int    $blocked       quanti non sono ancora operativi (nessuna linea)
 * @var array  $notice
 * @var string $base_url      URL di questa schermata
 * @var string $new_user_url  wp-admin/user-new.php
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! Dealer_DB::user_can( DEALER_PORTAL_CAP_ORGS ) ) {
	wp_die( esc_html__( 'Accesso non consentito.', 'dealer-portal' ) );
}

require DEALER_PORTAL_PATH . 'templates/admin-org-styles.php';
?>
<div class="wrap">
	<h1 class="wp-heading-inline">Area Manager</h1>
	<a href="<?php echo esc_url( $new_user_url ); ?>" class="page-title-action">Crea un nuovo utente</a>
	<hr class="wp-header-end">

	<?php if ( ! empty( $notice['message'] ) ) : ?>
		<div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?> is-dismissible">
			<p><?php echo esc_html( $notice['message'] ); ?></p>
		</div>
	<?php endif; ?>

	<div class="notice notice-info inline" style="margin:16px 0;">
		<p><strong>Come si mette in piedi un area manager — due passaggi.</strong></p>
		<ol style="margin:6px 0 10px 22px;">
			<li>
				<strong>Creare l'utente</strong> con il ruolo <em>Area Manager</em>
				(<a href="<?php echo esc_url( $new_user_url ); ?>">Utenti → Aggiungi nuovo</a>, scegliendo quel ruolo
				nel menu a tendina in fondo al modulo; oppure cambiando il ruolo di un utente che esiste già).
				Appena creato compare in questo elenco.
			</li>
			<li>
				<strong>Assegnargli il perimetro</strong> da qui, con il pulsante <em>Assegna perimetro</em>.
			</li>
		</ol>
		<p style="margin:0;">
			Il perimetro ha due assi, e non pesano uguale:
			le <strong>linee prodotto</strong> sono ciò che lo rende operativo — senza almeno una non può
			pubblicare né aggiornare alcun documento;
			le <strong>organizzazioni</strong> sono la supervisione (persone, log, statistiche dei dealer che segue)
			e restano <em>facoltative</em>: un area manager che si occupa solo di pubblicare documenti funziona
			benissimo senza nessuna organizzazione assegnata.
		</p>
		<p style="margin:8px 0 0;">
			Nota: il campo <em>Linee Prodotto Assegnate</em> che compare nel profilo WordPress dell'utente
			<strong>non</strong> vale per gli area manager — è il campo del vecchio modello per i dealer.
			Il perimetro di un area manager si imposta solo da questa schermata.
		</p>
	</div>

	<?php if ( $blocked > 0 ) : ?>
		<div class="notice notice-warning inline" style="margin:16px 0;">
			<p>
				<span class="dashicons dashicons-warning" style="vertical-align:middle;"></span>
				<strong><?php echo esc_html( (string) $blocked ); ?></strong>
				<?php echo esc_html( 1 === $blocked ? 'area manager non è ancora operativo' : 'area manager non sono ancora operativi' ); ?>:
				nessuna linea prodotto assegnata, quindi <?php echo esc_html( 1 === $blocked ? 'non può' : 'non possono' ); ?>
				pubblicare nulla e <?php echo esc_html( 1 === $blocked ? 'trova' : 'trovano' ); ?>
				la propria area di lavoro vuota.
			</p>
		</div>
	<?php endif; ?>

	<?php if ( empty( $rows ) ) : ?>
		<div class="postbox"><div class="inside">
			<p><strong>Nessun utente ha il ruolo Area Manager.</strong></p>
			<p>
				Crealo da <a href="<?php echo esc_url( $new_user_url ); ?>">Utenti → Aggiungi nuovo</a>
				scegliendo il ruolo <em>Area Manager</em>, oppure apri un utente esistente e cambiagli il ruolo.
				Torna poi qui: comparirà in elenco e potrai assegnargli il perimetro.
			</p>
		</div></div>
	<?php else : ?>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th>Area manager</th>
					<th>Linee prodotto <span class="dorg-cell-sub">(lo rendono operativo)</span></th>
					<th>Organizzazioni seguite <span class="dorg-cell-sub">(facoltative)</span></th>
					<th>Stato</th>
					<th></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<td>
							<strong><?php echo esc_html( $row['name'] ); ?></strong>
							<span class="dorg-cell-sub"><?php echo esc_html( $row['email'] ); ?></span>
						</td>
						<td>
							<span class="dorg-badge <?php echo $row['line_count'] > 0 ? 'is-own' : 'is-alert'; ?>">
								<?php echo esc_html( (string) $row['line_count'] ); ?> linee
							</span>
						</td>
						<td>
							<?php if ( empty( $row['root_names'] ) ) : ?>
								<span class="dorg-muted">Nessuna</span>
							<?php else : ?>
								<?php echo esc_html( implode( ', ', $row['root_names'] ) ); ?>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( $row['operational'] ) : ?>
								<span class="dorg-badge is-own">Operativo</span>
							<?php else : ?>
								<span class="dorg-badge is-alert">Non operativo</span>
								<span class="dorg-cell-sub">manca almeno una linea</span>
							<?php endif; ?>
						</td>
						<td>
							<a class="button button-primary button-small" href="<?php echo esc_url( add_query_arg( 'user', $row['id'], $base_url ) ); ?>">
								Assegna perimetro
							</a>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
