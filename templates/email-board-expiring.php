<?php
/**
 * Corpo email: un annuncio della bacheca sta per scadere.
 *
 * Variabili ($data):
 *  - user_name, listing_title, expiry, board_url : string
 *
 * È il promemoria che rende sostenibile una bacheca senza moderatori: se non si
 * fa nulla l'annuncio si archivia da solo, e la bacheca resta fatta di cose
 * ancora valide.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$dp_user   = (string) ( $data['user_name'] ?? '' );
$dp_title  = (string) ( $data['listing_title'] ?? '' );
$dp_expiry = (string) ( $data['expiry'] ?? '' );
$dp_board  = (string) ( $data['board_url'] ?? '' );

$dp_expiry_fmt = $dp_expiry ? gmdate( 'd/m/Y', strtotime( $dp_expiry ) ) : '';
?>
<p style="margin:0 0 16px;font-size:15px;">
	Ciao <?php echo esc_html( $dp_user ); ?>,
	il tuo annuncio <strong><?php echo esc_html( $dp_title ); ?></strong>
	resterà in bacheca fino al <strong><?php echo esc_html( $dp_expiry_fmt ); ?></strong>.
</p>

<p style="margin:0 0 20px;font-size:15px;">
	Se ti serve ancora, <strong>ripubblicalo</strong>. Se hai già risolto,
	<strong>chiudilo</strong>: eviterai che qualcuno ti chiami per un pezzo che
	non ti serve più.
</p>

<p style="margin:0 0 20px;">
	<a href="<?php echo esc_url( $dp_board ); ?>"
		style="display:inline-block;padding:11px 22px;background-color:#1e6fa8;color:#ffffff;
		text-decoration:none;border-radius:4px;font-size:15px;font-weight:bold;">
		Vai ai miei annunci
	</a>
</p>

<p style="margin:0;font-size:13px;color:#5a6673;">
	Se non fai nulla l’annuncio verrà archiviato automaticamente alla scadenza.
	Potrai ripubblicarlo quando vuoi.
</p>
