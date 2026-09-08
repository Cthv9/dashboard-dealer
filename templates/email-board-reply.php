<?php
/**
 * Corpo email: qualcuno ha risposto a un annuncio della bacheca.
 *
 * Variabili ($data):
 *  - listing_title, listing_type : string
 *  - message                     : string  testo scritto da chi risponde
 *  - sender_name, sender_org     : string
 *  - sender_email, sender_phone  : string
 *  - board_url                   : string
 *
 * Chi risponde si presenta per intero: senza nome, azienda e recapito il
 * messaggio sarebbe inservibile. Il recapito di chi ha pubblicato non compare
 * qui — l'email gli arriva e basta, e non passa mai dal browser di chi scrive.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$dp_title = (string) ( $data['listing_title'] ?? '' );
$dp_type  = (string) ( $data['listing_type'] ?? '' );
$dp_msg   = (string) ( $data['message'] ?? '' );
$dp_name  = (string) ( $data['sender_name'] ?? '' );
$dp_org   = (string) ( $data['sender_org'] ?? '' );
$dp_mail  = (string) ( $data['sender_email'] ?? '' );
$dp_phone = (string) ( $data['sender_phone'] ?? '' );
$dp_board = (string) ( $data['board_url'] ?? '' );
?>
<p style="margin:0 0 16px;font-size:15px;">
	Hai ricevuto una risposta al tuo annuncio
	<strong><?php echo esc_html( $dp_title ); ?></strong>
	(<?php echo esc_html( $dp_type ); ?>).
</p>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
	style="border:1px solid #e2e5ea;border-radius:4px;margin:0 0 20px;">
	<tr>
		<td style="padding:14px 16px;background-color:#f7f8fa;font-size:13px;color:#5a6673;">
			Da <strong style="color:#23282d;"><?php echo esc_html( $dp_name ); ?></strong>
			— <?php echo esc_html( $dp_org ); ?>
		</td>
	</tr>
	<tr>
		<td style="padding:16px;font-size:15px;line-height:1.55;">
			<?php echo nl2br( esc_html( $dp_msg ) ); ?>
		</td>
	</tr>
</table>

<p style="margin:0 0 16px;font-size:15px;">
	<strong>Per rispondere</strong> scrivi o telefona direttamente:<br>
	<?php if ( '' !== $dp_mail ) : ?>
		<a href="mailto:<?php echo esc_attr( $dp_mail ); ?>" style="color:#1e6fa8;"><?php echo esc_html( $dp_mail ); ?></a><br>
	<?php endif; ?>
	<?php if ( '' !== $dp_phone ) : ?>
		<?php echo esc_html( $dp_phone ); ?>
	<?php endif; ?>
</p>

<p style="margin:0;font-size:13px;color:#5a6673;">
	Quando l’annuncio non serve più, chiudilo dalla
	<a href="<?php echo esc_url( $dp_board ); ?>" style="color:#1e6fa8;">bacheca</a>:
	eviterai telefonate per un pezzo che hai già sistemato.
</p>
