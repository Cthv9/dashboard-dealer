<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
// Variabile $logs fornita da Dealer_Admin::render_logs()
?>
<div class="wrap dealer-admin-wrap">
	<h1>
		<span class="dashicons dashicons-download" style="vertical-align:middle;margin-right:6px;"></span>
		Log Download
	</h1>
	<p>Registro completo di tutti i download effettuati dai dealer (ultime 500 voci).</p>

	<?php if ( empty( $logs ) ) : ?>
		<div class="notice notice-info"><p>Nessun download registrato finora.</p></div>
	<?php else : ?>

	<table class="wp-list-table widefat fixed striped">
		<thead>
			<tr>
				<th style="width:20%">Documento</th>
				<th style="width:20%">Utente</th>
				<th style="width:18%">Data e Ora</th>
				<th style="width:15%">Indirizzo IP</th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $logs as $log ) : ?>
			<tr>
				<td>
					<?php if ( $log->post_title ) : ?>
						<a href="<?php echo esc_url( get_edit_post_link( (int) $log->post_id ) ); ?>">
							<?php echo esc_html( $log->post_title ); ?>
						</a>
					<?php else : ?>
						<em style="color:#888;">Post eliminato (ID <?php echo esc_html( $log->post_id ); ?>)</em>
					<?php endif; ?>
				</td>
				<td><?php echo esc_html( $log->display_name ?: 'Utente #' . $log->user_id ); ?></td>
				<td><?php echo esc_html( gmdate( 'd/m/Y H:i', strtotime( $log->download_date ) ) ); ?></td>
				<td><code><?php echo esc_html( $log->ip_address ?: '—' ); ?></code></td>
			</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<?php endif; ?>
</div>
