<?php
/**
 * Corpo email: digest periodico dei documenti in scadenza per un dealer.
 *
 * Variabili ($data):
 *  - user_name     : string
 *  - documents     : array di array (title, brand_line, type_label, expiry_fmt, days_left)
 *  - window        : int   giorni di preavviso
 *  - search_url    : string
 *  - dashboard_url : string
 *
 * L'email viene generata solo se $documents non è vuoto (nessun invio a vuoto).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$dp_docs   = (array) ( $data['documents'] ?? [] );
$dp_window = (int) ( $data['window'] ?? 30 );
?>
<p style="margin:0 0 14px;">Gentile <?php echo esc_html( (string) ( $data['user_name'] ?? '' ) ); ?>,</p>

<p style="margin:0 0 18px;">
	i seguenti documenti a te accessibili scadono nei prossimi <?php echo esc_html( (string) $dp_window ); ?> giorni.
	Dopo la data di scadenza non saranno più scaricabili dall'area riservata.
</p>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;margin:0 0 22px;font-size:14px;">
	<tr>
		<th align="left" style="padding:8px 12px;background-color:#1d2939;color:#ffffff;font-size:13px;">Documento</th>
		<th align="left" style="padding:8px 12px;background-color:#1d2939;color:#ffffff;font-size:13px;white-space:nowrap;">Scadenza</th>
	</tr>
	<?php foreach ( $dp_docs as $dp_doc ) : ?>
		<tr>
			<td style="padding:9px 12px;border:1px solid #e2e5ea;">
				<strong><?php echo esc_html( (string) ( $dp_doc['title'] ?? '' ) ); ?></strong>
				<?php if ( '' !== (string) ( $dp_doc['brand_line'] ?? '' ) || '' !== (string) ( $dp_doc['type_label'] ?? '' ) ) : ?>
					<br />
					<span style="font-size:12px;color:#5b6472;">
						<?php
						$dp_meta = array_filter( [
							(string) ( $dp_doc['brand_line'] ?? '' ),
							(string) ( $dp_doc['type_label'] ?? '' ),
						] );
						echo esc_html( implode( ' · ', $dp_meta ) );
						?>
					</span>
				<?php endif; ?>
			</td>
			<td style="padding:9px 12px;border:1px solid #e2e5ea;white-space:nowrap;">
				<?php echo esc_html( (string) ( $dp_doc['expiry_fmt'] ?? '' ) ); ?>
				<?php if ( null !== ( $dp_doc['days_left'] ?? null ) ) : ?>
					<br />
					<span style="font-size:12px;color:#b54708;">
						<?php
						$dp_days = max( 0, (int) $dp_doc['days_left'] );
						echo esc_html( 0 === $dp_days ? 'scade oggi' : 'fra ' . $dp_days . ( 1 === $dp_days ? ' giorno' : ' giorni' ) );
						?>
					</span>
				<?php endif; ?>
			</td>
		</tr>
	<?php endforeach; ?>
</table>

<p style="margin:0 0 22px;">
	<a href="<?php echo esc_url( (string) ( $data['search_url'] ?? '' ) ); ?>"
		style="display:inline-block;padding:11px 22px;background-color:#1d6fdc;color:#ffffff;text-decoration:none;border-radius:4px;font-weight:bold;">
		Consulta i documenti
	</a>
</p>

<p style="margin:0;font-size:13px;color:#5b6472;">
	Se ti serve una versione aggiornata di uno di questi documenti, contatta il tuo referente commerciale.<br />
	Dashboard: <a href="<?php echo esc_url( (string) ( $data['dashboard_url'] ?? '' ) ); ?>" style="color:#1d6fdc;"><?php echo esc_html( (string) ( $data['dashboard_url'] ?? '' ) ); ?></a>
</p>
