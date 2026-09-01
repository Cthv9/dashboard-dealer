<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/**
 * Card singola di un documento nella griglia dei risultati.
 * Richiesto dal loop di templates/dealer-search-results.php, quindi eredita
 * le variabili di quello scope:
 *
 * $item       array    voce prodotta da Dealer_Search::build_item()
 * $doc        WP_Post  = $item['post']
 * $downloads  int      numero di download registrati
 * $doc_types  array    chiave => etichetta
 * $search_term string
 *
 * Punto di innesto per altri moduli: l'action 'dealer_portal_search_card_meta'
 * (es. storico versioni, preferiti) riceve l'ID del documento e la voce.
 */

$type_label  = (string) ( $doc_types[ $item['type'] ] ?? $item['type'] );
$is_new      = Dealer_Search::is_new_doc( $item['id'] );
$is_expiring = Dealer_Search::is_expiring_doc( $item['id'] );
$icon = Dealer_Search::get_file_icon_svg( $item['filename'] );

// Estratto contestuale SearchWP quando c'è un termine di ricerca.
$excerpt = '';
if ( '' !== $search_term && function_exists( 'searchwp_get_contextual_excerpt' ) ) {
	$excerpt = (string) searchwp_get_contextual_excerpt( [ 'post' => $doc, 'query' => $search_term ] );
}
?>
<article class="dealer-doc-card" aria-label="<?php echo esc_attr( $doc->post_title ); ?>">

	<div class="dealer-doc-card-top">
		<div class="dealer-doc-icon" aria-hidden="true"><?php echo $icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — SVG statico generato dal plugin ?></div>
		<?php if ( $is_new || $is_expiring ) : ?>
			<div class="dealer-doc-badges">
				<?php if ( $is_new ) : ?>
					<span class="dealer-badge dealer-badge-new">NUOVO</span>
				<?php endif; ?>
				<?php if ( $is_expiring ) : ?>
					<span class="dealer-badge dealer-badge-expiring">IN SCADENZA</span>
				<?php endif; ?>
			</div>
		<?php endif; ?>
	</div>

	<h3 class="dealer-doc-title"><?php echo esc_html( $doc->post_title ); ?></h3>

	<div class="dealer-doc-meta">
		<?php if ( '' !== $item['brand'] ) : ?><span class="dealer-doc-brand"><?php echo esc_html( $item['brand'] ); ?></span><?php endif; ?>
		<?php if ( '' !== $item['line'] ) : ?><span class="dealer-doc-line"><?php echo esc_html( $item['line'] ); ?></span><?php endif; ?>
	</div>

	<div class="dealer-doc-tags">
		<?php if ( '' !== $type_label ) : ?><span class="dealer-doc-type"><?php echo esc_html( $type_label ); ?></span><?php endif; ?>
		<?php if ( '' !== $item['year'] ) : ?><span class="dealer-doc-chip"><?php echo esc_html( $item['year'] ); ?></span><?php endif; ?>
		<?php if ( '' !== $item['version'] ) : ?><span class="dealer-doc-chip">Ver. <?php echo esc_html( $item['version'] ); ?></span><?php endif; ?>
	</div>

	<?php if ( '' !== $excerpt ) : ?>
		<p class="dealer-doc-excerpt"><?php echo wp_kses( $excerpt, [ 'mark' => [], 'em' => [], 'strong' => [] ] ); ?></p>
	<?php endif; ?>

	<?php
	// Innesto per moduli esterni (storico versioni, preferiti, …).
	do_action( 'dealer_portal_search_card_meta', $item['id'], $item );
	?>

	<div class="dealer-doc-foot">
		<?php if ( $downloads > 0 ) : ?>
			<span class="dealer-doc-downloads" title="Download registrati">
				<span class="dashicons dashicons-download" aria-hidden="true"></span>
				<?php echo esc_html( number_format_i18n( $downloads ) ); ?>
			</span>
		<?php else : ?>
			<span class="dealer-doc-downloads"></span>
		<?php endif; ?>
		<?php
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — markup già escapato da render_document_actions()
		echo Dealer_Search::render_document_actions( $item['id'], $item['filename'], [ 'with_label' => true ] );
		?>
	</div>

</article>
