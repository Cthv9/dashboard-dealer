<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/**
 * Extra della card: pulsante preferiti e storico versioni.
 * Incluso da Dealer_Search::render_card_extras(), agganciata all'action
 * 'dealer_portal_search_card_meta'.
 *
 * $post_id      int      documento corrente (versione corrente della catena)
 * $item         array    voce prodotta da Dealer_Search::build_item()
 * $is_favorite  bool
 * $favorite_url string   link di fallback no-JS (azione opposta allo stato attuale)
 * $versions     array[]  versioni precedenti già filtrate per accesso: id, label, date, url
 *
 * Progressive enhancement: la stella è un link reale con nonce e lo storico usa
 * <details>/<summary> nativi. Entrambi funzionano senza JavaScript.
 */

$fav_label = Dealer_Search::favorite_label( $is_favorite );
$fav_title = Dealer_Search::favorite_title( $is_favorite );
?>
<div class="dealer-doc-extras">

	<a class="dealer-fav-toggle<?php echo $is_favorite ? ' is-active' : ''; ?>"
		href="<?php echo esc_url( $favorite_url ); ?>"
		data-fav-post="<?php echo esc_attr( $post_id ); ?>"
		data-fav-next="<?php echo esc_attr( $is_favorite ? 'remove' : 'add' ); ?>"
		aria-pressed="<?php echo $is_favorite ? 'true' : 'false'; ?>"
		title="<?php echo esc_attr( $fav_title ); ?>">
		<span class="dashicons dashicons-star-filled" aria-hidden="true"></span>
		<span class="dealer-fav-label"><?php echo esc_html( $fav_label ); ?></span>
	</a>

	<?php if ( ! empty( $versions ) ) : ?>
		<details class="dealer-doc-versions">
			<summary class="dealer-versions-summary">
				<span class="dashicons dashicons-backup" aria-hidden="true"></span>
				Versioni precedenti (<?php echo esc_html( number_format_i18n( count( $versions ) ) ); ?>)
			</summary>
			<ul class="dealer-version-list">
				<?php foreach ( $versions as $version ) : ?>
					<li class="dealer-version-item">
						<span class="dealer-version-info">
							<span class="dealer-version-label"><?php echo esc_html( $version['label'] ); ?></span>
							<span class="dealer-version-date"><?php echo esc_html( gmdate( 'd/m/Y', strtotime( $version['date'] ) ) ); ?></span>
						</span>
						<?php if ( ! empty( $version['previewable'] ) ) : ?>
							<a href="<?php echo esc_url( $version['view_url'] ); ?>"
								class="dealer-btn dealer-btn-sm dealer-btn-view"
								target="_blank" rel="noopener">
								<span class="dashicons dashicons-visibility" aria-hidden="true"></span>
								<span class="screen-reader-text">Apri <?php echo esc_html( $version['label'] ); ?> nel browser</span>
							</a>
						<?php endif; ?>
						<a href="<?php echo esc_url( $version['url'] ); ?>"
							class="dealer-btn dealer-btn-sm dealer-btn-download-version"
							data-post="<?php echo esc_attr( $version['id'] ); ?>">
							<span class="dashicons dashicons-download" aria-hidden="true"></span>
							<span class="screen-reader-text">Scarica <?php echo esc_html( $version['label'] ); ?></span>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		</details>
	<?php endif; ?>

</div>
