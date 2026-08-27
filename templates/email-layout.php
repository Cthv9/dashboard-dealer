<?php
/**
 * Involucro HTML comune a tutte le email del modulo notifiche.
 *
 * Variabili disponibili ($data, passato da Dealer_Notifications::render_template):
 *  - email_title : string  titolo mostrato nell'intestazione
 *  - email_body  : string  HTML del corpo, già composto ed escapato dal template chiamante
 *  - site_name   : string
 *  - site_url    : string
 *
 * Stili inline: i client email ignorano quasi sempre i CSS esterni.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$dp_title = (string) ( $data['email_title'] ?? '' );
$dp_site  = (string) ( $data['site_name'] ?? '' );
$dp_url   = (string) ( $data['site_url'] ?? '' );
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="<?php echo esc_attr( get_bloginfo( 'charset' ) ); ?>" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title><?php echo esc_html( $dp_title ); ?></title>
</head>
<body style="margin:0;padding:0;background-color:#f4f5f7;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f4f5f7;padding:24px 12px;">
	<tr>
		<td align="center">
			<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:620px;background-color:#ffffff;border:1px solid #e2e5ea;border-radius:6px;font-family:Arial,Helvetica,sans-serif;color:#23282d;">
				<tr>
					<td style="padding:20px 28px;background-color:#1d2939;border-radius:6px 6px 0 0;">
						<div style="font-size:13px;letter-spacing:.08em;text-transform:uppercase;color:#9aa5b1;">
							<?php echo esc_html( $dp_site ); ?>
						</div>
						<div style="font-size:20px;font-weight:bold;color:#ffffff;padding-top:4px;">
							<?php echo esc_html( $dp_title ); ?>
						</div>
					</td>
				</tr>
				<tr>
					<td style="padding:26px 28px;font-size:15px;line-height:1.6;">
						<?php
						// Corpo già composto ed escapato dal template chiamante.
						echo $data['email_body'] ?? ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						?>
					</td>
				</tr>
				<tr>
					<td style="padding:18px 28px;border-top:1px solid #e2e5ea;font-size:12px;line-height:1.6;color:#6b7280;border-radius:0 0 6px 6px;">
						<?php echo esc_html( $dp_site ); ?> — Area riservata dealer.<br />
						Puoi modificare le preferenze di notifica dal tuo profilo utente.<br />
						<a href="<?php echo esc_url( $dp_url ); ?>" style="color:#6b7280;"><?php echo esc_html( $dp_url ); ?></a>
					</td>
				</tr>
			</table>
		</td>
	</tr>
</table>
</body>
</html>
