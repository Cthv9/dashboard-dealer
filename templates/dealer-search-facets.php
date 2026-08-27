<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/**
 * Sidebar delle faccette. Sostituito integralmente via AJAX.
 *
 * Variabili da Dealer_Search::render_facets():
 * $facets      array   gruppo => [ [ 'value','label','count' ], … ] già ordinato
 * $state       array   stato filtri corrente
 * $user_lines  array   linee assegnate al dealer
 *
 * Le voci a conteggio 0 non vengono prodotte da run_search(), tranne quelle
 * attualmente selezionate: restano visibili (marcate .is-zero) per poterle togliere.
 */
?>
<?php foreach ( Dealer_Search::FACET_GROUPS as $group ) :
	$entries     = isset( $facets[ $group ] ) && is_array( $facets[ $group ] ) ? $facets[ $group ] : [];
	$selected    = isset( $state[ $group ] ) && is_array( $state[ $group ] ) ? $state[ $group ] : [];
	$group_label = Dealer_Search::facet_group_label( $group );
	?>
	<section class="dealer-facet-group<?php echo empty( $entries ) ? ' is-empty' : ''; ?>" data-facet-group="<?php echo esc_attr( $group ); ?>">
		<h3 class="dealer-facet-title"><?php echo esc_html( $group_label ); ?></h3>

		<?php if ( empty( $entries ) ) : ?>
			<p class="dealer-facet-none">
				<?php echo 'line' === $group && empty( $user_lines )
					? 'Nessuna linea assegnata al tuo account.'
					: 'Nessuna opzione disponibile con i filtri attuali.'; ?>
			</p>
		<?php else : ?>
			<ul class="dealer-facet-list">
				<?php foreach ( $entries as $entry ) :
					$is_checked = in_array( $entry['value'], $selected, true );
					$is_zero    = ( 0 === (int) $entry['count'] );
					$field_id   = 'dealer-facet-' . $group . '-' . substr( md5( $entry['value'] ), 0, 10 );
					?>
					<li class="dealer-facet-item<?php echo $is_zero ? ' is-zero' : ''; ?>">
						<input type="checkbox"
							class="dealer-facet-check"
							id="<?php echo esc_attr( $field_id ); ?>"
							name="<?php echo esc_attr( $group ); ?>[]"
							value="<?php echo esc_attr( $entry['value'] ); ?>"
							<?php checked( $is_checked ); ?>>
						<label for="<?php echo esc_attr( $field_id ); ?>">
							<span class="dealer-facet-label"><?php echo esc_html( $entry['label'] ); ?></span>
							<span class="dealer-facet-count"><?php echo esc_html( number_format_i18n( (int) $entry['count'] ) ); ?></span>
						</label>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	</section>
<?php endforeach; ?>
