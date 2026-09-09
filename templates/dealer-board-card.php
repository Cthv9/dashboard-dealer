<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/**
 * Scheda di un annuncio della bacheca.
 *
 * Inclusa da dealer_board_card() in templates/dealer-board.php, sia per la
 * fascia delle comunicazioni sia per l'elenco.
 *
 * @var array  $d         Dati da Dealer_Board::view_data()
 * @var string $post_url  admin-post.php
 * @var string $base_url  permalink della bacheca
 * @var int    $open      id dell'annuncio di cui aprire il modulo di risposta
 */
?>
<article class="dealer-board-card<?php echo $d['is_mine'] ? ' is-mine' : ''; ?><?php echo $d['is_notice'] ? ' is-notice' : ''; ?>"
	id="annuncio-<?php echo esc_attr( (string) $d['id'] ); ?>">

	<header class="dealer-board-card-head">
		<span class="dealer-board-tag dealer-board-tag-<?php echo esc_attr( $d['type'] ); ?>">
			<?php echo esc_html( Dealer_Board::type_label( $d['type'] ) ); ?>
		</span>
		<?php if ( Dealer_Board::STATUS_ACTIVE !== $d['status'] ) : ?>
			<span class="dealer-board-tag dealer-board-tag-off"><?php echo esc_html( ucfirst( $d['status'] ) ); ?></span>
		<?php endif; ?>
		<?php if ( $d['is_mine'] && $d['replies'] > 0 ) : ?>
			<span class="dealer-board-tag dealer-board-tag-replies">
				<?php echo esc_html( sprintf( '%d %s', $d['replies'], 1 === $d['replies'] ? 'risposta' : 'risposte' ) ); ?>
			</span>
		<?php endif; ?>
		<h3><?php echo esc_html( $d['title'] ); ?></h3>
	</header>

	<p class="dealer-board-org">
		<strong><?php echo esc_html( $d['org_name'] ); ?></strong>
		<?php if ( '' !== $d['area'] ) : ?>
			· <?php echo esc_html( $d['area'] ); ?>
		<?php endif; ?>
		· <?php echo esc_html( $d['published'] ); ?>
	</p>

	<?php if ( ! empty( $d['images'] ) ) : ?>
		<div class="dealer-board-images">
			<?php foreach ( $d['images'] as $img ) : ?>
				<a href="<?php echo esc_url( $img ); ?>" target="_blank" rel="noopener">
					<img src="<?php echo esc_url( $img ); ?>" alt="" loading="lazy">
				</a>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<p class="dealer-board-body"><?php echo nl2br( esc_html( $d['body'] ) ); ?></p>

	<ul class="dealer-board-meta">
		<?php if ( '' !== $d['code'] ) : ?>
			<li><span>Codice</span><?php echo esc_html( $d['code'] ); ?></li>
		<?php endif; ?>
		<?php if ( '' !== $d['brand'] ) : ?>
			<li><span>Linea</span><?php echo esc_html( $d['brand'] . ( '' !== $d['line'] ? ' › ' . $d['line'] : '' ) ); ?></li>
		<?php endif; ?>
		<?php if ( $d['qty'] > 0 && ! $d['is_notice'] ) : ?>
			<li><span>Quantità</span><?php echo esc_html( (string) $d['qty'] ); ?></li>
		<?php endif; ?>
		<?php if ( 'na' !== $d['condition'] && ! $d['is_notice'] ) : ?>
			<li><span>Stato</span><?php echo esc_html( Dealer_Board::CONDITIONS[ $d['condition'] ] ?? '' ); ?></li>
		<?php endif; ?>
	</ul>

	<footer class="dealer-board-card-foot">
		<?php if ( $d['is_mine'] ) : ?>

			<form method="post" action="<?php echo esc_url( $post_url ); ?>" class="dealer-board-inline">
				<?php wp_nonce_field( 'dealer_board_update' ); ?>
				<input type="hidden" name="action" value="dealer_board_update">
				<input type="hidden" name="listing" value="<?php echo esc_attr( (string) $d['id'] ); ?>">
				<?php if ( Dealer_Board::STATUS_ACTIVE === $d['status'] ) : ?>
					<button class="dealer-board-btn" name="op" value="solve">
						<?php echo $d['is_notice'] ? 'Ritira' : 'Segna come risolto'; ?>
					</button>
				<?php else : ?>
					<button class="dealer-board-btn" name="op" value="renew">Ripubblica</button>
				<?php endif; ?>
				<button class="dealer-board-btn dealer-board-btn-quiet" name="op" value="delete"
					onclick="return confirm('Eliminare definitivamente questo annuncio?');">Elimina</button>
			</form>
			<span class="dealer-board-expiry">Scade il <?php echo esc_html( $d['expiry'] ); ?></span>

			<?php
			// Le risposte ricevute: le vede solo chi ha pubblicato l'annuncio.
			// Non e' un thread — nessun altro le legge e non si puo' replicare
			// qui dentro — e' l'archivio di chi si e' fatto avanti, che prima
			// esisteva solo nella casella di posta e spariva se l'email non
			// arrivava.
			$b_replies = Dealer_Board::get_replies( $d['id'] );
			?>
			<?php if ( ! empty( $b_replies ) ) : ?>
				<details class="dealer-board-replies" open>
					<summary><?php echo esc_html( sprintf( 'Risposte ricevute (%d)', count( $b_replies ) ) ); ?></summary>
					<?php foreach ( $b_replies as $b_reply ) : ?>
						<div class="dealer-board-reply-item">
							<p class="dealer-board-reply-from">
								<strong><?php echo esc_html( (string) ( $b_reply['name'] ?? '' ) ); ?></strong>
								— <?php echo esc_html( (string) ( $b_reply['org'] ?? '' ) ); ?>
								<span><?php echo esc_html( mysql2date( 'd/m/Y H:i', (string) ( $b_reply['date'] ?? '' ) ) ); ?></span>
							</p>
							<p class="dealer-board-reply-msg"><?php echo nl2br( esc_html( (string) ( $b_reply['message'] ?? '' ) ) ); ?></p>
							<p class="dealer-board-reply-contact">
								<?php if ( ! empty( $b_reply['email'] ) ) : ?>
									<a href="mailto:<?php echo esc_attr( (string) $b_reply['email'] ); ?>"><?php echo esc_html( (string) $b_reply['email'] ); ?></a>
								<?php endif; ?>
								<?php if ( ! empty( $b_reply['phone'] ) ) : ?>
									· <?php echo esc_html( (string) $b_reply['phone'] ); ?>
								<?php endif; ?>
							</p>
						</div>
					<?php endforeach; ?>
				</details>
			<?php endif; ?>

			<?php // Correggere un refuso senza dover ripubblicare: l'annuncio lo vede tutta la rete. ?>
			<details class="dealer-board-replies">
				<summary>Modifica</summary>
				<form method="post" action="<?php echo esc_url( $post_url ); ?>">
					<?php wp_nonce_field( 'dealer_board_update' ); ?>
					<input type="hidden" name="action" value="dealer_board_update">
					<input type="hidden" name="listing" value="<?php echo esc_attr( (string) $d['id'] ); ?>">
					<input type="hidden" name="op" value="edit">
					<input type="hidden" name="b_type" value="<?php echo esc_attr( $d['type'] ); ?>">
					<input type="hidden" name="b_brand" value="<?php echo esc_attr( $d['brand'] ); ?>">
					<input type="hidden" name="b_line" value="<?php echo esc_attr( $d['line'] ); ?>">
					<input type="hidden" name="b_condition" value="<?php echo esc_attr( $d['condition'] ); ?>">
					<input type="hidden" name="b_show_contacts" value="<?php echo $d['show_contacts'] ? '1' : ''; ?>">

					<label>
						<span>Titolo</span>
						<input type="text" name="b_title" maxlength="120" required
							value="<?php echo esc_attr( $d['title'] ); ?>">
					</label>
					<label>
						<span>Descrizione</span>
						<textarea name="b_body" rows="3" maxlength="1500" required><?php echo esc_textarea( $d['body'] ); ?></textarea>
					</label>
					<div class="dealer-board-row">
						<label>
							<span>Codice</span>
							<input type="text" name="b_code" maxlength="60" value="<?php echo esc_attr( $d['code'] ); ?>">
						</label>
						<label>
							<span>Quantità</span>
							<input type="number" name="b_qty" min="0" max="9999" value="<?php echo esc_attr( (string) $d['qty'] ); ?>">
						</label>
						<label>
							<span>Zona</span>
							<input type="text" name="b_area" maxlength="60" value="<?php echo esc_attr( $d['area'] ); ?>">
						</label>
					</div>
					<label class="dealer-board-check">
						<input type="checkbox" name="b_replies_on" value="1" <?php checked( $d['replies_on'] ); ?>>
						<span>Accetta risposte</span>
					</label>
					<button type="submit" class="dealer-board-btn dealer-board-btn-primary">Salva</button>
				</form>
			</details>

		<?php elseif ( Dealer_Board::STATUS_ACTIVE === $d['status'] ) : ?>

			<?php if ( $d['show_contacts'] ) : ?>
				<p class="dealer-board-contacts">
					<?php if ( '' !== $d['contacts']['email'] ) : ?>
						<a href="mailto:<?php echo esc_attr( $d['contacts']['email'] ); ?>"><?php echo esc_html( $d['contacts']['email'] ); ?></a>
					<?php endif; ?>
					<?php if ( '' !== $d['contacts']['phone'] ) : ?>
						· <?php echo esc_html( $d['contacts']['phone'] ); ?>
					<?php endif; ?>
				</p>
			<?php endif; ?>

			<?php if ( $d['replies_on'] ) : ?>
				<details class="dealer-board-reply"<?php echo $open === $d['id'] ? ' open' : ''; ?>>
					<summary>Rispondi</summary>
					<form method="post" action="<?php echo esc_url( $post_url ); ?>">
						<?php wp_nonce_field( 'dealer_board_reply' ); ?>
						<input type="hidden" name="action" value="dealer_board_reply">
						<input type="hidden" name="listing" value="<?php echo esc_attr( (string) $d['id'] ); ?>">
						<label class="screen-reader-text" for="msg-<?php echo esc_attr( (string) $d['id'] ); ?>">Messaggio</label>
						<textarea id="msg-<?php echo esc_attr( (string) $d['id'] ); ?>" name="b_message" rows="3" required
							placeholder="Scrivi a chi ha pubblicato l’annuncio. Riceverà il tuo nome, la tua azienda e i tuoi recapiti."></textarea>
						<button type="submit" class="dealer-board-btn dealer-board-btn-primary">Invia</button>
					</form>
				</details>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( $post_url ); ?>" class="dealer-board-inline">
				<?php wp_nonce_field( 'dealer_board_report' ); ?>
				<input type="hidden" name="action" value="dealer_board_report">
				<input type="hidden" name="listing" value="<?php echo esc_attr( (string) $d['id'] ); ?>">
				<button type="submit" class="dealer-board-report"
					onclick="return confirm('Segnalare questo annuncio agli amministratori?');">Segnala</button>
			</form>
		<?php endif; ?>
	</footer>
</article>
