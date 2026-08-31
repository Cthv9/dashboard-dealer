<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
// Variabili fornite da Dealer_Admin::render_archive():
// $documents (array WP_Post), $total_pages (int), $paged (int),
// $filter_brand, $filter_type, $filter_role, $filter_line, $filter_status,
// $filter_versions ('' = solo versioni correnti, 'all' = incluse le superate),
// $bulk_summary (array|null: riepilogo dell'ultima operazione di gruppo),
// $restrict_scope (bool: elenco limitato al perimetro dell'utente),
// $scan_truncated (bool: la scansione ha raggiunto il tetto SCOPE_SCAN_LIMIT)

$brands    = Dealer_Admin::get_brands();
$doc_types = Dealer_Admin::get_doc_types();
$today     = gmdate( 'Y-m-d' );
$in30      = gmdate( 'Y-m-d', strtotime( '+30 days' ) );

// L'archivio è visibile a chi può caricare documenti (area manager compreso),
// ma le azioni distruttive restano dell'amministratore: eliminazione definitiva
// e azioni di gruppo. Nasconderle qui evita pulsanti che il server rifiuta —
// l'autorizzazione vera resta nei rispettivi handler AJAX.
$can_manage   = current_user_can( DEALER_PORTAL_CAP );
$current_user = wp_get_current_user();
$colspan      = $can_manage ? 9 : 8;
?>
<div class="wrap dealer-admin-wrap">
	<h1 class="wp-heading-inline">
		<span class="dashicons dashicons-list-view" style="vertical-align:middle;margin-right:6px;"></span>
		Archivio Documenti
	</h1>
	<a href="<?php echo esc_url( admin_url( 'admin.php?page=dealer-portal' ) ); ?>" class="page-title-action">+ Carica nuovo</a>

	<?php if ( ! empty( $restrict_scope ) ) : ?>
		<p class="description" style="margin-top:8px;">
			<span class="dashicons dashicons-visibility" style="vertical-align:middle;"></span>
			Stai vedendo solo i documenti che ricadono sulle linee del tuo perimetro.
			I documenti senza restrizione di linea sono di competenza dell'amministratore.
		</p>
	<?php endif; ?>

	<?php if ( ! empty( $scan_truncated ) ) : ?>
		<div class="notice notice-warning" style="margin-top:16px;">
			<p>
				<?php
				printf(
					'Elenco limitato ai primi %s documenti più recenti fra quelli che corrispondono ai filtri. Restringi i filtri per vedere il resto.',
					esc_html( number_format_i18n( Dealer_Admin::SCOPE_SCAN_LIMIT ) )
				);
				?>
			</p>
		</div>
	<?php endif; ?>

	<?php
	// ─── Riepilogo dell'ultima operazione di gruppo ──────────────────────────
	if ( ! empty( $bulk_summary ) ) :
		$b_skipped = $bulk_summary['invalid'] + $bulk_summary['already'] + $bulk_summary['failed'];
		$b_class   = $b_skipped ? 'notice-warning' : 'notice-success';
		$b_verb    = ( 'delete' === $bulk_summary['op'] ) ? 'eliminati' : 'segnati come obsoleti';
		?>
		<div class="notice <?php echo esc_attr( $b_class ); ?> is-dismissible" style="margin-top:16px;">
			<p>
				<strong>
					<?php
					printf(
						'%d document%s %s.',
						(int) $bulk_summary['processed'],
						1 === (int) $bulk_summary['processed'] ? 'o' : 'i',
						esc_html( $b_verb )
					);
					?>
				</strong>
			</p>
			<?php if ( $b_skipped || $bulk_summary['promoted'] || $bulk_summary['no_succ'] ) : ?>
			<ul style="margin:0 0 10px 20px;list-style:disc;">
				<?php if ( $bulk_summary['invalid'] ) : ?>
					<li><?php printf( '%d ID ignorat%s perché non corrispondono a un documento valido.', (int) $bulk_summary['invalid'], 1 === (int) $bulk_summary['invalid'] ? 'o' : 'i' ); ?></li>
				<?php endif; ?>
				<?php if ( $bulk_summary['already'] ) : ?>
					<li><?php printf( '%d document%s salta%s perché già obsolet%s.', (int) $bulk_summary['already'], 1 === (int) $bulk_summary['already'] ? 'o' : 'i', 1 === (int) $bulk_summary['already'] ? 'to' : 'ti', 1 === (int) $bulk_summary['already'] ? 'o' : 'i' ); ?></li>
				<?php endif; ?>
				<?php if ( $bulk_summary['failed'] ) : ?>
					<li><?php printf( '%d eliminazion%s non riuscit%s.', (int) $bulk_summary['failed'], 1 === (int) $bulk_summary['failed'] ? 'e' : 'i', 1 === (int) $bulk_summary['failed'] ? 'a' : 'e' ); ?></li>
				<?php endif; ?>
				<?php if ( $bulk_summary['promoted'] ) : ?>
					<li><?php printf( '%d version%s precedent%s tornat%s corrent%s nella propria catena.', (int) $bulk_summary['promoted'], 1 === (int) $bulk_summary['promoted'] ? 'e' : 'i', 1 === (int) $bulk_summary['promoted'] ? 'e' : 'i', 1 === (int) $bulk_summary['promoted'] ? 'a' : 'e', 1 === (int) $bulk_summary['promoted'] ? 'e' : 'i' ); ?></li>
				<?php endif; ?>
				<?php if ( $bulk_summary['no_succ'] ) : ?>
					<li><?php printf( '%d catena/e senza versione promuovibile: tutte le versioni erano nella selezione o già obsolete.', (int) $bulk_summary['no_succ'] ); ?></li>
				<?php endif; ?>
			</ul>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<!-- Filtri -->
	<form method="GET" id="dealer-archive-filter">
		<input type="hidden" name="page" value="dealer-portal-archive">
		<div class="dealer-filter-bar">
			<select name="filter_brand">
				<option value="">Tutti i brand</option>
				<?php foreach ( $brands as $b ) : ?>
					<option value="<?php echo esc_attr( $b ); ?>"<?php selected( $filter_brand, $b ); ?>><?php echo esc_html( $b ); ?></option>
				<?php endforeach; ?>
			</select>

			<select name="filter_type">
				<option value="">Tutti i tipi</option>
				<?php foreach ( $doc_types as $key => $label ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>"<?php selected( $filter_type, $key ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>

			<select name="filter_role">
				<option value="">Tutti i ruoli</option>
				<option value="dealer"<?php selected( $filter_role, 'dealer' ); ?>>Dealer</option>
				<option value="top_dealer"<?php selected( $filter_role, 'top_dealer' ); ?>>Top Dealer</option>
				<option value="part_center"<?php selected( $filter_role, 'part_center' ); ?>>Parts Center</option>
			</select>

			<select name="filter_status">
				<option value="">Attivi</option>
				<option value="obsoleto"<?php selected( $filter_status, 'obsoleto' ); ?>>Obsoleti</option>
			</select>

			<select name="filter_versions" title="Versioni superate">
				<option value="">Solo versioni correnti</option>
				<option value="all"<?php selected( $filter_versions, 'all' ); ?>>Includi versioni superate</option>
			</select>

			<button type="submit" class="button">Filtra</button>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=dealer-portal-archive' ) ); ?>" class="button">Reset</a>
		</div>
	</form>

	<?php if ( empty( $documents ) ) : ?>
		<div class="notice notice-info" style="margin-top:20px;"><p>Nessun documento trovato con i filtri selezionati.</p></div>
	<?php else : ?>

	<!-- Azioni di gruppo (pattern wp-list-table) — solo amministratore -->
	<?php if ( $can_manage ) : ?>
	<div class="tablenav top">
		<div class="alignleft actions bulkactions">
			<label for="dealer-bulk-action" class="screen-reader-text">Seleziona un'azione di gruppo</label>
			<select id="dealer-bulk-action">
				<option value="">Azioni di gruppo</option>
				<option value="obsolete">Segna come obsoleti</option>
				<option value="delete">Elimina definitivamente</option>
			</select>
			<button type="button" class="button action" id="dealer-bulk-apply">Applica</button>
			<span id="dealer-bulk-count" class="dealer-bulk-count" aria-live="polite">Nessun documento selezionato</span>
		</div>
	</div>
	<?php endif; ?>

	<table class="wp-list-table widefat fixed striped dealer-archive-table">
		<thead>
			<tr>
				<?php if ( $can_manage ) : ?>
				<td class="manage-column column-cb check-column">
					<label class="screen-reader-text" for="dealer-cb-select-all">Seleziona tutti i documenti</label>
					<input type="checkbox" id="dealer-cb-select-all">
				</td>
				<?php endif; ?>
				<th scope="col" style="width:28%">Documento</th>
				<th scope="col" style="width:12%">Brand</th>
				<th scope="col" style="width:10%">Tipo</th>
				<th scope="col" style="width:8%">Anno</th>
				<th scope="col" style="width:14%">Ruoli</th>
				<th scope="col" style="width:14%">Linee</th>
				<th scope="col" style="width:10%">Scadenza</th>
				<th scope="col" style="width:14%">Azioni</th>
			</tr>
		</thead>
		<tbody>
		<?php foreach ( $documents as $doc ) :
			$brand    = esc_html( get_post_meta( $doc->ID, '_doc_brand',        true ) );
			$type_key = get_post_meta( $doc->ID, '_doc_type',         true );
			$year     = get_post_meta( $doc->ID, '_doc_year',         true );
			$roles    = (array) get_post_meta( $doc->ID, '_doc_roles',  true );
			$lines    = (array) get_post_meta( $doc->ID, '_doc_lines',  true );
			$expiry   = get_post_meta( $doc->ID, '_doc_expiry',       true );
			$status   = get_post_meta( $doc->ID, '_doc_status',       true );
			$filename = get_post_meta( $doc->ID, '_doc_filename',     true );
			$type_lbl = Dealer_Admin::get_doc_types()[ $type_key ] ?? $type_key;

			// Catena di versioni (dimensione indipendente da _doc_status).
			$v_seq        = Dealer_Versioning::get_sequence( (int) $doc->ID );
			$v_is_current = Dealer_Versioning::is_current( (int) $doc->ID );
			$v_chain      = Dealer_Versioning::get_chain( (int) $doc->ID );
			$v_total      = count( $v_chain );

			$expiry_class = '';
			if ( $expiry ) {
				if ( $expiry < $today ) {
					$expiry_class = 'dealer-expired';
				} elseif ( $expiry <= $in30 ) {
					$expiry_class = 'dealer-expiring-soon';
				}
			}
			?>
			<tr id="doc-row-<?php echo esc_attr( $doc->ID ); ?>"<?php echo $v_is_current ? '' : ' class="dealer-row-superseded"'; ?>>
				<?php if ( $can_manage ) : ?>
				<th scope="row" class="check-column">
					<label class="screen-reader-text" for="dealer-cb-<?php echo esc_attr( $doc->ID ); ?>">
						Seleziona <?php echo esc_html( $doc->post_title ); ?>
					</label>
					<input type="checkbox"
						class="dealer-bulk-cb"
						id="dealer-cb-<?php echo esc_attr( $doc->ID ); ?>"
						value="<?php echo esc_attr( $doc->ID ); ?>">
				</th>
				<?php endif; ?>
				<td class="dealer-doc-title">
					<strong><?php echo esc_html( $doc->post_title ); ?></strong>
					<span class="dealer-badge-version" title="Versione <?php echo esc_attr( $v_seq ); ?> della catena">v<?php echo esc_html( $v_seq ); ?></span>
					<?php if ( ! $v_is_current ) : ?>
						<span class="dealer-badge-superseded">Versione superata</span>
					<?php endif; ?>
					<?php if ( $v_total > 1 ) : ?>
						<span class="dealer-badge-chain" title="Documenti nella catena di versioni"><?php echo esc_html( $v_total ); ?> versioni</span>
					<?php endif; ?>
					<?php if ( $filename ) : ?>
						<br><small style="color:#888;"><?php echo esc_html( $filename ); ?></small>
					<?php endif; ?>
					<?php if ( $status === 'obsoleto' ) : ?>
						<span class="dealer-badge-obsoleto">Obsoleto</span>
					<?php endif; ?>
				</td>
				<td><?php echo esc_html( $brand ); ?></td>
				<td><span class="dealer-type-badge dealer-type-<?php echo esc_attr( $type_key ); ?>"><?php echo esc_html( $type_lbl ); ?></span></td>
				<td><?php echo esc_html( $year ); ?></td>
				<td>
					<?php foreach ( $roles as $r ) :
						$r_labels = [ 'dealer' => 'D', 'top_dealer' => 'TD', 'part_center' => 'PC' ];
						?>
						<span class="dealer-role-pill dealer-role-<?php echo esc_attr( $r ); ?>" title="<?php echo esc_attr( ucfirst( str_replace( '_', ' ', $r ) ) ); ?>"><?php echo esc_html( $r_labels[ $r ] ?? $r ); ?></span>
					<?php endforeach; ?>
				</td>
				<td>
					<?php if ( ! empty( $lines ) && $lines[0] ) : ?>
						<small><?php echo esc_html( implode( ', ', array_map( static fn( $l ) => str_replace( '|', ' › ', $l ), $lines ) ) ); ?></small>
					<?php else : ?>
						<small style="color:#888;">Tutte</small>
					<?php endif; ?>
				</td>
				<td class="<?php echo esc_attr( $expiry_class ); ?>">
					<?php echo $expiry ? esc_html( gmdate( 'd/m/Y', strtotime( $expiry ) ) ) : '—'; ?>
				</td>
				<?php
				// Obsoleto/nuova versione: consentiti dentro il perimetro.
				// Un documento può essere visibile (una sua linea è nel
				// perimetro) senza essere modificabile (ne ha altre fuori):
				// in quel caso resta in sola lettura, e le azioni di modifica
				// spariscono invece di comparire e farsi rifiutare dal server.
				$can_touch = Dealer_Identity::can_edit_document( $current_user, (int) $doc->ID );
				// Modifica nell'editor WP: solo se l'utente ha davvero i
				// permessi sul post, altrimenti get_edit_post_link() è vuoto e
				// il link porterebbe su un href="".
				$edit_link = $can_touch ? get_edit_post_link( $doc->ID ) : '';
				?>
				<td class="dealer-actions-cell">
					<?php if ( $edit_link ) : ?>
					<a href="<?php echo esc_url( $edit_link ); ?>" class="button button-small" title="Modifica post WP">Modifica</a>
					<?php endif; ?>
					<button type="button"
						class="button button-small dealer-toggle-log-btn"
						data-post="<?php echo esc_attr( $doc->ID ); ?>"
						aria-expanded="false">Log</button>
					<?php if ( $v_total > 1 ) : ?>
					<button type="button"
						class="button button-small dealer-toggle-chain-btn"
						data-post="<?php echo esc_attr( $doc->ID ); ?>"
						aria-expanded="false"
						aria-controls="chain-row-<?php echo esc_attr( $doc->ID ); ?>"
						title="Mostra lo storico delle versioni">
						<span class="dashicons dashicons-backup" style="vertical-align:text-top;font-size:16px;height:16px;width:16px;"></span>
						Versioni
					</button>
					<?php endif; ?>
					<?php if ( $status !== 'obsoleto' && $can_touch ) : ?>
					<button type="button"
						class="button button-small dealer-obsolete-btn"
						data-post="<?php echo esc_attr( $doc->ID ); ?>">Obsoleto</button>
					<?php endif; ?>
					<?php if ( $can_manage ) : ?>
					<button type="button"
						class="button button-small button-link-delete dealer-delete-btn"
						data-post="<?php echo esc_attr( $doc->ID ); ?>">Elimina</button>
					<?php endif; ?>
				</td>
			</tr>
			<?php if ( $v_total > 1 ) : ?>
			<!-- Riga storico versioni (stesso pattern della riga log) -->
			<tr class="dealer-log-row dealer-chain-row" id="chain-row-<?php echo esc_attr( $doc->ID ); ?>" style="display:none;">
				<td colspan="<?php echo esc_attr( $colspan ); ?>">
					<div class="dealer-log-inner">
						<strong>Storico versioni:</strong>
						<table class="dealer-log-table">
							<tr>
								<th>Versione</th>
								<th>Documento</th>
								<th>Etichetta</th>
								<th>Data</th>
								<th>Stato</th>
								<th></th>
							</tr>
							<?php foreach ( $v_chain as $cdoc ) :
								$c_id      = (int) $cdoc->ID;
								$c_seq     = Dealer_Versioning::get_sequence( $c_id );
								$c_current = Dealer_Versioning::is_current( $c_id );
								$c_label   = get_post_meta( $c_id, '_doc_version', true );
								// Stessa regola della riga principale: si
								// modifica solo dentro il proprio perimetro.
								$c_edit    = Dealer_Identity::can_edit_document( $current_user, $c_id )
									? get_edit_post_link( $c_id )
									: '';
								?>
								<tr<?php echo $c_id === (int) $doc->ID ? ' class="dealer-chain-self"' : ''; ?>>
									<td>v<?php echo esc_html( $c_seq ); ?></td>
									<td><?php echo esc_html( $cdoc->post_title ); ?></td>
									<td><?php echo $c_label ? esc_html( $c_label ) : '—'; ?></td>
									<td><?php echo esc_html( get_the_date( 'd/m/Y H:i', $cdoc ) ); ?></td>
									<td>
										<?php if ( $c_current ) : ?>
											<span class="dealer-badge-current">Corrente</span>
										<?php else : ?>
											<span class="dealer-badge-superseded">Superata</span>
										<?php endif; ?>
									</td>
									<td>
										<?php if ( $c_edit ) : ?>
											<a href="<?php echo esc_url( $c_edit ); ?>">Modifica</a>
										<?php else : ?>
											—
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</table>
					</div>
				</td>
			</tr>
			<?php endif; ?>
			<!-- Riga log download (nascosta, popolata da JS) -->
			<tr class="dealer-log-row" id="log-row-<?php echo esc_attr( $doc->ID ); ?>" style="display:none;">
				<td colspan="<?php echo esc_attr( $colspan ); ?>">
					<div class="dealer-log-inner">
						<strong>Log download:</strong>
						<?php
						$logs = Dealer_DB::get_logs_for_post( $doc->ID, 20 );
						if ( empty( $logs ) ) {
							echo '<em>Nessun download registrato.</em>';
						} else {
							echo '<table class="dealer-log-table"><tr><th>Utente</th><th>Titolo</th><th>Data</th><th>IP</th></tr>';
							foreach ( $logs as $log ) {
								echo '<tr>';
								echo '<td>' . esc_html( $log->display_name ) . '</td>';
								echo '<td>' . esc_html( Dealer_DB::access_context_label( (string) ( $log->access_context ?? '' ) ) ) . '</td>';
								echo '<td>' . esc_html( gmdate( 'd/m/Y H:i', strtotime( $log->download_date ) ) ) . '</td>';
								echo '<td>' . esc_html( $log->ip_address ) . '</td>';
								echo '</tr>';
							}
							echo '</table>';
						}
						?>
					</div>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>

	<?php if ( $total_pages > 1 ) :
		$base_url = admin_url( 'admin.php?page=dealer-portal-archive' );
		if ( $filter_brand )  { $base_url = add_query_arg( 'filter_brand',  $filter_brand,  $base_url ); }
		if ( $filter_type )   { $base_url = add_query_arg( 'filter_type',   $filter_type,   $base_url ); }
		if ( $filter_role )   { $base_url = add_query_arg( 'filter_role',   $filter_role,   $base_url ); }
		if ( $filter_status )   { $base_url = add_query_arg( 'filter_status',   $filter_status,   $base_url ); }
		if ( $filter_line )     { $base_url = add_query_arg( 'filter_line',     $filter_line,     $base_url ); }
		if ( $filter_versions ) { $base_url = add_query_arg( 'filter_versions', $filter_versions, $base_url ); }
		?>
		<div class="tablenav bottom" style="margin-top:12px;">
			<?php
			echo paginate_links( [
				'base'    => $base_url . '%_%',
				'format'  => '&paged=%#%',
				'current' => $paged,
				'total'   => $total_pages,
			] );
			?>
		</div>
	<?php endif; ?>

	<?php endif; // empty $documents ?>
</div>
