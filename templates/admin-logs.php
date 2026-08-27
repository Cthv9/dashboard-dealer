<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
// Variabili fornite da Dealer_Admin::render_logs():
// $logs (array), $filters (array: doc/user/from/to/args), $paged, $per_page,
// $total_logs, $total_pages, $log_documents (WP_Post[]), $log_dealers (object[]),
// $export_url (string, include il nonce dedicato all'export)

$has_filters = ( $filters['doc'] || $filters['user'] || $filters['from'] || $filters['to'] );

// URL di base per la paginazione: conserva i filtri attivi.
$logs_base_url = admin_url( 'admin.php?page=dealer-portal-logs' );
if ( $filters['doc'] )  { $logs_base_url = add_query_arg( 'filter_doc',  $filters['doc'],  $logs_base_url ); }
if ( $filters['user'] ) { $logs_base_url = add_query_arg( 'filter_user', $filters['user'], $logs_base_url ); }
if ( $filters['from'] ) { $logs_base_url = add_query_arg( 'filter_from', $filters['from'], $logs_base_url ); }
if ( $filters['to'] )   { $logs_base_url = add_query_arg( 'filter_to',   $filters['to'],   $logs_base_url ); }

$first_row = $total_logs ? ( ( $paged - 1 ) * $per_page ) + 1 : 0;
$last_row  = min( $paged * $per_page, $total_logs );
?>
<div class="wrap dealer-admin-wrap">
	<h1 class="wp-heading-inline">
		<span class="dashicons dashicons-download" style="vertical-align:middle;margin-right:6px;"></span>
		Log Download
	</h1>
	<a href="<?php echo esc_url( $export_url ); ?>" class="page-title-action">
		<span class="dashicons dashicons-media-spreadsheet" style="vertical-align:text-top;font-size:16px;height:16px;width:16px;"></span>
		Esporta CSV
	</a>
	<p class="description" style="margin-top:8px;">
		Registro dei download effettuati dai dealer. L'export CSV rispetta i filtri attivi ed esporta
		<strong>tutte</strong> le righe corrispondenti, non solo quelle visualizzate in pagina.
	</p>
	<?php // isset() è falso anche quando la chiave c'è ma vale null: null = nessuna restrizione. ?>
	<?php if ( isset( $filters['args']['post_ids'] ) ) : ?>
		<p class="description">
			<span class="dashicons dashicons-visibility" style="vertical-align:middle;"></span>
			Sono elencati i download dei soli documenti che ricadono nel tuo perimetro di linee.
			L'export CSV esporta esattamente lo stesso sottoinsieme.
		</p>
	<?php endif; ?>

	<!-- Filtri -->
	<form method="GET" id="dealer-logs-filter">
		<input type="hidden" name="page" value="dealer-portal-logs">
		<div class="dealer-filter-bar">
			<label for="filter_from" class="screen-reader-text">Dal giorno</label>
			<span class="dealer-filter-date">
				Dal
				<input type="date" id="filter_from" name="filter_from" value="<?php echo esc_attr( $filters['from'] ); ?>">
			</span>

			<label for="filter_to" class="screen-reader-text">Al giorno</label>
			<span class="dealer-filter-date">
				al
				<input type="date" id="filter_to" name="filter_to" value="<?php echo esc_attr( $filters['to'] ); ?>">
			</span>

			<label for="filter_doc" class="screen-reader-text">Documento</label>
			<select id="filter_doc" name="filter_doc">
				<option value="">Tutti i documenti</option>
				<?php foreach ( $log_documents as $doc_option ) : ?>
					<option value="<?php echo esc_attr( $doc_option->ID ); ?>"<?php selected( $filters['doc'], (int) $doc_option->ID ); ?>>
						<?php echo esc_html( $doc_option->post_title ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<label for="filter_user" class="screen-reader-text">Dealer</label>
			<select id="filter_user" name="filter_user">
				<option value="">Tutti i dealer</option>
				<?php foreach ( $log_dealers as $dealer_option ) : ?>
					<option value="<?php echo esc_attr( $dealer_option->ID ); ?>"<?php selected( $filters['user'], (int) $dealer_option->ID ); ?>>
						<?php echo esc_html( $dealer_option->display_name ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<button type="submit" class="button">Filtra</button>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=dealer-portal-logs' ) ); ?>" class="button">Reset</a>
		</div>
	</form>

	<?php if ( empty( $logs ) ) : ?>
		<div class="notice notice-info" style="margin-top:20px;">
			<p><?php echo $has_filters ? 'Nessun download corrisponde ai filtri selezionati.' : 'Nessun download registrato finora.'; ?></p>
		</div>
	<?php else : ?>

	<div class="tablenav top">
		<div class="tablenav-pages">
			<span class="displaying-num">
				<?php
				printf(
					'%s download — righe %s–%s',
					esc_html( number_format_i18n( $total_logs ) ),
					esc_html( number_format_i18n( $first_row ) ),
					esc_html( number_format_i18n( $last_row ) )
				);
				?>
			</span>
		</div>
	</div>

	<table class="wp-list-table widefat fixed striped">
		<thead>
			<tr>
				<th scope="col" style="width:26%">Documento</th>
				<th scope="col" style="width:17%">Dealer</th>
				<th scope="col" style="width:19%">Email</th>
				<th scope="col" style="width:12%">Titolo di accesso</th>
				<th scope="col" style="width:14%">Data e Ora</th>
				<th scope="col" style="width:12%">Indirizzo IP</th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $logs as $log ) : ?>
			<tr>
				<td>
					<?php if ( $log->post_title ) : ?>
						<a href="<?php echo esc_url( (string) get_edit_post_link( (int) $log->post_id ) ); ?>">
							<?php echo esc_html( $log->post_title ); ?>
						</a>
					<?php else : ?>
						<em style="color:#888;">Post eliminato (ID <?php echo esc_html( $log->post_id ); ?>)</em>
					<?php endif; ?>
				</td>
				<td><?php echo esc_html( $log->display_name ?: 'Utente #' . $log->user_id ); ?></td>
				<td><?php echo esc_html( $log->user_email ?: '—' ); ?></td>
				<?php
				// Titolo con cui è avvenuto il download. Le righe anteriori
				// alla colonna hanno il valore vuoto: allora scaricava solo la
				// rete dealer, quindi vengono mostrate come "Dealer" — nessun
				// dato è stato riscritto nel registro.
				$log_context = (string) ( $log->access_context ?? '' );
				?>
				<td>
					<span class="dealer-context-pill dealer-context-<?php echo esc_attr( $log_context ?: 'dealer' ); ?>">
						<?php echo esc_html( Dealer_DB::access_context_label( $log_context ) ); ?>
					</span>
				</td>
				<td><?php echo esc_html( gmdate( 'd/m/Y H:i', strtotime( (string) $log->download_date ) ) ); ?></td>
				<td><code><?php echo esc_html( $log->ip_address ?: '—' ); ?></code></td>
			</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<?php if ( $total_pages > 1 ) : ?>
		<div class="tablenav bottom" style="margin-top:12px;">
			<div class="tablenav-pages">
				<?php
				echo wp_kses_post( (string) paginate_links( [
					'base'    => $logs_base_url . '%_%',
					'format'  => '&paged=%#%',
					'current' => $paged,
					'total'   => $total_pages,
				] ) );
				?>
			</div>
		</div>
	<?php endif; ?>

	<?php endif; ?>
</div>
