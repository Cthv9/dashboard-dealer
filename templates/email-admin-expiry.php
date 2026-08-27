<?php
/**
 * Corpo email: report scadenze per gli amministratori.
 *
 * Variabili ($data):
 *  - expiring    : array di array (title, brand_line, type_label, expiry_fmt, days_left)
 *  - expired     : array di array (idem, scadenza già superata)
 *  - archive_url : string  link all'archivio documenti in admin
 *  - window      : int
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$dp_expiring = (array) ( $data['expiring'] ?? [] );
$dp_expired  = (array) ( $data['expired'] ?? [] );
$dp_window   = (int) ( $data['window'] ?? 30 );
?>
<p style="margin:0 0 18px;">
	Riepilogo delle scadenze dei documenti del portale dealer.
	I documenti scaduti restano invisibili ai dealer finché la data non viene aggiornata.
</p>

<h3 style="margin:0 0 10px;font-size:16px;color:#1d2939;">
	In scadenza nei prossimi <?php echo esc_html( (string) $dp_window ); ?> giorni
	(<?php echo esc_html( (string) count( $dp_expiring ) ); ?>)
</h3>

<?php if ( empty( $dp_expiring ) ) : ?>
	<p style="margin:0 0 22px;font-size:14px;color:#5b6472;">Nessun documento in scadenza.</p>
<?php else : ?>
	<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;margin:0 0 24px;font-size:14px;">
		<tr>
			<th align="left" style="padding:8px 12px;background-color:#1d2939;color:#ffffff;font-size:13px;">Documento</th>
			<th align="left" style="padding:8px 12px;background-color:#1d2939;color:#ffffff;font-size:13px;white-space:nowrap;">Scade il</th>
		</tr>
		<?php foreach ( $dp_expiring as $dp_doc ) : ?>
			<tr>
				<td style="padding:9px 12px;border:1px solid #e2e5ea;">
					<strong><?php echo esc_html( (string) ( $dp_doc['title'] ?? '' ) ); ?></strong>
					<br />
					<span style="font-size:12px;color:#5b6472;">
						<?php
						$dp_meta = array_filter( [
							(string) ( $dp_doc['brand_line'] ?? '' ),
							(string) ( $dp_doc['type_label'] ?? '' ),
							'ID ' . (string) ( $dp_doc['id'] ?? 0 ),
						] );
						echo esc_html( implode( ' · ', $dp_meta ) );
						?>
					</span>
				</td>
				<td style="padding:9px 12px;border:1px solid #e2e5ea;white-space:nowrap;">
					<?php echo esc_html( (string) ( $dp_doc['expiry_fmt'] ?? '' ) ); ?>
					<?php if ( null !== ( $dp_doc['days_left'] ?? null ) ) : ?>
						<br />
						<span style="font-size:12px;color:#b54708;">
							<?php
							$dp_days = max( 0, (int) $dp_doc['days_left'] );
							echo esc_html( 0 === $dp_days ? 'oggi' : 'fra ' . $dp_days . ( 1 === $dp_days ? ' giorno' : ' giorni' ) );
							?>
						</span>
					<?php endif; ?>
				</td>
			</tr>
		<?php endforeach; ?>
	</table>
<?php endif; ?>

<h3 style="margin:0 0 10px;font-size:16px;color:#1d2939;">
	Già scaduti (<?php echo esc_html( (string) count( $dp_expired ) ); ?>)
</h3>

<?php if ( empty( $dp_expired ) ) : ?>
	<p style="margin:0 0 22px;font-size:14px;color:#5b6472;">Nessun documento scaduto.</p>
<?php else : ?>
	<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;margin:0 0 24px;font-size:14px;">
		<tr>
			<th align="left" style="padding:8px 12px;background-color:#7a271a;color:#ffffff;font-size:13px;">Documento</th>
			<th align="left" style="padding:8px 12px;background-color:#7a271a;color:#ffffff;font-size:13px;white-space:nowrap;">Scaduto il</th>
		</tr>
		<?php foreach ( $dp_expired as $dp_doc ) : ?>
			<tr>
				<td style="padding:9px 12px;border:1px solid #e2e5ea;">
					<strong><?php echo esc_html( (string) ( $dp_doc['title'] ?? '' ) ); ?></strong>
					<br />
					<span style="font-size:12px;color:#5b6472;">
						<?php
						$dp_meta = array_filter( [
							(string) ( $dp_doc['brand_line'] ?? '' ),
							(string) ( $dp_doc['type_label'] ?? '' ),
							'ID ' . (string) ( $dp_doc['id'] ?? 0 ),
						] );
						echo esc_html( implode( ' · ', $dp_meta ) );
						?>
					</span>
				</td>
				<td style="padding:9px 12px;border:1px solid #e2e5ea;white-space:nowrap;color:#7a271a;">
					<?php echo esc_html( (string) ( $dp_doc['expiry_fmt'] ?? '' ) ); ?>
				</td>
			</tr>
		<?php endforeach; ?>
	</table>
<?php endif; ?>

<p style="margin:0;">
	<a href="<?php echo esc_url( (string) ( $data['archive_url'] ?? '' ) ); ?>"
		style="display:inline-block;padding:11px 22px;background-color:#1d6fdc;color:#ffffff;text-decoration:none;border-radius:4px;font-weight:bold;">
		Apri l'archivio documenti
	</a>
</p>
