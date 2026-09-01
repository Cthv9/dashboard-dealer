<?php
/**
 * Elenco degli area manager con un riepilogo del loro perimetro.
 * Dealer Portal → Organizzazioni → Area Manager (view=area_managers)
 *
 * Variabili fornite da Dealer_Org_Admin::render_area_managers():
 * @var array  $rows      Un'entry per area manager (id, name, email, root_names, root_count, line_count, configured)
 * @var array  $notice
 * @var string $base_url
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! current_user_can( DEALER_PORTAL_CAP_ORGS ) ) {
	wp_die( esc_html__( 'Accesso non consentito.', 'dealer-portal' ) );
}

require DEALER_PORTAL_PATH . 'templates/admin-org-styles.php';
?>
<div class="wrap">
	<h1 class="wp-heading-inline">Area Manager</h1>
	<a href="<?php echo esc_url( $base_url ); ?>" class="page-title-action">Torna alle organizzazioni</a>
	<hr class="wp-header-end">

	<?php if ( ! empty( $notice['message'] ) ) : ?>
		<div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?> is-dismissible">
			<p><?php echo esc_html( $notice['message'] ); ?></p>
		</div>
	<?php endif; ?>

	<div class="notice notice-info inline" style="margin:16px 0;">
		<p>
			<strong>Il perimetro dell'area manager e' un asse separato da quello dei dealer.</strong>
			Non appartiene a un'organizzazione: <em>segue</em> una o piu' organizzazioni radice (il sottoalbero di
			ciascuna e' incluso da solo) ed e' abilitato a pubblicare solo su un sottoinsieme di linee prodotto.
			Un account con ruolo Area Manager e nessuno dei due assegnati non vede nulla — e' la stessa condizione
			che l'area manager legge come "perimetro non configurato" nella propria area di lavoro.
		</p>
	</div>

	<?php if ( empty( $rows ) ) : ?>
		<p class="dorg-muted">Nessun utente con ruolo "Area Manager". Se ne crea uno da <strong>Utenti → Aggiungi nuovo</strong>, oppure promuovendo un utente esistente.</p>
	<?php else : ?>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th>Area manager</th>
					<th>Organizzazioni seguite</th>
					<th>Linee di pubblicazione</th>
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
							<?php if ( empty( $row['root_names'] ) ) : ?>
								<span class="dorg-muted">Nessuna</span>
							<?php else : ?>
								<?php echo esc_html( implode( ', ', $row['root_names'] ) ); ?>
							<?php endif; ?>
						</td>
						<td>
							<span class="dorg-badge <?php echo $row['line_count'] > 0 ? 'is-own' : 'is-alert'; ?>">
								<?php echo esc_html( (string) $row['line_count'] ); ?> linee
							</span>
						</td>
						<td>
							<?php if ( $row['configured'] ) : ?>
								<span class="dorg-badge is-own">Configurato</span>
							<?php else : ?>
								<span class="dorg-badge is-alert">Perimetro non configurato</span>
							<?php endif; ?>
						</td>
						<td>
							<a class="button button-small" href="<?php echo esc_url( add_query_arg( [ 'view' => 'area_manager_edit', 'user' => $row['id'] ], $base_url ) ); ?>">
								Assegna perimetro
							</a>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
