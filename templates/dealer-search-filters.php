<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/**
 * Riepilogo dei filtri attivi (chip rimovibili). Sostituito via AJAX.
 *
 * Variabili da Dealer_Search::render_active_filters():
 * $chips        array   [ [ 'group','value','group_label','label','url' ], … ]
 * $has_filters  bool
 * $reset_url    string  URL della pagina senza alcun filtro
 * $state        array   stato filtri corrente
 * $base_url     string
 *
 * Ogni chip è un link reale (funziona senza JS); il JS lo intercetta e si limita
 * a togliere la spunta corrispondente ricaricando i risultati via AJAX.
 */

if ( ! $has_filters ) {
	return;
}
?>
<div class="dealer-chips" role="group" aria-label="Filtri attivi">
	<span class="dealer-chips-label">Filtri attivi:</span>

	<?php foreach ( $chips as $chip ) : ?>
		<a class="dealer-chip"
			href="<?php echo esc_url( $chip['url'] ); ?>"
			data-chip-group="<?php echo esc_attr( $chip['group'] ); ?>"
			data-chip-value="<?php echo esc_attr( $chip['value'] ); ?>">
			<span class="dealer-chip-group"><?php echo esc_html( $chip['group_label'] ); ?></span>
			<span class="dealer-chip-value"><?php echo esc_html( $chip['label'] ); ?></span>
			<span class="dealer-chip-x" aria-hidden="true">&times;</span>
			<span class="screen-reader-text">Rimuovi il filtro <?php echo esc_html( $chip['group_label'] . ' ' . $chip['label'] ); ?></span>
		</a>
	<?php endforeach; ?>

	<a class="dealer-chip-reset" href="<?php echo esc_url( $reset_url ); ?>" data-chip-reset="1">Azzera tutto</a>
</div>
