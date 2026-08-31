<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/**
 * Pagina "I miei preferiti" — etichette personali del dealer.
 *
 * Variabili da Dealer_Favorites::render():
 *
 * $user           WP_User  dealer corrente
 * $feedback       array    esito del ciclo PRG precedente (status, message)
 * $tags           array    etichette dell'utente: [ 'id' => string, 'label' => string ]
 * $filter_tag     string   id dell'etichetta selezionata, '' = "Tutte"
 * $orderby        string   'recent' | 'title'
 * $items          array    tutti i preferiti accessibili, con 'tag_ids' assegnati
 * $groups         array[]  usato quando $filter_tag === '': [ 'tag' => array|null, 'items' => array ]
 * $flat_items     array[]  usato quando $filter_tag !== '': preferiti con quell'etichetta
 * $total_count    int      totale preferiti mostrabili
 * $base_url       string   permalink della pagina Preferiti
 * $form_action    string   URL a cui inviare i form (pattern PRG, preserva filtro/ordinamento)
 * $dashboard_url  string   permalink della dashboard dealer
 * $search_url     string   permalink della ricerca documenti
 * $can_add_tag    bool     sotto il tetto MAX_TAGS
 *
 * Progressive enhancement: nessun JavaScript, né per la consultazione né per
 * la gestione delle etichette. Ogni azione di scrittura è un form POST con
 * nonce e pattern PRG (come Dealer_Team).
 */
?>
<div class="dealer-favorites-wrap" id="dealer-favorites">

	<a class="dealer-back-link" href="<?php echo esc_url( $dashboard_url ); ?>">
		<span class="dashicons dashicons-arrow-left-alt" aria-hidden="true"></span>
		Torna alla Dashboard
	</a>

	<div class="dealer-section-title-row">
		<h1 class="dealer-section-title">
			<span class="dashicons dashicons-star-filled"></span> I Tuoi Preferiti
		</h1>
	</div>

	<?php if ( ! empty( $feedback['message'] ) ) : ?>
		<div class="dealer-fav-msg <?php echo 'success' === ( $feedback['status'] ?? '' ) ? 'dealer-fav-msg-success' : 'dealer-fav-msg-error'; ?>">
			<?php echo esc_html( (string) $feedback['message'] ); ?>
		</div>
	<?php endif; ?>

	<!-- ── ETICHETTE PERSONALI ─────────────────────────────────────────── -->
	<div class="dealer-fav-panel">
		<h2 class="dealer-section-title">Le tue etichette</h2>
		<p class="dealer-fav-panel-hint">
			Crea etichette personali per organizzare i tuoi preferiti (es. “Urgenti”,
			“Da ristampare”). Sono visibili solo a te.
		</p>

		<?php if ( empty( $tags ) ) : ?>
			<p class="dealer-fav-panel-hint"><em>Non hai ancora creato nessuna etichetta.</em></p>
		<?php else : ?>
			<div class="dealer-fav-tag-list">
				<?php foreach ( $tags as $tag ) : ?>
					<div class="dealer-fav-tag-row">
						<span class="dealer-fav-tag-name"><?php echo esc_html( $tag['label'] ); ?></span>

						<form method="post" action="<?php echo esc_url( $form_action ); ?>">
							<input type="hidden" name="df_action" value="rename_tag">
							<input type="hidden" name="df_tag" value="<?php echo esc_attr( $tag['id'] ); ?>">
							<?php wp_nonce_field( Dealer_Favorites::NONCE_RENAME . '_' . $tag['id'], 'df_nonce', false ); ?>
							<label class="screen-reader-text" for="df-rename-<?php echo esc_attr( $tag['id'] ); ?>">
								Nuovo nome per <?php echo esc_html( $tag['label'] ); ?>
							</label>
							<input type="text" id="df-rename-<?php echo esc_attr( $tag['id'] ); ?>" name="df_label"
								value="<?php echo esc_attr( $tag['label'] ); ?>" maxlength="40" required>
							<button type="submit" class="dealer-btn dealer-btn-sm">Rinomina</button>
						</form>

						<form method="post" action="<?php echo esc_url( $form_action ); ?>"
							onsubmit="return confirm('Eliminare l’etichetta “<?php echo esc_js( $tag['label'] ); ?>”? Verrà rimossa da tutti i documenti a cui è assegnata.');">
							<input type="hidden" name="df_action" value="delete_tag">
							<input type="hidden" name="df_tag" value="<?php echo esc_attr( $tag['id'] ); ?>">
							<?php wp_nonce_field( Dealer_Favorites::NONCE_DELETE . '_' . $tag['id'], 'df_nonce', false ); ?>
							<button type="submit" class="dealer-btn dealer-btn-sm dealer-btn-danger">Elimina</button>
						</form>
					</div>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>

		<?php if ( $can_add_tag ) : ?>
			<form method="post" action="<?php echo esc_url( $form_action ); ?>" class="dealer-fav-create-form">
				<input type="hidden" name="df_action" value="create_tag">
				<?php wp_nonce_field( Dealer_Favorites::NONCE_CREATE, 'df_nonce', false ); ?>
				<label class="screen-reader-text" for="df-new-label">Nome nuova etichetta</label>
				<input type="text" id="df-new-label" name="df_label" maxlength="40" required
					placeholder="Nome nuova etichetta (es. Urgenti)" autocomplete="off">
				<button type="submit" class="dealer-btn dealer-btn-sm">Crea etichetta</button>
			</form>
		<?php else : ?>
			<p class="dealer-fav-panel-hint">
				<?php echo esc_html( sprintf( 'Hai raggiunto il numero massimo di %d etichette.', Dealer_Favorites::MAX_TAGS ) ); ?>
			</p>
		<?php endif; ?>
	</div>

	<?php if ( 0 === $total_count ) : ?>

		<div class="dealer-empty">
			<span class="dashicons dashicons-star-filled dealer-empty-icon" aria-hidden="true"></span>
			<p class="dealer-empty-title">Nessun documento nei preferiti</p>
			<p>Aggiungi documenti ai preferiti dalla ricerca per ritrovarli qui ed etichettarli.</p>
			<a class="dealer-btn dealer-btn-search" href="<?php echo esc_url( $search_url ); ?>">
				<span class="dashicons dashicons-search" aria-hidden="true"></span> Vai alla ricerca
			</a>
		</div>

	<?php else : ?>

		<!-- ── FILTRO PER ETICHETTA ────────────────────────────────────────── -->
		<div class="dealer-fav-tagbar">
			<a class="dealer-fav-tagchip<?php echo '' === $filter_tag ? ' is-active' : ''; ?>"
				href="<?php echo esc_url( Dealer_Favorites::state_url( $base_url, '', $orderby ) ); ?>">
				Tutte <span class="dealer-fav-tagchip-count">(<?php echo (int) $total_count; ?>)</span>
			</a>
			<?php foreach ( $tags as $tag ) :
				$tag_count = count( array_filter( $items, static function ( array $item ) use ( $tag ): bool {
					return in_array( $tag['id'], $item['tag_ids'], true );
				} ) );
				if ( 0 === $tag_count ) {
					continue; // Nessun preferito con questa etichetta: non ha senso proporla come filtro.
				}
				?>
				<a class="dealer-fav-tagchip<?php echo $filter_tag === $tag['id'] ? ' is-active' : ''; ?>"
					href="<?php echo esc_url( Dealer_Favorites::state_url( $base_url, $tag['id'], $orderby ) ); ?>">
					<?php echo esc_html( $tag['label'] ); ?> <span class="dealer-fav-tagchip-count">(<?php echo (int) $tag_count; ?>)</span>
				</a>
			<?php endforeach; ?>
		</div>

		<!-- ── ORDINAMENTO ─────────────────────────────────────────────────── -->
		<div class="dealer-results-toolbar">
			<p class="dealer-results-count">
				<?php echo esc_html( '' !== $filter_tag ? sprintf( '%d documenti con questa etichetta', count( $flat_items ) ) : sprintf( '%d documenti nei preferiti', $total_count ) ); ?>
			</p>
			<form method="get" action="<?php echo esc_url( $base_url ); ?>" class="dealer-sort">
				<input type="hidden" name="tag" value="<?php echo esc_attr( $filter_tag ); ?>">
				<label for="dealer-fav-orderby">Ordina per</label>
				<select name="orderby" id="dealer-fav-orderby">
					<?php foreach ( Dealer_Favorites::SORT_OPTIONS as $sort_key => $sort_label ) : ?>
						<option value="<?php echo esc_attr( $sort_key ); ?>"<?php selected( $orderby, $sort_key ); ?>><?php echo esc_html( $sort_label ); ?></option>
					<?php endforeach; ?>
				</select>
				<button type="submit" class="dealer-btn dealer-btn-sm">Applica</button>
			</form>
		</div>

		<?php
		/**
		 * Renderizza un elenco di preferiti (usata sia per il flat-list con
		 * filtro attivo, sia dentro ogni gruppo con filtro "Tutte").
		 *
		 * @param array $items_list
		 */
		$render_items = static function ( array $items_list ) use ( $tags, $form_action ): void {
			foreach ( $items_list as $item ) :
				?>
				<div class="dealer-fav-feed-item">
					<div class="dealer-fav-feed-top">
						<div class="dealer-feed-icon" aria-hidden="true"><?php echo $item['icon']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — SVG statico generato dal plugin ?></div>
						<div class="dealer-feed-body">
							<span class="dealer-feed-title"><?php echo esc_html( $item['title'] ); ?></span>
							<span class="dealer-feed-meta">
								<?php echo esc_html( implode( ' — ', array_filter( [ $item['brand'], $item['line'], $item['type'] ] ) ) ); ?>
							</span>
						</div>
						<div class="dealer-feed-actions">
							<a href="<?php echo esc_url( $item['remove_url'] ); ?>" class="dealer-btn dealer-btn-sm dealer-fav-remove"
								title="Rimuovi dai preferiti">
								<span class="dashicons dashicons-star-filled" aria-hidden="true"></span>
								<span class="screen-reader-text">Rimuovi <?php echo esc_html( $item['title'] ); ?> dai preferiti</span>
							</a>
							<a href="<?php echo esc_url( $item['download_url'] ); ?>" class="dealer-btn dealer-btn-sm">
								<span class="dashicons dashicons-download" aria-hidden="true"></span>
								<span class="screen-reader-text">Scarica <?php echo esc_html( $item['title'] ); ?></span>
							</a>
						</div>
					</div>

					<?php if ( ! empty( $item['tag_ids'] ) ) : ?>
						<div class="dealer-fav-item-tags">
							<?php foreach ( $item['tag_ids'] as $tid ) :
								$found_label = '';
								foreach ( $tags as $t ) {
									if ( $t['id'] === $tid ) {
										$found_label = $t['label'];
										break;
									}
								}
								if ( '' === $found_label ) {
									continue;
								}
								?>
								<span class="dealer-doc-chip"><?php echo esc_html( $found_label ); ?></span>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>

					<?php if ( ! empty( $tags ) ) : ?>
						<details class="dealer-fav-assign">
							<summary>
								<span class="dashicons dashicons-tag" aria-hidden="true"></span>
								Gestisci etichette
							</summary>
							<div class="dealer-fav-assign-body">
								<form method="post" action="<?php echo esc_url( $form_action ); ?>">
									<input type="hidden" name="df_action" value="assign_tags">
									<input type="hidden" name="df_post" value="<?php echo esc_attr( (string) $item['id'] ); ?>">
									<?php wp_nonce_field( Dealer_Favorites::NONCE_ASSIGN . '_' . $item['id'], 'df_nonce', false ); ?>
									<div class="dealer-fav-assign-checks">
										<?php foreach ( $tags as $tag ) :
											$checked = in_array( $tag['id'], $item['tag_ids'], true );
											?>
											<label class="dealer-fav-assign-check">
												<input type="checkbox" name="df_tags[]" value="<?php echo esc_attr( $tag['id'] ); ?>" <?php checked( $checked ); ?>>
												<?php echo esc_html( $tag['label'] ); ?>
											</label>
										<?php endforeach; ?>
									</div>
									<button type="submit" class="dealer-btn dealer-btn-sm">Salva etichette</button>
								</form>
							</div>
						</details>
					<?php endif; ?>
				</div>
				<?php
			endforeach;
		};
		?>

		<?php if ( '' !== $filter_tag ) : ?>

			<div class="dealer-fav-group">
				<?php if ( empty( $flat_items ) ) : ?>
					<p class="dealer-fav-panel-hint"><em>Nessun documento con questa etichetta.</em></p>
				<?php else : ?>
					<?php $render_items( $flat_items ); ?>
				<?php endif; ?>
			</div>

		<?php else : ?>

			<?php foreach ( $groups as $group ) : ?>
				<div class="dealer-fav-group">
					<h3 class="dealer-fav-group-title">
						<?php echo esc_html( null !== $group['tag'] ? $group['tag']['label'] : 'Senza etichetta' ); ?>
						(<?php echo (int) count( $group['items'] ); ?>)
					</h3>
					<?php $render_items( $group['items'] ); ?>
				</div>
			<?php endforeach; ?>

		<?php endif; ?>

	<?php endif; ?>

</div><!-- .dealer-favorites-wrap -->
