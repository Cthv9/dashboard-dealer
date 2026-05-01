<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
// Variabili fornite da Dealer_Admin::render_archive():
// $documents (array WP_Post), $total_pages (int), $paged (int),
// $filter_brand, $filter_type, $filter_role, $filter_line, $filter_status

$brands    = Dealer_Admin::get_brands();
$doc_types = Dealer_Admin::get_doc_types();
$today     = gmdate( 'Y-m-d' );
$in30      = gmdate( 'Y-m-d', strtotime( '+30 days' ) );
?>
<div class="wrap dealer-admin-wrap">
	<h1 class="wp-heading-inline">
		<span class="dashicons dashicons-list-view" style="vertical-align:middle;margin-right:6px;"></span>
		Archivio Documenti
	</h1>
	<a href="<?php echo esc_url( admin_url( 'admin.php?page=dealer-portal' ) ); ?>" class="page-title-action">+ Carica nuovo</a>

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
				<option value="part_center"<?php selected( $filter_role, 'part_center' ); ?>>Part Center</option>
			</select>

			<select name="filter_status">
				<option value="">Attivi</option>
				<option value="obsoleto"<?php selected( $filter_status, 'obsoleto' ); ?>>Obsoleti</option>
			</select>

			<button type="submit" class="button">Filtra</button>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=dealer-portal-archive' ) ); ?>" class="button">Reset</a>
		</div>
	</form>

	<?php if ( empty( $documents ) ) : ?>
		<div class="notice notice-info" style="margin-top:20px;"><p>Nessun documento trovato con i filtri selezionati.</p></div>
	<?php else : ?>

	<table class="wp-list-table widefat fixed striped dealer-archive-table">
		<thead>
			<tr>
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

			$expiry_class = '';
			if ( $expiry ) {
				if ( $expiry < $today ) {
					$expiry_class = 'dealer-expired';
				} elseif ( $expiry <= $in30 ) {
					$expiry_class = 'dealer-expiring-soon';
				}
			}
			?>
			<tr id="doc-row-<?php echo esc_attr( $doc->ID ); ?>">
				<td>
					<strong><?php echo esc_html( $doc->post_title ); ?></strong>
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
				<td class="dealer-actions-cell">
					<a href="<?php echo esc_url( get_edit_post_link( $doc->ID ) ); ?>" class="button button-small" title="Modifica post WP">Modifica</a>
					<button type="button"
						class="button button-small dealer-toggle-log-btn"
						data-post="<?php echo esc_attr( $doc->ID ); ?>"
						aria-expanded="false">Log</button>
					<?php if ( $status !== 'obsoleto' ) : ?>
					<button type="button"
						class="button button-small dealer-obsolete-btn"
						data-post="<?php echo esc_attr( $doc->ID ); ?>">Obsoleto</button>
					<?php endif; ?>
					<button type="button"
						class="button button-small button-link-delete dealer-delete-btn"
						data-post="<?php echo esc_attr( $doc->ID ); ?>">Elimina</button>
				</td>
			</tr>
			<!-- Riga log download (nascosta, popolata da JS) -->
			<tr class="dealer-log-row" id="log-row-<?php echo esc_attr( $doc->ID ); ?>" style="display:none;">
				<td colspan="8">
					<div class="dealer-log-inner">
						<strong>Log download:</strong>
						<?php
						$logs = Dealer_DB::get_logs_for_post( $doc->ID, 20 );
						if ( empty( $logs ) ) {
							echo '<em>Nessun download registrato.</em>';
						} else {
							echo '<table class="dealer-log-table"><tr><th>Utente</th><th>Data</th><th>IP</th></tr>';
							foreach ( $logs as $log ) {
								echo '<tr>';
								echo '<td>' . esc_html( $log->display_name ) . '</td>';
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
		if ( $filter_status ) { $base_url = add_query_arg( 'filter_status', $filter_status, $base_url ); }
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
