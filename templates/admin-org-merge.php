<?php
/**
 * Fusione di piu' organizzazioni in una sola.
 * Dealer Portal → Organizzazioni → Fondi (view=merge)
 *
 * Due passi: prima l'anteprima (nessuna scrittura), poi l'esecuzione con
 * conferma esplicita. L'anteprima e' l'unico momento in cui e' ancora possibile
 * accorgersi che qualcuno perdera' dei diritti.
 *
 * Variabili fornite da Dealer_Org_Admin::render_merge():
 * @var array      $choices  Organizzazioni selezionabili
 * @var array|null $preview  Esito di Dealer_Org_Admin::merge_preview()
 * @var array      $notice
 * @var string     $base_url
 * @var string     $post_url
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! current_user_can( DEALER_PORTAL_CAP_ORGS ) ) {
	wp_die( esc_html__( 'Accesso non consentito.', 'dealer-portal' ) );
}

require DEALER_PORTAL_PATH . 'templates/admin-org-styles.php';

$merge_url    = add_query_arg( 'view', 'merge', $base_url );
$sel_sources  = $preview ? wp_list_pluck( $preview['sources'], 'id' ) : [];
$sel_dest     = $preview ? (int) $preview['destination'] : 0;
?>
<div class="wrap">
	<h1 class="wp-heading-inline">Fondi organizzazioni</h1>
	<a href="<?php echo esc_url( $base_url ); ?>" class="page-title-action">Torna all'elenco</a>
	<hr class="wp-header-end">

	<?php if ( ! empty( $notice['message'] ) ) : ?>
		<div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?> is-dismissible">
			<p><?php echo esc_html( $notice['message'] ); ?></p>
		</div>
	<?php endif; ?>

	<div class="notice notice-info inline" style="margin:16px 0;">
		<p>
			<strong>A cosa serve.</strong> La migrazione crea un'organizzazione per ogni utente: cinque collaboratori
			della stessa azienda diventano cinque organizzazioni distinte. Fonderle significa riportare l'azienda a
			un'unica scheda, con un solo insieme di diritti da mantenere.
		</p>
		<p>
			Gli utenti delle organizzazioni sorgenti passano alla destinazione conservando la loro funzione; le eventuali
			figlie vengono riagganciate alla destinazione; le sorgenti rimaste vuote vengono eliminate.
			<strong>Le linee effettive diventano quelle della destinazione</strong>, quindi qualcuno puo' perderci:
			l'anteprima lo dice prima, non dopo.
		</p>
	</div>

	<!-- ─── Passo 1: selezione ────────────────────────────────────────────── -->
	<form method="post" action="<?php echo esc_url( $merge_url ); ?>">
		<?php wp_nonce_field( 'dealer_org_merge_preview' ); ?>
		<input type="hidden" name="dealer_org_merge_preview" value="1">

		<div class="postbox">
			<div class="postbox-header"><h2 class="hndle" style="padding:8px 12px;">1. Scegli sorgenti e destinazione</h2></div>
			<div class="inside">
				<p>
					<label for="destination"><strong>Destinazione</strong> (l'organizzazione che sopravvive):</label><br>
					<select name="destination" id="destination" required>
						<option value="">— scegli —</option>
						<?php foreach ( $choices as $choice ) : ?>
							<option value="<?php echo esc_attr( (string) $choice['id'] ); ?>" <?php selected( $sel_dest, (int) $choice['id'] ); ?>>
								<?php echo esc_html( sprintf( '%s — %d utenti, %d linee effettive', $choice['path'], $choice['users'], $choice['lines'] ) ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</p>

				<p><strong>Organizzazioni da fondere</strong> (verranno svuotate ed eliminate):</p>
				<div class="dorg-lines-scroll" style="max-height:340px;">
					<table class="wp-list-table widefat striped">
						<thead><tr>
							<th style="width:40px;"></th><th>Organizzazione</th><th style="width:90px;">Utenti</th><th style="width:130px;">Linee effettive</th>
						</tr></thead>
						<tbody>
						<?php foreach ( $choices as $choice ) : ?>
							<tr>
								<td>
									<input type="checkbox" name="sources[]" value="<?php echo esc_attr( (string) $choice['id'] ); ?>"
										<?php checked( in_array( (int) $choice['id'], array_map( 'intval', $sel_sources ), true ) ); ?>>
								</td>
								<td><?php echo esc_html( $choice['path'] ); ?></td>
								<td><?php echo esc_html( (string) (int) $choice['users'] ); ?></td>
								<td><?php echo esc_html( (string) (int) $choice['lines'] ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<p class="description">
					Massimo <?php echo esc_html( (string) Dealer_Org_Admin::MAX_MERGE_SOURCES ); ?> organizzazioni
					e <?php echo esc_html( (string) Dealer_Org_Admin::MAX_MERGE_USERS ); ?> utenti per singola fusione.
				</p>
				<p><button type="submit" class="button button-primary">Calcola l'effetto della fusione</button></p>
			</div>
		</div>
	</form>

	<?php if ( $preview ) : ?>
		<!-- ─── Passo 2: anteprima ────────────────────────────────────────── -->
		<div class="postbox">
			<div class="postbox-header"><h2 class="hndle" style="padding:8px 12px;">2. Anteprima — nessuna modifica e' stata ancora scritta</h2></div>
			<div class="inside">

				<?php if ( ! empty( $preview['fatal'] ) ) : ?>
					<div class="notice notice-error inline"><p><?php echo esc_html( $preview['fatal'] ); ?></p></div>
				<?php else : ?>

					<p>
						<span class="dorg-badge is-own">Destinazione: <?php echo esc_html( $preview['dest_name'] ); ?></span>
						<span class="dorg-badge"><?php echo esc_html( (string) count( $preview['dest_lines'] ) ); ?> linee effettive</span>
						<span class="dorg-badge"><?php echo esc_html( (string) (int) $preview['total_users'] ); ?> utenti da spostare</span>
						<span class="dorg-badge <?php echo $preview['total_losers'] > 0 ? 'is-alert' : 'is-muted'; ?>">
							<?php echo esc_html( (string) (int) $preview['total_losers'] ); ?> utenti perderanno linee
						</span>
					</p>

					<?php if ( $preview['total_losers'] > 0 ) : ?>
						<div class="dorg-effect is-alert">
							<h3><span class="dashicons dashicons-warning"></span> La fusione riduce dei diritti</h3>
							<p>
								<strong><?php echo esc_html( (string) (int) $preview['total_losers'] ); ?></strong> utenti vedranno
								meno linee di adesso, perche' la destinazione non le possiede. Se non e' voluto, concedi prima
								quelle linee alla destinazione (e ai suoi antenati) e ricalcola.
							</p>
						</div>
					<?php endif; ?>

					<?php if ( empty( $preview['dest_lines'] ) ) : ?>
						<div class="dorg-effect is-alert">
							<h3>La destinazione non ha linee effettive</h3>
							<p>Tutti gli utenti spostati resterebbero senza alcun accesso ai documenti riservati.</p>
						</div>
					<?php endif; ?>

					<?php foreach ( $preview['sources'] as $source ) : ?>
						<h3 style="margin-bottom:4px;">
							<?php echo esc_html( $source['name'] ); ?>
							<span class="dorg-muted" style="font-weight:400;">#<?php echo esc_html( (string) (int) $source['id'] ); ?></span>
						</h3>

						<?php if ( ! empty( $source['blocked'] ) ) : ?>
							<div class="notice notice-error inline"><p>
								<strong>Esclusa dalla fusione.</strong> <?php echo esc_html( $source['blocked'] ); ?>
							</p></div>
							<?php continue; ?>
						<?php endif; ?>

						<?php if ( empty( $source['users'] ) ) : ?>
							<p class="dorg-muted">Nessun utente da spostare.</p>
						<?php else : ?>
							<table class="wp-list-table widefat striped" style="margin-bottom:10px;">
								<thead><tr>
									<th style="width:26%;">Utente</th>
									<th style="width:110px;">Linee oggi</th>
									<th style="width:110px;">Dopo</th>
									<th>Conseguenza</th>
								</tr></thead>
								<tbody>
								<?php foreach ( $source['users'] as $member ) : ?>
									<tr>
										<td>
											<strong><?php echo esc_html( $member['name'] ); ?></strong>
											<span class="dorg-cell-sub"><?php echo esc_html( $member['email'] ); ?></span>
										</td>
										<td><?php echo esc_html( (string) (int) $member['before'] ); ?></td>
										<td class="<?php echo ( (int) $member['after'] < (int) $member['before'] ) ? 'dorg-dead' : ''; ?>">
											<?php echo esc_html( (string) (int) $member['after'] ); ?>
										</td>
										<td>
											<?php if ( ! empty( $member['empty'] ) ) : ?>
												<span class="dorg-dead">
													Resterebbe senza alcuna linea: la sua restrizione individuale non interseca
													le linee della destinazione.
												</span>
											<?php elseif ( ! empty( $member['lost'] ) ) : ?>
												<span class="dorg-dead">Perde <?php echo esc_html( (string) count( $member['lost'] ) ); ?> linee:</span>
												<span class="dorg-muted">
													<?php
													$shown = array_slice( $member['lost'], 0, 6 );
													$names = array_map( [ 'Dealer_Org_Admin', 'line_label' ], $shown );
													echo esc_html( implode( ', ', $names ) );
													echo count( $member['lost'] ) > 6 ? esc_html( ' … (+' . ( count( $member['lost'] ) - 6 ) . ')' ) : '';
													?>
												</span>
											<?php elseif ( ! empty( $member['gained'] ) ) : ?>
												<span class="dorg-muted">Guadagna <?php echo esc_html( (string) count( $member['gained'] ) ); ?> linee dalla destinazione.</span>
											<?php else : ?>
												<span class="dorg-muted">Nessuna variazione.</span>
											<?php endif; ?>
										</td>
									</tr>
								<?php endforeach; ?>
								</tbody>
							</table>
						<?php endif; ?>

						<?php if ( ! empty( $source['children'] ) ) : ?>
							<p>
								<strong><?php echo esc_html( (string) count( $source['children'] ) ); ?> organizzazioni figlie</strong>
								verranno riagganciate alla destinazione (mai lasciate orfane). Il nuovo tetto ereditato ne cambia le linee:
							</p>
							<ul style="list-style:disc;margin-left:20px;">
								<?php foreach ( $source['children'] as $child ) : ?>
									<li>
										<?php echo esc_html( $child['name'] ); ?>:
										<?php echo esc_html( (string) (int) $child['now'] ); ?> →
										<span class="<?php echo esc_attr( Dealer_Org_Admin::delta_class( (int) $child['now'], (int) $child['after'] ) ); ?>">
											<?php echo esc_html( (string) (int) $child['after'] ); ?>
										</span> linee effettive
										<span class="dorg-muted">(<?php echo esc_html( Dealer_Org_Admin::delta_note( (int) $child['now'], (int) $child['after'] ) ); ?>)</span>
									</li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>
					<?php endforeach; ?>

					<!-- ─── Passo 3: esecuzione ───────────────────────────── -->
					<hr>
					<form method="post" action="<?php echo esc_url( $post_url ); ?>"
						onsubmit="return confirm(<?php echo esc_attr( wp_json_encode( sprintf(
							"Confermi la fusione?\n\n%d utenti passeranno a «%s» e le organizzazioni sorgenti svuotate verranno eliminate. L'operazione non e' reversibile.",
							(int) $preview['total_users'],
							(string) $preview['dest_name']
						) ) ); ?>);">
						<?php wp_nonce_field( 'dealer_org_merge' ); ?>
						<input type="hidden" name="action" value="dealer_org_merge">
						<input type="hidden" name="destination" value="<?php echo esc_attr( (string) (int) $preview['destination'] ); ?>">
						<?php foreach ( $preview['sources'] as $source ) : ?>
							<?php if ( empty( $source['blocked'] ) ) : ?>
								<input type="hidden" name="sources[]" value="<?php echo esc_attr( (string) (int) $source['id'] ); ?>">
							<?php endif; ?>
						<?php endforeach; ?>

						<p>
							<label>
								<input type="checkbox" name="confirm" value="1" required>
								Ho letto l'anteprima: confermo lo spostamento di
								<strong><?php echo esc_html( (string) (int) $preview['total_users'] ); ?></strong> utenti
								verso «<?php echo esc_html( $preview['dest_name'] ); ?>»
								<?php if ( $preview['total_losers'] > 0 ) : ?>
									e accetto che <strong><?php echo esc_html( (string) (int) $preview['total_losers'] ); ?></strong>
									di loro vedranno meno linee di adesso
								<?php endif; ?>.
							</label>
						</p>
						<button type="submit" class="button button-primary button-large">Esegui la fusione</button>
						<a href="<?php echo esc_url( $merge_url ); ?>" class="button">Annulla</a>
					</form>

				<?php endif; ?>
			</div>
		</div>
	<?php endif; ?>
</div>
