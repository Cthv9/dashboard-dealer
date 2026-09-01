<?php
/**
 * Creazione e modifica di un'organizzazione.
 * Dealer Portal → Organizzazioni → Modifica (view=edit)
 *
 * Variabili fornite da Dealer_Org_Admin::render_edit():
 * @var array  $org            Dati correnti (o valori vuoti in creazione)
 * @var int    $org_id
 * @var bool   $is_new
 * @var array  $parents        Candidate madri (self e sottoalbero esclusi)
 * @var array  $parent_map     org_id => indici delle sue linee effettive
 * @var array|null $breakdown  Scomposizione proprie/ereditate/effettive
 * @var array  $children       ID delle figlie dirette
 * @var array  $impact         Impatto di una sospensione (orgs, users)
 * @var array|null $delete_info
 * @var array  $lines_by_brand
 * @var array  $valid_lines
 * @var array  $line_index
 * @var array  $tier_labels
 * @var array  $notice
 * @var string $base_url
 * @var string $post_url
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! Dealer_DB::user_can( DEALER_PORTAL_CAP_ORGS ) ) {
	wp_die( esc_html__( 'Accesso non consentito.', 'dealer-portal' ) );
}

require DEALER_PORTAL_PATH . 'templates/admin-org-styles.php';

$own_lines = (array) $org['own_lines'];

// Etichette leggibili nello stesso ordine degli indici usati dal calcolo live.
$js_labels = [];
foreach ( $valid_lines as $line ) {
	$js_labels[] = Dealer_Org_Admin::line_label( $line );
}
?>
<div class="wrap">
	<h1 class="wp-heading-inline"><?php echo $is_new ? 'Nuova organizzazione' : esc_html( $org['name'] ); ?></h1>
	<a href="<?php echo esc_url( $base_url ); ?>" class="page-title-action">Torna all'elenco</a>
	<?php if ( ! $is_new ) : ?>
		<a href="<?php echo esc_url( add_query_arg( [ 'view' => 'users', 'org' => $org_id ], $base_url ) ); ?>" class="page-title-action">Utenti dell'organizzazione</a>
	<?php endif; ?>
	<hr class="wp-header-end">

	<?php if ( ! empty( $notice['message'] ) ) : ?>
		<div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?> is-dismissible">
			<p><?php echo esc_html( $notice['message'] ); ?></p>
		</div>
	<?php endif; ?>

	<?php if ( ! $is_new ) : ?>
		<p class="dorg-muted">
			Percorso: <strong><?php echo esc_html( Dealer_Org_Admin::org_path( $org_id ) ); ?></strong>
			<?php if ( ! empty( $children ) ) : ?>
				· <?php echo esc_html( (string) count( $children ) ); ?> organizzazioni figlie dirette erediteranno da questa.
			<?php endif; ?>
		</p>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( $post_url ); ?>" id="dorg-form">
		<?php wp_nonce_field( 'dealer_org_save' ); ?>
		<input type="hidden" name="action" value="dealer_org_save">
		<input type="hidden" name="org_id" value="<?php echo esc_attr( (string) $org_id ); ?>">

		<div style="display:flex; gap:20px; align-items:flex-start; flex-wrap:wrap;">

			<!-- ─── Colonna sinistra: anagrafica e linee ─────────────────── -->
			<div style="flex:2 1 560px; min-width:340px;">

				<div class="postbox">
					<div class="postbox-header"><h2 class="hndle" style="padding:8px 12px;">Anagrafica</h2></div>
					<div class="inside">
						<table class="form-table" role="presentation">
							<tr>
								<th scope="row"><label for="org_name">Nome <span class="dorg-dead">*</span></label></th>
								<td><input type="text" class="regular-text" id="org_name" name="org_name" required
									value="<?php echo esc_attr( $org['name'] ); ?>"></td>
							</tr>
							<tr>
								<th scope="row"><label for="org_vat">Partita IVA</label></th>
								<td><input type="text" class="regular-text" id="org_vat" name="org_vat"
									value="<?php echo esc_attr( $org['vat'] ); ?>"></td>
							</tr>
							<tr>
								<th scope="row"><label for="org_tier">Livello commerciale</label></th>
								<td>
									<select id="org_tier" name="org_tier">
										<?php foreach ( Dealer_Organization::TIERS as $tier ) : ?>
											<option value="<?php echo esc_attr( $tier ); ?>" <?php selected( $org['tier'], $tier ); ?>>
												<?php echo esc_html( $tier_labels[ $tier ] ?? $tier ); ?>
											</option>
										<?php endforeach; ?>
									</select>
									<p class="description">Il livello e' un attributo dell'azienda: vale per tutti i suoi utenti.</p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="org_parent">Organizzazione madre</label></th>
								<td>
									<select id="org_parent" name="org_parent">
										<option value="0" <?php selected( (int) $org['parent'], 0 ); ?>>— nessuna (organizzazione radice) —</option>
										<?php foreach ( $parents as $candidate ) : ?>
											<option value="<?php echo esc_attr( (string) $candidate['id'] ); ?>" <?php selected( (int) $org['parent'], (int) $candidate['id'] ); ?>>
												<?php echo esc_html( $candidate['path'] ); ?>
											</option>
										<?php endforeach; ?>
									</select>
									<p class="description">
										Le discendenti di questa organizzazione non compaiono nell'elenco: renderle madri creerebbe un ciclo
										e <code>set_parent()</code> lo rifiuta.
									</p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="org_status">Stato</label></th>
								<td>
									<select id="org_status" name="org_status">
										<option value="<?php echo esc_attr( Dealer_Organization::STATUS_ACTIVE ); ?>" <?php selected( $org['status'], Dealer_Organization::STATUS_ACTIVE ); ?>>Attiva</option>
										<option value="<?php echo esc_attr( Dealer_Organization::STATUS_SUSPENDED ); ?>" <?php selected( $org['status'], Dealer_Organization::STATUS_SUSPENDED ); ?>>Sospesa</option>
									</select>
									<p class="description">
										<span class="dashicons dashicons-warning" style="vertical-align:middle;"></span>
										<strong>La sospensione si propaga alle discendenti.</strong>
										<?php if ( ! $is_new ) : ?>
											Sospendendo questa organizzazione resterebbero senza accesso
											<strong><?php echo esc_html( (string) (int) $impact['orgs'] ); ?></strong> organizzazioni
											(questa inclusa) e <strong><?php echo esc_html( (string) (int) $impact['users'] ); ?></strong> utenti.
										<?php endif; ?>
									</p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="org_ref_nome">Referente commerciale</label></th>
								<td>
									<input type="text" class="regular-text" id="org_ref_nome" name="org_ref_nome"
										placeholder="Nome e cognome" value="<?php echo esc_attr( $org['ref_nome'] ); ?>"><br>
									<input type="email" class="regular-text" name="org_ref_email" style="margin-top:6px;"
										placeholder="Email" value="<?php echo esc_attr( $org['ref_email'] ); ?>"><br>
									<input type="text" class="regular-text" name="org_ref_tel" style="margin-top:6px;"
										placeholder="Telefono" value="<?php echo esc_attr( $org['ref_tel'] ); ?>">
								</td>
							</tr>
						</table>
					</div>
				</div>

				<div class="postbox">
					<div class="postbox-header"><h2 class="hndle" style="padding:8px 12px;">Linee prodotto proprie</h2></div>
					<div class="inside">
						<p class="description" style="margin-bottom:10px;">
							Queste sono le linee <strong>dichiarate</strong> su questa organizzazione. Se ha una madre,
							quelle che la madre non possiede vengono annullate dall'intersezione: il riquadro
							<em>Effetto risultante</em> le elenca. Lasciando tutto vuoto la sede vale esattamente quanto la madre.
						</p>
						<p>
							<input type="search" id="dorg-line-filter" class="regular-text" placeholder="Filtra brand o linea…">
							<button type="button" class="button" id="dorg-clear-all">Deseleziona tutto</button>
						</p>
						<div class="dorg-lines-scroll">
							<?php foreach ( $lines_by_brand as $brand => $brand_lines ) : ?>
								<div class="dorg-brand-group" data-brand="<?php echo esc_attr( $brand ); ?>">
									<div class="dorg-brand-head">
										<label>
											<input type="checkbox" class="dorg-brand-toggle"> <?php echo esc_html( $brand ); ?>
										</label>
										<span class="dorg-brand-tools">
											<span class="dorg-brand-count">0</span>/<?php echo esc_html( (string) count( $brand_lines ) ); ?> selezionate
										</span>
									</div>
									<div class="dorg-brand-body">
										<?php foreach ( $brand_lines as $line ) :
											$value = $brand . '|' . $line;
											if ( ! isset( $line_index[ $value ] ) ) {
												continue;
											}
											?>
											<label data-search="<?php echo esc_attr( strtolower( $brand . ' ' . $line ) ); ?>">
												<input type="checkbox" class="dorg-line" name="org_lines[]"
													value="<?php echo esc_attr( $value ); ?>"
													data-idx="<?php echo esc_attr( (string) (int) $line_index[ $value ] ); ?>"
													<?php checked( in_array( $value, $own_lines, true ) ); ?>>
												<?php echo esc_html( $line ); ?>
											</label>
										<?php endforeach; ?>
									</div>
								</div>
							<?php endforeach; ?>
						</div>
					</div>
				</div>

				<p>
					<button type="submit" class="button button-primary button-large">
						<?php echo $is_new ? 'Crea organizzazione' : 'Salva modifiche'; ?>
					</button>
					<a href="<?php echo esc_url( $base_url ); ?>" class="button">Annulla</a>
				</p>
			</div>

			<!-- ─── Colonna destra: effetto risultante ───────────────────── -->
			<div style="flex:1 1 320px; min-width:300px;" class="dorg-sticky">
				<div class="postbox">
					<div class="postbox-header"><h2 class="hndle" style="padding:8px 12px;">
						<span class="dashicons dashicons-visibility" style="vertical-align:middle;"></span> Effetto risultante
					</h2></div>
					<div class="inside">
						<p class="description">
							<code>effettive = proprie ∩ effettive della madre</code><br>
							Radice: valgono le proprie. Nessuna linea propria: vale quanto la madre.
						</p>

						<div class="dorg-effect is-ok" id="dorg-effect-box">
							<h3>Linee realmente visibili</h3>
							<p>
								<span class="dorg-count" id="dorg-eff-count">0</span>
								<span class="dorg-muted">linee effettive</span>
								<span class="dorg-badge" id="dorg-mode">—</span>
							</p>
							<p class="dorg-muted" style="font-size:12px;">
								proprie selezionate: <strong id="dorg-own-count">0</strong> ·
								tetto dalla madre: <strong id="dorg-parent-count">—</strong>
							</p>
						</div>

						<div class="dorg-effect is-alert" id="dorg-dead-box" style="display:none;">
							<h3><span class="dashicons dashicons-warning"></span> Linee senza effetto</h3>
							<p>
								<strong id="dorg-dead-count">0</strong> linee sono selezionate qui ma <strong>non</strong> sono
								concesse alla madre: l'intersezione le annulla, quindi non produrranno alcun accesso.
								Per attivarle vanno prima concesse alla madre.
							</p>
							<ul class="dorg-line-list" id="dorg-dead-list"></ul>
						</div>

						<div class="dorg-effect is-warn" id="dorg-empty-box" style="display:none;">
							<h3>Nessuna linea effettiva</h3>
							<p>Gli utenti di questa organizzazione non vedranno alcun documento riservato a una linea.</p>
						</div>

						<?php if ( ! $is_new && ! empty( $children ) ) : ?>
							<div class="dorg-effect is-warn">
								<h3>Ricadute sulle figlie</h3>
								<p>
									<?php echo esc_html( (string) count( $children ) ); ?> organizzazioni dipendono da questa.
									Togliere una linea qui la toglie anche a loro, immediatamente.
								</p>
								<ul>
									<?php foreach ( $children as $child ) : ?>
										<li>
											<a href="<?php echo esc_url( add_query_arg( [ 'view' => 'edit', 'org' => $child ], $base_url ) ); ?>">
												<?php echo esc_html( Dealer_Organization::get_name( $child ) ); ?>
											</a>
											<span class="dorg-muted">(<?php echo esc_html( (string) count( Dealer_Organization::get_effective_lines( $child ) ) ); ?> linee effettive oggi)</span>
										</li>
									<?php endforeach; ?>
								</ul>
							</div>
						<?php endif; ?>

						<?php if ( ! $is_new && $breakdown && ! empty( $breakdown['effective'] ) ) : ?>
							<details>
								<summary>Elenco delle linee effettive salvate (<?php echo esc_html( (string) count( $breakdown['effective'] ) ); ?>)</summary>
								<ul class="dorg-line-list">
									<?php foreach ( $breakdown['effective'] as $line ) : ?>
										<li><?php echo esc_html( Dealer_Org_Admin::line_label( $line ) ); ?></li>
									<?php endforeach; ?>
								</ul>
							</details>
						<?php endif; ?>
					</div>
				</div>
			</div>
		</div>
	</form>

	<?php if ( ! $is_new && $delete_info ) : ?>
		<!-- ─── Eliminazione ─────────────────────────────────────────────── -->
		<div class="postbox" style="border-left:4px solid #d63638;">
			<div class="postbox-header"><h2 class="hndle" style="padding:8px 12px;">Elimina organizzazione</h2></div>
			<div class="inside">
				<?php if ( (int) $delete_info['users'] > 0 ) : ?>
					<div class="notice notice-error inline"><p>
						Questa organizzazione ha <strong><?php echo esc_html( (string) (int) $delete_info['users'] ); ?></strong> utenti:
						l'eliminazione e' bloccata. Un utente che punta a un'organizzazione inesistente resterebbe senza diritti
						e senza spiegazione. Spostali prima in un'altra organizzazione (o fondi questa in un'altra), poi riprova.
					</p></div>
					<p>
						<a class="button" href="<?php echo esc_url( add_query_arg( [ 'view' => 'users', 'org' => $org_id ], $base_url ) ); ?>">Gestisci gli utenti</a>
						<a class="button" href="<?php echo esc_url( add_query_arg( 'view', 'merge', $base_url ) ); ?>">Fondi in un'altra organizzazione</a>
					</p>
				<?php else : ?>
					<form method="post" action="<?php echo esc_url( $post_url ); ?>"
						onsubmit="return confirm('Eliminare definitivamente questa organizzazione?');">
						<?php wp_nonce_field( 'dealer_org_delete' ); ?>
						<input type="hidden" name="action" value="dealer_org_delete">
						<input type="hidden" name="org_id" value="<?php echo esc_attr( (string) $org_id ); ?>">

						<?php if ( ! empty( $delete_info['children'] ) ) : ?>
							<p>
								L'organizzazione ha <strong><?php echo esc_html( (string) count( $delete_info['children'] ) ); ?></strong>
								figlie: vanno riagganciate, non lasciate orfane. <strong>Attenzione</strong>: cambiando ramo cambia il
								tetto ereditato, quindi le loro linee effettive possono <em>aumentare</em> (linee proprie prima annullate
								che tornano valide) oppure <em>azzerarsi</em> (una figlia senza linee proprie valeva quanto la madre).
							</p>
							<table class="wp-list-table widefat striped" style="margin-bottom:10px;">
								<thead><tr>
									<th>Figlia</th><th>Utenti</th><th>Linee oggi</th>
									<th>Se riagganciata a «<?php echo esc_html( $delete_info['parent'] ? $delete_info['parent_name'] : '— nessuna —' ); ?>»</th>
									<th>Se promossa a radice</th>
								</tr></thead>
								<tbody>
								<?php foreach ( $delete_info['children'] as $child ) : ?>
									<tr>
										<td><?php echo esc_html( $child['name'] ); ?></td>
										<td><?php echo esc_html( (string) (int) $child['users'] ); ?></td>
										<td><?php echo esc_html( (string) (int) $child['now'] ); ?></td>
										<td class="<?php echo esc_attr( Dealer_Org_Admin::delta_class( (int) $child['now'], (int) $child['after_parent'] ) ); ?>">
											<?php echo esc_html( (string) (int) $child['after_parent'] ); ?>
											<span class="dorg-cell-sub"><?php echo esc_html( Dealer_Org_Admin::delta_note( (int) $child['now'], (int) $child['after_parent'] ) ); ?></span>
										</td>
										<td class="<?php echo esc_attr( Dealer_Org_Admin::delta_class( (int) $child['now'], (int) $child['after_root'] ) ); ?>">
											<?php echo esc_html( (string) (int) $child['after_root'] ); ?>
											<span class="dorg-cell-sub"><?php echo esc_html( Dealer_Org_Admin::delta_note( (int) $child['now'], (int) $child['after_root'] ) ); ?></span>
										</td>
									</tr>
								<?php endforeach; ?>
								</tbody>
							</table>
							<p>
								<label>
									<input type="radio" name="children_action" value="reparent" checked>
									Riaggancia le figlie a
									<strong><?php echo esc_html( $delete_info['parent'] ? $delete_info['parent_name'] : '— nessuna madre: diventeranno radici —' ); ?></strong>
								</label><br>
								<label>
									<input type="radio" name="children_action" value="promote">
									Promuovi le figlie a organizzazioni radice (nessun tetto ereditato)
								</label>
							</p>
						<?php else : ?>
							<p>L'organizzazione non ha figlie ne' utenti: puo' essere eliminata.</p>
						<?php endif; ?>

						<p>
							<label>
								<input type="checkbox" name="confirm" value="1" required>
								Confermo l'eliminazione definitiva di «<?php echo esc_html( $org['name'] ); ?>».
							</label>
						</p>
						<button type="submit" class="button button-link-delete button-large">Elimina definitivamente</button>
					</form>
				<?php endif; ?>
			</div>
		</div>
	<?php endif; ?>
</div>

<script>
/* Calcolo live dell'effetto dell'ereditarieta'.
   Nessuna libreria: le linee viaggiano come indici, la madre porta con se' le
   proprie linee effettive gia' risolte lato server. */
( function () {
	var PARENT_MAP = <?php echo wp_json_encode( $parent_map ); ?>;
	var LABELS     = <?php echo wp_json_encode( $js_labels ); ?>;

	var form       = document.getElementById( 'dorg-form' );
	if ( ! form ) { return; }

	var parentSel  = document.getElementById( 'org_parent' );
	var boxes      = form.querySelectorAll( '.dorg-line' );
	var effCount   = document.getElementById( 'dorg-eff-count' );
	var ownCount   = document.getElementById( 'dorg-own-count' );
	var parCount   = document.getElementById( 'dorg-parent-count' );
	var modeBadge  = document.getElementById( 'dorg-mode' );
	var effectBox  = document.getElementById( 'dorg-effect-box' );
	var deadBox    = document.getElementById( 'dorg-dead-box' );
	var deadList   = document.getElementById( 'dorg-dead-list' );
	var deadCount  = document.getElementById( 'dorg-dead-count' );
	var emptyBox   = document.getElementById( 'dorg-empty-box' );

	function parentLines() {
		var id = parentSel ? parseInt( parentSel.value, 10 ) : 0;
		if ( ! id || ! PARENT_MAP.hasOwnProperty( id ) ) { return null; }
		var set = {};
		PARENT_MAP[ id ].forEach( function ( idx ) { set[ idx ] = true; } );
		return { set: set, count: PARENT_MAP[ id ].length };
	}

	function recompute() {
		var own = [];
		Array.prototype.forEach.call( boxes, function ( box ) {
			if ( box.checked ) { own.push( parseInt( box.getAttribute( 'data-idx' ), 10 ) ); }
		} );

		var parent = parentLines();
		var eff, dead = [], mode;

		if ( ! parent ) {
			eff  = own.slice();
			dead = [];
			mode = 'proprie (radice)';
			parCount.textContent = '—';
		} else if ( 0 === own.length ) {
			eff  = new Array( parent.count );
			dead = [];
			mode = 'ereditate dalla madre';
			parCount.textContent = String( parent.count );
		} else {
			eff = own.filter( function ( idx ) { return parent.set[ idx ]; } );
			dead = own.filter( function ( idx ) { return ! parent.set[ idx ]; } );
			mode = 'proprie ∩ madre';
			parCount.textContent = String( parent.count );
		}

		effCount.textContent = String( eff.length );
		ownCount.textContent = String( own.length );
		modeBadge.textContent = mode;

		if ( dead.length ) {
			deadBox.style.display = '';
			deadCount.textContent = String( dead.length );
			deadList.innerHTML = '';
			dead.forEach( function ( idx ) {
				var li = document.createElement( 'li' );
				li.textContent = LABELS[ idx ] || ( 'linea #' + idx );
				deadList.appendChild( li );
			} );
			effectBox.className = 'dorg-effect is-warn';
		} else {
			deadBox.style.display = 'none';
			effectBox.className = eff.length ? 'dorg-effect is-ok' : 'dorg-effect is-warn';
		}

		emptyBox.style.display = eff.length ? 'none' : '';

		// Contatore per brand + stato della casella "tutto il brand".
		Array.prototype.forEach.call( form.querySelectorAll( '.dorg-brand-group' ), function ( group ) {
			var inputs  = group.querySelectorAll( '.dorg-line' );
			var checked = group.querySelectorAll( '.dorg-line:checked' ).length;
			group.querySelector( '.dorg-brand-count' ).textContent = String( checked );
			var toggle = group.querySelector( '.dorg-brand-toggle' );
			toggle.checked       = ( checked > 0 && checked === inputs.length );
			toggle.indeterminate = ( checked > 0 && checked < inputs.length );
		} );
	}

	Array.prototype.forEach.call( boxes, function ( box ) {
		box.addEventListener( 'change', recompute );
	} );
	if ( parentSel ) { parentSel.addEventListener( 'change', recompute ); }

	// Selezione rapida per brand intero.
	Array.prototype.forEach.call( form.querySelectorAll( '.dorg-brand-toggle' ), function ( toggle ) {
		toggle.addEventListener( 'change', function () {
			var group = toggle.closest( '.dorg-brand-group' );
			Array.prototype.forEach.call( group.querySelectorAll( '.dorg-line' ), function ( box ) {
				box.checked = toggle.checked;
			} );
			recompute();
		} );
	} );

	var clearAll = document.getElementById( 'dorg-clear-all' );
	if ( clearAll ) {
		clearAll.addEventListener( 'click', function () {
			Array.prototype.forEach.call( boxes, function ( box ) { box.checked = false; } );
			recompute();
		} );
	}

	// Filtro: nasconde le voci che non corrispondono, non le deseleziona.
	var filter = document.getElementById( 'dorg-line-filter' );
	if ( filter ) {
		filter.addEventListener( 'input', function () {
			var q = filter.value.toLowerCase().trim();
			Array.prototype.forEach.call( form.querySelectorAll( '.dorg-brand-group' ), function ( group ) {
				var visible = 0;
				Array.prototype.forEach.call( group.querySelectorAll( '.dorg-brand-body label' ), function ( label ) {
					var hit = ( '' === q ) || label.getAttribute( 'data-search' ).indexOf( q ) !== -1;
					label.style.display = hit ? '' : 'none';
					if ( hit ) { visible++; }
				} );
				group.style.display = visible ? '' : 'none';
			} );
		} );
	}

	recompute();
} )();
</script>
