<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/**
 * Bacheca della rete — shortcode [dealer_bacheca].
 *
 * Variabili da Dealer_Board::render():
 * @var WP_User $user
 * @var array   $result    {items, total, pages}
 * @var array   $filters   type, brand, line, s, mine
 * @var int     $paged
 * @var string  $base_url  permalink della pagina
 * @var string  $post_url  admin-post.php
 * @var array   $lines     catalogo brand => linee
 * @var array   $options   configurazione della bacheca
 * @var int     $active    annunci attivi dell'utente
 * @var bool    $can_post
 * @var string  $org_name  azienda con cui l'utente pubblica
 * @var string  $messages  esito dell'ultima azione
 * @var int     $open      annuncio da aprire (dopo un'azione)
 */

$b_types = [
	Dealer_Board::TYPE_WANTED  => 'Cerco',
	Dealer_Board::TYPE_OFFERED => 'Offro',
];
?>
<div class="dealer-board-wrap">

	<div class="dealer-board-head">
		<div>
			<h2 class="dealer-board-title">Bacheca della rete</h2>
			<p class="dealer-board-sub">
				Chiedi un pezzo che ti serve, o segnala quello che hai in più. Gli annunci
				sono visibili a tutte le aziende del portale.
			</p>
		</div>
		<div class="dealer-board-actions">
			<a class="dealer-board-btn dealer-board-btn-primary" href="#dealer-board-new">Pubblica un annuncio</a>
			<?php if ( $filters['mine'] ) : ?>
				<a class="dealer-board-btn" href="<?php echo esc_url( $base_url ); ?>">Tutti gli annunci</a>
			<?php else : ?>
				<a class="dealer-board-btn" href="<?php echo esc_url( add_query_arg( 'b_mine', 1, $base_url ) ); ?>">I miei annunci</a>
			<?php endif; ?>
		</div>
	</div>

	<?php if ( '' !== $messages ) : ?>
		<p class="dealer-board-notice"><?php echo esc_html( $messages ); ?></p>
	<?php endif; ?>

	<!-- ── Filtri ───────────────────────────────────────────────────────── -->
	<form class="dealer-board-filters" method="get" action="<?php echo esc_url( $base_url ); ?>">
		<?php if ( $filters['mine'] ) : ?>
			<input type="hidden" name="b_mine" value="1">
		<?php endif; ?>

		<input type="search" name="b_s" value="<?php echo esc_attr( $filters['s'] ); ?>"
			placeholder="Cerca per titolo, codice o descrizione…" aria-label="Cerca nella bacheca">

		<select name="b_type" aria-label="Tipo di annuncio">
			<option value="">Cerco e Offro</option>
			<?php foreach ( $b_types as $b_key => $b_label ) : ?>
				<option value="<?php echo esc_attr( $b_key ); ?>" <?php selected( $filters['type'], $b_key ); ?>>
					<?php echo esc_html( $b_label ); ?>
				</option>
			<?php endforeach; ?>
		</select>

		<select name="b_brand" aria-label="Brand">
			<option value="">Tutti i brand</option>
			<?php foreach ( array_keys( $lines ) as $b_brand ) : ?>
				<option value="<?php echo esc_attr( $b_brand ); ?>" <?php selected( $filters['brand'], $b_brand ); ?>>
					<?php echo esc_html( $b_brand ); ?>
				</option>
			<?php endforeach; ?>
		</select>

		<button type="submit" class="dealer-board-btn">Filtra</button>
		<a class="dealer-board-btn dealer-board-btn-quiet" href="<?php echo esc_url( $base_url ); ?>">Azzera</a>
	</form>

	<p class="dealer-board-count">
		<?php
		printf(
			'%d %s',
			(int) $result['total'],
			1 === (int) $result['total'] ? 'annuncio' : 'annunci'
		);
		?>
	</p>

	<!-- ── Elenco ───────────────────────────────────────────────────────── -->
	<?php if ( empty( $result['items'] ) ) : ?>
		<p class="dealer-board-empty">
			<?php echo $filters['mine']
				? 'Non hai ancora pubblicato annunci.'
				: 'Nessun annuncio corrisponde a questi filtri.'; ?>
		</p>
	<?php else : ?>
		<div class="dealer-board-grid">
			<?php foreach ( $result['items'] as $listing ) :
				$d = Dealer_Board::view_data( $listing );
				?>
				<article class="dealer-board-card<?php echo $d['is_mine'] ? ' is-mine' : ''; ?>" id="annuncio-<?php echo esc_attr( (string) $d['id'] ); ?>">

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
						<?php if ( $d['qty'] > 0 ) : ?>
							<li><span>Quantità</span><?php echo esc_html( (string) $d['qty'] ); ?></li>
						<?php endif; ?>
						<?php if ( 'na' !== $d['condition'] ) : ?>
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
									<button class="dealer-board-btn" name="op" value="solve">Segna come risolto</button>
								<?php else : ?>
									<button class="dealer-board-btn" name="op" value="renew">Ripubblica</button>
								<?php endif; ?>
								<button class="dealer-board-btn dealer-board-btn-quiet" name="op" value="delete"
									onclick="return confirm('Eliminare definitivamente questo annuncio?');">Elimina</button>
							</form>
							<span class="dealer-board-expiry">Scade il <?php echo esc_html( $d['expiry'] ); ?></span>

							<?php
							// Le risposte ricevute: le vede solo chi ha pubblicato
							// l'annuncio. Non e' un thread — nessun altro le legge e
							// non si puo' replicare qui dentro — e' l'archivio di chi
							// si e' fatto avanti, che prima esisteva solo nella
							// casella di posta e spariva se l'email non arrivava.
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
							<details class="dealer-board-reply">
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
			<?php endforeach; ?>
		</div>

		<?php if ( (int) $result['pages'] > 1 ) : ?>
			<nav class="dealer-board-pagination" aria-label="Pagine della bacheca">
				<?php
				echo wp_kses_post( paginate_links( [
					'base'      => esc_url_raw( add_query_arg( 'b_page', '%#%', $base_url ) ),
					'format'    => '',
					'current'   => (int) $paged,
					'total'     => (int) $result['pages'],
					'prev_text' => '‹',
					'next_text' => '›',
				] ) );
				?>
			</nav>
		<?php endif; ?>
	<?php endif; ?>

	<!-- ── Nuovo annuncio ───────────────────────────────────────────────── -->
	<section class="dealer-board-form" id="dealer-board-new">
		<h3>Pubblica un annuncio</h3>

		<?php if ( ! $can_post ) : ?>
			<p class="dealer-board-empty">
				Hai raggiunto il limite di annunci (<?php echo esc_html( (string) $active ); ?> attivi,
				massimo <?php echo esc_html( (string) $options['max_active'] ); ?>, e
				<?php echo esc_html( (string) $options['max_per_day'] ); ?> al giorno).
				Chiudi un annuncio risolto per pubblicarne uno nuovo.
			</p>
		<?php else : ?>
			<form method="post" action="<?php echo esc_url( $post_url ); ?>" enctype="multipart/form-data">
				<?php wp_nonce_field( 'dealer_board_publish' ); ?>
				<input type="hidden" name="action" value="dealer_board_publish">

				<div class="dealer-board-row">
					<label>
						<span>Tipo</span>
						<select name="b_type">
							<?php foreach ( $b_types as $b_key => $b_label ) : ?>
								<option value="<?php echo esc_attr( $b_key ); ?>"><?php echo esc_html( $b_label ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<label class="dealer-board-grow">
						<span>Titolo</span>
						<input type="text" name="b_title" maxlength="120" required
							placeholder="Es. Elica di manovra 24V — cerco urgente">
					</label>
				</div>

				<label>
					<span>Descrizione</span>
					<textarea name="b_body" rows="4" maxlength="1500" required
						placeholder="Cosa ti serve o cosa hai disponibile. Niente prezzi: quelli si concordano in privato."></textarea>
				</label>

				<div class="dealer-board-row">
					<label>
						<span>Codice articolo <em>(facoltativo)</em></span>
						<input type="text" name="b_code" maxlength="60">
					</label>
					<label>
						<span>Quantità</span>
						<input type="number" name="b_qty" min="0" max="9999" value="1">
					</label>
					<label>
						<span>Stato</span>
						<select name="b_condition">
							<?php foreach ( Dealer_Board::CONDITIONS as $c_key => $c_label ) : ?>
								<option value="<?php echo esc_attr( $c_key ); ?>"><?php echo esc_html( $c_label ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
				</div>

				<div class="dealer-board-row">
					<label>
						<span>Brand <em>(facoltativo)</em></span>
						<select name="b_brand">
							<option value="">—</option>
							<?php foreach ( array_keys( $lines ) as $b_brand ) : ?>
								<option value="<?php echo esc_attr( $b_brand ); ?>"><?php echo esc_html( $b_brand ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<label>
						<span>Linea <em>(facoltativo)</em></span>
						<select name="b_line">
							<option value="">—</option>
							<?php foreach ( $lines as $b_brand => $b_lines ) : ?>
								<optgroup label="<?php echo esc_attr( $b_brand ); ?>">
									<?php foreach ( (array) $b_lines as $b_line ) : ?>
										<option value="<?php echo esc_attr( $b_line ); ?>"><?php echo esc_html( $b_line ); ?></option>
									<?php endforeach; ?>
								</optgroup>
							<?php endforeach; ?>
						</select>
					</label>
					<label>
						<span>Zona <em>(facoltativo)</em></span>
						<input type="text" name="b_area" maxlength="60" placeholder="Es. Liguria">
					</label>
				</div>

				<label>
					<span>Foto <em>(fino a <?php echo esc_html( (string) Dealer_Board::MAX_IMAGES ); ?>, JPG/PNG/WEBP)</em></span>
					<input type="file" name="b_images[]" accept="image/jpeg,image/png,image/webp" multiple>
				</label>

				<label class="dealer-board-check">
					<input type="checkbox" name="b_show_contacts" value="1">
					<span>
						Mostra i miei recapiti nell’annuncio.
						<em>Se lasci la casella vuota nessuno vedrà la tua email o il tuo telefono: chi è
						interessato ti scriverà tramite il portale e riceverai il messaggio per email.
						Se la spunti, email e telefono del referente saranno visibili a tutti gli utenti
						registrati del portale.</em>
					</span>
				</label>

				<p class="dealer-board-signature">
					Pubblicherai come <strong><?php echo esc_html( $org_name ); ?></strong> —
					<?php echo esc_html( $user->display_name ); ?>.
					L’annuncio resterà in bacheca <?php echo esc_html( (string) $options['duration_days'] ); ?> giorni.
				</p>

				<button type="submit" class="dealer-board-btn dealer-board-btn-primary">Pubblica</button>
			</form>
		<?php endif; ?>
	</section>

	<p class="dealer-board-disclaimer"><?php echo esc_html( (string) $options['disclaimer'] ); ?></p>
</div>
