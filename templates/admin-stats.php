<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
// Variabili fornite da Dealer_Admin::render_stats():
// $periods (array slug => etichetta), $period (slug attivo), $since (datetime MySQL o ''),
// $total_downloads, $downloads_30, $downloads_period, $active_docs, $docs_truncated,
// $active_dealers, $registered_dealers, $top_documents, $top_users,
// $never_downloaded (int[] ID), $never_downloaded_total,
// $inactive_dealers (array), $inactive_total,
// $restricted (bool: numeri calcolati sul solo perimetro dell'utente)

$period_label = $periods[ $period ];

// Massimi per dimensionare le barre CSS (nessuna libreria esterna: il CSP vieta i CDN).
$max_doc_downloads  = 0;
foreach ( $top_documents as $row ) {
	$max_doc_downloads = max( $max_doc_downloads, (int) $row->downloads );
}
$max_user_downloads = 0;
foreach ( $top_users as $row ) {
	$max_user_downloads = max( $max_user_downloads, (int) $row->downloads );
}

/** Percentuale (0-100) di un valore rispetto al massimo della classifica. */
$bar_width = static function ( int $value, int $max ): float {
	if ( $max <= 0 ) { return 0.0; }
	return round( ( $value / $max ) * 100, 2 );
};

/** Formatta una data MySQL, con fallback per i valori mancanti. */
$fmt_date = static function ( string $mysql_date ): string {
	if ( ! $mysql_date ) { return '—'; }
	$ts = strtotime( $mysql_date );
	return $ts ? gmdate( 'd/m/Y', $ts ) : '—';
};
?>
<div class="wrap dealer-admin-wrap dealer-stats-wrap">
	<h1 class="wp-heading-inline">
		<span class="dashicons dashicons-chart-bar" style="vertical-align:middle;margin-right:6px;"></span>
		Statistiche
	</h1>

	<?php if ( ! empty( $restricted ) ) : ?>
		<p class="description" style="margin-top:8px;">
			<span class="dashicons dashicons-visibility" style="vertical-align:middle;"></span>
			Tutti i numeri di questa pagina sono calcolati sui soli documenti del tuo perimetro di linee
			e sui dealer delle organizzazioni che segui.
		</p>
	<?php endif; ?>

	<!-- Selettore di periodo -->
	<form method="GET" class="dealer-stats-periodbar">
		<input type="hidden" name="page" value="dealer-portal-stats">
		<label for="periodo"><strong>Periodo delle classifiche:</strong></label>
		<select id="periodo" name="periodo">
			<?php foreach ( $periods as $key => $label ) : ?>
				<option value="<?php echo esc_attr( $key ); ?>"<?php selected( $period, $key ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<button type="submit" class="button">Aggiorna</button>
	</form>

	<!-- Riquadri riassuntivi -->
	<div class="dealer-stat-cards">
		<div class="dealer-stat-card">
			<span class="dashicons dashicons-download"></span>
			<span class="dealer-stat-value"><?php echo esc_html( number_format_i18n( $total_downloads ) ); ?></span>
			<span class="dealer-stat-label">Download totali</span>
		</div>
		<div class="dealer-stat-card">
			<span class="dashicons dashicons-calendar-alt"></span>
			<span class="dealer-stat-value"><?php echo esc_html( number_format_i18n( $downloads_30 ) ); ?></span>
			<span class="dealer-stat-label">Download ultimi 30 giorni</span>
		</div>
		<div class="dealer-stat-card">
			<span class="dashicons dashicons-media-document"></span>
			<span class="dealer-stat-value"><?php echo esc_html( number_format_i18n( $active_docs ) ); ?></span>
			<span class="dealer-stat-label">Documenti attivi</span>
		</div>
		<div class="dealer-stat-card">
			<span class="dashicons dashicons-groups"></span>
			<span class="dealer-stat-value"><?php echo esc_html( number_format_i18n( $active_dealers ) ); ?></span>
			<span class="dealer-stat-label">
				Dealer attivi (90 gg)
				<br><small style="text-transform:none;letter-spacing:0;">su <?php echo esc_html( number_format_i18n( $registered_dealers ) ); ?> registrati</small>
			</span>
		</div>
	</div>

	<p class="description">
		<?php
		printf(
			'Nel periodo selezionato (%s) sono stati registrati %s download.',
			esc_html( $period_label ),
			esc_html( number_format_i18n( $downloads_period ) )
		);
		?>
	</p>

	<!-- Classifiche -->
	<div class="dealer-stats-grid">

		<div class="postbox">
			<h2 class="hndle"><span>Top 10 documenti più scaricati — <?php echo esc_html( $period_label ); ?></span></h2>
			<div class="inside">
				<?php if ( empty( $top_documents ) ) : ?>
					<p><em>Nessun download nel periodo selezionato.</em></p>
				<?php else : ?>
				<table class="wp-list-table widefat striped dealer-stats-table">
					<thead>
						<tr>
							<th scope="col" style="width:55%">Documento</th>
							<th scope="col">Download</th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $top_documents as $row ) :
						$doc_id    = (int) $row->post_id;
						$downloads = (int) $row->downloads;
						$edit_link = get_edit_post_link( $doc_id );
						?>
						<tr>
							<td>
								<?php if ( $row->post_title && $edit_link ) : ?>
									<a href="<?php echo esc_url( (string) $edit_link ); ?>"><?php echo esc_html( $row->post_title ); ?></a>
								<?php elseif ( $row->post_title ) : ?>
									<?php echo esc_html( $row->post_title ); ?>
								<?php else : ?>
									<em style="color:#888;">Documento eliminato (ID <?php echo esc_html( $doc_id ); ?>)</em>
								<?php endif; ?>
							</td>
							<td>
								<div class="dealer-bar-wrap">
									<span class="dealer-bar" style="width:<?php echo esc_attr( $bar_width( $downloads, $max_doc_downloads ) ); ?>%"></span>
									<span class="dealer-bar-value"><?php echo esc_html( number_format_i18n( $downloads ) ); ?></span>
								</div>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<?php endif; ?>
			</div>
		</div>

		<div class="postbox">
			<h2 class="hndle"><span>Top 10 dealer più attivi — <?php echo esc_html( $period_label ); ?></span></h2>
			<div class="inside">
				<?php if ( empty( $top_users ) ) : ?>
					<p><em>Nessun download nel periodo selezionato.</em></p>
				<?php else : ?>
				<table class="wp-list-table widefat striped dealer-stats-table">
					<thead>
						<tr>
							<th scope="col" style="width:40%">Dealer</th>
							<th scope="col" style="width:25%">Ultimo download</th>
							<th scope="col">Download</th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $top_users as $row ) :
						$user_id   = (int) $row->user_id;
						$downloads = (int) $row->downloads;
						?>
						<tr>
							<td>
								<a href="<?php echo esc_url( get_edit_user_link( $user_id ) ); ?>">
									<?php echo esc_html( $row->display_name ?: 'Utente #' . $user_id ); ?>
								</a>
							</td>
							<td><?php echo esc_html( $fmt_date( (string) $row->last_download ) ); ?></td>
							<td>
								<div class="dealer-bar-wrap">
									<span class="dealer-bar dealer-bar-alt" style="width:<?php echo esc_attr( $bar_width( $downloads, $max_user_downloads ) ); ?>%"></span>
									<span class="dealer-bar-value"><?php echo esc_html( number_format_i18n( $downloads ) ); ?></span>
								</div>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<?php endif; ?>
			</div>
		</div>

	</div>

	<!-- Documenti mai scaricati -->
	<div class="postbox">
		<h2 class="hndle">
			<span>
				Documenti mai scaricati
				<span class="dealer-badge-chain"><?php echo esc_html( number_format_i18n( $never_downloaded_total ) ); ?></span>
			</span>
		</h2>
		<div class="inside">
			<p class="description">
				Documenti attivi e visibili che non compaiono in nessuna riga di log: nessun dealer li ha mai scaricati.
				<?php if ( $docs_truncated ) : ?>
					<br><em>Analisi limitata ai primi 1000 documenti pubblicati.</em>
				<?php endif; ?>
			</p>
			<?php if ( empty( $never_downloaded ) ) : ?>
				<p><strong>Ottimo:</strong> ogni documento attivo è stato scaricato almeno una volta.</p>
			<?php else : ?>
			<table class="wp-list-table widefat striped dealer-stats-table">
				<thead>
					<tr>
						<th scope="col" style="width:45%">Documento</th>
						<th scope="col" style="width:15%">Brand</th>
						<th scope="col" style="width:15%">Tipo</th>
						<th scope="col" style="width:15%">Pubblicato il</th>
						<th scope="col"></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $never_downloaded as $doc_id ) :
					$doc_id    = (int) $doc_id;
					$doc_post  = get_post( $doc_id );
					if ( ! $doc_post ) { continue; }
					$doc_brand = get_post_meta( $doc_id, '_doc_brand', true );
					$doc_type  = get_post_meta( $doc_id, '_doc_type',  true );
					$type_lbl  = Dealer_Admin::get_doc_types()[ $doc_type ] ?? $doc_type;
					$edit_link = get_edit_post_link( $doc_id );
					?>
					<tr>
						<td><strong><?php echo esc_html( $doc_post->post_title ); ?></strong></td>
						<td><?php echo esc_html( (string) $doc_brand ); ?></td>
						<td><?php echo esc_html( (string) $type_lbl ); ?></td>
						<td><?php echo esc_html( get_the_date( 'd/m/Y', $doc_post ) ); ?></td>
						<td>
							<?php if ( $edit_link ) : ?>
								<a href="<?php echo esc_url( (string) $edit_link ); ?>" class="button button-small">Modifica</a>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php if ( $never_downloaded_total > count( $never_downloaded ) ) : ?>
				<p class="description">
					<?php
					printf(
						'Mostrati i primi %s di %s documenti mai scaricati.',
						esc_html( number_format_i18n( count( $never_downloaded ) ) ),
						esc_html( number_format_i18n( $never_downloaded_total ) )
					);
					?>
				</p>
			<?php endif; ?>
			<?php endif; ?>
		</div>
	</div>

	<!-- Dealer inattivi -->
	<div class="postbox">
		<h2 class="hndle">
			<span>
				Dealer inattivi (nessun download da oltre 90 giorni)
				<span class="dealer-badge-chain"><?php echo esc_html( number_format_i18n( $inactive_total ) ); ?></span>
			</span>
		</h2>
		<div class="inside">
			<p class="description">
				Utenti con ruolo Dealer, Top Dealer o Parts Center che non scaricano da più di 90 giorni — o che non hanno mai scaricato nulla.
			</p>
			<?php if ( empty( $inactive_dealers ) ) : ?>
				<p><strong>Nessun dealer inattivo:</strong> tutti hanno scaricato almeno un documento negli ultimi 90 giorni.</p>
			<?php else : ?>
			<table class="wp-list-table widefat striped dealer-stats-table">
				<thead>
					<tr>
						<th scope="col" style="width:28%">Dealer</th>
						<th scope="col" style="width:28%">Email</th>
						<th scope="col" style="width:18%">Ultimo download</th>
						<th scope="col" style="width:18%">Ultimo accesso</th>
						<th scope="col"></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $inactive_dealers as $dealer ) : ?>
					<tr>
						<td><strong><?php echo esc_html( $dealer['name'] ?: 'Utente #' . (int) $dealer['id'] ); ?></strong></td>
						<td><?php echo esc_html( $dealer['email'] ?: '—' ); ?></td>
						<td>
							<?php if ( $dealer['last_download'] ) : ?>
								<?php echo esc_html( $fmt_date( $dealer['last_download'] ) ); ?>
							<?php else : ?>
								<span class="dealer-badge-superseded" style="margin-left:0;">Mai</span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $fmt_date( (string) $dealer['last_login'] ) ); ?></td>
						<td>
							<a href="<?php echo esc_url( get_edit_user_link( (int) $dealer['id'] ) ); ?>" class="button button-small">Profilo</a>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php if ( $inactive_total > count( $inactive_dealers ) ) : ?>
				<p class="description">
					<?php
					printf(
						'Mostrati i primi %s di %s dealer inattivi.',
						esc_html( number_format_i18n( count( $inactive_dealers ) ) ),
						esc_html( number_format_i18n( $inactive_total ) )
					);
					?>
				</p>
			<?php endif; ?>
			<?php endif; ?>
		</div>
	</div>
</div>
