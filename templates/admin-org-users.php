<?php
/**
 * Utenti di un'organizzazione: assegnazione, funzione, restrizione individuale.
 * Dealer Portal → Organizzazioni → Utenti (view=users)
 *
 * Variabili fornite da Dealer_Org_Admin::render_users():
 * @var int    $org_id
 * @var string $org_name
 * @var array  $org_lines   Linee effettive dell'organizzazione
 * @var array  $breakdown
 * @var array  $status
 * @var array  $members     Utenti della pagina corrente, gia' descritti
 * @var array  $candidates  Utenti dealer senza organizzazione
 * @var string $search
 * @var array  $lines_by_brand
 * @var array  $function_labels
 * @var array  $notice
 * @var string $base_url
 * @var string $post_url
 * @var int    $paged
 * @var int    $total_pages
 * @var int    $total_members
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! Dealer_DB::user_can( DEALER_PORTAL_CAP_ORGS ) ) {
	wp_die( esc_html__( 'Accesso non consentito.', 'dealer-portal' ) );
}

require DEALER_PORTAL_PATH . 'templates/admin-org-styles.php';

// Linee dell'organizzazione raggruppate per brand: la restrizione individuale
// non puo' andare oltre, quindi non ha senso mostrare l'intero catalogo.
$org_lines_by_brand = [];
foreach ( $org_lines as $line ) {
	$parts = explode( '|', $line, 2 );
	if ( isset( $parts[1] ) ) {
		$org_lines_by_brand[ $parts[0] ][] = $parts[1];
	}
}
ksort( $org_lines_by_brand );

$users_url = add_query_arg( [ 'view' => 'users', 'org' => $org_id ], $base_url );
?>
<div class="wrap">
	<h1 class="wp-heading-inline">Utenti · <?php echo esc_html( $org_name ); ?></h1>
	<a href="<?php echo esc_url( $base_url ); ?>" class="page-title-action">Torna all'elenco</a>
	<a href="<?php echo esc_url( add_query_arg( [ 'view' => 'edit', 'org' => $org_id ], $base_url ) ); ?>" class="page-title-action">Modifica organizzazione</a>
	<hr class="wp-header-end">

	<?php if ( ! empty( $notice['message'] ) ) : ?>
		<div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?> is-dismissible">
			<p><?php echo esc_html( $notice['message'] ); ?></p>
		</div>
	<?php endif; ?>

	<?php if ( ! $status['active'] ) : ?>
		<div class="notice notice-error inline"><p>
			<strong>Organizzazione sospesa<?php echo $status['blocker'] ? esc_html( ' per effetto di «' . $status['blocker_name'] . '»' ) : ''; ?>.</strong>
			Finche' dura, le linee effettive di tutti i suoi utenti sono <strong>zero</strong>, qualunque cosa risulti configurata qui sotto.
		</p></div>
	<?php endif; ?>

	<p>
		<span class="dorg-badge"><?php echo esc_html( Dealer_Org_Admin::org_path( $org_id ) ); ?></span>
		<span class="dorg-badge <?php echo empty( $org_lines ) ? 'is-alert' : 'is-own'; ?>">
			<?php echo esc_html( (string) count( $org_lines ) ); ?> linee effettive dell'azienda
		</span>
		<span class="dorg-badge is-muted"><?php echo esc_html( Dealer_Org_Admin::mode_label( $breakdown['mode'] ) ); ?></span>
		<span class="dorg-badge is-muted"><?php echo esc_html( (string) (int) $total_members ); ?> utenti</span>
	</p>

	<?php if ( ! empty( $breakdown['dead'] ) ) : ?>
		<div class="notice notice-warning inline"><p>
			L'organizzazione dichiara <strong><?php echo esc_html( (string) count( $breakdown['dead'] ) ); ?></strong> linee
			che la madre non possiede: l'intersezione le annulla e nessun utente le vedra'.
			<a href="<?php echo esc_url( add_query_arg( [ 'view' => 'edit', 'org' => $org_id ], $base_url ) ); ?>">Vedi il dettaglio</a>.
		</p></div>
	<?php endif; ?>

	<?php if ( empty( $org_lines ) ) : ?>
		<div class="notice notice-warning inline"><p>
			L'organizzazione non ha nessuna linea effettiva: non e' possibile impostare restrizioni individuali,
			e i suoi utenti non vedono alcun documento riservato.
		</p></div>
	<?php endif; ?>

	<!-- ─── Assegnazione di un nuovo utente ───────────────────────────────── -->
	<div class="postbox">
		<div class="postbox-header"><h2 class="hndle" style="padding:8px 12px;">Assegna un utente</h2></div>
		<div class="inside">
			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" style="margin-bottom:10px;">
				<input type="hidden" name="page" value="<?php echo esc_attr( Dealer_Org_Admin::MENU_SLUG ); ?>">
				<input type="hidden" name="view" value="users">
				<input type="hidden" name="org" value="<?php echo esc_attr( (string) $org_id ); ?>">
				<input type="search" name="us" value="<?php echo esc_attr( $search ); ?>" placeholder="Cerca per nome, login o email…">
				<button type="submit" class="button">Cerca fra gli utenti liberi</button>
				<?php if ( '' !== $search ) : ?>
					<a class="button" href="<?php echo esc_url( $users_url ); ?>">Azzera</a>
				<?php endif; ?>
			</form>

			<?php if ( empty( $candidates ) ) : ?>
				<p class="dorg-muted">
					Nessun utente dealer disponibile<?php echo ( '' !== $search ) ? ' con questi criteri' : ''; ?>.
					Un utente gia' assegnato ad un'altra organizzazione va prima rimosso da quella, oppure spostato con una fusione.
				</p>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( $post_url ); ?>">
					<?php wp_nonce_field( 'dealer_org_user_assign' ); ?>
					<input type="hidden" name="action" value="dealer_org_user_assign">
					<input type="hidden" name="org_id" value="<?php echo esc_attr( (string) $org_id ); ?>">
					<select name="user_id" required>
						<option value="">— scegli un utente —</option>
						<?php foreach ( $candidates as $candidate ) : ?>
							<option value="<?php echo esc_attr( (string) $candidate['id'] ); ?>">
								<?php echo esc_html( $candidate['name'] . ' (' . $candidate['login'] . ' · ' . $candidate['email'] . ')' ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<select name="function">
						<?php foreach ( $function_labels as $key => $label ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $key, Dealer_Identity::FUNCTION_COLLABORATORE ); ?>>
								<?php echo esc_html( $label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<button type="submit" class="button button-primary">Assegna</button>
					<span class="description">
						L'utente parte senza restrizione individuale: vede tutte le linee effettive dell'azienda.
					</span>
				</form>
				<?php if ( count( $candidates ) >= Dealer_Org_Admin::MAX_CANDIDATES ) : ?>
					<p class="description">Sono elencati i primi <?php echo esc_html( (string) Dealer_Org_Admin::MAX_CANDIDATES ); ?> utenti: usa la ricerca per restringere.</p>
				<?php endif; ?>
			<?php endif; ?>
		</div>
	</div>

	<!-- ─── Membri ────────────────────────────────────────────────────────── -->
	<table class="wp-list-table widefat fixed striped">
		<thead>
			<tr>
				<th style="width:26%;">Utente</th>
				<th style="width:120px;">Funzione</th>
				<th style="width:20%;">Restrizione individuale</th>
				<th style="width:20%;">Linee effettive</th>
				<th>Configurazione</th>
			</tr>
		</thead>
		<tbody>
		<?php if ( empty( $members ) ) : ?>
			<tr><td colspan="5">Nessun utente assegnato a questa organizzazione.</td></tr>
		<?php endif; ?>

		<?php foreach ( $members as $member ) : ?>
			<tr>
				<td>
					<strong><?php echo esc_html( $member['name'] ); ?></strong>
					<span class="dorg-cell-sub"><?php echo esc_html( $member['email'] ); ?></span>
					<span class="dorg-cell-sub">
						<a href="<?php echo esc_url( get_edit_user_link( $member['id'] ) ); ?>">profilo WordPress</a>
					</span>
				</td>
				<td><?php echo esc_html( $function_labels[ $member['function'] ] ?? $member['function'] ); ?></td>
				<td>
					<?php if ( $member['has_limit'] ) : ?>
						<span class="dorg-badge is-mixed"><?php echo esc_html( (string) count( $member['limit'] ) ); ?> linee</span>
						<?php if ( ! empty( $member['limit_dead'] ) ) : ?>
							<span class="dorg-cell-sub dorg-dead">
								<?php echo esc_html( (string) count( $member['limit_dead'] ) ); ?> fuori dalle linee dell'azienda: senza effetto
							</span>
						<?php endif; ?>
					<?php else : ?>
						<span class="dorg-badge is-muted">nessuna</span>
						<span class="dorg-cell-sub">vede tutte le linee dell'azienda</span>
					<?php endif; ?>
				</td>
				<td>
					<strong class="<?php echo empty( $member['effective'] ) ? 'dorg-dead' : ''; ?>">
						<?php echo esc_html( (string) count( $member['effective'] ) ); ?>
					</strong>
					<span class="dorg-cell-sub">
						<?php if ( ! $member['active'] ) : ?>
							<span class="dorg-dead">organizzazione sospesa: accesso azzerato</span>
						<?php elseif ( empty( $member['effective'] ) ) : ?>
							<span class="dorg-dead">non vede alcun documento riservato</span>
						<?php else : ?>
							azienda ∩ restrizione
						<?php endif; ?>
					</span>
					<?php if ( ! empty( $member['effective'] ) ) : ?>
						<details>
							<summary class="dorg-muted" style="font-size:11px;">elenco</summary>
							<ul style="font-size:11px;margin:4px 0 0 14px;list-style:disc;">
								<?php foreach ( $member['effective'] as $line ) : ?>
									<li><?php echo esc_html( Dealer_Org_Admin::line_label( $line ) ); ?></li>
								<?php endforeach; ?>
							</ul>
						</details>
					<?php endif; ?>
				</td>
				<td>
					<details>
						<summary class="button button-small">Modifica</summary>
						<form method="post" action="<?php echo esc_url( $post_url ); ?>" style="margin-top:10px;">
							<?php wp_nonce_field( 'dealer_org_user_update' ); ?>
							<input type="hidden" name="action" value="dealer_org_user_update">
							<input type="hidden" name="org_id" value="<?php echo esc_attr( (string) $org_id ); ?>">
							<input type="hidden" name="user_id" value="<?php echo esc_attr( (string) $member['id'] ); ?>">

							<p>
								<label>
									Funzione
									<select name="function">
										<?php foreach ( $function_labels as $key => $label ) : ?>
											<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $key, $member['function'] ); ?>>
												<?php echo esc_html( $label ); ?>
											</option>
										<?php endforeach; ?>
									</select>
								</label>
							</p>

							<p>
								<label>
									<input type="radio" name="limit_mode" value="none" <?php checked( ! $member['has_limit'] ); ?>>
									Nessuna restrizione: tutte le linee effettive dell'azienda
								</label><br>
								<label>
									<input type="radio" name="limit_mode" value="subset" <?php checked( $member['has_limit'] ); ?>>
									Limita a un sottoinsieme delle linee aziendali
								</label>
							</p>

							<?php if ( ! empty( $org_lines_by_brand ) ) : ?>
								<div class="dorg-lines-scroll" style="max-height:260px;">
									<?php foreach ( $org_lines_by_brand as $brand => $brand_lines ) : ?>
										<div class="dorg-brand-group">
											<div class="dorg-brand-head">
												<label><input type="checkbox" class="dorg-brand-toggle"> <?php echo esc_html( $brand ); ?></label>
											</div>
											<div class="dorg-brand-body">
												<?php foreach ( $brand_lines as $line ) :
													$value = $brand . '|' . $line;
													?>
													<label>
														<input type="checkbox" class="dorg-line" name="limit_lines[]"
															value="<?php echo esc_attr( $value ); ?>"
															<?php checked( in_array( $value, $member['limit'], true ) ); ?>>
														<?php echo esc_html( $line ); ?>
													</label>
												<?php endforeach; ?>
											</div>
										</div>
									<?php endforeach; ?>
								</div>
								<p class="description">
									Sono elencate solo le linee effettive dell'azienda: la restrizione e' un'intersezione,
									non puo' concedere nulla in piu' (<code>Dealer_Identity::set_line_limit()</code> filtra comunque lato server).
								</p>
							<?php endif; ?>

							<p>
								<button type="submit" class="button button-primary">Salva</button>
							</p>
						</form>

						<form method="post" action="<?php echo esc_url( $post_url ); ?>"
							onsubmit="return confirm(<?php echo esc_attr( wp_json_encode( sprintf(
								"Rimuovere %s da «%s»?\n\nL'utente torna al modello storico (meta _dealer_lines + ruolo WordPress) e la sua restrizione individuale viene cancellata.",
								$member['name'],
								$org_name
							) ) ); ?>);">
							<?php wp_nonce_field( 'dealer_org_user_remove' ); ?>
							<input type="hidden" name="action" value="dealer_org_user_remove">
							<input type="hidden" name="org_id" value="<?php echo esc_attr( (string) $org_id ); ?>">
							<input type="hidden" name="user_id" value="<?php echo esc_attr( (string) $member['id'] ); ?>">
							<button type="submit" class="button button-link-delete">Rimuovi dall'organizzazione</button>
						</form>
					</details>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>

	<?php if ( $total_pages > 1 ) : ?>
		<div class="tablenav bottom">
			<div class="tablenav-pages">
				<span class="displaying-num"><?php echo esc_html( (string) (int) $total_members ); ?> utenti</span>
				<span class="pagination-links">
					<?php
					echo paginate_links( [ // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup generato da WordPress.
						'base'      => esc_url_raw( add_query_arg( 'mpaged', '%#%', $users_url ) ),
						'format'    => '',
						'prev_text' => '‹',
						'next_text' => '›',
						'total'     => $total_pages,
						'current'   => $paged,
					] );
					?>
				</span>
			</div>
		</div>
	<?php endif; ?>
</div>

<script>
/* Selezione rapida per brand nei selettori di restrizione individuale. */
( function () {
	Array.prototype.forEach.call( document.querySelectorAll( '.dorg-brand-toggle' ), function ( toggle ) {
		var group = toggle.closest( '.dorg-brand-group' );
		if ( ! group ) { return; }

		function sync() {
			var inputs  = group.querySelectorAll( '.dorg-line' );
			var checked = group.querySelectorAll( '.dorg-line:checked' ).length;
			toggle.checked       = ( checked > 0 && checked === inputs.length );
			toggle.indeterminate = ( checked > 0 && checked < inputs.length );
		}

		toggle.addEventListener( 'change', function () {
			Array.prototype.forEach.call( group.querySelectorAll( '.dorg-line' ), function ( box ) {
				box.checked = toggle.checked;
			} );
		} );

		Array.prototype.forEach.call( group.querySelectorAll( '.dorg-line' ), function ( box ) {
			box.addEventListener( 'change', sync );
		} );

		sync();
	} );
} )();
</script>
