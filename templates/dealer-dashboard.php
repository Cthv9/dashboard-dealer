<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/**
 * Variabili da Dealer_Dashboard::render():
 * $user, $display_role, $role_labels, $last_login,
 * $recent_docs, $expiring_docs,
 * $ref_nome, $ref_email, $ref_telefono
 */

$role_colors = [
	'dealer'      => 'dealer-role-dealer',
	'top_dealer'  => 'dealer-role-top-dealer',
	'part_center' => 'dealer-role-part-center',
];
$role_color_class = $role_colors[ $display_role ] ?? '';

$search_url  = esc_url( site_url( '/dealer-search/' ) );
$last_login_fmt = $last_login ? gmdate( 'd/m/Y \a\l\l\e H:i', strtotime( $last_login ) ) : '—';
?>
<div class="dealer-dashboard-wrap">

	<!-- ── HERO ─────────────────────────────────────────────────────────── -->
	<div class="dealer-hero">
		<div class="dealer-hero-content">
			<p class="dealer-hero-welcome">Benvenuto nell'Area Riservata</p>
			<h1 class="dealer-hero-name"><?php echo esc_html( $user->display_name ); ?></h1>
			<?php if ( $display_role && isset( $role_labels[ $display_role ] ) ) : ?>
				<span class="dealer-role-badge <?php echo esc_attr( $role_color_class ); ?>">
					<?php echo esc_html( $role_labels[ $display_role ] ); ?>
				</span>
			<?php endif; ?>
		</div>
		<div class="dealer-hero-meta">
			<small>Ultimo accesso: <?php echo esc_html( $last_login_fmt ); ?></small>
		</div>
		<div class="dealer-hero-logout">
			<a href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>" class="dealer-logout-link">
				<span class="dashicons dashicons-exit"></span> Esci
			</a>
		</div>
	</div>

	<!-- ── ACCESSI RAPIDI ───────────────────────────────────────────────── -->
	<h2 class="dealer-section-title">Accessi Rapidi</h2>
	<div class="dealer-card-grid">

		<a class="dealer-card" href="<?php echo $search_url; ?>">
			<span class="dealer-card-icon dashicons dashicons-search"></span>
			<span class="dealer-card-title">Cerca Documenti</span>
			<span class="dealer-card-text">Ricerca full-text nei manuali, listini, schede tecniche e certificazioni.</span>
		</a>

		<a class="dealer-card" href="<?php echo esc_url( add_query_arg( 'orderby', 'date', $search_url ) ); ?>">
			<span class="dealer-card-icon dashicons dashicons-calendar-alt"></span>
			<span class="dealer-card-title">Documenti Recenti</span>
			<span class="dealer-card-text">Ultimi documenti aggiunti per le tue linee di prodotto.</span>
			<?php if ( ! empty( $recent_docs ) ) : ?>
				<span class="dealer-card-badge"><?php echo count( $recent_docs ); ?></span>
			<?php endif; ?>
		</a>

		<a class="dealer-card<?php echo empty( $expiring_docs ) ? ' dealer-card-disabled' : ''; ?>"
			href="<?php echo $search_url; ?>">
			<span class="dealer-card-icon dashicons dashicons-warning"></span>
			<span class="dealer-card-title">In Scadenza</span>
			<span class="dealer-card-text">Documenti che scadono nei prossimi 30 giorni.</span>
			<?php if ( ! empty( $expiring_docs ) ) : ?>
				<span class="dealer-card-badge dealer-card-badge-warn"><?php echo count( $expiring_docs ); ?></span>
			<?php endif; ?>
		</a>

	</div>

	<!-- ── DOCUMENTI RECENTI ─────────────────────────────────────────────── -->
	<?php if ( ! empty( $recent_docs ) ) : ?>
	<h2 class="dealer-section-title">Ultimi Documenti Aggiunti</h2>
	<div class="dealer-doc-feed">
		<?php foreach ( $recent_docs as $doc ) :
			$brand    = get_post_meta( $doc->ID, '_doc_brand',       true );
			$line     = get_post_meta( $doc->ID, '_doc_product_line', true );
			$type_key = get_post_meta( $doc->ID, '_doc_type',        true );
			$filename = get_post_meta( $doc->ID, '_doc_filename',    true );
			$type_lbl = Dealer_Admin::get_doc_types()[ $type_key ] ?? $type_key;
			$is_new   = Dealer_Search::is_new_doc( $doc->ID );
			$dl_url   = Dealer_Search::get_download_url( $doc->ID );
			$icon     = Dealer_Search::get_file_icon_svg( $filename ?: '' );
			?>
			<div class="dealer-feed-item">
				<div class="dealer-feed-icon" aria-hidden="true"><?php echo $icon; ?></div>
				<div class="dealer-feed-body">
					<span class="dealer-feed-title"><?php echo esc_html( $doc->post_title ); ?></span>
					<span class="dealer-feed-meta">
						<?php echo esc_html( implode( ' — ', array_filter( [ $brand, $line, $type_lbl ] ) ) ); ?>
					</span>
					<span class="dealer-feed-date"><?php echo esc_html( gmdate( 'd/m/Y', strtotime( $doc->post_date ) ) ); ?></span>
				</div>
				<div class="dealer-feed-actions">
					<?php if ( $is_new ) : ?>
						<span class="dealer-badge dealer-badge-new">NUOVO</span>
					<?php endif; ?>
					<a href="<?php echo esc_url( $dl_url ); ?>" class="dealer-btn dealer-btn-sm">
						<span class="dashicons dashicons-download"></span>
					</a>
				</div>
			</div>
		<?php endforeach; ?>
	</div>
	<?php endif; ?>

	<!-- ── DOCUMENTI IN SCADENZA ─────────────────────────────────────────── -->
	<?php if ( ! empty( $expiring_docs ) ) : ?>
	<h2 class="dealer-section-title dealer-title-warn">
		<span class="dashicons dashicons-warning"></span> Documenti in Scadenza
	</h2>
	<div class="dealer-doc-feed">
		<?php foreach ( $expiring_docs as $doc ) :
			$expiry   = get_post_meta( $doc->ID, '_doc_expiry',   true );
			$brand    = get_post_meta( $doc->ID, '_doc_brand',    true );
			$filename = get_post_meta( $doc->ID, '_doc_filename', true );
			$dl_url   = Dealer_Search::get_download_url( $doc->ID );
			$icon     = Dealer_Search::get_file_icon_svg( $filename ?: '' );
			?>
			<div class="dealer-feed-item dealer-feed-expiring">
				<div class="dealer-feed-icon" aria-hidden="true"><?php echo $icon; ?></div>
				<div class="dealer-feed-body">
					<span class="dealer-feed-title"><?php echo esc_html( $doc->post_title ); ?></span>
					<span class="dealer-feed-meta"><?php echo esc_html( $brand ); ?></span>
					<span class="dealer-feed-date" style="color:#e67e22;">
						Scade il <?php echo esc_html( gmdate( 'd/m/Y', strtotime( $expiry ) ) ); ?>
					</span>
				</div>
				<div class="dealer-feed-actions">
					<span class="dealer-badge dealer-badge-expiring">IN SCADENZA</span>
					<a href="<?php echo esc_url( $dl_url ); ?>" class="dealer-btn dealer-btn-sm">
						<span class="dashicons dashicons-download"></span>
					</a>
				</div>
			</div>
		<?php endforeach; ?>
	</div>
	<?php endif; ?>

	<!-- ── REFERENTE COMMERCIALE ─────────────────────────────────────────── -->
	<?php if ( $ref_nome || $ref_email || $ref_telefono ) : ?>
	<h2 class="dealer-section-title">Il Tuo Referente</h2>
	<div class="dealer-referente-card">
		<div class="dealer-referente-icon" aria-hidden="true">
			<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="40" height="40" fill="none" stroke="#1e6fa8" stroke-width="1.5">
				<circle cx="12" cy="8" r="4"/>
				<path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/>
			</svg>
		</div>
		<div class="dealer-referente-info">
			<?php if ( $ref_nome ) : ?>
				<strong class="dealer-referente-name"><?php echo esc_html( $ref_nome ); ?></strong>
			<?php endif; ?>
			<?php if ( $ref_email ) : ?>
				<a href="mailto:<?php echo esc_attr( $ref_email ); ?>" class="dealer-referente-link">
					<span class="dashicons dashicons-email-alt"></span> <?php echo esc_html( $ref_email ); ?>
				</a>
			<?php endif; ?>
			<?php if ( $ref_telefono ) : ?>
				<a href="tel:<?php echo esc_attr( preg_replace( '/[^0-9+]/', '', $ref_telefono ) ); ?>" class="dealer-referente-link">
					<span class="dashicons dashicons-phone"></span> <?php echo esc_html( $ref_telefono ); ?>
				</a>
			<?php endif; ?>
		</div>
	</div>
	<?php else : ?>
	<div class="dealer-referente-card dealer-referente-placeholder">
		<p>Il referente commerciale non è ancora stato configurato dall'amministratore.</p>
	</div>
	<?php endif; ?>

</div><!-- .dealer-dashboard-wrap -->
