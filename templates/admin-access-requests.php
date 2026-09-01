<?php
/**
 * Coda di approvazione delle richieste di accesso.
 * Dealer Portal → Richieste Accesso (slug: dealer-portal-access-requests)
 *
 * Variabili fornite da Dealer_Access_Request::render_admin_page():
 * @var WP_Post[] $requests
 * @var string    $filter_status
 * @var int       $paged
 * @var int       $total_pages
 * @var array     $counts
 * @var array     $lines_by_brand
 * @var array     $roles
 * @var array     $notice
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

// Difesa in profondità: il template non viene mai reso senza capability.
if ( ! Dealer_DB::user_can( DEALER_PORTAL_CAP ) ) {
	wp_die( esc_html__( 'Accesso non consentito.', 'dealer-portal' ) );
}

$status_labels = Dealer_Access_Request::get_status_labels();
$valid_lines   = Dealer_Admin::get_valid_lines();
$open_id       = absint( $_GET['dar_open'] ?? 0 );
$base_url      = admin_url( 'admin.php?page=' . Dealer_Access_Request::MENU_SLUG );
$post_url      = admin_url( 'admin-post.php' );

$filters = [
	Dealer_Access_Request::STATUS_PENDING  => 'In attesa',
	Dealer_Access_Request::STATUS_APPROVED => 'Approvate',
	Dealer_Access_Request::STATUS_REJECTED => 'Rifiutate',
	'all'                                  => 'Tutte',
];
?>
<div class="wrap">
	<h1>Richieste Accesso Dealer</h1>

	<?php if ( ! empty( $notice['message'] ) ) : ?>
		<div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?> is-dismissible">
			<p><?php echo esc_html( $notice['message'] ); ?></p>
		</div>
	<?php endif; ?>

	<div class="notice notice-info inline" style="margin:16px 0;">
		<p>
			Per pubblicare il modulo pubblico crea (o modifica) una pagina e inserisci lo shortcode
			<code>[<?php echo esc_html( Dealer_Access_Request::SHORTCODE ); ?>]</code>.
			Le pagine non vengono create automaticamente da questo modulo.
		</p>
	</div>

	<ul class="subsubsub">
		<?php
		$i = 0;
		foreach ( $filters as $key => $label ) :
			$i++;
			$url     = add_query_arg( 'dar_status', $key, $base_url );
			$current = ( $filter_status === $key ) ? ' class="current"' : '';
			$count   = isset( $counts[ $key ] ) ? (int) $counts[ $key ] : 0;
			?>
			<li>
				<a href="<?php echo esc_url( $url ); ?>"<?php echo $current; // phpcs:ignore ?>>
					<?php echo esc_html( $label ); ?>
					<span class="count">(<?php echo esc_html( (string) $count ); ?>)</span>
				</a><?php echo $i < count( $filters ) ? ' |' : ''; ?>
			</li>
		<?php endforeach; ?>
	</ul>
	<div class="clear"></div>

	<table class="wp-list-table widefat fixed striped">
		<thead>
			<tr>
				<th style="width:130px;">Data</th>
				<th>Ragione sociale</th>
				<th style="width:200px;">Referente</th>
				<th style="width:150px;">P. IVA</th>
				<th style="width:90px;">Linee</th>
				<th style="width:110px;">Stato</th>
			</tr>
		</thead>
		<tbody>
		<?php if ( empty( $requests ) ) : ?>
			<tr><td colspan="6">Nessuna richiesta in questa vista.</td></tr>
		<?php else : ?>
			<?php foreach ( $requests as $request_post ) :
				$r          = Dealer_Access_Request::get_request_data( (int) $request_post->ID );
				$is_pending = ( Dealer_Access_Request::STATUS_PENDING === $r['status'] );
				$status_lbl = $status_labels[ $r['status'] ] ?? $r['status'];
				$submitted  = $r['submitted'] ? $r['submitted'] : $request_post->post_date;
				$badge_bg   = $is_pending ? '#f0b849' : ( Dealer_Access_Request::STATUS_APPROVED === $r['status'] ? '#46b450' : '#a7aaad' );
				?>
				<tr>
					<td><?php echo esc_html( mysql2date( 'd/m/Y H:i', $submitted ) ); ?></td>
					<td>
						<strong><?php echo esc_html( $r['company'] ); ?></strong>
						<?php if ( $r['existing_user'] ) : ?>
							<br><span style="color:#b32d2e;font-size:12px;">⚠ Email già presente fra gli utenti del sito</span>
						<?php endif; ?>
					</td>
					<td>
						<?php echo esc_html( $r['contact'] ); ?><br>
						<a href="<?php echo esc_url( 'mailto:' . $r['email'] ); ?>"><?php echo esc_html( $r['email'] ); ?></a><br>
						<span class="description"><?php echo esc_html( $r['phone'] ); ?></span>
					</td>
					<td><?php echo esc_html( $r['vat'] ); ?></td>
					<td><?php echo esc_html( (string) count( $r['lines'] ) ); ?></td>
					<td>
						<span style="display:inline-block;padding:2px 8px;border-radius:10px;color:#fff;font-size:11px;background:<?php echo esc_attr( $badge_bg ); ?>;">
							<?php echo esc_html( $status_lbl ); ?>
						</span>
					</td>
				</tr>
				<tr>
					<td colspan="6" style="background:#fbfbfb;">
						<details<?php echo ( $open_id === (int) $r['id'] ) ? ' open' : ''; ?>>
							<summary style="cursor:pointer;font-weight:600;">Dettagli e azioni</summary>
							<div style="padding:14px 4px 6px;">

								<h4 style="margin:0 0 6px;">Dati della richiesta</h4>
								<table class="widefat striped" style="max-width:820px;margin-bottom:16px;">
									<tbody>
										<tr><td style="width:200px;"><strong>Ragione sociale</strong></td><td><?php echo esc_html( $r['company'] ); ?></td></tr>
										<tr><td><strong>Referente</strong></td><td><?php echo esc_html( $r['contact'] ); ?></td></tr>
										<tr><td><strong>Email</strong></td><td><?php echo esc_html( $r['email'] ); ?></td></tr>
										<tr><td><strong>Telefono</strong></td><td><?php echo esc_html( $r['phone'] ); ?></td></tr>
										<tr><td><strong>Partita IVA</strong></td><td><?php echo esc_html( $r['vat'] ); ?></td></tr>
										<tr>
											<td><strong>Linee richieste</strong></td>
											<td>
												<?php if ( $r['lines'] ) : ?>
													<?php echo esc_html( implode( ' · ', array_map( [ 'Dealer_Access_Request', 'line_label' ], $r['lines'] ) ) ); ?>
												<?php else : ?>
													—
												<?php endif; ?>
											</td>
										</tr>
										<tr><td><strong>Note</strong></td><td><?php echo nl2br( esc_html( $r['notes'] ) ); ?></td></tr>
										<tr><td><strong>IP di invio</strong></td><td><?php echo esc_html( $r['ip'] ? $r['ip'] : '—' ); ?></td></tr>
									</tbody>
								</table>

								<?php if ( ! $is_pending ) : ?>
									<h4 style="margin:0 0 6px;">Esito</h4>
									<table class="widefat striped" style="max-width:820px;">
										<tbody>
											<tr>
												<td style="width:200px;"><strong>Stato</strong></td>
												<td><?php echo esc_html( $status_lbl ); ?></td>
											</tr>
											<?php if ( $r['reviewed_at'] ) : ?>
												<tr>
													<td><strong>Evasa il</strong></td>
													<td>
														<?php echo esc_html( mysql2date( 'd/m/Y H:i', $r['reviewed_at'] ) ); ?>
														<?php
														$reviewer = $r['reviewed_by'] ? get_userdata( $r['reviewed_by'] ) : null;
														if ( $reviewer ) {
															echo ' — ' . esc_html( $reviewer->display_name );
														}
														?>
													</td>
												</tr>
											<?php endif; ?>
											<?php if ( Dealer_Access_Request::STATUS_APPROVED === $r['status'] ) : ?>
												<tr>
													<td><strong>Ruolo assegnato</strong></td>
													<td><?php echo esc_html( $roles[ $r['approved_role'] ] ?? $r['approved_role'] ); ?></td>
												</tr>
												<tr>
													<td><strong>Linee approvate</strong></td>
													<td><?php echo esc_html( $r['approved_lines'] ? implode( ' · ', array_map( [ 'Dealer_Access_Request', 'line_label' ], $r['approved_lines'] ) ) : '—' ); ?></td>
												</tr>
												<tr>
													<td><strong>Utente creato</strong></td>
													<td>
														<?php
														$created = $r['user_id'] ? get_userdata( $r['user_id'] ) : null;
														if ( $created ) : ?>
															<a href="<?php echo esc_url( get_edit_user_link( $created->ID ) ); ?>"><?php echo esc_html( $created->user_login ); ?></a>
														<?php else : ?>
															— (utente non più presente)
														<?php endif; ?>
													</td>
												</tr>
											<?php elseif ( '' !== $r['reject_reason'] ) : ?>
												<tr>
													<td><strong>Motivazione</strong></td>
													<td><?php echo nl2br( esc_html( $r['reject_reason'] ) ); ?></td>
												</tr>
											<?php endif; ?>
										</tbody>
									</table>
								<?php else : ?>

									<?php // ── Approvazione ─────────────────────────────────────── ?>
									<h4 style="margin:0 0 6px;">Approva la richiesta</h4>
									<p class="description" style="margin-top:0;">
										Le linee indicate dal richiedente sono solo una proposta: conferma o correggi la selezione.
										All’approvazione viene creato l’utente e gli viene inviato il link per impostare la password.
										Nessuna password viene mai generata in chiaro.
									</p>
									<form method="post" action="<?php echo esc_url( $post_url ); ?>" style="margin-bottom:22px;">
										<?php wp_nonce_field( 'dealer_access_approve_' . (int) $r['id'] ); ?>
										<input type="hidden" name="action" value="dealer_access_approve">
										<input type="hidden" name="request_id" value="<?php echo esc_attr( (string) $r['id'] ); ?>">

										<table class="form-table" style="max-width:820px;">
											<tr>
												<th scope="row" style="width:200px;">
													<label for="approved_role_<?php echo esc_attr( (string) $r['id'] ); ?>">Ruolo da assegnare</label>
												</th>
												<td>
													<select name="approved_role" id="approved_role_<?php echo esc_attr( (string) $r['id'] ); ?>" required>
														<?php foreach ( $roles as $role_key => $role_label ) : ?>
															<option value="<?php echo esc_attr( $role_key ); ?>"><?php echo esc_html( $role_label ); ?></option>
														<?php endforeach; ?>
													</select>
												</td>
											</tr>
											<tr>
												<th scope="row">
													<label for="approved_lines_<?php echo esc_attr( (string) $r['id'] ); ?>">Linee prodotto approvate</label>
												</th>
												<td>
													<select name="approved_lines[]" id="approved_lines_<?php echo esc_attr( (string) $r['id'] ); ?>" multiple size="12" style="min-width:360px;">
														<?php foreach ( $lines_by_brand as $brand => $brand_lines ) : ?>
															<optgroup label="<?php echo esc_attr( $brand ); ?>">
																<?php foreach ( $brand_lines as $line ) :
																	$value = $brand . '|' . $line;
																	?>
																	<option value="<?php echo esc_attr( $value ); ?>"<?php echo in_array( $value, $r['lines'], true ) ? ' selected' : ''; ?>>
																		<?php echo esc_html( $line ); ?>
																	</option>
																<?php endforeach; ?>
															</optgroup>
														<?php endforeach; ?>
													</select>
													<p class="description">
														Tieni premuto <kbd>Ctrl</kbd> (Windows) o <kbd>Cmd</kbd> (Mac) per selezionare più linee.
														Preselezionate quelle richieste dal dealer.
													</p>
												</td>
											</tr>
										</table>

										<?php if ( $r['existing_user'] ) : ?>
											<p style="color:#b32d2e;">
												Esiste già un utente con questa email: l’approvazione automatica è bloccata.
												Gestisci l’account dal profilo utente e poi rifiuta questa richiesta per archiviarla.
											</p>
										<?php endif; ?>

										<p>
											<button type="submit" class="button button-primary"
												onclick="return confirm('Creare l\'utente e inviare il link per impostare la password?');">
												Approva e crea utente
											</button>
										</p>
									</form>

									<?php // ── Rifiuto ──────────────────────────────────────────── ?>
									<h4 style="margin:0 0 6px;">Rifiuta la richiesta</h4>
									<form method="post" action="<?php echo esc_url( $post_url ); ?>">
										<?php wp_nonce_field( 'dealer_access_reject_' . (int) $r['id'] ); ?>
										<input type="hidden" name="action" value="dealer_access_reject">
										<input type="hidden" name="request_id" value="<?php echo esc_attr( (string) $r['id'] ); ?>">

										<p>
											<label for="reject_reason_<?php echo esc_attr( (string) $r['id'] ); ?>">Motivazione (facoltativa, inclusa nell’email)</label><br>
											<textarea name="reject_reason" id="reject_reason_<?php echo esc_attr( (string) $r['id'] ); ?>" rows="3" style="width:100%;max-width:820px;" maxlength="1000"></textarea>
										</p>
										<p>
											<label>
												<input type="checkbox" name="notify_applicant" value="1">
												Invia email di cortesia al richiedente
											</label>
										</p>
										<p>
											<button type="submit" class="button"
												onclick="return confirm('Rifiutare questa richiesta?');">
												Rifiuta
											</button>
										</p>
									</form>
								<?php endif; ?>
							</div>
						</details>
					</td>
				</tr>
			<?php endforeach; ?>
		<?php endif; ?>
		</tbody>
	</table>

	<?php if ( $total_pages > 1 ) : ?>
		<div class="tablenav bottom">
			<div class="tablenav-pages">
				<?php
				$links = paginate_links( [
					'base'      => add_query_arg( 'paged', '%#%', add_query_arg( 'dar_status', $filter_status, $base_url ) ),
					'format'    => '',
					'current'   => $paged,
					'total'     => $total_pages,
					'prev_text' => '‹',
					'next_text' => '›',
				] );
				if ( $links ) {
					echo wp_kses_post( $links );
				}
				?>
			</div>
		</div>
	<?php endif; ?>
</div>
