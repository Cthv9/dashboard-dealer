<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/**
 * Area "Gestione collaboratori", riservata al titolare.
 *
 * Variabili da Dealer_Team::render():
 *   $title           string   titolo dell'area
 *   $feedback        array    esito del ciclo PRG precedente (status, message)
 *   $user            WP_User  il titolare
 *   $org_id          int      organizzazione del titolare
 *   $org_name        string
 *   $org_tier        string   livello commerciale (slug)
 *   $tier_label      string   livello commerciale (etichetta)
 *   $org_lines       string[] linee effettive dell'AZIENDA ("Brand|Linea")
 *   $lines_by_brand  array    le stesse linee raggruppate per marchio
 *   $members         array    righe gia' normalizzate (vedi collect_members())
 *   $members_count   int
 *   $max_members     int
 *   $can_invite      bool
 *   $suspended       bool     organizzazione sospesa: sola lettura
 *   $form_action     string   URL a cui inviare i form (pattern PRG)
 *
 * Nota: qui non si decide nulla. $can_invite e 'manageable' servono solo a non
 * mostrare comandi che il server rifiuterebbe comunque; l'autorizzazione vera
 * sta negli handler di Dealer_Team.
 */
?>
<div class="dealer-team-wrap" id="dealer-team">

	<style>
	/* Palette allineata a dealer.css (#1e6fa8), ma classi indipendenti: questo
	   modulo non tocca il foglio di stile condiviso. */
	.dealer-team-wrap{--dt-navy:#0a1628;--dt-blue:#1e6fa8;--dt-blue-dk:#155c91;--dt-gray:#f4f6f8;
		--dt-border:#dce5ea;--dt-text:#1a2535;--dt-muted:#52616b;--dt-warn:#b8860b;--dt-danger:#a63232;
		--dt-radius:8px;--dt-shadow:0 4px 14px rgba(0,0,0,.07);
		color:var(--dt-text);box-sizing:border-box;}
	/* Larghezza in una regola a parte, con "body" davanti e !important: vedi
	   il blocco "Wrapper globale" in assets/css/dealer.css per il perché
	   (spiegato lì per non ripeterlo in ogni template) — un tema a blocchi
	   applica ai figli diretti del contenuto un margin:auto !important che
	   altrimenti annulla silenziosamente questa uscita dal contenitore.
	   --dp-vw arriva da assets/js/dealer-layout.js; senza JS ricade su 100vw.
	   Il contenuto resta comunque entro 1080px. */
	body .dealer-team-wrap{
		width:var(--dp-vw,100vw) !important;max-width:var(--dp-vw,100vw) !important;
		margin-left:calc(50% - var(--dp-vw,100vw) / 2) !important;
		margin-right:calc(50% - var(--dp-vw,100vw) / 2) !important;
		padding:0 max(16px,calc((var(--dp-vw,100vw) - 1080px) / 2)) !important;}
	/* Ripiego applicato da dealer-layout.js se un contenitore del tema
	   ritaglia ciò che deborda: stretto ma integro. Stesso !important della
	   regola sopra: deve poterla annullare. */
	body .dealer-team-wrap.dp-no-bleed{
		width:auto !important;max-width:1080px !important;
		margin-left:auto !important;margin-right:auto !important;padding:0 !important;}
	.dealer-team-head{background:var(--dt-navy);color:#fff;border-radius:var(--dt-radius);
		padding:24px 28px;margin-bottom:24px;}
	.dealer-team-head h2{margin:0 0 6px;color:#fff;font-size:1.5rem;line-height:1.25;}
	.dealer-team-org{font-size:1rem;color:#c9d6e2;margin:0;}
	.dealer-team-badges{margin-top:12px;display:flex;flex-wrap:wrap;gap:8px;}
	.dealer-team-badge{display:inline-block;padding:4px 12px;border-radius:99px;font-size:.8rem;
		font-weight:600;background:var(--dt-blue);color:#fff;}
	.dealer-team-badge-soft{background:rgba(255,255,255,.14);color:#e6eef5;}
	.dealer-team-msg{margin:0 0 22px;padding:14px 18px;border-radius:var(--dt-radius);
		border:1px solid var(--dt-border);border-left:4px solid var(--dt-blue);background:#fff;}
	.dealer-team-msg-success{border-left-color:#2e7d4f;background:#f2faf5;}
	.dealer-team-msg-error{border-left-color:var(--dt-danger);background:#fdf4f4;}
	.dealer-team-panel{background:#fff;border:1px solid var(--dt-border);border-radius:var(--dt-radius);
		box-shadow:var(--dt-shadow);padding:22px 24px;margin-bottom:24px;}
	.dealer-team-panel h3{margin:0 0 6px;font-size:1.12rem;color:var(--dt-navy);}
	.dealer-team-hint{margin:0 0 16px;color:var(--dt-muted);font-size:.9rem;}
	.dealer-team-field{display:flex;flex-direction:column;gap:5px;margin-bottom:14px;}
	.dealer-team-field label{font-weight:600;font-size:.9rem;}
	.dealer-team-field input[type=text],.dealer-team-field input[type=email]{
		padding:10px 12px;border:1px solid var(--dt-border);border-radius:6px;font-size:.95rem;
		background:#fff;color:var(--dt-text);width:100%;box-sizing:border-box;}
	.dealer-team-field input:focus{outline:none;border-color:var(--dt-blue);
		box-shadow:0 0 0 3px rgba(30,111,168,.12);}
	.dealer-team-row{display:flex;flex-wrap:wrap;gap:16px;}
	.dealer-team-row .dealer-team-field{flex:1;min-width:240px;}
	.dealer-team-lines{border:1px solid var(--dt-border);border-radius:6px;padding:12px 14px;
		background:var(--dt-gray);max-height:280px;overflow:auto;}
	.dealer-team-brand{margin:0 0 12px;}
	.dealer-team-brand:last-child{margin-bottom:0;}
	.dealer-team-brand-name{font-weight:700;font-size:.85rem;text-transform:uppercase;
		letter-spacing:.03em;color:var(--dt-blue);display:block;margin-bottom:6px;}
	.dealer-team-check{display:inline-flex;align-items:center;gap:6px;font-size:.88rem;
		margin:0 14px 6px 0;}
	.dealer-team-btn{display:inline-block;padding:10px 20px;border:0;border-radius:6px;
		background:var(--dt-blue);color:#fff;font-weight:600;font-size:.92rem;cursor:pointer;}
	.dealer-team-btn:hover{background:var(--dt-blue-dk);}
	.dealer-team-btn-ghost{background:#fff;color:var(--dt-blue);border:1px solid var(--dt-blue);}
	.dealer-team-btn-ghost:hover{background:var(--dt-gray);color:var(--dt-blue-dk);}
	.dealer-team-btn-danger{background:#fff;color:var(--dt-danger);border:1px solid #e0b4b4;}
	.dealer-team-btn-danger:hover{background:#fdf4f4;color:var(--dt-danger);}
	.dealer-team-btn-sm{padding:7px 14px;font-size:.85rem;}
	.dealer-team-member{border:1px solid var(--dt-border);border-radius:var(--dt-radius);
		background:#fff;box-shadow:var(--dt-shadow);padding:18px 20px;margin-bottom:14px;}
	.dealer-team-member-top{display:flex;flex-wrap:wrap;gap:12px;align-items:flex-start;
		justify-content:space-between;}
	.dealer-team-member-name{font-weight:700;font-size:1.02rem;color:var(--dt-navy);}
	.dealer-team-member-mail{display:block;color:var(--dt-muted);font-size:.88rem;}
	.dealer-team-meta{margin:12px 0 0;display:flex;flex-wrap:wrap;gap:22px;font-size:.86rem;
		color:var(--dt-muted);}
	.dealer-team-meta b{display:block;color:var(--dt-text);font-weight:600;font-size:.8rem;
		text-transform:uppercase;letter-spacing:.03em;}
	.dealer-team-linelist{margin:10px 0 0;display:flex;flex-wrap:wrap;gap:6px;}
	.dealer-team-linechip{display:inline-block;padding:3px 10px;border-radius:99px;font-size:.78rem;
		background:var(--dt-gray);border:1px solid var(--dt-border);color:var(--dt-text);}
	.dealer-team-linechip-none{background:#fdf4f4;border-color:#e0b4b4;color:var(--dt-danger);}
	.dealer-team-actions{margin-top:16px;padding-top:14px;border-top:1px dashed var(--dt-border);
		display:flex;flex-wrap:wrap;gap:12px;align-items:flex-start;}
	.dealer-team-actions details{flex:1;min-width:260px;}
	.dealer-team-actions summary{cursor:pointer;font-weight:600;font-size:.88rem;color:var(--dt-blue);}
	.dealer-team-actions details[open] summary{margin-bottom:12px;}
	.dealer-team-empty{color:var(--dt-muted);font-style:italic;}
	.dealer-team-note{color:var(--dt-muted);font-size:.84rem;margin:8px 0 0;}
	.dealer-team-back{display:inline-flex;align-items:center;gap:6px;margin-bottom:14px;
		color:var(--dt-blue);text-decoration:none;font-size:.9rem;font-weight:500;}
	.dealer-team-back:hover,.dealer-team-back:focus{color:var(--dt-blue-dk);text-decoration:underline;}
	</style>

	<a class="dealer-team-back" href="<?php echo esc_url( Dealer_DB::dashboard_url() ); ?>">← Torna alla Dashboard</a>

	<div class="dealer-team-head">
		<h2><?php echo esc_html( $title ); ?></h2>
		<p class="dealer-team-org"><?php echo esc_html( '' !== $org_name ? $org_name : 'La tua azienda' ); ?></p>
		<div class="dealer-team-badges">
			<span class="dealer-team-badge"><?php echo esc_html( $tier_label ); ?></span>
			<span class="dealer-team-badge dealer-team-badge-soft">
				<?php echo esc_html( sprintf( '%d di %d membri', (int) $members_count, (int) $max_members ) ); ?>
			</span>
			<span class="dealer-team-badge dealer-team-badge-soft">
				<?php echo esc_html( sprintf( '%d linee aziendali', count( $org_lines ) ) ); ?>
			</span>
		</div>
	</div>

	<?php if ( ! empty( $feedback['message'] ) ) : ?>
		<div class="dealer-team-msg <?php echo 'success' === ( $feedback['status'] ?? '' ) ? 'dealer-team-msg-success' : 'dealer-team-msg-error'; ?>">
			<?php echo esc_html( (string) $feedback['message'] ); ?>
		</div>
	<?php endif; ?>

	<?php if ( $suspended ) : ?>
		<div class="dealer-team-msg dealer-team-msg-error">
			L’accesso della tua azienda è attualmente sospeso: la gestione dei collaboratori
			non è disponibile. Contatta il tuo referente commerciale.
		</div>
	<?php endif; ?>

	<!-- ── INVITO ───────────────────────────────────────────────────────── -->
	<?php if ( ! $suspended ) : ?>
	<div class="dealer-team-panel">
		<h3>Invita un collaboratore</h3>
		<p class="dealer-team-hint">
			Il collaboratore riceverà un’email con un link per impostare la propria password.
			Nessuna password viene mai generata o inviata in chiaro. Il nuovo utente accede
			con lo stesso livello dell’azienda; se non indichi nessuna linea vedrà tutte
			quelle aziendali.
		</p>

		<?php if ( ! $can_invite ) : ?>
			<p class="dealer-team-empty">
				<?php echo esc_html( sprintf(
					'Hai raggiunto il numero massimo di %d membri. Per aggiungerne altri contatta il tuo referente.',
					(int) $max_members
				) ); ?>
			</p>
		<?php elseif ( empty( $org_lines ) ) : ?>
			<p class="dealer-team-empty">
				La tua azienda non ha ancora linee prodotto abilitate: un nuovo collaboratore
				non vedrebbe alcun documento. Contatta il tuo referente prima di invitarlo.
			</p>
		<?php else : ?>
			<form method="post" action="<?php echo esc_url( $form_action ); ?>">
				<input type="hidden" name="dt_action" value="invite">
				<?php wp_nonce_field( Dealer_Team::NONCE_INVITE, 'dt_nonce', false ); ?>

				<div class="dealer-team-row">
					<div class="dealer-team-field">
						<label for="dt-name">Nome e cognome</label>
						<input type="text" id="dt-name" name="dt_name" required maxlength="80"
							autocomplete="off" placeholder="Es. Marco Rossi">
					</div>
					<div class="dealer-team-field">
						<label for="dt-email">Email aziendale</label>
						<input type="email" id="dt-email" name="dt_email" required maxlength="120"
							autocomplete="off" placeholder="nome@azienda.it">
					</div>
				</div>

				<div class="dealer-team-field">
					<label>Limita alle linee di competenza <em>(facoltativo)</em></label>
					<div class="dealer-team-lines">
						<?php foreach ( $lines_by_brand as $brand => $brand_lines ) : ?>
							<div class="dealer-team-brand">
								<span class="dealer-team-brand-name"><?php echo esc_html( $brand ); ?></span>
								<?php foreach ( $brand_lines as $line ) :
									$value = $brand . '|' . $line; ?>
									<label class="dealer-team-check">
										<input type="checkbox" name="dt_lines[]" value="<?php echo esc_attr( $value ); ?>">
										<?php echo esc_html( $line ); ?>
									</label>
								<?php endforeach; ?>
							</div>
						<?php endforeach; ?>
					</div>
					<p class="dealer-team-note">
						Nessuna selezione = accesso a tutte le linee dell’azienda. Puoi comunque
						modificare le linee in qualsiasi momento dopo l’invito.
					</p>
				</div>

				<button type="submit" class="dealer-team-btn">Invia invito</button>
			</form>
		<?php endif; ?>
	</div>
	<?php endif; ?>

	<!-- ── SQUADRA ──────────────────────────────────────────────────────── -->
	<div class="dealer-team-panel">
		<h3>La tua squadra</h3>
		<p class="dealer-team-hint">
			Le linee mostrate sono quelle <strong>effettive</strong>: l’intersezione fra i
			diritti dell’azienda e l’eventuale limitazione del singolo collaboratore.
		</p>

		<?php if ( empty( $members ) ) : ?>
			<p class="dealer-team-empty">Nessun membro registrato per questa azienda.</p>
		<?php endif; ?>

		<?php foreach ( $members as $member ) : ?>
			<div class="dealer-team-member">
				<div class="dealer-team-member-top">
					<div>
						<span class="dealer-team-member-name"><?php echo esc_html( $member['name'] ); ?></span>
						<span class="dealer-team-member-mail"><?php echo esc_html( $member['email'] ); ?></span>
					</div>
					<span class="dealer-team-badge<?php echo $member['is_titolare'] ? '' : ' dealer-team-badge-soft'; ?>"
						style="<?php echo $member['is_titolare'] ? '' : 'background:#f4f6f8;color:#52616b;'; ?>">
						<?php echo esc_html( $member['function'] ); ?>
					</span>
				</div>

				<div class="dealer-team-meta">
					<span><b>Ultimo accesso</b><?php echo esc_html( Dealer_Team::format_datetime( $member['last_login'] ) ); ?></span>
					<span><b>Ambito linee</b><?php echo $member['restricted'] ? 'Limitato' : 'Tutte le linee aziendali'; ?></span>
					<?php if ( '' !== $member['invited_at'] ) : ?>
						<span><b>Invitato il</b><?php echo esc_html( Dealer_Team::format_datetime( $member['invited_at'] ) ); ?></span>
					<?php endif; ?>
				</div>

				<div class="dealer-team-linelist">
					<?php if ( empty( $member['lines'] ) ) : ?>
						<span class="dealer-team-linechip dealer-team-linechip-none">Nessuna linea accessibile</span>
					<?php else : ?>
						<?php foreach ( $member['lines'] as $line ) : ?>
							<span class="dealer-team-linechip"><?php echo esc_html( Dealer_Team::line_label( $line ) ); ?></span>
						<?php endforeach; ?>
					<?php endif; ?>
				</div>

				<?php if ( $member['manageable'] && ! $suspended ) : ?>
				<div class="dealer-team-actions">

					<details>
						<summary>Limita le linee di <?php echo esc_html( $member['name'] ); ?></summary>
						<form method="post" action="<?php echo esc_url( $form_action ); ?>">
							<input type="hidden" name="dt_action" value="set_lines">
							<input type="hidden" name="dt_user" value="<?php echo esc_attr( (string) $member['id'] ); ?>">
							<?php wp_nonce_field( Dealer_Team::NONCE_LINES . '_' . $member['id'], 'dt_nonce', false ); ?>

							<div class="dealer-team-lines">
								<?php foreach ( $lines_by_brand as $brand => $brand_lines ) : ?>
									<div class="dealer-team-brand">
										<span class="dealer-team-brand-name"><?php echo esc_html( $brand ); ?></span>
										<?php foreach ( $brand_lines as $line ) :
											$value   = $brand . '|' . $line;
											$checked = in_array( $value, (array) $member['limit'], true ); ?>
											<label class="dealer-team-check">
												<input type="checkbox" name="dt_lines[]"
													value="<?php echo esc_attr( $value ); ?>"
													<?php checked( $checked ); ?>>
												<?php echo esc_html( $line ); ?>
											</label>
										<?php endforeach; ?>
									</div>
								<?php endforeach; ?>
							</div>
							<p class="dealer-team-note">
								Deseleziona tutto per ridare accesso a tutte le linee dell’azienda.
								Non è possibile concedere linee che l’azienda non possiede.
							</p>
							<button type="submit" class="dealer-team-btn dealer-team-btn-ghost dealer-team-btn-sm">
								Salva linee
							</button>
						</form>
					</details>

					<form method="post" action="<?php echo esc_url( $form_action ); ?>"
						onsubmit="return confirm('Disattivare <?php echo esc_js( $member['name'] ); ?>? Perderà l’accesso all’area riservata. Lo storico dei download resta consultabile.');">
						<input type="hidden" name="dt_action" value="deactivate">
						<input type="hidden" name="dt_user" value="<?php echo esc_attr( (string) $member['id'] ); ?>">
						<?php wp_nonce_field( Dealer_Team::NONCE_DEACTIVATE . '_' . $member['id'], 'dt_nonce', false ); ?>
						<button type="submit" class="dealer-team-btn dealer-team-btn-danger dealer-team-btn-sm">
							Disattiva collaboratore
						</button>
					</form>

				</div>
				<?php elseif ( $member['is_titolare'] ) : ?>
					<p class="dealer-team-note">
						La funzione di titolare viene assegnata solo dall’amministratore del portale.
					</p>
				<?php endif; ?>
			</div>
		<?php endforeach; ?>
	</div>

	<p class="dealer-team-note">
		Disattivare un collaboratore non cancella il suo account né lo storico dei suoi
		download: l’accesso viene revocato, la tracciabilità resta.
	</p>

</div>
