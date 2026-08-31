<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/**
 * Scheda "Attività" — cosa succede ai documenti del perimetro.
 *
 * Non replica la pagina statistiche dell'amministratore: qui servono i download
 * dei propri documenti e pochi numeri utili a chi segue quell'area. Tutte le
 * letture passano da Dealer_DB con il filtro post_ids impostato sul perimetro,
 * quindi un documento fuori perimetro non compare mai — nemmeno come titolo in
 * una classifica.
 */

$am_window   = (int) ( $activity['window'] ?? 30 );
$am_logs     = (array) ( $activity['logs'] ?? [] );
$am_top      = (array) ( $activity['top_documents'] ?? [] );
?>

<div class="dealer-am-panel">
	<h3>Attività del perimetro</h3>
	<p class="dealer-am-hint">
		I numeri contano solo i download dei documenti che ricadono nelle tue linee prodotto.
	</p>

	<div class="dealer-am-row">
		<div class="dealer-am-field">
			<label>Download totali</label>
			<p style="font-size:1.6rem;font-weight:700;margin:0;color:#0a1628;">
				<?php echo esc_html( (string) (int) ( $activity['downloads_all'] ?? 0 ) ); ?>
			</p>
		</div>
		<div class="dealer-am-field">
			<label><?php echo esc_html( sprintf( 'Ultimi %d giorni', $am_window ) ); ?></label>
			<p style="font-size:1.6rem;font-weight:700;margin:0;color:#0a1628;">
				<?php echo esc_html( (string) (int) ( $activity['downloads_recent'] ?? 0 ) ); ?>
			</p>
		</div>
		<div class="dealer-am-field">
			<label>Documenti seguiti</label>
			<p style="font-size:1.6rem;font-weight:700;margin:0;color:#0a1628;">
				<?php echo esc_html( (string) (int) $summary['documents'] ); ?>
			</p>
		</div>
		<div class="dealer-am-field">
			<label>Persone seguite</label>
			<p style="font-size:1.6rem;font-weight:700;margin:0;color:#0a1628;">
				<?php echo esc_html( (string) (int) $summary['people'] ); ?>
			</p>
		</div>
	</div>
</div>

<div class="dealer-am-panel">
	<h4><?php echo esc_html( sprintf( 'Documenti più scaricati negli ultimi %d giorni', $am_window ) ); ?></h4>

	<?php if ( empty( $am_top ) ) : ?>
		<p class="dealer-am-empty">Nessun download registrato nel periodo.</p>
	<?php else : ?>
		<div class="dealer-am-tablewrap">
			<table class="dealer-am-table">
				<thead>
					<tr>
						<th scope="col">Documento</th>
						<th scope="col">Download</th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $am_top as $am_row ) : ?>
					<tr>
						<td>
							<?php
							$am_title = (string) ( $am_row->post_title ?? '' );
							echo esc_html( '' !== $am_title ? $am_title : sprintf( 'Documento #%d', (int) $am_row->post_id ) );
							?>
						</td>
						<td><?php echo esc_html( (string) (int) $am_row->downloads ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php endif; ?>
</div>

<div class="dealer-am-panel">
	<h4>Download recenti</h4>
	<p class="dealer-am-hint">
		Ultimi <?php echo esc_html( (string) Dealer_Area_Manager::LOG_LIMIT ); ?> accessi
		registrati sui documenti del tuo perimetro.
	</p>

	<?php if ( empty( $am_logs ) ) : ?>
		<p class="dealer-am-empty">Nessun download registrato.</p>
	<?php else : ?>
		<div class="dealer-am-tablewrap">
			<table class="dealer-am-table">
				<thead>
					<tr>
						<th scope="col">Data</th>
						<th scope="col">Documento</th>
						<th scope="col">Utente</th>
						<th scope="col">Titolo di accesso</th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $am_logs as $am_log ) : ?>
					<tr>
						<td><?php echo esc_html( Dealer_Area_Manager::format_datetime( (string) ( $am_log->download_date ?? '' ) ) ); ?></td>
						<td>
							<?php
							$am_log_title = (string) ( $am_log->post_title ?? '' );
							echo esc_html( '' !== $am_log_title ? $am_log_title : sprintf( 'Documento #%d', (int) ( $am_log->post_id ?? 0 ) ) );
							?>
						</td>
						<td>
							<?php echo esc_html( (string) ( $am_log->display_name ?? '—' ) ); ?>
							<?php if ( ! empty( $am_log->user_email ) ) : ?>
								<br><span class="dealer-am-doc-file"><?php echo esc_html( (string) $am_log->user_email ); ?></span>
							<?php endif; ?>
						</td>
						<td>
							<span class="dealer-am-badge dealer-am-badge-soft">
								<?php echo esc_html( Dealer_DB::access_context_label( (string) ( $am_log->access_context ?? '' ) ) ); ?>
							</span>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php endif; ?>
</div>
