<?php
/**
 * Ruoli e Linee — tutti gli utenti del portale e cosa vede ciascuno.
 * Dealer Portal → Ruoli e Linee
 *
 * Variabili fornite da Dealer_Org_Admin::render_roles_lines():
 * @var array  $rows            id, name, email, is_am, role, org_id, org_name, function,
 *                              lines, active, edit_url, edit_label
 * @var int    $total           utenti totali che soddisfano il filtro
 * @var int    $without_access  quanti non vedono (o non pubblicano) nulla
 * @var array  $role_options    slug => etichetta, per il filtro
 * @var string $role_filter
 * @var string $search
 * @var int    $paged
 * @var int    $total_pages
 * @var string $base_url        questa schermata
 * @var string $orgs_url        Organizzazioni
 * @var string $am_url          Area Manager
 * @var string $post_url        admin-post.php
 * @var array  $all_roles       slug => [label, active, custom]
 * @var string $lines_text      catalogo come testo "Brand | Linea"
 * @var int    $role_count
 * @var int    $line_count
 * @var string[] $flash         esiti dell'ultimo salvataggio
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! Dealer_DB::user_can( DEALER_PORTAL_CAP_ORGS ) ) {
	wp_die( esc_html__( 'Accesso non consentito.', 'dealer-portal' ) );
}

require DEALER_PORTAL_PATH . 'templates/admin-org-styles.php';
?>
<div class="wrap">
	<h1 class="wp-heading-inline">Ruoli e Linee</h1>
	<a href="<?php echo esc_url( admin_url( 'user-new.php' ) ); ?>" class="page-title-action">Aggiungi utente</a>
	<hr class="wp-header-end">

	<?php if ( ! empty( $flash ) ) : ?>
		<div class="notice notice-info is-dismissible">
			<?php foreach ( $flash as $line ) : ?>
				<p><?php echo esc_html( $line ); ?></p>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<!-- ═══ 1. Ruoli area riservata + 2. Linee prodotto ═══════════════════ -->
	<form method="post" action="<?php echo esc_url( $post_url ); ?>">
		<?php wp_nonce_field( 'dealer_save_roles_lines' ); ?>
		<input type="hidden" name="action" value="dealer_save_roles_lines">

		<div class="postbox" style="margin-top:16px;">
			<div class="postbox-header"><h2 class="hndle" style="padding:8px 12px;">
				1 · Ruoli area riservata <span style="font-weight:400;color:#787c82;">(<?php echo esc_html( (string) $role_count ); ?> attivi)</span>
			</h2></div>
			<div class="inside">
				<p class="description">
					Da qui si modificano le etichette dei ruoli visibili nel plugin e si aggiungono nuovi ruoli dealer.
					Un ruolo <strong>disattivato</strong> non viene più usato per dashboard, ricerca e restrizioni sui
					documenti: gli utenti che ce l'hanno restano, ma smettono di essere trattati come dealer. Il ruolo
					WordPress non viene eliminato — cancellarlo toglierebbe l'accesso a persone reali.
				</p>
				<table class="wp-list-table widefat striped" style="max-width:760px;">
					<thead><tr><th style="width:80px;">Attivo</th><th style="width:240px;">Slug ruolo</th><th>Etichetta</th></tr></thead>
					<tbody>
					<?php foreach ( $all_roles as $slug => $role ) : ?>
						<tr>
							<td>
								<input type="checkbox" name="roles[<?php echo esc_attr( $slug ); ?>][active]" value="1"
									<?php checked( $role['active'] ); ?>>
							</td>
							<td>
								<code><?php echo esc_html( $slug ); ?></code>
								<?php if ( ! $role['custom'] ) : ?>
									<span class="dorg-cell-sub">ruolo di base</span>
								<?php endif; ?>
							</td>
							<td>
								<input type="text" class="regular-text"
									name="roles[<?php echo esc_attr( $slug ); ?>][label]"
									value="<?php echo esc_attr( $role['label'] ); ?>">
							</td>
						</tr>
					<?php endforeach; ?>
						<tr>
							<td><span class="dorg-muted">nuovo</span></td>
							<td><input type="text" class="regular-text" name="new_role_slug" placeholder="es. service_center"></td>
							<td><input type="text" class="regular-text" name="new_role_label" placeholder="es. Service Center"></td>
						</tr>
					</tbody>
				</table>
				<p class="description" style="margin-top:8px;">
					Lo slug deve contenere solo lettere minuscole, numeri e underscore. Non sono ammessi i ruoli nativi
					di WordPress (administrator, editor, author, contributor, subscriber) né <code>area_manager</code>:
					darebbero a un dealer permessi che non gli competono.
				</p>
			</div>
		</div>

		<div class="postbox">
			<div class="postbox-header"><h2 class="hndle" style="padding:8px 12px;">
				2 · Linee prodotto <span style="font-weight:400;color:#787c82;">(<?php echo esc_html( (string) $line_count ); ?> linee)</span>
			</h2></div>
			<div class="inside">
				<p class="description">
					Gestione massiva: <strong>una riga per ogni combinazione <code>Brand | Linea</code></strong>.
					Si può incollare direttamente da Excel o CSV — sono accettati anche <code>;</code> e <code>,</code>
					come separatore. Esempio: <code>Mercury | FourStroke</code>.
				</p>
				<textarea name="product_lines" rows="18" style="width:100%;font-family:Consolas,Monaco,monospace;font-size:12px;"><?php echo esc_textarea( $lines_text ); ?></textarea>
				<p class="description">
					Le righe senza separatore vengono segnalate al salvataggio, non ignorate in silenzio: una linea
					persa non si nota in un catalogo di centinaia, ma rende invisibili i documenti già pubblicati su
					di essa. Un catalogo vuoto viene rifiutato.
				</p>
			</div>
		</div>

		<p class="submit"><button type="submit" class="button button-primary">Salva ruoli e linee</button></p>
	</form>

	<!-- ═══ 3. Assegnazione massiva utenti ═══════════════════════════════ -->
	<div class="postbox">
		<div class="postbox-header"><h2 class="hndle" style="padding:8px 12px;">3 · Assegnazione massiva utenti</h2></div>
		<div class="inside">
			<p class="description">
				Seleziona uno o più utenti nella tabella qui sotto e applica in blocco ruolo e linee prodotto.
				Le linee valgono per gli utenti <strong>senza organizzazione</strong> (modello storico): per chi ne ha
				una i diritti li detiene l'azienda, e scriverli sull'utente non avrebbe effetto — quegli utenti
				vengono saltati e contati a parte.
			</p>
			<form method="post" action="<?php echo esc_url( $post_url ); ?>" id="dealer-bulk-assign">
				<?php wp_nonce_field( 'dealer_bulk_assign' ); ?>
				<input type="hidden" name="action" value="dealer_bulk_assign">

				<p>
					<label for="bulk_role"><strong>Ruolo area riservata</strong></label><br>
					<select id="bulk_role" name="bulk_role">
						<option value="">Non modificare il ruolo</option>
						<?php foreach ( Dealer_Roles::labels() as $slug => $label ) : ?>
							<option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>

				<p>
					<label><strong>Linee prodotto</strong></label><br>
					<label><input type="radio" name="lines_mode" value="keep" checked> Non modificare le linee</label>
					&nbsp;&nbsp;
					<label><input type="radio" name="lines_mode" value="set"> Sostituisci con quelle selezionate</label>
				</p>

				<div class="dorg-lines-scroll" style="max-height:260px;">
					<?php foreach ( Dealer_Admin::get_product_lines() as $brand => $brand_lines ) : ?>
						<div class="dorg-brand-group">
							<div class="dorg-brand-head"><?php echo esc_html( $brand ); ?></div>
							<div class="dorg-brand-body">
								<?php foreach ( $brand_lines as $line ) :
									$value = $brand . '|' . $line;
									?>
									<label>
										<input type="checkbox" name="bulk_lines[]" value="<?php echo esc_attr( $value ); ?>">
										<?php echo esc_html( $line ); ?>
									</label>
								<?php endforeach; ?>
							</div>
						</div>
					<?php endforeach; ?>
				</div>

				<p class="submit" style="padding-bottom:0;">
					<button type="submit" class="button button-primary"
						onclick="return confirm('Applicare ruolo e linee agli utenti selezionati?');">
						Applica agli utenti selezionati
					</button>
					<span class="description" style="margin-left:8px;">
						Amministratori e il tuo stesso account vengono sempre saltati.
					</span>
				</p>
			</form>
		</div>
	</div>

	<div class="notice notice-info inline" style="margin:16px 0;">
		<p style="margin:0;">
			Questa pagina mostra <strong>cosa vede davvero</strong> ogni utente del portale, con i diritti già
			risolti (organizzazione, ereditarietà, restrizioni individuali). Non si assegna nulla da qui:
			ogni riga porta all'unico punto che quel valore lo scrive, così non esistono due strade per lo
			stesso dato che possano dire cose diverse.
		</p>
	</div>

	<?php if ( $without_access > 0 ) : ?>
		<div class="notice notice-warning inline" style="margin:16px 0;">
			<p style="margin:0;">
				<strong><?php echo esc_html( (string) $without_access ); ?></strong>
				<?php echo esc_html( 1 === $without_access ? 'utente in questa pagina non ha nessuna linea' : 'utenti in questa pagina non hanno nessuna linea' ); ?>:
				i dealer non vedono alcun documento, gli area manager non possono pubblicarne.
			</p>
		</div>
	<?php endif; ?>

	<form method="get" style="margin:12px 0;">
		<input type="hidden" name="page" value="<?php echo esc_attr( Dealer_Org_Admin::ROLES_MENU_SLUG ); ?>">
		<label for="dlr-ruolo" class="screen-reader-text">Ruolo</label>
		<select id="dlr-ruolo" name="ruolo">
			<?php foreach ( $role_options as $slug => $label ) : ?>
				<option value="<?php echo esc_attr( $slug ); ?>"<?php selected( $role_filter, $slug ); ?>>
					<?php echo esc_html( $label ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<label for="dlr-q" class="screen-reader-text">Cerca</label>
		<input type="search" id="dlr-q" name="q" value="<?php echo esc_attr( $search ); ?>" placeholder="Nome, email, login…">
		<button type="submit" class="button">Filtra</button>
		<?php if ( '' !== $search || '' !== $role_filter ) : ?>
			<a class="button" href="<?php echo esc_url( $base_url ); ?>">Azzera</a>
		<?php endif; ?>
		<span class="dorg-muted" style="margin-left:10px;"><?php echo esc_html( (string) $total ); ?> utenti</span>
	</form>

	<?php if ( empty( $rows ) ) : ?>
		<div class="postbox"><div class="inside">
			<p><strong>Nessun utente del portale con questi criteri.</strong></p>
			<p>
				Gli utenti del portale sono quelli con ruolo Dealer, Top Dealer, Parts Center o Area Manager.
				Si creano da <a href="<?php echo esc_url( admin_url( 'user-new.php' ) ); ?>">Utenti → Aggiungi nuovo</a>
				scegliendo uno di quei ruoli.
			</p>
		</div></div>
	<?php else : ?>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th style="width:28px;"><input type="checkbox" id="dealer-check-all" title="Seleziona tutti"></th>
					<th style="width:22%;">Utente</th>
					<th style="width:12%;">Ruolo</th>
					<th style="width:22%;">Organizzazione</th>
					<th>Linee</th>
					<th style="width:12%;">Stato</th>
					<th style="width:12%;"></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<td>
							<input type="checkbox" form="dealer-bulk-assign" name="users[]"
								value="<?php echo esc_attr( (string) $row['id'] ); ?>" class="dealer-user-check">
						</td>
						<td>
							<strong><?php echo esc_html( $row['name'] ); ?></strong>
							<span class="dorg-cell-sub"><?php echo esc_html( $row['email'] ); ?></span>
						</td>
						<td>
							<span class="dorg-badge <?php echo $row['is_am'] ? 'is-inherit' : 'is-muted'; ?>">
								<?php echo esc_html( $row['role'] ); ?>
							</span>
						</td>
						<td>
							<?php if ( $row['org_id'] ) : ?>
								<?php echo esc_html( $row['org_name'] ); ?>
								<?php if ( '' !== $row['function'] ) : ?>
									<span class="dorg-cell-sub"><?php echo esc_html( $row['function'] ); ?></span>
								<?php endif; ?>
							<?php elseif ( $row['is_am'] ) : ?>
								<span class="dorg-muted">— <span class="dorg-cell-sub">segue organizzazioni, non ne fa parte</span></span>
							<?php else : ?>
								<span class="dorg-muted">Nessuna</span>
								<span class="dorg-cell-sub">modello storico (_dealer_lines)</span>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( empty( $row['lines'] ) ) : ?>
								<span class="dorg-badge is-alert">Nessuna linea</span>
								<span class="dorg-cell-sub">
									<?php echo esc_html( $row['is_am'] ? 'non può pubblicare' : 'non vede alcun documento' ); ?>
								</span>
							<?php else : ?>
								<span class="dorg-badge is-own"><?php echo esc_html( (string) count( $row['lines'] ) ); ?></span>
								<span class="dorg-cell-sub">
									<?php
									$preview = array_slice( $row['lines'], 0, 4 );
									echo esc_html( implode( ', ', array_map( [ 'Dealer_Org_Admin', 'line_label' ], $preview ) ) );
									echo count( $row['lines'] ) > 4 ? ' …' : '';
									?>
								</span>
								<span class="dorg-cell-sub">
									<?php echo esc_html( $row['is_am'] ? 'può pubblicare su queste linee' : 'vede i documenti di queste linee' ); ?>
								</span>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( $row['active'] ) : ?>
								<span class="dorg-badge is-own">Attivo</span>
							<?php else : ?>
								<span class="dorg-badge is-alert">Sospeso</span>
								<span class="dorg-cell-sub">organizzazione sospesa</span>
							<?php endif; ?>
						</td>
						<td>
							<a class="button button-small" href="<?php echo esc_url( $row['edit_url'] ); ?>">
								<?php echo esc_html( $row['edit_label'] ); ?>
							</a>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<?php if ( $total_pages > 1 ) : ?>
			<div class="tablenav"><div class="tablenav-pages">
				<?php
				// I filtri attivi vanno conservati nei link di pagina, altrimenti
				// passare a pagina 2 azzera ricerca e ruolo senza dirlo.
				$paged_base = add_query_arg( array_filter( [
					'ruolo' => $role_filter,
					'q'     => $search,
				] ), $base_url );
				echo wp_kses_post( paginate_links( [
					'base'      => add_query_arg( 'paged', '%#%', $paged_base ),
					'format'    => '',
					'current'   => $paged,
					'total'     => $total_pages,
					'prev_text' => '‹',
					'next_text' => '›',
				] ) );
				?>
			</div></div>
		<?php endif; ?>
	<?php endif; ?>

	<script>
	( function () {
		var all = document.getElementById( 'dealer-check-all' );
		if ( ! all ) { return; }
		all.addEventListener( 'change', function () {
			Array.prototype.forEach.call(
				document.querySelectorAll( '.dealer-user-check' ),
				function ( box ) { box.checked = all.checked; }
			);
		} );
	} )();
	</script>

	<p class="dorg-muted" style="margin-top:18px;">
		Dove si cambia cosa:
		<a href="<?php echo esc_url( $orgs_url ); ?>">Organizzazioni</a> per livello commerciale e linee dei dealer ·
		<a href="<?php echo esc_url( $am_url ); ?>">Area Manager</a> per il perimetro di chi pubblica.
	</p>
</div>
