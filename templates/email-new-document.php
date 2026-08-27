<?php
/**
 * Corpo email: nuovo documento pubblicato oppure nuova versione di un documento.
 *
 * Variabili ($data):
 *  - user_name     : string
 *  - doc           : array  (title, brand_line, type_label, version, expiry_fmt)
 *  - is_version    : bool
 *  - prev_title    : string
 *  - search_url    : string
 *  - dashboard_url : string
 *
 * Nota: nessun link di download diretto. Gli URL di download contengono un nonce
 * legato alla sessione e non funzionerebbero da un client email.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$dp_doc     = (array) ( $data['doc'] ?? [] );
$dp_version = ! empty( $data['is_version'] );

$dp_rows = [
	'Titolo'        => (string) ( $dp_doc['title'] ?? '' ),
	'Brand / Linea' => (string) ( $dp_doc['brand_line'] ?? '' ),
	'Tipo'          => (string) ( $dp_doc['type_label'] ?? '' ),
	'Versione'      => (string) ( $dp_doc['version'] ?? '' ),
	'Valido fino al' => (string) ( $dp_doc['expiry_fmt'] ?? '' ),
];
if ( $dp_version && '' !== (string) ( $data['prev_title'] ?? '' ) ) {
	$dp_rows['Sostituisce'] = (string) $data['prev_title'];
}
?>
<p style="margin:0 0 14px;">Gentile <?php echo esc_html( (string) ( $data['user_name'] ?? '' ) ); ?>,</p>

<p style="margin:0 0 18px;">
	<?php if ( $dp_version ) : ?>
		è disponibile una <strong>versione aggiornata</strong> di un documento a cui hai accesso.
		La versione precedente resta consultabile nello storico.
	<?php else : ?>
		è stato pubblicato un <strong>nuovo documento</strong> a cui hai accesso.
	<?php endif; ?>
</p>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;margin:0 0 22px;font-size:14px;">
	<?php foreach ( $dp_rows as $dp_label => $dp_value ) : ?>
		<?php if ( '' === $dp_value ) { continue; } ?>
		<tr>
			<td style="padding:7px 12px;background-color:#f7f8fa;border:1px solid #e2e5ea;color:#5b6472;width:35%;white-space:nowrap;">
				<?php echo esc_html( $dp_label ); ?>
			</td>
			<td style="padding:7px 12px;border:1px solid #e2e5ea;">
				<?php echo esc_html( $dp_value ); ?>
			</td>
		</tr>
	<?php endforeach; ?>
</table>

<p style="margin:0 0 22px;">
	<a href="<?php echo esc_url( (string) ( $data['search_url'] ?? '' ) ); ?>"
		style="display:inline-block;padding:11px 22px;background-color:#1d6fdc;color:#ffffff;text-decoration:none;border-radius:4px;font-weight:bold;">
		Apri l'area riservata
	</a>
</p>

<p style="margin:0 0 10px;font-size:13px;color:#5b6472;">
	Per motivi di sicurezza il file non è allegato e non esiste un link diretto di download:
	accedi al portale con le tue credenziali per scaricarlo.
</p>

<p style="margin:0;font-size:13px;color:#5b6472;">
	Ricerca documenti: <a href="<?php echo esc_url( (string) ( $data['search_url'] ?? '' ) ); ?>" style="color:#1d6fdc;"><?php echo esc_html( (string) ( $data['search_url'] ?? '' ) ); ?></a><br />
	Dashboard: <a href="<?php echo esc_url( (string) ( $data['dashboard_url'] ?? '' ) ); ?>" style="color:#1d6fdc;"><?php echo esc_html( (string) ( $data['dashboard_url'] ?? '' ) ); ?></a>
</p>
