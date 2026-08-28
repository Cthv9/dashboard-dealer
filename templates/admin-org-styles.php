<?php
/**
 * Stili condivisi dalle schermate delle organizzazioni.
 * Incluso una sola volta da ciascun template (guardia $GLOBALS).
 *
 * Nessuna libreria esterna: solo classi native WordPress piu' queste poche
 * regole per l'albero e per i riquadri dell'effetto risultante.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! empty( $GLOBALS['dealer_org_styles_done'] ) ) {
	return;
}
$GLOBALS['dealer_org_styles_done'] = true;
?>
<style>
	.dorg-tree-name { display:flex; align-items:center; gap:6px; }
	.dorg-indent { display:inline-block; }
	.dorg-branch { color:#8c8f94; font-family:Consolas,Monaco,monospace; }
	.dorg-badge {
		display:inline-block; padding:1px 7px; border-radius:9px; font-size:11px;
		line-height:18px; background:#f0f0f1; color:#3c434a; border:1px solid #dcdcde;
	}
	.dorg-badge.is-own      { background:#edfaef; border-color:#a7d7ae; color:#1e5b2a; }
	.dorg-badge.is-inherit  { background:#eef3fb; border-color:#b4c8e8; color:#1d4d92; }
	.dorg-badge.is-mixed    { background:#fdf6e6; border-color:#e3cf95; color:#7a5a00; }
	.dorg-badge.is-alert    { background:#fcefef; border-color:#e2b0b0; color:#8a1f1f; }
	.dorg-badge.is-muted    { background:#f6f7f7; border-color:#dcdcde; color:#787c82; }
	.dorg-dead { color:#8a1f1f; font-weight:600; }
	.dorg-muted { color:#787c82; }
	.dorg-cell-sub { display:block; font-size:11px; color:#787c82; margin-top:2px; }
	.dorg-actions form { display:inline; }
	.dorg-brand-group {
		border:1px solid #dcdcde; border-radius:4px; margin:0 0 8px; background:#fff;
	}
	.dorg-brand-head {
		display:flex; align-items:center; gap:10px; padding:6px 10px;
		background:#f6f7f7; border-bottom:1px solid #dcdcde; font-weight:600;
	}
	.dorg-brand-head .dorg-brand-tools { margin-left:auto; font-weight:400; font-size:12px; }
	.dorg-brand-body { display:flex; flex-wrap:wrap; gap:4px 18px; padding:8px 10px; }
	.dorg-brand-body label { display:block; min-width:210px; font-size:13px; }
	.dorg-lines-scroll { max-height:460px; overflow:auto; padding-right:4px; }
	.dorg-effect {
		border:1px solid #c3c4c7; border-left-width:4px; background:#fff;
		padding:10px 14px; margin:0 0 12px;
	}
	.dorg-effect.is-ok    { border-left-color:#00a32a; }
	.dorg-effect.is-warn  { border-left-color:#dba617; }
	.dorg-effect.is-alert { border-left-color:#d63638; }
	.dorg-effect h3 { margin:0 0 6px; font-size:14px; }
	.dorg-effect ul { margin:6px 0 0 18px; list-style:disc; }
	.dorg-count { font-size:22px; font-weight:600; line-height:1.1; }
	.dorg-sticky { position:sticky; top:32px; }
	.dorg-line-list { columns:2; column-gap:24px; font-size:12px; margin:6px 0 0; }
	.dorg-line-list li { break-inside:avoid; }
	.dorg-report { max-height:320px; overflow:auto; background:#fff; border:1px solid #dcdcde; padding:8px 12px; }
	.dorg-report li { font-family:Consolas,Monaco,monospace; font-size:12px; }
	@media screen and (max-width:900px) {
		.dorg-line-list { columns:1; }
	}
</style>
