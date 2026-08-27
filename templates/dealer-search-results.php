<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/**
 * Griglia dei risultati + paginazione. Sostituito integralmente via AJAX.
 *
 * Variabili da Dealer_Search::render_results():
 * $items            array   voci della pagina corrente (vedi Dealer_Search::build_item())
 * $total            int     risultati totali (tutte le pagine)
 * $total_pages      int
 * $paged            int     pagina corrente
 * $download_counts  array   post_id => numero download
 * $library_empty    bool    il dealer non ha alcun documento accessibile
 * $has_filters      bool    almeno un filtro/testo attivo
 * $search_term      string
 * $doc_types        array   chiave => etichetta
 * $state            array   stato filtri corrente
 * $base_url         string
 * $reset_url        string
 */
?>
<?php if ( empty( $items ) ) : ?>

	<?php if ( $library_empty && ! $has_filters ) : ?>
		<div class="dealer-empty dealer-empty-library">
			<span class="dashicons dashicons-portfolio dealer-empty-icon" aria-hidden="true"></span>
			<h3 class="dealer-empty-title">La tua libreria è ancora vuota</h3>
			<p>Non ci sono documenti disponibili per il tuo account.<br>
			Contatta il tuo referente per richiedere l'accesso a nuove linee prodotto.</p>
		</div>

	<?php elseif ( ! $has_filters ) : ?>
		<div class="dealer-empty dealer-empty-nofilters">
			<span class="dashicons dashicons-search dealer-empty-icon" aria-hidden="true"></span>
			<h3 class="dealer-empty-title">Nessun documento da mostrare</h3>
			<p>Usa i filtri nella colonna a sinistra o cerca per parola chiave.</p>
		</div>

	<?php else : ?>
		<div class="dealer-empty dealer-empty-noresults">
			<span class="dashicons dashicons-filter dealer-empty-icon" aria-hidden="true"></span>
			<h3 class="dealer-empty-title">Nessun documento corrisponde ai filtri</h3>
			<p>Prova a togliere qualche filtro o a cambiare le parole chiave.</p>
			<a class="dealer-btn dealer-btn-reset" href="<?php echo esc_url( $reset_url ); ?>" data-chip-reset="1">Azzera tutto</a>
		</div>
	<?php endif; ?>

<?php else : ?>

	<?php if ( $total > 1 ) : ?>
		<?php /* Download aggregato: l'insieme è quello dei risultati filtrati correnti.
		         Nell'URL viaggiano solo i filtri, non gli ID: il set viene ricalcolato
		         lato server con i controlli di accesso su ogni documento. */ ?>
		<div class="dealer-bulk-bar">
			<span class="dealer-bulk-text">
				<?php echo esc_html( $has_filters ? 'Risultati filtrati:' : 'Tutti i tuoi documenti:' ); ?>
				<strong><?php echo esc_html( Dealer_Search::count_label( $total ) ); ?></strong>
			</span>
			<?php if ( $total <= Dealer_Search::ZIP_MAX_FILES ) : ?>
				<a class="dealer-btn dealer-btn-zip"
					href="<?php echo esc_url( Dealer_Search::get_zip_url( 'results', $state ) ); ?>">
					<span class="dashicons dashicons-media-archive" aria-hidden="true"></span>
					Scarica tutto in ZIP
				</a>
			<?php else : ?>
				<span class="dealer-bulk-note"><?php echo esc_html( Dealer_Search::zip_limit_notice() ); ?></span>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<div class="dealer-doc-grid">
		<?php foreach ( $items as $item ) :
			$doc       = $item['post'];
			$downloads = (int) ( $download_counts[ $item['id'] ] ?? 0 );
			require DEALER_PORTAL_PATH . 'templates/dealer-search-card.php';
		endforeach; ?>
	</div>

	<?php if ( $total_pages > 1 ) :
		$window_start = max( 1, $paged - 2 );
		$window_end   = min( $total_pages, $paged + 2 );
		?>
		<nav class="dealer-pagination" aria-label="Paginazione risultati">
			<?php if ( $paged > 1 ) : ?>
				<a class="dealer-page dealer-page-prev"
					href="<?php echo esc_url( Dealer_Search::build_url( $base_url, $state, [ 'pag' => $paged - 1 ] ) ); ?>"
					data-page="<?php echo esc_attr( $paged - 1 ); ?>" rel="prev">‹ Precedente</a>
			<?php endif; ?>

			<?php if ( $window_start > 1 ) : ?>
				<a class="dealer-page" href="<?php echo esc_url( Dealer_Search::build_url( $base_url, $state, [ 'pag' => 1 ] ) ); ?>" data-page="1">1</a>
				<?php if ( $window_start > 2 ) : ?><span class="dealer-page-gap">…</span><?php endif; ?>
			<?php endif; ?>

			<?php for ( $p = $window_start; $p <= $window_end; $p++ ) : ?>
				<?php if ( $p === $paged ) : ?>
					<span class="dealer-page is-current" aria-current="page"><?php echo esc_html( $p ); ?></span>
				<?php else : ?>
					<a class="dealer-page"
						href="<?php echo esc_url( Dealer_Search::build_url( $base_url, $state, [ 'pag' => $p ] ) ); ?>"
						data-page="<?php echo esc_attr( $p ); ?>"><?php echo esc_html( $p ); ?></a>
				<?php endif; ?>
			<?php endfor; ?>

			<?php if ( $window_end < $total_pages ) : ?>
				<?php if ( $window_end < $total_pages - 1 ) : ?><span class="dealer-page-gap">…</span><?php endif; ?>
				<a class="dealer-page"
					href="<?php echo esc_url( Dealer_Search::build_url( $base_url, $state, [ 'pag' => $total_pages ] ) ); ?>"
					data-page="<?php echo esc_attr( $total_pages ); ?>"><?php echo esc_html( $total_pages ); ?></a>
			<?php endif; ?>

			<?php if ( $paged < $total_pages ) : ?>
				<a class="dealer-page dealer-page-next"
					href="<?php echo esc_url( Dealer_Search::build_url( $base_url, $state, [ 'pag' => $paged + 1 ] ) ); ?>"
					data-page="<?php echo esc_attr( $paged + 1 ); ?>" rel="next">Successiva ›</a>
			<?php endif; ?>
		</nav>

		<p class="dealer-pagination-info">
			Pagina <?php echo esc_html( $paged ); ?> di <?php echo esc_html( $total_pages ); ?>
			&mdash; <?php echo esc_html( Dealer_Search::count_label( $total ) ); ?>
		</p>
	<?php endif; ?>

	<?php if ( $total >= Dealer_Search::MAX_RESULTS ) : ?>
		<p class="dealer-results-cap">
			Sono mostrati i primi <?php echo esc_html( number_format_i18n( Dealer_Search::MAX_RESULTS ) ); ?> documenti:
			restringi la ricerca con i filtri per risultati più precisi.
		</p>
	<?php endif; ?>

<?php endif; ?>
