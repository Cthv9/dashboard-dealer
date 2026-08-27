<?php
/**
 * Form pubblico di richiesta accesso — shortcode [dealer_access_request].
 *
 * Variabili fornite da Dealer_Access_Request::render_form_shortcode():
 * @var string $title
 * @var bool   $is_logged_in
 * @var array  $lines_by_brand
 * @var array  $prefill
 * @var array  $feedback
 * @var int    $form_time
 * @var string $form_time_hash
 * @var string $form_action
 * @var string $honeypot
 *
 * Stile scoped: non tocca assets/css/dealer.css, ma ne riprende la palette
 * (--dp-blue #1e6fa8, --dp-navy #0a1628).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$p_company = isset( $prefill['company'] ) ? (string) $prefill['company'] : '';
$p_contact = isset( $prefill['contact'] ) ? (string) $prefill['contact'] : '';
$p_email   = isset( $prefill['email'] )   ? (string) $prefill['email']   : '';
$p_phone   = isset( $prefill['phone'] )   ? (string) $prefill['phone']   : '';
$p_vat     = isset( $prefill['vat'] )     ? (string) $prefill['vat']     : '';
$p_notes   = isset( $prefill['notes'] )   ? (string) $prefill['notes']   : '';
$p_lines   = ( isset( $prefill['lines'] ) && is_array( $prefill['lines'] ) ) ? $prefill['lines'] : [];

$fb_status  = isset( $feedback['status'] )  ? (string) $feedback['status']  : '';
$fb_message = isset( $feedback['message'] ) ? (string) $feedback['message'] : '';
$submitted  = ( 'success' === $fb_status );
?>
<div class="dar-wrap" id="dealer-access-request">
	<style>
		.dar-wrap {
			--dar-navy: #0a1628;
			--dar-blue: #1e6fa8;
			--dar-blue-dk: #155c91;
			--dar-gray: #f4f6f8;
			--dar-border: #dce5ea;
			--dar-text: #1a2535;
			--dar-muted: #52616b;
			--dar-radius: 8px;
			background: var(--dar-gray);
			padding: 40px 20px 56px;
			font-family: inherit;
			color: var(--dar-text);
		}
		.dar-inner { max-width: 780px; margin: 0 auto; }
		.dar-head {
			background: linear-gradient(135deg, var(--dar-navy) 0%, var(--dar-blue) 100%);
			color: #fff;
			border-radius: var(--dar-radius);
			padding: 28px 30px;
			margin-bottom: 24px;
		}
		.dar-head h2 { margin: 0 0 8px; font-size: 1.5rem; line-height: 1.25; color: #fff; }
		.dar-head p { margin: 0; opacity: .88; font-size: .95rem; line-height: 1.5; }
		.dar-card {
			background: #fff;
			border: 1px solid var(--dar-border);
			border-radius: var(--dar-radius);
			box-shadow: 0 4px 14px rgba(0,0,0,.07);
			padding: 28px 30px;
		}
		.dar-notice {
			border-radius: var(--dar-radius);
			padding: 16px 18px;
			margin-bottom: 22px;
			font-size: .95rem;
			line-height: 1.5;
			border-left: 4px solid var(--dar-blue);
			background: #eef5fa;
			color: var(--dar-text);
		}
		.dar-notice-error { border-left-color: #c0392b; background: #fdecea; }
		.dar-notice-success { border-left-color: #27ae60; background: #eafaf0; }
		.dar-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 18px; }
		.dar-field { display: flex; flex-direction: column; }
		.dar-field-full { grid-column: 1 / -1; }
		.dar-field label { font-weight: 600; font-size: .9rem; margin-bottom: 6px; }
		.dar-req { color: #c0392b; }
		.dar-field input[type="text"],
		.dar-field input[type="email"],
		.dar-field input[type="tel"],
		.dar-field textarea {
			width: 100%;
			box-sizing: border-box;
			border: 1px solid var(--dar-border);
			border-radius: 6px;
			padding: 10px 12px;
			font-size: .95rem;
			font-family: inherit;
			color: var(--dar-text);
			background: #fff;
		}
		.dar-field input:focus, .dar-field textarea:focus {
			outline: none;
			border-color: var(--dar-blue);
			box-shadow: 0 0 0 3px rgba(30,111,168,.15);
		}
		.dar-field textarea { min-height: 96px; resize: vertical; }
		.dar-hint { font-size: .82rem; color: var(--dar-muted); margin-top: 6px; }
		.dar-lines {
			border: 1px solid var(--dar-border);
			border-radius: 6px;
			background: #fff;
			max-height: 300px;
			overflow-y: auto;
			padding: 12px 14px;
		}
		.dar-brand { margin-bottom: 14px; }
		.dar-brand:last-child { margin-bottom: 0; }
		.dar-brand-name {
			font-weight: 700;
			font-size: .82rem;
			text-transform: uppercase;
			letter-spacing: .04em;
			color: var(--dar-blue);
			margin-bottom: 6px;
		}
		.dar-line-opts { display: flex; flex-wrap: wrap; gap: 6px 16px; }
		.dar-line-opt { font-size: .9rem; font-weight: 400; display: inline-flex; align-items: center; gap: 6px; cursor: pointer; }
		.dar-hp {
			position: absolute !important;
			left: -9999px !important;
			width: 1px; height: 1px;
			overflow: hidden;
		}
		.dar-actions { margin-top: 24px; display: flex; align-items: center; gap: 16px; flex-wrap: wrap; }
		.dar-submit {
			background: var(--dar-blue);
			color: #fff;
			border: none;
			border-radius: 6px;
			padding: 12px 26px;
			font-size: .98rem;
			font-weight: 600;
			cursor: pointer;
			font-family: inherit;
		}
		.dar-submit:hover { background: var(--dar-blue-dk); }
		.dar-privacy { font-size: .82rem; color: var(--dar-muted); margin: 0; }
		@media (max-width: 640px) {
			.dar-grid { grid-template-columns: 1fr; }
			.dar-wrap { padding: 24px 14px 40px; }
			.dar-card, .dar-head { padding: 20px 18px; }
		}
	</style>

	<div class="dar-inner">
		<div class="dar-head">
			<h2><?php echo esc_html( $title ); ?></h2>
			<p>Compila il modulo: il nostro staff verificherà i dati e ti invierà via email le istruzioni per accedere all’area riservata.</p>
		</div>

		<?php if ( '' !== $fb_message ) : ?>
			<div class="dar-notice <?php echo $submitted ? 'dar-notice-success' : 'dar-notice-error'; ?>" role="alert">
				<?php echo esc_html( $fb_message ); ?>
			</div>
		<?php endif; ?>

		<?php if ( $is_logged_in ) : ?>
			<div class="dar-card">
				<p style="margin:0;">
					Risulti già autenticato su questo sito.
					<a href="<?php echo esc_url( site_url( '/dashboard-dealer/' ) ); ?>">Vai alla tua area riservata</a>.
				</p>
			</div>
		<?php elseif ( $submitted ) : ?>
			<div class="dar-card">
				<p style="margin:0;">Grazie: abbiamo registrato la tua richiesta. Non è necessario inviarla di nuovo.</p>
			</div>
		<?php else : ?>
			<div class="dar-card">
				<form method="post" action="<?php echo esc_url( $form_action ); ?>" novalidate>
					<?php wp_nonce_field( 'dealer_access_request', 'dar_nonce' ); ?>
					<input type="hidden" name="dar_action" value="submit_access_request">
					<input type="hidden" name="dar_time" value="<?php echo esc_attr( (string) $form_time ); ?>">
					<input type="hidden" name="dar_time_hash" value="<?php echo esc_attr( $form_time_hash ); ?>">

					<?php // Honeypot: invisibile agli umani, i bot lo compilano. ?>
					<div class="dar-hp" aria-hidden="true">
						<label for="<?php echo esc_attr( $honeypot ); ?>">Non compilare questo campo</label>
						<input type="text" id="<?php echo esc_attr( $honeypot ); ?>" name="<?php echo esc_attr( $honeypot ); ?>" value="" tabindex="-1" autocomplete="off">
					</div>

					<div class="dar-grid">
						<div class="dar-field">
							<label for="dar_company">Ragione sociale <span class="dar-req">*</span></label>
							<input type="text" id="dar_company" name="dar_company" value="<?php echo esc_attr( $p_company ); ?>" maxlength="150" required>
						</div>
						<div class="dar-field">
							<label for="dar_contact">Referente <span class="dar-req">*</span></label>
							<input type="text" id="dar_contact" name="dar_contact" value="<?php echo esc_attr( $p_contact ); ?>" maxlength="120" required>
						</div>
						<div class="dar-field">
							<label for="dar_email">Email <span class="dar-req">*</span></label>
							<input type="email" id="dar_email" name="dar_email" value="<?php echo esc_attr( $p_email ); ?>" maxlength="150" autocomplete="email" required>
						</div>
						<div class="dar-field">
							<label for="dar_phone">Telefono <span class="dar-req">*</span></label>
							<input type="tel" id="dar_phone" name="dar_phone" value="<?php echo esc_attr( $p_phone ); ?>" maxlength="40" autocomplete="tel" required>
						</div>
						<div class="dar-field">
							<label for="dar_vat">Partita IVA <span class="dar-req">*</span></label>
							<input type="text" id="dar_vat" name="dar_vat" value="<?php echo esc_attr( $p_vat ); ?>" maxlength="20" required>
							<span class="dar-hint">Da 8 a 20 caratteri alfanumerici.</span>
						</div>

						<div class="dar-field dar-field-full">
							<label>Brand e linee di interesse <span class="dar-req">*</span></label>
							<div class="dar-lines">
								<?php foreach ( $lines_by_brand as $brand => $brand_lines ) : ?>
									<div class="dar-brand">
										<div class="dar-brand-name"><?php echo esc_html( $brand ); ?></div>
										<div class="dar-line-opts">
											<?php foreach ( $brand_lines as $line ) :
												$value = $brand . '|' . $line;
												?>
												<label class="dar-line-opt">
													<input type="checkbox" name="dar_lines[]" value="<?php echo esc_attr( $value ); ?>"<?php echo in_array( $value, $p_lines, true ) ? ' checked' : ''; ?>>
													<span><?php echo esc_html( $line ); ?></span>
												</label>
											<?php endforeach; ?>
										</div>
									</div>
								<?php endforeach; ?>
							</div>
							<span class="dar-hint">Seleziona almeno una linea. L’assegnazione definitiva viene confermata dal nostro staff.</span>
						</div>

						<div class="dar-field dar-field-full">
							<label for="dar_notes">Note</label>
							<textarea id="dar_notes" name="dar_notes" maxlength="2000" placeholder="Raccontaci brevemente la tua attività o eventuali esigenze particolari."><?php echo esc_textarea( $p_notes ); ?></textarea>
						</div>
					</div>

					<div class="dar-actions">
						<button type="submit" class="dar-submit">Invia richiesta</button>
						<p class="dar-privacy">I dati sono trattati esclusivamente per la valutazione della richiesta di accesso.</p>
					</div>
				</form>
			</div>
		<?php endif; ?>
	</div>
</div>
