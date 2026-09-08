<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/**
 * Dealer Portal → Bacheca.
 *
 * Variabili da Dealer_Board::render_admin_page():
 * @var array     $options
 * @var WP_Post[] $reported  annunci nascosti dalle segnalazioni
 * @var WP_Post[] $recent    ultimi annunci pubblicati
 * @var string    $post_url
 * @var string    $notice
 */

// Difesa in profondità: il template non viene mai reso senza capability.
if ( ! Dealer_DB::user_can( DEALER_PORTAL_CAP ) ) {
	wp_die( esc_html__( 'Accesso non consentito.', 'dealer-portal' ) );
}
?>
<div class="wrap">
	<h1>Bacheca</h1>

	<?php if ( $notice ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php echo esc_html( (string) $notice ); ?></p></div>
	<?php endif; ?>

	<div class="notice notice-info inline" style="margin:16px 0;">
		<p>
			La bacheca è pensata per funzionare <strong>senza moderazione attiva</strong>: gli annunci
			scadono da soli, non ci sono commenti pubblici, ogni annuncio porta il nome dell’azienda che
			lo ha pubblicato e la rete può segnalare. Qui arriva solo ciò che i meccanismi automatici
			hanno già fermato.
		</p>
		<p>
			Pagina pubblica dell’area riservata:
			<a href="<?php echo esc_url( Dealer_DB::board_url() ); ?>" target="_blank" rel="noopener">
				<?php echo esc_html( Dealer_DB::board_url() ); ?>
			</a>
		</p>
	</div>

	<!-- ═══ 1. Segnalazioni ═══════════════════════════════════════════════ -->
	<h2>Annunci segnalati <span style="font-weight:400;color:#787c82;">(<?php echo esc_html( (string) count( $reported ) ); ?>)</span></h2>

	<?php if ( empty( $reported ) ) : ?>
		<p>Nessun annuncio segnalato. Niente da fare.</p>
	<?php else : ?>
		<table class="wp-list-table widefat striped">
			<thead>
				<tr>
					<th>Annuncio</th>
					<th style="width:18%">Azienda</th>
					<th style="width:10%">Segnalazioni</th>
					<th style="width:20%">Azioni</th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $reported as $listing ) :
				$reports = (array) get_post_meta( $listing->ID, Dealer_Board::META_REPORTS, true );
				?>
				<tr>
					<td>
						<strong><?php echo esc_html( $listing->post_title ); ?></strong><br>
						<small><?php echo esc_html( wp_trim_words( $listing->post_content, 40 ) ); ?></small>
					</td>
					<td><?php echo esc_html( (string) get_post_meta( $listing->ID, Dealer_Board::META_ORG_NAME, true ) ); ?></td>
					<td><?php echo esc_html( (string) count( $reports ) ); ?></td>
					<td>
						<form method="post" action="<?php echo esc_url( $post_url ); ?>" style="display:inline;">
							<?php wp_nonce_field( 'dealer_board_moderate' ); ?>
							<input type="hidden" name="action" value="dealer_board_moderate">
							<input type="hidden" name="listing" value="<?php echo esc_attr( (string) $listing->ID ); ?>">
							<button class="button button-small" name="op" value="restore">Ripristina</button>
							<button class="button button-small" name="op" value="delete"
								onclick="return confirm('Eliminare definitivamente questo annuncio?');">Elimina</button>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<!-- ═══ 2. Impostazioni ═══════════════════════════════════════════════ -->
	<h2 style="margin-top:32px;">Impostazioni</h2>
	<form method="post" action="<?php echo esc_url( $post_url ); ?>">
		<?php wp_nonce_field( 'dealer_board_settings' ); ?>
		<input type="hidden" name="action" value="dealer_board_settings">

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">Bacheca attiva</th>
				<td>
					<label>
						<input type="checkbox" name="b_enabled" value="1" <?php checked( ! empty( $options['enabled'] ) ); ?>>
						La pagina è utilizzabile dalla rete
					</label>
					<p class="description">
						Spegnendola la pagina resta al suo posto ma mostra un avviso, e nessuno può
						pubblicare o rispondere. Nulla viene cancellato.
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="b_duration">Durata di un annuncio</label></th>
				<td>
					<input type="number" id="b_duration" name="b_duration" min="7" max="180"
						value="<?php echo esc_attr( (string) $options['duration_days'] ); ?>" class="small-text"> giorni
					<p class="description">
						È il meccanismo che tiene pulita la bacheca: alla scadenza l’annuncio si archivia
						da solo. Chi lo ha pubblicato può sempre ripubblicarlo.
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="b_reminder">Promemoria prima della scadenza</label></th>
				<td>
					<input type="number" id="b_reminder" name="b_reminder" min="1" max="30"
						value="<?php echo esc_attr( (string) $options['reminder_days'] ); ?>" class="small-text"> giorni
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="b_max_active">Annunci attivi per utente</label></th>
				<td>
					<input type="number" id="b_max_active" name="b_max_active" min="1" max="100"
						value="<?php echo esc_attr( (string) $options['max_active'] ); ?>" class="small-text">
					<p class="description">Evita che una sola azienda occupi da sola una pagina di risultati.</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="b_max_day">Pubblicazioni al giorno</label></th>
				<td>
					<input type="number" id="b_max_day" name="b_max_day" min="1" max="50"
						value="<?php echo esc_attr( (string) $options['max_per_day'] ); ?>" class="small-text">
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="b_threshold">Segnalazioni per nascondere</label></th>
				<td>
					<input type="number" id="b_threshold" name="b_threshold" min="2" max="20"
						value="<?php echo esc_attr( (string) $options['report_threshold'] ); ?>" class="small-text">
					<p class="description">
						Segnalazioni da utenti <strong>diversi</strong>. Raggiunta la soglia l’annuncio
						si nasconde e compare qui sopra.
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="b_disclaimer">Nota in fondo alla pagina</label></th>
				<td>
					<textarea id="b_disclaimer" name="b_disclaimer" rows="4" class="large-text"><?php echo esc_textarea( (string) $options['disclaimer'] ); ?></textarea>
					<p class="description">
						Chiarisce che il portale ospita l’annuncio e non è parte della trattativa.
						Modificabile da qui: fallo rivedere a chi vi segue sul piano legale senza toccare il codice.
					</p>
				</td>
			</tr>
		</table>

		<?php submit_button( 'Salva impostazioni' ); ?>
	</form>

	<!-- ═══ 3. Ultimi annunci ═════════════════════════════════════════════ -->
	<h2 style="margin-top:32px;">Ultimi annunci</h2>
	<?php if ( empty( $recent ) ) : ?>
		<p>Nessun annuncio pubblicato finora.</p>
	<?php else : ?>
		<table class="wp-list-table widefat striped">
			<thead>
				<tr>
					<th>Annuncio</th>
					<th style="width:10%">Tipo</th>
					<th style="width:20%">Azienda</th>
					<th style="width:10%">Stato</th>
					<th style="width:12%">Scadenza</th>
					<th style="width:12%">Azioni</th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $recent as $listing ) : ?>
				<tr>
					<td><strong><?php echo esc_html( $listing->post_title ); ?></strong></td>
					<td><?php echo esc_html( Dealer_Board::type_label( (string) get_post_meta( $listing->ID, Dealer_Board::META_TYPE, true ) ) ); ?></td>
					<td><?php echo esc_html( (string) get_post_meta( $listing->ID, Dealer_Board::META_ORG_NAME, true ) ); ?></td>
					<td><?php echo esc_html( (string) get_post_meta( $listing->ID, Dealer_Board::META_STATUS, true ) ); ?></td>
					<td><?php echo esc_html( (string) get_post_meta( $listing->ID, Dealer_Board::META_EXPIRY, true ) ); ?></td>
					<td>
						<form method="post" action="<?php echo esc_url( $post_url ); ?>">
							<?php wp_nonce_field( 'dealer_board_moderate' ); ?>
							<input type="hidden" name="action" value="dealer_board_moderate">
							<input type="hidden" name="listing" value="<?php echo esc_attr( (string) $listing->ID ); ?>">
							<button class="button button-small" name="op" value="delete"
								onclick="return confirm('Eliminare definitivamente questo annuncio?');">Elimina</button>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
