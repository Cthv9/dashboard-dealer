<?php
/**
 * Elenco delle organizzazioni con la gerarchia visibile.
 * Dealer Portal → Organizzazioni (slug: dealer-portal-orgs)
 *
 * Variabili fornite da Dealer_Org_Admin::render_list():
 * @var array  $rows        Righe dell'albero appiattito (id, depth, lines, status, ...)
 * @var array  $totals      Totali di testata
 * @var array  $notice      Avviso da query string
 * @var array  $report      Rapporto dell'ultima migrazione (una volta sola)
 * @var string $base_url    URL della pagina
 * @var string $post_url    admin-post.php
 * @var array  $tier_labels
 * @var int    $paged
 * @var int    $total_pages
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

// Difesa in profondita': il template non viene mai reso senza capability.
if ( ! Dealer_DB::user_can( DEALER_PORTAL_CAP_ORGS ) ) {
	wp_die( esc_html__( 'Accesso non consentito.', 'dealer-portal' ) );
}

require DEALER_PORTAL_PATH . 'templates/admin-org-styles.php';
?>
<div class="wrap">
	<h1 class="wp-heading-inline">Organizzazioni</h1>
	<a href="<?php echo esc_url( add_query_arg( 'view', 'edit', $base_url ) ); ?>" class="page-title-action">Aggiungi organizzazione</a>
	<a href="<?php echo esc_url( add_query_arg( 'view', 'merge', $base_url ) ); ?>" class="page-title-action">Fondi organizzazioni</a>
	<a href="<?php echo esc_url( Dealer_Org_Admin::am_page_url() ); ?>" class="page-title-action">Area Manager</a>
	<hr class="wp-header-end">

	<?php if ( ! empty( $notice['message'] ) ) : ?>
		<div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?> is-dismissible">
			<p><?php echo esc_html( $notice['message'] ); ?></p>
		</div>
	<?php endif; ?>

	<div class="notice notice-info inline" style="margin:16px 0;">
		<p>
			<strong>Come si leggono i diritti.</strong>
			L'organizzazione detiene livello commerciale e linee prodotto; l'utente ne fa parte con una funzione.
			L'ereditarieta' <strong>restringe e non amplia mai</strong>:
			<code>linee effettive = linee proprie ∩ linee effettive della madre</code>.
			Una sede senza linee proprie vale esattamente quanto la madre; una radice vale quanto ha dichiarato.
		</p>
	</div>

	<!-- ─── Migrazione ────────────────────────────────────────────────────── -->
	<div class="postbox" style="margin-top:16px;">
		<div class="postbox-header"><h2 class="hndle" style="padding:8px 12px;">
			<span class="dashicons dashicons-migrate" style="vertical-align:middle;"></span> Migrazione dal modello per-utente
		</h2></div>
		<div class="inside">
			<p>
				Utenti dealer non ancora migrati:
				<strong class="<?php echo $totals['unmigrated'] > 0 ? 'dorg-dead' : ''; ?>"><?php echo esc_html( (string) $totals['unmigrated'] ); ?></strong>.
				<?php if ( $totals['unmigrated'] > 0 ) : ?>
					Fino alla migrazione questi utenti continuano a funzionare con i meta storici
					(<code>_dealer_lines</code> + ruolo WordPress): nessuno perde l'accesso.
				<?php else : ?>
					Nessun utente dealer e' rimasto fuori dal modello a organizzazioni.
				<?php endif; ?>
			</p>
			<p>
				La migrazione crea <strong>un'organizzazione per ogni utente</strong>, con le sue linee e il suo livello:
				il giorno dell'aggiornamento nessuno vede ne' piu' ne' meno di prima. E' idempotente, quindi si puo' rieseguire.
			</p>
			<p class="dorg-dead">
				<span class="dashicons dashicons-warning" style="vertical-align:middle;"></span>
				Il passo successivo e' <strong>fondere</strong> le organizzazioni che nella realta' sono la stessa azienda:
				cinque collaboratori migrati diventano cinque aziende distinte, e finche' restano tali i diritti continuano
				a divergere fra loro esattamente come nel vecchio modello. Usa <em>Fondi organizzazioni</em>.
			</p>
			<form method="post" action="<?php echo esc_url( $post_url ); ?>">
				<?php wp_nonce_field( 'dealer_org_migrate' ); ?>
				<input type="hidden" name="action" value="dealer_org_migrate">
				<button type="submit" class="button button-primary"
					onclick="return confirm('Migrare gli utenti dealer non ancora assegnati? Verra\' creata un\'organizzazione per ciascuno di loro.');">
					Esegui migrazione
				</button>
				<span class="description" style="margin-left:8px;">Gli utenti gia' assegnati vengono saltati.</span>
			</form>

			<?php if ( ! empty( $report ) ) : ?>
				<hr>
				<h3 style="margin-bottom:6px;">Rapporto dell'ultima migrazione</h3>
				<p>
					<span class="dorg-badge is-own">Create: <?php echo esc_html( (string) (int) ( $report['created'] ?? 0 ) ); ?></span>
					<span class="dorg-badge is-muted">Saltate (gia' migrate): <?php echo esc_html( (string) (int) ( $report['skipped'] ?? 0 ) ); ?></span>
					<span class="dorg-badge <?php echo ( (int) ( $report['failed'] ?? 0 ) > 0 ) ? 'is-alert' : 'is-muted'; ?>">Fallite: <?php echo esc_html( (string) (int) ( $report['failed'] ?? 0 ) ); ?></span>
				</p>
				<?php if ( ! empty( $report['details'] ) ) : ?>
					<div class="dorg-report">
						<ul>
							<?php foreach ( (array) $report['details'] as $line ) : ?>
								<li><?php echo esc_html( (string) $line ); ?></li>
							<?php endforeach; ?>
						</ul>
					</div>
					<p class="description">Il rapporto viene mostrato una volta sola.</p>
				<?php endif; ?>
			<?php endif; ?>
		</div>
	</div>

	<!-- ─── Totali ────────────────────────────────────────────────────────── -->
	<p>
		<span class="dorg-badge">Organizzazioni: <?php echo esc_html( (string) $totals['orgs'] ); ?></span>
		<span class="dorg-badge">Utenti assegnati: <?php echo esc_html( (string) $totals['users'] ); ?></span>
		<span class="dorg-badge <?php echo $totals['suspended'] > 0 ? 'is-alert' : 'is-muted'; ?>">Sospese: <?php echo esc_html( (string) $totals['suspended'] ); ?></span>
	</p>

	<?php if ( ! empty( $totals['truncated'] ) ) : ?>
		<div class="notice notice-warning inline"><p>
			Sono state caricate le prime <?php echo esc_html( (string) Dealer_Org_Admin::MAX_ORGS ); ?> organizzazioni.
			Oltre questo tetto l'albero non viene mostrato per intero.
		</p></div>
	<?php endif; ?>

	<!-- ─── Albero ────────────────────────────────────────────────────────── -->
	<table class="wp-list-table widefat fixed striped">
		<thead>
			<tr>
				<th style="width:28%;">Organizzazione</th>
				<th style="width:110px;">Livello</th>
				<th style="width:22%;">Linee effettive</th>
				<th style="width:80px;">Utenti</th>
				<th style="width:150px;">Stato</th>
				<th>Azioni</th>
			</tr>
		</thead>
		<tbody>
		<?php if ( empty( $rows ) ) : ?>
			<tr><td colspan="6">
				Nessuna organizzazione. Esegui la migrazione qui sopra oppure creane una a mano.
			</td></tr>
		<?php endif; ?>

		<?php foreach ( $rows as $row ) :
			$lines   = $row['lines'];
			$status  = $row['status'];
			$org_id  = (int) $row['id'];
			$edit_url = add_query_arg( [ 'view' => 'edit',  'org' => $org_id ], $base_url );
			$user_url = add_query_arg( [ 'view' => 'users', 'org' => $org_id ], $base_url );
			?>
			<tr>
				<td>
					<div class="dorg-tree-name">
						<?php if ( $row['depth'] > 0 ) : ?>
							<span class="dorg-indent" style="width:<?php echo esc_attr( (string) ( $row['depth'] * 22 ) ); ?>px;"></span>
							<span class="dorg-branch">└─</span>
						<?php endif; ?>
						<strong><a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( $row['name'] ); ?></a></strong>
						<?php if ( $row['children'] > 0 ) : ?>
							<span class="dorg-badge is-muted" title="Organizzazioni figlie dirette"><?php echo esc_html( (string) $row['children'] ); ?> figlie</span>
						<?php endif; ?>
					</div>
				</td>
				<td>
					<?php echo esc_html( Dealer_Org_Admin::tier_label( $row['tier'] ) ); ?>
					<?php if ( '' === $row['own_tier'] ) : ?>
						<span class="dorg-cell-sub">ereditato</span>
					<?php endif; ?>
				</td>
				<td>
					<?php
					$mode_class = 'intersect' === $lines['mode'] ? 'is-mixed' : ( 'inherited' === $lines['mode'] ? 'is-inherit' : 'is-own' );
					?>
					<strong><?php echo esc_html( (string) count( $lines['effective'] ) ); ?></strong>
					<span class="dorg-badge <?php echo esc_attr( $mode_class ); ?>"><?php echo esc_html( Dealer_Org_Admin::mode_label( $lines['mode'] ) ); ?></span>
					<span class="dorg-cell-sub">
						proprie: <?php echo esc_html( (string) count( $lines['own'] ) ); ?>
						<?php if ( $lines['parent'] ) : ?>
							· tetto dalla madre: <?php echo esc_html( (string) count( $lines['inherited'] ) ); ?>
						<?php endif; ?>
					</span>
					<?php if ( ! empty( $lines['dead'] ) ) : ?>
						<span class="dorg-cell-sub dorg-dead" title="Linee dichiarate su questa organizzazione che la madre non ha: l'intersezione le annulla.">
							<span class="dashicons dashicons-warning" style="font-size:14px;height:14px;width:14px;vertical-align:text-top;"></span>
							<?php echo esc_html( (string) count( $lines['dead'] ) ); ?> linee proprie senza effetto
						</span>
					<?php endif; ?>
					<?php if ( empty( $lines['effective'] ) ) : ?>
						<span class="dorg-cell-sub dorg-dead">nessuna linea: non vede alcun documento riservato</span>
					<?php endif; ?>
				</td>
				<td>
					<a href="<?php echo esc_url( $user_url ); ?>"><?php echo esc_html( (string) $row['users'] ); ?></a>
				</td>
				<td>
					<?php if ( $status['active'] ) : ?>
						<span class="dorg-badge is-own">Attiva</span>
					<?php elseif ( $status['own_suspended'] ) : ?>
						<span class="dorg-badge is-alert">Sospesa</span>
					<?php else : ?>
						<span class="dorg-badge is-alert">Sospesa</span>
						<span class="dorg-cell-sub">per effetto di «<?php echo esc_html( $status['blocker_name'] ); ?>»</span>
					<?php endif; ?>
				</td>
				<td class="dorg-actions">
					<a class="button button-small" href="<?php echo esc_url( $edit_url ); ?>">Modifica</a>
					<a class="button button-small" href="<?php echo esc_url( $user_url ); ?>">Utenti</a>

					<?php
					// Sospensione e riattivazione: POST con nonce. Un link GET
					// potrebbe essere aperto da un prefetch del browser.
					$impact = $row['impact'];
					if ( $status['own_suspended'] ) :
						?>
						<form method="post" action="<?php echo esc_url( $post_url ); ?>">
							<?php wp_nonce_field( 'dealer_org_status' ); ?>
							<input type="hidden" name="action" value="dealer_org_status">
							<input type="hidden" name="org_id" value="<?php echo esc_attr( (string) $org_id ); ?>">
							<input type="hidden" name="status" value="<?php echo esc_attr( Dealer_Organization::STATUS_ACTIVE ); ?>">
							<button type="submit" class="button button-small">Riattiva</button>
						</form>
					<?php else : ?>
						<form method="post" action="<?php echo esc_url( $post_url ); ?>"
							onsubmit="return confirm(<?php echo esc_attr( wp_json_encode( sprintf(
								"Sospendere «%s»?\n\nLa sospensione si propaga alle discendenti: coinvolge %d organizzazioni e %d utenti, che perderanno l'accesso a ogni documento riservato.",
								$row['name'],
								(int) $impact['orgs'],
								(int) $impact['users']
							) ) ); ?>);">
							<?php wp_nonce_field( 'dealer_org_status' ); ?>
							<input type="hidden" name="action" value="dealer_org_status">
							<input type="hidden" name="org_id" value="<?php echo esc_attr( (string) $org_id ); ?>">
							<input type="hidden" name="status" value="<?php echo esc_attr( Dealer_Organization::STATUS_SUSPENDED ); ?>">
							<button type="submit" class="button button-small"
								title="Coinvolge <?php echo esc_attr( (string) (int) $impact['orgs'] ); ?> organizzazioni e <?php echo esc_attr( (string) (int) $impact['users'] ); ?> utenti">
								Sospendi
							</button>
						</form>
					<?php endif; ?>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>

	<?php if ( $total_pages > 1 ) : ?>
		<div class="tablenav bottom">
			<div class="tablenav-pages">
				<span class="displaying-num">
					<?php echo esc_html( sprintf( 'Pagina %d di %d (l\'impaginazione conta le radici: ogni radice porta con se\' il suo sottoalbero)', $paged, $total_pages ) ); ?>
				</span>
				<span class="pagination-links">
					<?php
					echo paginate_links( [ // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- paginate_links() produce markup gia' sicuro.
						'base'      => esc_url_raw( add_query_arg( 'paged', '%#%', $base_url ) ),
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
