<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/**
 * Scheda "Persone" — collaboratori delle organizzazioni seguite.
 *
 * Le tre operazioni (invito, limitazione alle linee, disattivazione) sono form
 * POST classici con pattern PRG: funzionano senza JavaScript. Ogni form porta un
 * nonce contestualizzato sull'ID bersaglio, ma il nonce non è un permesso: gli
 * handler di Dealer_Area_Manager ricontrollano ruolo e perimetro da capo.
 *
 * Cosa NON c'è, deliberatamente: nessun campo per scegliere il ruolo. Il ruolo
 * WordPress deriva dal livello commerciale dell'organizzazione. L'unica scelta
 * sulla funzione è la casella "titolare" del modulo d'invito, che compare solo
 * finché l'azienda non ne ha uno e che il server ricontrolla: non esiste un
 * percorso che da qui produca un secondo titolare, un area manager o un
 * amministratore.
 */
?>

<div class="dealer-am-panel">
	<h3>Le persone del tuo perimetro</h3>
	<p class="dealer-am-hint">
		Puoi invitare, limitare alle linee di competenza e disattivare i <strong>collaboratori</strong>
		delle organizzazioni che segui. I titolari e gli account amministrativi restano
		all’amministratore del portale. Le linee mostrate sono quelle <strong>effettive</strong>:
		l’intersezione fra i diritti dell’azienda e l’eventuale limitazione individuale.
	</p>
</div>

<?php
// ── Crea una nuova azienda ────────────────────────────────────────────────
// Finche' questo modulo non esisteva, un area manager senza organizzazioni
// arrivava qui e non aveva niente da fare: le persone si invitano dentro
// un'azienda, e le aziende gliele assegnava solo l'amministratore. Ora se le
// crea, e cio' che crea entra nel suo perimetro.
?>
<div class="dealer-am-panel">
	<details class="dealer-am-neworg"<?php echo empty( $organizations ) ? ' open' : ''; ?>>
		<summary><strong>Aggiungi un’azienda alla rete</strong></summary>

		<?php if ( empty( $organizations ) ) : ?>
			<p class="dealer-am-hint">
				Non segui ancora nessuna azienda. Creane una qui: entrerà subito nel tuo perimetro
				e potrai invitarci le persone, senza passare dall’amministratore.
			</p>
		<?php endif; ?>

		<?php if ( empty( $scope_lines ) ) : ?>
			<p class="dealer-am-empty">
				Non hai ancora linee prodotto assegnate: senza quelle non puoi creare aziende.
				Contatta l’amministratore del portale.
			</p>
		<?php else : ?>
			<form method="post" action="<?php echo esc_url( $form_action ); ?>" class="dealer-am-form">
				<input type="hidden" name="am_action" value="create_org">
				<?php wp_nonce_field( Dealer_Area_Manager::NONCE_CREATE_ORG, 'am_nonce', false ); ?>

				<div class="dealer-am-grid">
					<label>
						<span>Ragione sociale</span>
						<input type="text" name="org_name" maxlength="150" required
							placeholder="Es. Nautica Rossi S.r.l.">
					</label>
					<label>
						<span>Partita IVA <em>(facoltativa)</em></span>
						<input type="text" name="org_vat" maxlength="20">
					</label>
				</div>

				<fieldset class="dealer-am-lines">
					<legend>Linee prodotto dell’azienda</legend>
					<p class="dealer-am-hint">
						Puoi assegnare solo linee del tuo perimetro: quello che l’azienda riceve
						non può superare quello che hai tu.
					</p>
					<div class="dealer-am-lineopts">
						<?php foreach ( $scope_lines as $am_line ) : ?>
							<label class="dealer-am-lineopt">
								<input type="checkbox" name="org_lines[]" value="<?php echo esc_attr( $am_line ); ?>">
								<span><?php echo esc_html( str_replace( '|', ' › ', $am_line ) ); ?></span>
							</label>
						<?php endforeach; ?>
					</div>
				</fieldset>

				<div class="dealer-am-submit">
					<button type="submit" class="dealer-am-btn dealer-am-btn-primary">Crea azienda</button>
				</div>
			</form>
		<?php endif; ?>
	</details>
</div>

<?php foreach ( $organizations as $am_org ) : ?>

	<div class="dealer-am-panel dealer-am-org">

		<div class="dealer-am-org-head">
			<h3><?php echo esc_html( '' !== $am_org['name'] ? $am_org['name'] : 'Organizzazione' ); ?></h3>
			<div>
				<span class="dealer-am-badge"><?php echo esc_html( $am_org['tier_label'] ); ?></span>
				<span class="dealer-am-badge dealer-am-badge-soft">
					<?php echo esc_html( sprintf( '%d di %d membri', (int) $am_org['members_count'], (int) $am_org['max_members'] ) ); ?>
				</span>
				<span class="dealer-am-badge dealer-am-badge-soft">
					<?php echo esc_html( sprintf( '%d linee', count( (array) $am_org['lines'] ) ) ); ?>
				</span>
				<?php if ( ! $am_org['active'] ) : ?>
					<span class="dealer-am-badge dealer-am-badge-danger">Sospesa</span>
				<?php endif; ?>
			</div>
		</div>

		<?php if ( ! $am_org['active'] ) : ?>
			<p class="dealer-am-note">
				L’accesso di questa organizzazione è sospeso: la sezione è in sola lettura.
			</p>
		<?php endif; ?>

		<!-- ── INVITO ───────────────────────────────────────────────────── -->
		<?php if ( $am_org['active'] ) : ?>
			<details>
				<summary>Invita un collaboratore in <?php echo esc_html( $am_org['name'] ); ?></summary>

				<?php if ( ! $am_org['can_invite'] ) : ?>
					<p class="dealer-am-empty" style="margin-top:12px;">
						<?php if ( empty( $am_org['lines'] ) ) : ?>
							Questa organizzazione non ha linee prodotto abilitate: un nuovo collaboratore
							non vedrebbe alcun documento.
						<?php else : ?>
							<?php echo esc_html( sprintf(
								'Questa organizzazione ha già il numero massimo di %d membri.',
								(int) $am_org['max_members']
							) ); ?>
						<?php endif; ?>
					</p>
				<?php else : ?>
					<form method="post" action="<?php echo esc_url( $form_action ); ?>" style="margin-top:12px;">
						<input type="hidden" name="am_action" value="invite">
						<input type="hidden" name="am_org" value="<?php echo esc_attr( (string) $am_org['id'] ); ?>">
						<?php wp_nonce_field( Dealer_Area_Manager::NONCE_INVITE . '_' . $am_org['id'], 'am_nonce', false ); ?>

						<div class="dealer-am-row">
							<div class="dealer-am-field">
								<label for="am-name-<?php echo esc_attr( (string) $am_org['id'] ); ?>">Nome e cognome</label>
								<input type="text" id="am-name-<?php echo esc_attr( (string) $am_org['id'] ); ?>"
									name="am_name" required maxlength="80" autocomplete="off"
									placeholder="Es. Marco Rossi">
							</div>
							<div class="dealer-am-field">
								<label for="am-email-<?php echo esc_attr( (string) $am_org['id'] ); ?>">Email aziendale</label>
								<input type="email" id="am-email-<?php echo esc_attr( (string) $am_org['id'] ); ?>"
									name="am_email" required maxlength="120" autocomplete="off"
									placeholder="nome@azienda.it">
							</div>
						</div>

						<?php if ( empty( $am_org['has_titolare'] ) ) : ?>
							<?php
							// Un'azienda senza titolare non puo' gestirsi da sola: nessuno
							// al suo interno vede "Gestione Collaboratori" e ogni aggiunta
							// deve passare per sempre da qui. La casella compare solo
							// finche' il posto e' libero, e il server ricontrolla la
							// condizione: due titolari non si possono creare.
							?>
							<div class="dealer-am-field dealer-am-titolare">
								<label class="dealer-am-check">
									<input type="checkbox" name="as_titolare" value="1">
									<span>
										<strong>Rendi questa persona il titolare dell’azienda.</strong>
										<em>
											Questa azienda non ha ancora un titolare, quindi non può gestirsi da sola:
											ogni collaboratore da aggiungere o rimuovere deve passare da te. Il titolare
											vede «Gestione Collaboratori» nella propria area e se ne occupa lui, dentro
											le linee dell’azienda. Puoi nominarne uno solo.
										</em>
									</span>
								</label>
							</div>
						<?php endif; ?>

						<div class="dealer-am-field">
							<label>Limita alle linee di competenza <em>(facoltativo)</em></label>
							<div class="dealer-am-lines">
								<?php foreach ( $am_org['lines_by_brand'] as $am_brand_name => $am_brand_lines ) : ?>
									<div class="dealer-am-brand">
										<span class="dealer-am-brand-name"><?php echo esc_html( $am_brand_name ); ?></span>
										<?php foreach ( $am_brand_lines as $am_brand_line ) :
											$am_line_value = $am_brand_name . '|' . $am_brand_line; ?>
											<label class="dealer-am-check">
												<input type="checkbox" name="am_lines[]"
													value="<?php echo esc_attr( $am_line_value ); ?>">
												<?php echo esc_html( $am_brand_line ); ?>
											</label>
										<?php endforeach; ?>
									</div>
								<?php endforeach; ?>
							</div>
							<p class="dealer-am-note">
								Nessuna selezione = accesso a tutte le linee dell’azienda. Non è possibile
								concedere linee che l’azienda non possiede.
							</p>
						</div>

						<p class="dealer-am-note">
							Il collaboratore riceverà un’email con un link per impostare la propria password:
							nessuna password viene mai generata o inviata in chiaro.
						</p>

						<button type="submit" class="dealer-am-btn">Invia invito</button>
					</form>
				<?php endif; ?>
			</details>
		<?php endif; ?>

		<!-- ── MEMBRI ───────────────────────────────────────────────────── -->
		<div style="margin-top:18px;">
			<?php if ( empty( $am_org['members'] ) ) : ?>
				<p class="dealer-am-empty">Nessun membro registrato per questa organizzazione.</p>
			<?php endif; ?>

			<?php foreach ( $am_org['members'] as $am_member ) : ?>
				<div class="dealer-am-member">
					<div class="dealer-am-member-top">
						<div>
							<span class="dealer-am-member-name"><?php echo esc_html( $am_member['name'] ); ?></span>
							<span class="dealer-am-member-mail"><?php echo esc_html( $am_member['email'] ); ?></span>
						</div>
						<span class="dealer-am-badge<?php echo $am_member['is_titolare'] ? '' : ' dealer-am-badge-soft'; ?>">
							<?php echo esc_html( $am_member['function'] ); ?>
						</span>
					</div>

					<div class="dealer-am-meta">
						<span><b>Ultimo accesso</b><?php echo esc_html( Dealer_Area_Manager::format_datetime( $am_member['last_login'] ) ); ?></span>
						<span><b>Ambito linee</b><?php echo $am_member['restricted'] ? 'Limitato' : 'Tutte le linee aziendali'; ?></span>
						<?php if ( '' !== $am_member['invited_at'] ) : ?>
							<span><b>Invitato il</b><?php echo esc_html( Dealer_Area_Manager::format_datetime( $am_member['invited_at'] ) ); ?></span>
						<?php endif; ?>
					</div>

					<div class="dealer-am-chiplist">
						<?php if ( empty( $am_member['lines'] ) ) : ?>
							<span class="dealer-am-chip dealer-am-chip-none">Nessuna linea accessibile</span>
						<?php else : ?>
							<?php foreach ( $am_member['lines'] as $am_member_line ) : ?>
								<span class="dealer-am-chip"><?php echo esc_html( Dealer_Area_Manager::line_label( (string) $am_member_line ) ); ?></span>
							<?php endforeach; ?>
						<?php endif; ?>
					</div>

					<?php if ( $am_member['manageable'] ) : ?>
						<div class="dealer-am-actions">

							<details>
								<summary>Limita le linee di <?php echo esc_html( $am_member['name'] ); ?></summary>
								<form method="post" action="<?php echo esc_url( $form_action ); ?>">
									<input type="hidden" name="am_action" value="set_lines">
									<input type="hidden" name="am_user" value="<?php echo esc_attr( (string) $am_member['id'] ); ?>">
									<?php wp_nonce_field( Dealer_Area_Manager::NONCE_LINES . '_' . $am_member['id'], 'am_nonce', false ); ?>

									<div class="dealer-am-lines">
										<?php foreach ( $am_org['lines_by_brand'] as $am_brand_name => $am_brand_lines ) : ?>
											<div class="dealer-am-brand">
												<span class="dealer-am-brand-name"><?php echo esc_html( $am_brand_name ); ?></span>
												<?php foreach ( $am_brand_lines as $am_brand_line ) :
													$am_line_value = $am_brand_name . '|' . $am_brand_line;
													$am_checked    = in_array( $am_line_value, (array) $am_member['limit'], true ); ?>
													<label class="dealer-am-check">
														<input type="checkbox" name="am_lines[]"
															value="<?php echo esc_attr( $am_line_value ); ?>"
															<?php checked( $am_checked ); ?>>
														<?php echo esc_html( $am_brand_line ); ?>
													</label>
												<?php endforeach; ?>
											</div>
										<?php endforeach; ?>
									</div>
									<p class="dealer-am-note">
										Deseleziona tutto per ridare accesso a tutte le linee dell’azienda.
									</p>
									<button type="submit" class="dealer-am-btn dealer-am-btn-ghost dealer-am-btn-sm">
										Salva linee
									</button>
								</form>
							</details>

							<form method="post" action="<?php echo esc_url( $form_action ); ?>"
								onsubmit="return confirm('Disattivare <?php echo esc_js( $am_member['name'] ); ?>? Perderà l’accesso all’area riservata. Lo storico dei download resta consultabile.');">
								<input type="hidden" name="am_action" value="deactivate">
								<input type="hidden" name="am_user" value="<?php echo esc_attr( (string) $am_member['id'] ); ?>">
								<?php wp_nonce_field( Dealer_Area_Manager::NONCE_DEACTIVATE . '_' . $am_member['id'], 'am_nonce', false ); ?>
								<button type="submit" class="dealer-am-btn dealer-am-btn-danger dealer-am-btn-sm">
									Disattiva collaboratore
								</button>
							</form>

						</div>
					<?php elseif ( $am_member['is_titolare'] ) : ?>
						<p class="dealer-am-note">
							Il titolare dell’azienda è gestito dall’amministratore del portale.
						</p>
					<?php elseif ( ! $am_org['active'] ) : ?>
						<p class="dealer-am-note">
							Organizzazione sospesa: nessuna operazione disponibile.
						</p>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>

	</div>

<?php endforeach; ?>

<p class="dealer-am-note">
	Disattivare un collaboratore non cancella il suo account né lo storico dei suoi
	download: l’accesso viene revocato, la tracciabilità resta.
</p>
