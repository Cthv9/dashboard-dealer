<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
// Questo template è incluso da Dealer_Admin::render_upload() — context admin garantito.

$brands    = Dealer_Admin::get_brands();
$doc_types = Dealer_Admin::get_doc_types();
$cur_year  = (int) gmdate( 'Y' );

// Documenti candidabili come "versione precedente": solo le versioni correnti.
$versionable = Dealer_Versioning::get_versionable_documents();
?>
<div class="wrap dealer-admin-wrap">
	<h1 class="wp-heading-inline">
		<span class="dashicons dashicons-upload" style="vertical-align:middle;margin-right:6px;"></span>
		Carica Documento
	</h1>

	<div id="dealer-upload-notice" class="notice" style="display:none;" role="alert"></div>

	<!-- Indicatore step -->
	<div class="dealer-steps-bar" aria-label="Avanzamento wizard">
		<?php
		$step_labels = [ '1. File', '2. Classificazione', '3. Visibilità', '4. Keywords', '5. Riepilogo' ];
		foreach ( $step_labels as $i => $label ) :
			$n = $i + 1;
			?>
			<div class="dealer-step<?php echo $n === 1 ? ' active' : ''; ?>" data-step="<?php echo esc_attr( $n ); ?>" aria-current="<?php echo $n === 1 ? 'step' : 'false'; ?>">
				<span class="dealer-step-number"><?php echo esc_html( $n ); ?></span>
				<span class="dealer-step-label"><?php echo esc_html( $label ); ?></span>
			</div>
		<?php endforeach; ?>
	</div>

	<form id="dealer-upload-form" enctype="multipart/form-data" novalidate>
		<?php wp_nonce_field( 'dealer_upload_nonce', 'nonce' ); ?>

		<!-- ── STEP 1: File ───────────────────────────────────────────── -->
		<div class="dealer-panel active" data-panel="1">
			<h2>Step 1 — Selezione file</h2>

			<div class="dealer-upload-area" id="dealer-drop-area" tabindex="0" role="button" aria-label="Zona upload file">
				<span class="dashicons dashicons-cloud-upload dealer-upload-icon"></span>
				<p>Trascina qui il file oppure <label for="doc_file" class="dealer-upload-label">scegli dal computer</label></p>
				<p class="dealer-upload-hint">Formati accettati: PDF, XLSX, DOCX — Max 50 MB</p>
				<input type="file" name="doc_file" id="doc_file" accept=".pdf,.xlsx,.docx" required aria-required="true" style="position:absolute;opacity:0;width:1px;height:1px;">
			</div>
			<div id="dealer-selected-file" class="dealer-selected-file" style="display:none;" aria-live="polite"></div>

			<fieldset class="dealer-fieldset" style="margin-top:20px;">
				<legend>Nome del file</legend>
				<label class="dealer-checkbox-label">
					<input type="checkbox" name="keep_original_name" id="keep_original_name" value="1" checked>
					<strong>Mantieni nome originale</strong> — il file verrà salvato con il nome con cui è stato caricato
				</label>

				<div id="rename-section" style="display:none;margin-top:16px;">
					<label for="doc_custom_name" class="dealer-label">Nuovo nome file <span class="required">*</span></label>
					<p class="description" style="margin-bottom:8px;">Suggerimento formato: <code>BRAND_TipoDoc_Prodotto_Anno_v1</code> (senza estensione)</p>
					<input type="text" id="doc_custom_name" name="doc_custom_name" class="regular-text" placeholder="es. Mercury_Manuale_FourStroke_2024_v1">
					<p class="dealer-filename-preview" id="filename-preview" aria-live="polite"></p>
				</div>
			</fieldset>

			<div class="dealer-panel-nav">
				<button type="button" class="button button-primary dealer-next-btn" data-next="2">Avanti →</button>
			</div>
		</div>

		<!-- ── STEP 2: Classificazione ───────────────────────────────── -->
		<div class="dealer-panel" data-panel="2">
			<h2>Step 2 — Classificazione</h2>

			<table class="form-table">
				<tr>
					<th><label for="doc_brand">Brand / Produttore <span class="required">*</span></label></th>
					<td>
						<select name="doc_brand" id="doc_brand" required aria-required="true">
							<option value="">— Seleziona brand —</option>
							<?php foreach ( $brands as $brand ) : ?>
								<option value="<?php echo esc_attr( $brand ); ?>"><?php echo esc_html( $brand ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th><label for="doc_product_line">Linea Prodotto</label></th>
					<td>
						<select name="doc_product_line" id="doc_product_line">
							<option value="">— Prima seleziona il brand —</option>
						</select>
					</td>
				</tr>
				<tr>
					<th><label for="doc_type">Tipo Documento <span class="required">*</span></label></th>
					<td>
						<select name="doc_type" id="doc_type" required aria-required="true">
							<option value="">— Seleziona tipo —</option>
							<?php foreach ( $doc_types as $key => $label ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th><label for="doc_year">Anno</label></th>
					<td>
						<select name="doc_year" id="doc_year">
							<?php for ( $y = $cur_year; $y >= $cur_year - 5; $y-- ) : ?>
								<option value="<?php echo esc_attr( $y ); ?>"><?php echo esc_html( $y ); ?></option>
							<?php endfor; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th><label for="doc_version">Versione</label></th>
					<td>
						<input type="text" name="doc_version" id="doc_version" class="small-text" placeholder="es. v1, rev2">
					</td>
				</tr>
				<tr>
					<th><label for="doc_previous_version">Questo documento sostituisce…</label></th>
					<td>
						<?php if ( empty( $versionable ) ) : ?>
							<p class="description">
								<span class="dashicons dashicons-info" style="vertical-align:middle;"></span>
								Nessun documento esistente da sostituire: questo sarà il primo della sua catena di versioni.
							</p>
							<input type="hidden" name="doc_previous_version" id="doc_previous_version" value="0">
						<?php else : ?>
							<div class="dealer-version-picker">
								<label for="doc_previous_version_search" class="screen-reader-text">Filtra i documenti esistenti</label>
								<input type="search"
									id="doc_previous_version_search"
									class="regular-text dealer-version-search"
									placeholder="Filtra per titolo, brand, file…"
									autocomplete="off">

								<select name="doc_previous_version" id="doc_previous_version" size="8" class="dealer-version-select">
									<option value="0" data-search="">— Nessuno: è un documento nuovo —</option>
									<?php foreach ( $versionable as $vdoc ) :
										$v_label    = get_post_meta( $vdoc->ID, '_doc_version',  true );
										$v_filename = get_post_meta( $vdoc->ID, '_doc_filename', true );
										$v_seq      = Dealer_Versioning::get_sequence( (int) $vdoc->ID );

										$option_text = $vdoc->post_title;
										if ( $v_label ) {
											$option_text .= ' — ' . $v_label;
										}
										$option_text .= ' (v' . $v_seq . ' · ' . get_the_date( 'd/m/Y', $vdoc ) . ')';

										$search_blob = strtolower( trim( $vdoc->post_title . ' ' . $v_label . ' ' . $v_filename . ' #' . $vdoc->ID ) );
										?>
										<option value="<?php echo esc_attr( $vdoc->ID ); ?>" data-search="<?php echo esc_attr( $search_blob ); ?>">
											<?php echo esc_html( $option_text ); ?>
										</option>
									<?php endforeach; ?>
								</select>

								<p class="dealer-version-count" id="doc-previous-version-count" aria-live="polite">
									<?php echo esc_html( count( $versionable ) ); ?> documenti disponibili
								</p>
								<p class="dealer-version-hint" id="doc-previous-version-hint" aria-live="polite"></p>
							</div>
							<p class="description">
								Lascia su <strong>Nessuno</strong> se è un documento nuovo. Selezionando un documento
								esistente, quello caricato diventa la <strong>nuova versione corrente</strong>: il
								precedente resta scaricabile come storico ma esce dalla ricerca dei dealer.
							</p>
						<?php endif; ?>
					</td>
				</tr>
			</table>

			<div class="dealer-panel-nav">
				<button type="button" class="button dealer-back-btn" data-back="1">← Indietro</button>
				<button type="button" class="button button-primary dealer-next-btn" data-next="3">Avanti →</button>
			</div>
		</div>

		<!-- ── STEP 3: Visibilità ────────────────────────────────────── -->
		<div class="dealer-panel" data-panel="3">
			<h2>Step 3 — Visibilità e Accessi</h2>

			<table class="form-table">
				<tr>
					<th>Ruoli autorizzati <span class="required">*</span></th>
					<td>
						<fieldset>
							<legend class="screen-reader-text">Seleziona i ruoli che possono vedere questo documento</legend>
							<label><input type="checkbox" name="doc_roles[]" value="dealer"> <strong>Dealer</strong> — accesso base</label><br>
							<label><input type="checkbox" name="doc_roles[]" value="top_dealer"> <strong>Top Dealer</strong> — accesso esteso</label><br>
							<label><input type="checkbox" name="doc_roles[]" value="part_center"> <strong>Part Center</strong> — ricambi e tecnico</label>
						</fieldset>
						<p class="description">Seleziona almeno un ruolo.</p>
					</td>
				</tr>
				<tr>
					<th><label for="doc_lines">Linee Prodotto (restrizione)</label></th>
					<td>
						<select name="doc_lines[]" id="doc_lines" multiple style="min-height:100px;min-width:280px;">
							<option value="">— Prima seleziona il brand nello Step 2 —</option>
						</select>
						<p class="description">Solo i dealer assegnati a queste linee vedranno il documento. <strong>Lascia vuoto</strong> per renderlo visibile a tutti i dealer dei ruoli scelti sopra.</p>
					</td>
				</tr>
				<tr>
					<th><label for="doc_expiry">Data Scadenza</label></th>
					<td>
						<input type="date" name="doc_expiry" id="doc_expiry">
						<p class="description">Opzionale. Utile per listini stagionali. Il documento verrà marcato "In Scadenza" nei 30 giorni prima.</p>
					</td>
				</tr>
			</table>

			<div class="dealer-panel-nav">
				<button type="button" class="button dealer-back-btn" data-back="2">← Indietro</button>
				<button type="button" class="button button-primary dealer-next-btn" data-next="4">Avanti →</button>
			</div>
		</div>

		<!-- ── STEP 4: Keywords ──────────────────────────────────────── -->
		<div class="dealer-panel" data-panel="4">
			<h2>Step 4 — Parole Chiave e Note</h2>

			<table class="form-table">
				<tr>
					<th><label for="doc_keywords">Parole chiave</label></th>
					<td>
						<input type="text" name="doc_keywords" id="doc_keywords" class="large-text" placeholder="es. fuoribordo, 4 tempi, 150 CV, CE, trim">
						<p class="description">Separate da virgola. SearchWP le usa per la ricerca full-text dei dealer.</p>
					</td>
				</tr>
				<tr>
					<th><label for="doc_internal_notes">Note interne</label></th>
					<td>
						<textarea name="doc_internal_notes" id="doc_internal_notes" rows="4" class="large-text" placeholder="es. Sostituisce rev precedente. Inviato via email il 15/03."></textarea>
						<p class="description"><strong>Non visibili ai dealer.</strong> Solo per uso amministrativo interno.</p>
					</td>
				</tr>
			</table>

			<div class="dealer-panel-nav">
				<button type="button" class="button dealer-back-btn" data-back="3">← Indietro</button>
				<button type="button" class="button button-primary dealer-next-btn" data-next="5">Avanti →</button>
			</div>
		</div>

		<!-- ── STEP 5: Riepilogo ─────────────────────────────────────── -->
		<div class="dealer-panel" data-panel="5">
			<h2>Step 5 — Riepilogo e Salvataggio</h2>
			<p>Verifica i dati inseriti prima di salvare.</p>

			<div id="dealer-summary" class="dealer-summary-box" aria-live="polite">
				<!-- Popolato da admin-upload.js prima di mostrare questo panel -->
			</div>

			<div class="dealer-panel-nav">
				<button type="button" class="button dealer-back-btn" data-back="4">← Indietro</button>
				<button type="submit" id="dealer-save-btn" class="button button-primary button-hero">
					<span class="dashicons dashicons-yes" style="vertical-align:middle;"></span>
					Salva Documento
				</button>
			</div>
		</div>

	</form><!-- #dealer-upload-form -->
</div><!-- .wrap -->
