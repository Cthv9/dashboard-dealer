<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/**
 * Variabili da Dealer_Search::render():
 * $search_term, $filter_brand, $filter_type, $filter_year, $filter_line,
 * $user_lines (array "Brand|Linea"), $results (array WP_Post), $user, $did_search
 */

$doc_types      = Dealer_Admin::get_doc_types();
$brands         = Dealer_Admin::get_brands();
$current_year   = (int) gmdate( 'Y' );
$search_page_url = esc_url( get_permalink() );
?>
<div class="dealer-search-wrap">

	<!-- Modulo di ricerca (GET per URL condivisibili e funzionamento senza JS) -->
	<form class="dealer-search-form" method="GET" action="<?php echo $search_page_url; ?>" role="search">

		<div class="dealer-search-row">
			<div class="dealer-search-field dealer-search-text">
				<label for="dealer-s" class="screen-reader-text">Cerca documenti</label>
				<input type="search" id="dealer-s" name="s"
					value="<?php echo esc_attr( $search_term ); ?>"
					placeholder="Cerca per testo, brand, modello, parola chiave…"
					autocomplete="off">
			</div>
			<button type="submit" class="dealer-btn dealer-btn-search">
				<span class="dashicons dashicons-search"></span> Cerca
			</button>
		</div>

		<div class="dealer-filters-row">
			<!-- Brand -->
			<select name="filter_brand" aria-label="Filtra per brand">
				<option value="">Tutti i brand</option>
				<?php foreach ( $brands as $b ) : ?>
					<option value="<?php echo esc_attr( $b ); ?>"<?php selected( $filter_brand, $b ); ?>><?php echo esc_html( $b ); ?></option>
				<?php endforeach; ?>
			</select>

			<!-- Tipo -->
			<select name="filter_type" aria-label="Filtra per tipo">
				<option value="">Tutti i tipi</option>
				<?php foreach ( $doc_types as $key => $label ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>"<?php selected( $filter_type, $key ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>

			<!-- Anno -->
			<select name="filter_year" aria-label="Filtra per anno">
				<option value="">Tutti gli anni</option>
				<?php for ( $y = $current_year; $y >= $current_year - 5; $y-- ) : ?>
					<option value="<?php echo esc_attr( $y ); ?>"<?php selected( $filter_year, $y ); ?>><?php echo esc_html( $y ); ?></option>
				<?php endfor; ?>
			</select>

			<!-- Linea prodotto: solo le linee assegnate al dealer corrente -->
			<?php if ( ! empty( $user_lines ) ) : ?>
			<select name="filter_line" aria-label="Filtra per linea prodotto">
				<option value="">Tutte le linee</option>
				<?php foreach ( $user_lines as $line_key ) : ?>
					<option value="<?php echo esc_attr( $line_key ); ?>"<?php selected( $filter_line, $line_key ); ?>>
						<?php echo esc_html( str_replace( '|', ' › ', $line_key ) ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<?php endif; ?>

			<!-- Reset -->
			<a href="<?php echo $search_page_url; ?>" class="dealer-btn dealer-btn-reset">Reset filtri</a>
		</div>

	</form>

	<!-- Risultati -->
	<div id="dealer-results" class="dealer-results-wrap" aria-live="polite">

		<?php if ( $did_search ) : ?>

			<?php if ( empty( $results ) ) : ?>
				<div class="dealer-no-results">
					<span class="dashicons dashicons-search" style="font-size:36px;color:#ccc;"></span>
					<p>Nessun documento trovato per questa ricerca.<br>Prova a modificare i filtri o le parole chiave.</p>
				</div>

			<?php else : ?>
				<p class="dealer-results-count">
					Trovati <strong><?php echo count( $results ); ?></strong> document<?php echo count( $results ) === 1 ? 'o' : 'i'; ?>.
				</p>

				<div class="dealer-cards-list">
				<?php foreach ( $results as $doc ) :
					$brand       = get_post_meta( $doc->ID, '_doc_brand',        true );
					$line        = get_post_meta( $doc->ID, '_doc_product_line',  true );
					$type_key    = get_post_meta( $doc->ID, '_doc_type',          true );
					$year        = get_post_meta( $doc->ID, '_doc_year',          true );
					$version     = get_post_meta( $doc->ID, '_doc_version',       true );
					$expiry      = get_post_meta( $doc->ID, '_doc_expiry',        true );
					$filename    = get_post_meta( $doc->ID, '_doc_filename',      true );
					$type_lbl    = Dealer_Admin::get_doc_types()[ $type_key ] ?? $type_key;
					$is_new      = Dealer_Search::is_new_doc( $doc->ID );
					$is_expiring = Dealer_Search::is_expiring_doc( $doc->ID );
					$dl_url      = Dealer_Search::get_download_url( $doc->ID );
					$icon        = Dealer_Search::get_file_icon_svg( $filename ?: '' );

					// Estratto contestuale SearchWP o fallback.
					$excerpt = '';
					if ( $search_term ) {
						if ( function_exists( 'searchwp_get_contextual_excerpt' ) ) {
							$excerpt = searchwp_get_contextual_excerpt( [ 'post' => $doc, 'query' => $search_term ] );
						}
						if ( ! $excerpt ) {
							$excerpt = '';
						}
					}
					?>
					<article class="dealer-doc-card" aria-label="<?php echo esc_attr( $doc->post_title ); ?>">
						<div class="dealer-doc-icon" aria-hidden="true"><?php echo $icon; // Already sanitized SVG ?></div>
						<div class="dealer-doc-body">
							<h3 class="dealer-doc-title"><?php echo esc_html( $doc->post_title ); ?></h3>
							<div class="dealer-doc-meta">
								<?php if ( $brand ) : ?><span><?php echo esc_html( $brand ); ?></span><?php endif; ?>
								<?php if ( $line )  : ?><span><?php echo esc_html( $line );  ?></span><?php endif; ?>
								<?php if ( $type_lbl ) : ?><span class="dealer-doc-type"><?php echo esc_html( $type_lbl ); ?></span><?php endif; ?>
							</div>
							<div class="dealer-doc-meta-secondary">
								<?php if ( $year )    : ?>Anno <?php echo esc_html( $year ); ?><?php endif; ?>
								<?php if ( $version ) : ?>&bull; Ver. <?php echo esc_html( $version ); ?><?php endif; ?>
							</div>
							<?php if ( $excerpt ) : ?>
								<p class="dealer-doc-excerpt"><?php echo wp_kses( $excerpt, [ 'mark' => [], 'em' => [], 'strong' => [] ] ); ?></p>
							<?php endif; ?>
							<div class="dealer-doc-badges">
								<?php if ( $is_new ) : ?>
									<span class="dealer-badge dealer-badge-new">NUOVO</span>
								<?php endif; ?>
								<?php if ( $is_expiring ) : ?>
									<span class="dealer-badge dealer-badge-expiring">IN SCADENZA</span>
								<?php endif; ?>
							</div>
						</div>
						<div class="dealer-doc-actions">
							<a href="<?php echo esc_url( $dl_url ); ?>"
								class="dealer-btn dealer-btn-download"
								data-post="<?php echo esc_attr( $doc->ID ); ?>">
								<span class="dashicons dashicons-download"></span> Scarica
							</a>
						</div>
					</article>
				<?php endforeach; ?>
				</div><!-- .dealer-cards-list -->

			<?php endif; // empty results ?>

		<?php else : ?>
			<div class="dealer-search-intro">
				<span class="dashicons dashicons-search" style="font-size:40px;color:#1e6fa8;"></span>
				<p>Inserisci una parola chiave o seleziona un filtro per cercare i documenti.</p>
			</div>
		<?php endif; // $did_search ?>

	</div><!-- #dealer-results -->
</div><!-- .dealer-search-wrap -->
