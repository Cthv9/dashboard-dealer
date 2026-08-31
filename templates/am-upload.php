<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/**
 * Scheda "Carica documento" — wizard equivalente a quello admin, sul front-end.
 *
 * I nomi dei campi sono quelli che Dealer_Admin::ajax_save_document() si aspetta
 * (doc_file, doc_brand, doc_product_line, doc_type, doc_year, doc_version,
 * doc_previous_version, doc_roles[], doc_lines[], doc_expiry, doc_keywords,
 * doc_internal_notes, keep_original_name, doc_custom_name) e il campo nonce si
 * chiama "nonce", perché è quello che check_ajax_referer() legge. Il modulo non
 * salva nulla per conto proprio: invia all'endpoint già esistente, che applica
 * DEALER_PORTAL_CAP_UPLOAD e la validazione di perimetro.
 *
 * Brand e linee mostrati sono solo quelli di Dealer_Admin::allowed_product_lines():
 * è una comodità dell'interfaccia, non un'autorizzazione — pubblicare fuori
 * perimetro fallisce comunque lato server (can_publish_to_lines()).
 */

$am_lines_by_brand = (array) ( $upload['lines_by_brand'] ?? [] );
$am_upload_types   = (array) ( $upload['doc_types'] ?? [] );
$am_versionable    = (array) ( $upload['versionable'] ?? [] );
$am_preselect      = (int) ( $upload['preselect'] ?? 0 );
$am_year           = (int) ( $upload['year'] ?? (int) gmdate( 'Y' ) );
?>

<?php if ( empty( $am_lines_by_brand ) ) : ?>

	<div class="dealer-am-panel">
		<h3>Carica documento</h3>
		<p class="dealer-am-empty">
			Non ti risulta assegnata nessuna linea prodotto: senza almeno una linea nel
			perimetro non è possibile pubblicare documenti. Contatta l’amministratore del portale.
		</p>
	</div>

<?php else : ?>

	<noscript>
		<p class="dealer-am-noscript">
			Il caricamento di un documento richiede JavaScript attivo, come nel pannello
			di amministrazione. Le altre sezioni di quest’area funzionano comunque.
		</p>
	</noscript>

	<div class="dealer-am-panel">
		<h3>Carica un documento</h3>
		<p class="dealer-am-hint">
			Il documento sarà visibile solo ai dealer dei livelli e delle linee che indichi.
			Almeno una linea del tuo perimetro è obbligatoria: un documento senza restrizione
			di linea sarebbe visibile a tutta la rete e il server lo rifiuta.
		</p>

		<div class="dealer-am-steps" aria-hidden="true">
			<div class="dealer-am-step is-active" data-step="1"><b>Step 1</b>File</div>
			<div class="dealer-am-step" data-step="2"><b>Step 2</b>Classificazione</div>
			<div class="dealer-am-step" data-step="3"><b>Step 3</b>Visibilità</div>
			<div class="dealer-am-step" data-step="4"><b>Step 4</b>Parole chiave</div>
			<div class="dealer-am-step" data-step="5"><b>Step 5</b>Riepilogo</div>
		</div>

		<div id="am-upload-msg" class="dealer-am-msg" role="alert" hidden></div>

		<form id="am-upload-form" enctype="multipart/form-data" novalidate>
			<?php wp_nonce_field( 'dealer_upload_nonce', 'nonce', false ); ?>

			<!-- ── STEP 1: file ─────────────────────────────────────────── -->
			<div class="dealer-am-pane is-active" data-pane="1">
				<h4>Selezione del file</h4>

				<div class="dealer-am-drop" id="am-drop" tabindex="0" role="button"
					aria-label="Seleziona o trascina il file da caricare">
					<p>
						Trascina qui il file oppure
						<label for="am-doc-file" class="dealer-am-filelabel">scegli dal computer</label>
					</p>
					<p class="dealer-am-note">Formati accettati: PDF, XLSX, DOCX — massimo 50 MB</p>
					<input type="file" name="doc_file" id="am-doc-file" accept=".pdf,.xlsx,.docx"
						style="position:absolute;opacity:0;width:1px;height:1px;">
					<p class="dealer-am-selected" id="am-selected" aria-live="polite"></p>
				</div>
				<p class="dealer-am-error" data-error="doc_file" hidden></p>

				<div class="dealer-am-field" style="margin-top:18px;">
					<label class="dealer-am-check">
						<input type="checkbox" name="keep_original_name" id="am-keep-name" value="1" checked>
						Mantieni il nome originale del file
					</label>
				</div>

				<div class="dealer-am-field" id="am-rename" hidden>
					<label for="am-custom-name">Nuovo nome del file (senza estensione)</label>
					<input type="text" id="am-custom-name" name="doc_custom_name"
						placeholder="es. Mercury_Manuale_FourStroke_2024_v1">
					<p class="dealer-am-note">Sono ammessi lettere, numeri, punto, trattino e underscore.</p>
					<p class="dealer-am-error" data-error="doc_custom_name" hidden></p>
				</div>

				<div class="dealer-am-nav">
					<button type="button" class="dealer-am-btn am-next" data-next="2">Avanti</button>
				</div>
			</div>

			<!-- ── STEP 2: classificazione ──────────────────────────────── -->
			<div class="dealer-am-pane" data-pane="2">
				<h4>Classificazione</h4>

				<div class="dealer-am-row">
					<div class="dealer-am-field">
						<label for="am-doc-brand">Brand</label>
						<select id="am-doc-brand" name="doc_brand">
							<option value="">— Seleziona brand —</option>
							<?php foreach ( array_keys( $am_lines_by_brand ) as $am_brand_name ) : ?>
								<option value="<?php echo esc_attr( $am_brand_name ); ?>">
									<?php echo esc_html( $am_brand_name ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="dealer-am-error" data-error="doc_brand" hidden></p>
					</div>
					<div class="dealer-am-field">
						<label for="am-doc-line">Linea prodotto</label>
						<select id="am-doc-line" name="doc_product_line">
							<option value="">— Prima seleziona il brand —</option>
						</select>
					</div>
				</div>

				<div class="dealer-am-row">
					<div class="dealer-am-field">
						<label for="am-doc-type">Tipo documento</label>
						<select id="am-doc-type" name="doc_type">
							<option value="">— Seleziona tipo —</option>
							<?php foreach ( $am_upload_types as $am_type_key => $am_type_label ) : ?>
								<option value="<?php echo esc_attr( $am_type_key ); ?>">
									<?php echo esc_html( $am_type_label ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="dealer-am-error" data-error="doc_type" hidden></p>
					</div>
					<div class="dealer-am-field">
						<label for="am-doc-year">Anno</label>
						<select id="am-doc-year" name="doc_year">
							<?php for ( $am_y = $am_year; $am_y >= $am_year - 5; $am_y-- ) : ?>
								<option value="<?php echo esc_attr( (string) $am_y ); ?>"><?php echo esc_html( (string) $am_y ); ?></option>
							<?php endfor; ?>
						</select>
					</div>
					<div class="dealer-am-field">
						<label for="am-doc-version">Versione</label>
						<input type="text" id="am-doc-version" name="doc_version" placeholder="es. v1, rev2">
					</div>
				</div>

				<div class="dealer-am-field">
					<label for="am-doc-previous">Questo documento sostituisce…</label>
					<?php if ( empty( $am_versionable ) ) : ?>
						<p class="dealer-am-note">
							Nessun documento del tuo perimetro può essere sostituito: questo sarà il
							primo della sua catena di versioni.
						</p>
						<input type="hidden" name="doc_previous_version" id="am-doc-previous" value="0">
					<?php else : ?>
						<select id="am-doc-previous" name="doc_previous_version">
							<option value="0">— Nessuno: è un documento nuovo —</option>
							<?php foreach ( $am_versionable as $am_v ) :
								$am_v_label = $am_v['title'];
								if ( '' !== $am_v['version'] ) {
									$am_v_label .= ' — ' . $am_v['version'];
								}
								$am_v_label .= ' (v' . $am_v['seq'] . ')';
								?>
								<option value="<?php echo esc_attr( (string) $am_v['id'] ); ?>"
									<?php selected( $am_preselect, (int) $am_v['id'] ); ?>>
									<?php echo esc_html( $am_v_label ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="dealer-am-note">
							Sono elencati solo i documenti che puoi davvero modificare. Selezionandone
							uno, quello caricato diventa la <strong>nuova versione corrente</strong>: il
							precedente resta scaricabile come storico ma esce dalla ricerca dei dealer.
						</p>
					<?php endif; ?>
				</div>

				<div class="dealer-am-nav">
					<button type="button" class="dealer-am-btn dealer-am-btn-ghost am-back" data-back="1">Indietro</button>
					<button type="button" class="dealer-am-btn am-next" data-next="3">Avanti</button>
				</div>
			</div>

			<!-- ── STEP 3: visibilità ───────────────────────────────────── -->
			<div class="dealer-am-pane" data-pane="3">
				<h4>Visibilità e accessi</h4>

				<div class="dealer-am-field">
					<label>Livelli destinatari</label>
					<div>
						<label class="dealer-am-check"><input type="checkbox" name="doc_roles[]" value="dealer"> Dealer</label>
						<label class="dealer-am-check"><input type="checkbox" name="doc_roles[]" value="top_dealer"> Top Dealer</label>
						<label class="dealer-am-check"><input type="checkbox" name="doc_roles[]" value="part_center"> Parts Center</label>
					</div>
					<p class="dealer-am-error" data-error="doc_roles" hidden></p>
				</div>

				<div class="dealer-am-field">
					<label>Linee prodotto abilitate</label>
					<div class="dealer-am-lines" id="am-doc-lines">
						<?php foreach ( $am_lines_by_brand as $am_brand_name => $am_brand_lines ) : ?>
							<div class="dealer-am-brand" data-brand="<?php echo esc_attr( $am_brand_name ); ?>">
								<span class="dealer-am-brand-name"><?php echo esc_html( $am_brand_name ); ?></span>
								<?php foreach ( $am_brand_lines as $am_brand_line ) :
									$am_line_value = $am_brand_name . '|' . $am_brand_line; ?>
									<label class="dealer-am-check">
										<input type="checkbox" name="doc_lines[]"
											value="<?php echo esc_attr( $am_line_value ); ?>">
										<?php echo esc_html( $am_brand_line ); ?>
									</label>
								<?php endforeach; ?>
							</div>
						<?php endforeach; ?>
					</div>
					<p class="dealer-am-note">
						Sono elencate solo le linee del tuo perimetro. Almeno una è obbligatoria.
					</p>
					<p class="dealer-am-error" data-error="doc_lines" hidden></p>
				</div>

				<div class="dealer-am-field" style="max-width:280px;">
					<label for="am-doc-expiry">Data di scadenza <em>(facoltativa)</em></label>
					<input type="date" id="am-doc-expiry" name="doc_expiry">
				</div>

				<div class="dealer-am-nav">
					<button type="button" class="dealer-am-btn dealer-am-btn-ghost am-back" data-back="2">Indietro</button>
					<button type="button" class="dealer-am-btn am-next" data-next="4">Avanti</button>
				</div>
			</div>

			<!-- ── STEP 4: parole chiave ────────────────────────────────── -->
			<div class="dealer-am-pane" data-pane="4">
				<h4>Parole chiave e note</h4>

				<div class="dealer-am-field">
					<label for="am-doc-keywords">Parole chiave</label>
					<input type="text" id="am-doc-keywords" name="doc_keywords"
						placeholder="es. fuoribordo, 4 tempi, 150 CV, trim">
					<p class="dealer-am-note">Separate da virgola. Migliorano la ricerca dei dealer.</p>
				</div>

				<div class="dealer-am-field">
					<label for="am-doc-notes">Note interne</label>
					<textarea id="am-doc-notes" name="doc_internal_notes" rows="4"
						placeholder="Non visibili ai dealer."></textarea>
				</div>

				<div class="dealer-am-nav">
					<button type="button" class="dealer-am-btn dealer-am-btn-ghost am-back" data-back="3">Indietro</button>
					<button type="button" class="dealer-am-btn am-next" data-next="5">Avanti</button>
				</div>
			</div>

			<!-- ── STEP 5: riepilogo ────────────────────────────────────── -->
			<div class="dealer-am-pane" data-pane="5">
				<h4>Riepilogo</h4>
				<p class="dealer-am-hint">Verifica i dati prima di salvare.</p>

				<dl class="dealer-am-summary" id="am-summary" aria-live="polite"></dl>

				<div class="dealer-am-nav">
					<button type="button" class="dealer-am-btn dealer-am-btn-ghost am-back" data-back="4">Indietro</button>
					<button type="submit" class="dealer-am-btn" id="am-save">Salva documento</button>
				</div>
			</div>

		</form>
	</div>

<?php endif; ?>
