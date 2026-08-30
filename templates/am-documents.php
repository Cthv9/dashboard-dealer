<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/**
 * Scheda "I miei documenti".
 *
 * Elenco dei documenti del perimetro, con la distinzione — che non è cosmetica —
 * fra documenti soltanto VISIBILI (una linea nel perimetro: sovrapposizione) e
 * documenti MODIFICABILI (tutte le linee nel perimetro: contenimento). Il
 * criterio è Dealer_Identity::can_edit_document(), risolto in PHP e riportato
 * qui nel flag 'editable': un documento a cavallo di linee altrui resta
 * dell'amministratore.
 *
 * Consultazione e filtri funzionano senza JavaScript (form GET e link reali).
 * La sola marcatura come obsoleto passa dall'endpoint AJAX già esistente.
 */

$am_filter_args = [
	'am_tab'   => Dealer_Area_Manager::TAB_DOCUMENTS,
	'am_q'     => $doc_filters['q'],
	'am_brand' => $doc_filters['brand'],
	'am_type'  => $doc_filters['type'],
	'am_stato' => $doc_filters['stato'],
];
$am_filter_args = array_filter( $am_filter_args, static function ( $value ): bool {
	return '' !== $value && null !== $value;
} );

$am_states = [
	''         => 'Tutti gli stati',
	'corrente' => 'Solo versioni correnti',
	'storico'  => 'Solo versioni superate',
	'obsoleto' => 'Solo obsoleti',
	'scadenza' => 'In scadenza o scaduti',
];
?>

<div class="dealer-am-panel">
	<h3>I documenti del tuo perimetro</h3>
	<p class="dealer-am-hint">
		Sono elencati i documenti che ricadono almeno in parte nelle tue linee prodotto.
		Quelli che <strong>toccano anche linee fuori dal tuo perimetro</strong> restano in
		sola lettura: per intervenirci serve l’amministratore del portale.
	</p>

	<form method="get" action="<?php echo esc_url( $base_url ); ?>">
		<input type="hidden" name="am_tab" value="<?php echo esc_attr( Dealer_Area_Manager::TAB_DOCUMENTS ); ?>">

		<div class="dealer-am-row">
			<div class="dealer-am-field">
				<label for="am-q">Cerca</label>
				<input type="search" id="am-q" name="am_q" value="<?php echo esc_attr( $doc_filters['q'] ); ?>"
					placeholder="Titolo, nome file, parole chiave…">
			</div>
			<div class="dealer-am-field">
				<label for="am-brand">Brand</label>
				<select id="am-brand" name="am_brand">
					<option value="">Tutti i brand</option>
					<?php foreach ( $brands as $am_brand ) : ?>
						<option value="<?php echo esc_attr( $am_brand ); ?>" <?php selected( $doc_filters['brand'], $am_brand ); ?>>
							<?php echo esc_html( $am_brand ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>
			<div class="dealer-am-field">
				<label for="am-type">Tipo</label>
				<select id="am-type" name="am_type">
					<option value="">Tutti i tipi</option>
					<?php foreach ( $doc_types as $am_type_key => $am_type_label ) : ?>
						<option value="<?php echo esc_attr( $am_type_key ); ?>" <?php selected( $doc_filters['type'], $am_type_key ); ?>>
							<?php echo esc_html( $am_type_label ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>
			<div class="dealer-am-field">
				<label for="am-stato">Stato</label>
				<select id="am-stato" name="am_stato">
					<?php foreach ( $am_states as $am_state_key => $am_state_label ) : ?>
						<option value="<?php echo esc_attr( $am_state_key ); ?>" <?php selected( $doc_filters['stato'], $am_state_key ); ?>>
							<?php echo esc_html( $am_state_label ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>
		</div>

		<button type="submit" class="dealer-am-btn dealer-am-btn-sm">Applica filtri</button>
		<?php if ( count( $am_filter_args ) > 1 ) : ?>
			<a class="dealer-am-btn dealer-am-btn-ghost dealer-am-btn-sm"
				href="<?php echo esc_url( $this->tab_url( Dealer_Area_Manager::TAB_DOCUMENTS ) ); ?>">Azzera</a>
		<?php endif; ?>
	</form>
</div>

<div class="dealer-am-panel">
	<h4><?php echo esc_html( sprintf( '%d documenti', (int) $doc_paging['total'] ) ); ?></h4>

	<?php if ( empty( $documents ) ) : ?>
		<p class="dealer-am-empty">
			Nessun documento corrisponde ai criteri scelti.
		</p>
	<?php else : ?>

		<noscript>
			<p class="dealer-am-noscript">
				Con JavaScript disattivato puoi consultare e filtrare l’elenco, ma non
				segnare un documento come obsoleto.
			</p>
		</noscript>

		<div class="dealer-am-tablewrap">
			<table class="dealer-am-table">
				<thead>
					<tr>
						<th scope="col">Documento</th>
						<th scope="col">Classificazione</th>
						<th scope="col">Versione</th>
						<th scope="col">Stato</th>
						<th scope="col">Download</th>
						<th scope="col">Azioni</th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $documents as $am_doc ) : ?>
					<tr id="am-doc-<?php echo esc_attr( (string) $am_doc['id'] ); ?>">
						<td>
							<span class="dealer-am-doc-title"><?php echo esc_html( $am_doc['title'] ); ?></span>
							<?php
							// Chi pubblica e aggiorna un documento deve poterlo aprire.
							// Il permesso sul singolo file resta deciso a valle da
							// user_can_download_post(); qui mostriamo il link solo
							// quando quel controllo passerebbe davvero, per non
							// offrire un'azione che finirebbe in un errore.
							$am_can_download = Dealer_Search::user_can_download_post( $user, [], (int) $am_doc['id'] );
							?>
							<?php if ( '' !== $am_doc['filename'] ) : ?>
								<?php if ( $am_can_download ) : ?>
									<a class="dealer-am-doc-file"
										href="<?php echo esc_url( Dealer_Search::get_download_url( (int) $am_doc['id'] ) ); ?>"
										title="Scarica il documento">
										<?php echo esc_html( $am_doc['filename'] ); ?>
									</a>
								<?php else : ?>
									<span class="dealer-am-doc-file"><?php echo esc_html( $am_doc['filename'] ); ?></span>
								<?php endif; ?>
							<?php endif; ?>
							<div class="dealer-am-chiplist">
								<?php foreach ( $am_doc['lines'] as $am_line ) : ?>
									<span class="dealer-am-chip"><?php echo esc_html( Dealer_Area_Manager::line_label( (string) $am_line ) ); ?></span>
								<?php endforeach; ?>
							</div>
						</td>
						<td>
							<?php echo esc_html( $am_doc['brand'] ); ?>
							<?php if ( '' !== $am_doc['line'] ) : ?>
								<br><span class="dealer-am-doc-file"><?php echo esc_html( $am_doc['line'] ); ?></span>
							<?php endif; ?>
							<br><span class="dealer-am-doc-file">
								<?php echo esc_html( trim( $am_doc['type'] . ' ' . $am_doc['year'] ) ); ?>
							</span>
						</td>
						<td>
							<span class="dealer-am-badge dealer-am-badge-soft">
								<?php echo esc_html( 'v' . $am_doc['seq'] ); ?>
							</span>
							<?php if ( '' !== $am_doc['version'] ) : ?>
								<br><span class="dealer-am-doc-file"><?php echo esc_html( $am_doc['version'] ); ?></span>
							<?php endif; ?>
							<?php if ( $am_doc['previous'] > 0 ) : ?>
								<br><span class="dealer-am-doc-file">
									<?php echo esc_html( sprintf( '%d precedenti', (int) $am_doc['previous'] ) ); ?>
								</span>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( $am_doc['obsolete'] ) : ?>
								<span class="dealer-am-badge dealer-am-badge-danger">Obsoleto</span>
							<?php elseif ( $am_doc['is_current'] ) : ?>
								<span class="dealer-am-badge dealer-am-badge-ok">Corrente</span>
							<?php else : ?>
								<span class="dealer-am-badge dealer-am-badge-soft">Versione superata</span>
							<?php endif; ?>

							<?php if ( $am_doc['expired'] ) : ?>
								<br><span class="dealer-am-badge dealer-am-badge-danger">Scaduto</span>
							<?php elseif ( $am_doc['expiring'] ) : ?>
								<br><span class="dealer-am-badge dealer-am-badge-warn">In scadenza</span>
							<?php endif; ?>

							<?php if ( ! $am_doc['editable'] ) : ?>
								<br><span class="dealer-am-badge dealer-am-badge-soft">Sola lettura</span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( (string) $am_doc['downloads'] ); ?></td>
						<td>
							<?php if ( $am_doc['editable'] && ! $am_doc['obsolete'] ) : ?>
								<div class="dealer-am-doc-actions">
									<a class="dealer-am-btn dealer-am-btn-ghost dealer-am-btn-sm"
										href="<?php echo esc_url( add_query_arg(
											[
												'am_tab'  => Dealer_Area_Manager::TAB_UPLOAD,
												'am_prev' => (int) $am_doc['id'],
											],
											$base_url
										) ); ?>">Nuova versione</a>

									<button type="button"
										class="dealer-am-btn dealer-am-btn-danger dealer-am-btn-sm dealer-am-obsolete"
										data-doc="<?php echo esc_attr( (string) $am_doc['id'] ); ?>">
										Segna obsoleto
									</button>
								</div>
								<p class="dealer-am-error" data-doc-msg="<?php echo esc_attr( (string) $am_doc['id'] ); ?>" hidden></p>
							<?php elseif ( $am_doc['obsolete'] ) : ?>
								<span class="dealer-am-doc-file">Nessuna azione disponibile.</span>
							<?php else : ?>
								<span class="dealer-am-doc-file">
									Documento condiviso con linee fuori dal tuo perimetro.
								</span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>

		<?php if ( (int) $doc_paging['pages'] > 1 ) : ?>
			<div class="dealer-am-pager">
				<?php for ( $am_page = 1; $am_page <= (int) $doc_paging['pages']; $am_page++ ) : ?>
					<?php if ( $am_page === (int) $doc_paging['current'] ) : ?>
						<span class="is-current"><?php echo esc_html( (string) $am_page ); ?></span>
					<?php else : ?>
						<a href="<?php echo esc_url( add_query_arg(
							array_merge( $am_filter_args, [ 'am_paged' => $am_page ] ),
							$base_url
						) ); ?>"><?php echo esc_html( (string) $am_page ); ?></a>
					<?php endif; ?>
				<?php endfor; ?>
			</div>
		<?php endif; ?>

	<?php endif; ?>
</div>
