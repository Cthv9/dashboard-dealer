<?php
/**
 * Assegnazione del perimetro (linee + organizzazioni) a un area manager.
 * Dealer Portal → Area Manager → Assegna perimetro
 *
 * Variabili fornite da Dealer_Org_Admin::render_area_manager_edit():
 * @var \WP_User $user
 * @var array    $roots           Radici + eventuali assegnate non-radice: id, name, children, orphan, path
 * @var int[]    $selected_orgs   Organizzazioni gia' assegnate (_am_orgs)
 * @var string[] $selected_lines  Linee gia' assegnate (_am_lines)
 * @var array    $lines_by_brand
 * @var array    $notice
 * @var string   $base_url        Elenco area manager
 * @var string   $orgs_url        Schermata Organizzazioni
 * @var string   $post_url
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! Dealer_DB::user_can( DEALER_PORTAL_CAP_ORGS ) ) {
	wp_die( esc_html__( 'Accesso non consentito.', 'dealer-portal' ) );
}

require DEALER_PORTAL_PATH . 'templates/admin-org-styles.php';
?>
<div class="wrap">
	<h1 class="wp-heading-inline">Perimetro · <?php echo esc_html( $user->display_name ); ?></h1>
	<a href="<?php echo esc_url( $base_url ); ?>" class="page-title-action">Torna all'elenco</a>
	<hr class="wp-header-end">

	<?php if ( ! empty( $notice['message'] ) ) : ?>
		<div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?> is-dismissible">
			<p><?php echo esc_html( $notice['message'] ); ?></p>
		</div>
	<?php endif; ?>

	<?php if ( empty( $selected_lines ) ) : ?>
		<div class="notice notice-warning inline"><p>
			<strong>Questo area manager non è ancora operativo.</strong>
			Senza almeno una linea prodotto non può pubblicare né aggiornare alcun documento, e trova
			la propria area di lavoro vuota. Le organizzazioni, invece, sono facoltative: servono solo
			se deve anche seguire dei dealer (persone, log, statistiche).
		</p></div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( $post_url ); ?>">
		<?php wp_nonce_field( 'dealer_org_am_scope' ); ?>
		<input type="hidden" name="action" value="dealer_org_am_scope">
		<input type="hidden" name="user_id" value="<?php echo esc_attr( (string) $user->ID ); ?>">

		<div style="display:flex; gap:20px; flex-wrap:wrap;">
			<div class="postbox" style="flex:1; min-width:320px;">
				<div class="postbox-header"><h2 class="hndle" style="padding:8px 12px;">
					1 · Linee di pubblicazione <span style="font-weight:400;color:#d63638;">— necessarie</span>
				</h2></div>
				<div class="inside">
					<p class="description" style="margin-bottom:10px;">
						Le linee su cui l'area manager può caricare, versionare e marcare obsoleti i documenti.
						<strong>Sono ciò che lo rende operativo</strong>: senza almeno una non può pubblicare nulla.
						Un documento senza restrizione di linea sarebbe visibile a tutta la rete, quindi pubblicarne
						uno non è un'opzione concessa a questo ruolo — o si sceglie una linea, o non si pubblica.
					</p>
					<p>
						<input type="search" id="dam-line-filter" class="regular-text" placeholder="Filtra brand o linea…">
						<button type="button" class="button" id="dam-clear-all">Deseleziona tutto</button>
					</p>
					<div class="dorg-lines-scroll">
						<?php foreach ( $lines_by_brand as $brand => $brand_lines ) : ?>
							<div class="dorg-brand-group" data-brand="<?php echo esc_attr( $brand ); ?>">
								<div class="dorg-brand-head">
									<label>
										<input type="checkbox" class="dam-brand-toggle"> <?php echo esc_html( $brand ); ?>
									</label>
									<span class="dorg-brand-tools">
										<span class="dam-brand-count">0</span>/<?php echo esc_html( (string) count( $brand_lines ) ); ?> selezionate
									</span>
								</div>
								<div class="dorg-brand-body">
									<?php foreach ( $brand_lines as $line ) :
										$value = $brand . '|' . $line;
										?>
										<label data-search="<?php echo esc_attr( strtolower( $brand . ' ' . $line ) ); ?>">
											<input type="checkbox" class="dam-line" name="am_lines[]"
												value="<?php echo esc_attr( $value ); ?>"
												<?php checked( in_array( $value, $selected_lines, true ) ); ?>>
											<?php echo esc_html( $line ); ?>
										</label>
									<?php endforeach; ?>
								</div>
							</div>
						<?php endforeach; ?>
					</div>
				</div>
			</div>

			<div class="postbox" style="flex:1; min-width:320px;">
				<div class="postbox-header"><h2 class="hndle" style="padding:8px 12px;">
					2 · Organizzazioni seguite <span style="font-weight:400;color:#787c82;">— facoltative</span>
				</h2></div>
				<div class="inside">
					<p class="description" style="margin-bottom:10px;">
						Servono solo se l'area manager deve anche <strong>seguire dei dealer</strong>: gestirne i
						collaboratori e vedere log e statistiche del proprio giro. Un area manager che si occupa
						soltanto di pubblicare documenti funziona benissimo senza nessuna organizzazione.
					</p>
					<p class="description" style="margin-bottom:10px;">
						Si elencano le <strong>radici</strong>: seguirne una include automaticamente tutto il suo
						sottoalbero (concessionarie, filiali), quindi non serve selezionare anche le figlie.
						Un'organizzazione già assegnata che nel frattempo è diventata figlia di un'altra resta
						comunque in elenco, segnalata come <em>sotto-organizzazione</em>: così non sparisce dal
						perimetro senza che tu lo abbia deciso.
					</p>
					<?php if ( empty( $roots ) ) : ?>
						<p class="dorg-muted">
							Non è ancora stata creata nessuna organizzazione. Non è un problema per pubblicare:
							le linee qui a fianco bastano a rendere operativo l'area manager. Se e quando servirà
							anche la supervisione dei dealer, le organizzazioni si creano da
							<a href="<?php echo esc_url( $orgs_url ); ?>">Dealer Portal → Organizzazioni</a>.
						</p>
					<?php else : ?>
						<p>
							<input type="search" id="dam-org-filter" class="regular-text" placeholder="Filtra per nome…">
						</p>
						<div class="dorg-lines-scroll">
							<?php foreach ( $roots as $root ) : ?>
								<label data-search="<?php echo esc_attr( strtolower( $root['name'] ) ); ?>" style="display:block; padding:4px 2px;">
									<input type="checkbox" name="am_orgs[]" value="<?php echo esc_attr( (string) $root['id'] ); ?>"
										<?php checked( in_array( $root['id'], $selected_orgs, true ) ); ?>>
									<?php echo esc_html( $root['name'] ); ?>
									<?php if ( $root['children'] > 0 ) : ?>
										<span class="dorg-cell-sub" style="display:inline;">(+<?php echo esc_html( (string) $root['children'] ); ?> nel sottoalbero)</span>
									<?php endif; ?>
									<?php if ( ! empty( $root['orphan'] ) ) : ?>
										<span class="dorg-badge is-mixed" title="<?php echo esc_attr( $root['path'] ); ?>">sotto-organizzazione</span>
									<?php endif; ?>
								</label>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
				</div>
			</div>
		</div>

		<p class="submit">
			<button type="submit" class="button button-primary">Salva perimetro</button>
		</p>
	</form>
</div>

<script>
( function () {
	'use strict';

	var form = document.currentScript.closest( 'div.wrap' ) || document;

	// ── Filtro organizzazioni ────────────────────────────────────────────
	var orgFilter = document.getElementById( 'dam-org-filter' );
	if ( orgFilter ) {
		orgFilter.addEventListener( 'input', function () {
			var term = orgFilter.value.trim().toLowerCase();
			Array.prototype.forEach.call( form.querySelectorAll( '[data-search]' ), function ( label ) {
				if ( ! label.closest( '.dorg-brand-group' ) ) {
					label.style.display = ( '' === term || -1 !== label.getAttribute( 'data-search' ).indexOf( term ) ) ? '' : 'none';
				}
			} );
		} );
	}

	// ── Linee: conteggio, selezione per brand, filtro, deseleziona tutto ──
	function updateBrandCount( group ) {
		var boxes = group.querySelectorAll( '.dam-line' );
		var checked = group.querySelectorAll( '.dam-line:checked' ).length;
		var counter = group.querySelector( '.dam-brand-count' );
		if ( counter ) {
			counter.textContent = String( checked );
		}
		var toggle = group.querySelector( '.dam-brand-toggle' );
		if ( toggle ) {
			toggle.checked = ( checked > 0 && checked === boxes.length );
			toggle.indeterminate = ( checked > 0 && checked < boxes.length );
		}
	}

	Array.prototype.forEach.call( form.querySelectorAll( '.dorg-brand-group' ), function ( group ) {
		updateBrandCount( group );
		Array.prototype.forEach.call( group.querySelectorAll( '.dam-line' ), function ( box ) {
			box.addEventListener( 'change', function () { updateBrandCount( group ); } );
		} );
	} );

	Array.prototype.forEach.call( form.querySelectorAll( '.dam-brand-toggle' ), function ( toggle ) {
		toggle.addEventListener( 'change', function () {
			var group = toggle.closest( '.dorg-brand-group' );
			Array.prototype.forEach.call( group.querySelectorAll( '.dam-line' ), function ( box ) {
				box.checked = toggle.checked;
			} );
			updateBrandCount( group );
		} );
	} );

	var clearAll = document.getElementById( 'dam-clear-all' );
	if ( clearAll ) {
		clearAll.addEventListener( 'click', function () {
			Array.prototype.forEach.call( form.querySelectorAll( '.dam-line' ), function ( box ) { box.checked = false; } );
			Array.prototype.forEach.call( form.querySelectorAll( '.dorg-brand-group' ), updateBrandCount );
		} );
	}

	var lineFilter = document.getElementById( 'dam-line-filter' );
	if ( lineFilter ) {
		lineFilter.addEventListener( 'input', function () {
			var term = lineFilter.value.trim().toLowerCase();
			Array.prototype.forEach.call( form.querySelectorAll( '.dorg-brand-group' ), function ( group ) {
				var brandMatch = ( group.getAttribute( 'data-brand' ) || '' ).toLowerCase().indexOf( term ) !== -1;
				var anyLineVisible = false;
				Array.prototype.forEach.call( group.querySelectorAll( '[data-search]' ), function ( label ) {
					var show = brandMatch || '' === term || -1 !== label.getAttribute( 'data-search' ).indexOf( term );
					label.style.display = show ? '' : 'none';
					if ( show ) { anyLineVisible = true; }
				} );
				group.style.display = anyLineVisible ? '' : 'none';
			} );
		} );
	}
} )();
</script>
