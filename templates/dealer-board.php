<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/**
 * Bacheca della rete — shortcode [dealer_bacheca].
 *
 * Due viste sulla stessa pagina, distinte da $view:
 *  - 'list' : comunicazioni in evidenza, filtri, elenco annunci;
 *  - 'new'  : solo il modulo di pubblicazione.
 * Non sono due pagine WordPress: vedi il commento in Dealer_Board::render().
 *
 * Variabili da Dealer_Board::render():
 * @var WP_User   $user
 * @var string    $view      'list' | 'new'
 * @var array     $result    {items, total, pages}
 * @var WP_Post[] $notices   comunicazioni in evidenza (solo nella vista elenco)
 * @var array     $filters   type, brand, line, s, mine
 * @var array     $types     tipi che QUESTO utente può pubblicare
 * @var int       $paged
 * @var string    $base_url  permalink della pagina
 * @var string    $new_url   permalink con la vista di pubblicazione
 * @var string    $post_url  admin-post.php
 * @var array     $lines     catalogo brand => linee
 * @var array     $options   configurazione della bacheca
 * @var int       $active    annunci attivi dell'utente
 * @var bool      $can_post
 * @var string    $org_name  azienda con cui l'utente pubblica
 * @var string    $messages  esito dell'ultima azione
 * @var array     $sent_list risposte inviate dall'utente, le più recenti per prime
 * @var int       $open      annuncio da aprire (dopo un'azione)
 */

/**
 * Scheda di un annuncio. Chiusa in una funzione perché la stessa scheda serve
 * sia alla fascia delle comunicazioni sia all'elenco, e duplicarne il markup
 * significherebbe correggerlo due volte ogni volta.
 */
if ( ! function_exists( 'dealer_board_card' ) ) {
	function dealer_board_card( WP_Post $listing, string $post_url, string $base_url, int $open ): void {
		$d = Dealer_Board::view_data( $listing );
		require DEALER_PORTAL_PATH . 'templates/dealer-board-card.php';
	}
}
?>
<div class="dealer-board-wrap">

<?php if ( 'new' === $view ) : ?>

	<!-- ══ VISTA: pubblica un annuncio ═══════════════════════════════════ -->
	<div class="dealer-board-head">
		<div>
			<h2 class="dealer-board-title">Pubblica un annuncio</h2>
			<p class="dealer-board-sub">Resterà visibile a tutte le aziende del portale fino alla scadenza.</p>
		</div>
		<div class="dealer-board-actions">
			<a class="dealer-board-btn" href="<?php echo esc_url( $base_url ); ?>">← Torna alla bacheca</a>
		</div>
	</div>

	<?php if ( ! $can_post ) : ?>
		<p class="dealer-board-empty">
			Hai raggiunto il limite di annunci (<?php echo esc_html( (string) $active ); ?> attivi,
			massimo <?php echo esc_html( (string) $options['max_active'] ); ?>, e
			<?php echo esc_html( (string) $options['max_per_day'] ); ?> al giorno).
			Chiudi un annuncio risolto per pubblicarne uno nuovo.
		</p>
	<?php else : ?>
		<section class="dealer-board-form">
			<form method="post" action="<?php echo esc_url( $post_url ); ?>" enctype="multipart/form-data">
				<?php wp_nonce_field( 'dealer_board_publish' ); ?>
				<input type="hidden" name="action" value="dealer_board_publish">

				<div class="dealer-board-row">
					<label>
						<span>Tipo</span>
						<select name="b_type" id="dealer-board-type">
							<?php foreach ( $types as $t_key => $t_label ) : ?>
								<option value="<?php echo esc_attr( $t_key ); ?>"><?php echo esc_html( $t_label ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<label class="dealer-board-grow">
						<span>Titolo</span>
						<input type="text" name="b_title" maxlength="120" required
							placeholder="Es. Elica di manovra 24V — cerco urgente">
					</label>
				</div>

				<?php if ( isset( $types[ Dealer_Board::TYPE_NOTICE ] ) ) : ?>
					<p class="dealer-board-hint">
						<strong>Comunicazione</strong> è riservata a chi pubblica per conto della rete:
						compare in evidenza in cima alla bacheca, non scorre con gli annunci e resta
						visibile <?php echo esc_html( (string) $options['notice_duration_days'] ); ?> giorni.
					</p>
				<?php endif; ?>

				<label>
					<span>Descrizione</span>
					<textarea name="b_body" rows="5" maxlength="1500" required
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
					<input type="checkbox" name="b_replies_on" value="1" checked>
					<span>
						Accetta risposte.
						<em>Togli la spunta se non vuoi essere contattato tramite la bacheca — utile per
						una comunicazione che non richiede risposta. Il pulsante “Rispondi” non comparirà.</em>
					</span>
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
				</p>

				<button type="submit" class="dealer-board-btn dealer-board-btn-primary">Pubblica</button>
			</form>
		</section>
	<?php endif; ?>

<?php else : ?>

	<!-- ══ VISTA: bacheca ════════════════════════════════════════════════ -->
	<div class="dealer-board-head">
		<div>
			<h2 class="dealer-board-title">Bacheca della rete</h2>
			<p class="dealer-board-sub">
				Chiedi un pezzo che ti serve, o segnala quello che hai in più. Gli annunci
				sono visibili a tutte le aziende del portale.
			</p>
		</div>
		<div class="dealer-board-actions">
			<a class="dealer-board-btn dealer-board-btn-primary" href="<?php echo esc_url( $new_url ); ?>">Pubblica un annuncio</a>
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

	<?php // Comunicazioni della rete: in cima, fuori dalla paginazione. ?>
	<?php if ( ! empty( $notices ) ) : ?>
		<section class="dealer-board-pinned" aria-label="Comunicazioni della rete">
			<?php foreach ( $notices as $listing ) : ?>
				<?php dealer_board_card( $listing, $post_url, $base_url, $open ); ?>
			<?php endforeach; ?>
		</section>
	<?php endif; ?>

	<?php // Copia di ciò che l'utente ha mandato: l'annuncio può essere già stato chiuso o eliminato. ?>
	<?php if ( ! empty( $sent_list ) ) : ?>
		<details class="dealer-board-sent">
			<summary><?php echo esc_html( sprintf( 'Le risposte che hai inviato (%d)', count( $sent_list ) ) ); ?></summary>
			<?php foreach ( $sent_list as $b_sent ) : ?>
				<div class="dealer-board-reply-item">
					<p class="dealer-board-reply-from">
						<strong>
							<?php
							$b_sent_id    = (int) ( $b_sent['listing_id'] ?? 0 );
							$b_sent_title = (string) ( $b_sent['title'] ?? '' );
							$b_sent_alive = $b_sent_id && Dealer_Board::CPT === get_post_type( $b_sent_id );
							?>
							<?php if ( $b_sent_alive ) : ?>
								<a href="<?php echo esc_url( $base_url . '#annuncio-' . $b_sent_id ); ?>">
									<?php echo esc_html( $b_sent_title ); ?>
								</a>
							<?php else : ?>
								<?php echo esc_html( $b_sent_title ); ?>
								<em>(annuncio non più in bacheca)</em>
							<?php endif; ?>
						</strong>
						<span><?php echo esc_html( mysql2date( 'd/m/Y H:i', (string) ( $b_sent['date'] ?? '' ) ) ); ?></span>
					</p>
					<p class="dealer-board-reply-msg"><?php echo nl2br( esc_html( (string) ( $b_sent['message'] ?? '' ) ) ); ?></p>
				</div>
			<?php endforeach; ?>
		</details>
	<?php endif; ?>

	<!-- ── Filtri ───────────────────────────────────────────────────────── -->
	<form class="dealer-board-filters" method="get" action="<?php echo esc_url( $base_url ); ?>">
		<?php if ( $filters['mine'] ) : ?>
			<input type="hidden" name="b_mine" value="1">
		<?php endif; ?>

		<input type="search" name="b_s" value="<?php echo esc_attr( $filters['s'] ); ?>"
			placeholder="Cerca per titolo, codice o descrizione…" aria-label="Cerca nella bacheca">

		<select name="b_type" aria-label="Tipo di annuncio">
			<option value="">Tutti i tipi</option>
			<?php foreach ( Dealer_Board::all_types() as $t_key => $t_label ) : ?>
				<option value="<?php echo esc_attr( $t_key ); ?>" <?php selected( $filters['type'], $t_key ); ?>>
					<?php echo esc_html( $t_label ); ?>
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

	<?php if ( empty( $result['items'] ) ) : ?>
		<p class="dealer-board-empty">
			<?php echo $filters['mine']
				? 'Non hai ancora pubblicato annunci.'
				: 'Nessun annuncio corrisponde a questi filtri.'; ?>
		</p>
	<?php else : ?>
		<div class="dealer-board-grid">
			<?php foreach ( $result['items'] as $listing ) : ?>
				<?php dealer_board_card( $listing, $post_url, $base_url, $open ); ?>
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

	<p class="dealer-board-disclaimer"><?php echo esc_html( (string) $options['disclaimer'] ); ?></p>

<?php endif; ?>

</div>
