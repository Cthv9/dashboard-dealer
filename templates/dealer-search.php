<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/**
 * Ricerca a faccette — guscio della pagina.
 *
 * Variabili da Dealer_Search::render():
 * $user          WP_User          dealer corrente
 * $user_lines    array            linee assegnate ("Brand|Linea")
 * $state         array            stato filtri: s, brand[], line[], type[], year[], orderby, paged
 * $search_term   string           = $state['s']
 * $orderby       string           = $state['orderby']
 * $sort_options  array            chiave => etichetta
 * $total         int              risultati totali
 * $count_label   string           etichetta del contatore
 * $paged         int              pagina corrente (normalizzata)
 * $base_url      string           permalink della pagina di ricerca
 * $facets_html   string           HTML sidebar facet   (già escapato lato PHP)
 * $filters_html  string           HTML chip filtri     (già escapato lato PHP)
 * $results_html  string           HTML griglia + paginazione (già escapato lato PHP)
 * $dashboard_url string           permalink della dashboard dealer
 *
 * Progressive enhancement: senza JavaScript questo è un normale form GET con
 * i pulsanti "Cerca" e "Applica filtri". Il JS intercetta il submit e aggiorna
 * i tre contenitori via AJAX. NON rimuovere il fallback.
 */
?>
<div class="dealer-search-wrap">

	<a class="dealer-back-link" href="<?php echo esc_url( $dashboard_url ); ?>">
		<span class="dashicons dashicons-arrow-left-alt" aria-hidden="true"></span>
		Torna alla Dashboard
	</a>

	<form class="dealer-search-form" id="dealer-search-form" method="get" action="<?php echo esc_url( $base_url ); ?>" role="search">

		<!-- Pagina corrente: aggiornata dal JS al click sulla paginazione, inviata anche senza JS -->
		<input type="hidden" name="pag" id="dealer-paged" value="<?php echo esc_attr( $paged ); ?>">

		<div class="dealer-search-bar">
			<label for="dealer-s" class="screen-reader-text">Cerca documenti</label>
			<span class="dealer-search-bar-icon dashicons dashicons-search" aria-hidden="true"></span>
			<input type="search" id="dealer-s" name="s"
				value="<?php echo esc_attr( $search_term ); ?>"
				placeholder="Cerca per titolo, brand, modello, parola chiave…"
				autocomplete="off">
			<button type="submit" class="dealer-btn dealer-btn-search">Cerca</button>
		</div>

		<div class="dealer-search-layout">

			<!-- ── Sidebar facet ───────────────────────────────────────────── -->
			<aside class="dealer-facets" id="dealer-facets-panel" aria-label="Filtri di ricerca">
				<div class="dealer-facets-head">
					<h2 class="dealer-facets-title">Filtri</h2>
					<button type="button" class="dealer-facets-toggle" id="dealer-facets-toggle"
						aria-expanded="true" aria-controls="dealer-facets-inner">
						<span class="dealer-facets-toggle-label">Nascondi</span>
						<span class="dashicons dashicons-arrow-up-alt2" aria-hidden="true"></span>
					</button>
				</div>

				<div class="dealer-facets-inner" id="dealer-facets-inner">
					<div id="dealer-facets">
						<?php echo $facets_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — HTML generato ed escapato in PHP ?>
					</div>
					<div class="dealer-nojs-only dealer-facets-apply">
						<button type="submit" class="dealer-btn dealer-btn-search">Applica filtri</button>
					</div>
				</div>
			</aside>

			<!-- ── Colonna risultati ───────────────────────────────────────── -->
			<div class="dealer-results-col">

				<div class="dealer-results-toolbar">
					<p class="dealer-results-count" id="dealer-result-count" role="status" aria-live="polite"><?php echo esc_html( $count_label ); ?></p>

					<div class="dealer-sort">
						<label for="dealer-orderby">Ordina per</label>
						<select name="orderby" id="dealer-orderby">
							<?php foreach ( $sort_options as $sort_key => $sort_label ) : ?>
								<option value="<?php echo esc_attr( $sort_key ); ?>"<?php selected( $orderby, $sort_key ); ?><?php disabled( 'relevance' === $sort_key && '' === $search_term ); ?>><?php echo esc_html( $sort_label ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
				</div>

				<div id="dealer-active-filters" class="dealer-active-filters"><?php echo $filters_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — HTML generato ed escapato in PHP ?></div>

				<div id="dealer-results" class="dealer-results-wrap" aria-busy="false">
					<?php echo $results_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — HTML generato ed escapato in PHP ?>
				</div>

			</div><!-- .dealer-results-col -->

		</div><!-- .dealer-search-layout -->

	</form>
</div><!-- .dealer-search-wrap -->
