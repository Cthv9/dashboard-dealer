<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/**
 * Area di lavoro dell'area manager — guscio e navigazione a schede.
 *
 * Variabili da Dealer_Area_Manager::render():
 *   $title         string   titolo dell'area
 *   $feedback      array    esito del ciclo PRG precedente (status, message)
 *   $user          WP_User  l'area manager
 *   $scope_orgs    int[]    organizzazioni del perimetro (sottoalberi espansi)
 *   $scope_lines   string[] linee del perimetro ("Brand|Linea")
 *   $summary       array    numeri di intestazione
 *   $tab           string   scheda corrente
 *   $tabs          array    slug => etichetta
 *   $base_url      string   URL della pagina, senza parametri consumati
 *   $form_action   string   URL a cui inviare i form (pattern PRG)
 *   $doc_types     array    slug => etichetta dei tipi documento
 *   $documents, $doc_filters, $doc_paging, $brands   (scheda documenti)
 *   $upload        array    (scheda caricamento)
 *   $organizations array    (scheda persone)
 *   $activity      array    (scheda attività)
 *
 * Nota: qui non si decide nulla. I flag 'editable', 'manageable' e 'can_invite'
 * servono solo a non mostrare comandi che il server rifiuterebbe comunque;
 * l'autorizzazione vera sta negli handler di Dealer_Area_Manager e negli
 * endpoint AJAX di Dealer_Admin.
 *
 * La navigazione è fatta di link e form reali: nessun riferimento a wp-admin.
 */
?>
<div class="dealer-am-wrap" id="dealer-am">

	<style>
	/* Palette allineata a dealer.css (#1e6fa8, #0a1628), ma classi indipendenti:
	   questo modulo non tocca il foglio di stile condiviso. */
	.dealer-am-wrap{--am-navy:#0a1628;--am-blue:#1e6fa8;--am-blue-dk:#155c91;--am-gray:#f4f6f8;
		--am-border:#dce5ea;--am-text:#1a2535;--am-muted:#52616b;--am-warn:#b8860b;--am-danger:#a63232;
		--am-ok:#2e7d4f;--am-radius:8px;--am-shadow:0 4px 14px rgba(0,0,0,.07);
		color:var(--am-text);box-sizing:border-box;}
	/* Larghezza in una regola a parte, con "body" davanti e !important: vedi
	   il blocco "Wrapper globale" in assets/css/dealer.css per il perché
	   (spiegato lì per non ripeterlo in ogni template) — un tema a blocchi
	   applica ai figli diretti del contenuto un margin:auto !important che
	   altrimenti annulla silenziosamente questa uscita dal contenitore.
	   --dp-vw arriva da assets/js/dealer-layout.js; senza JS ricade su 100vw.
	   Il contenuto resta comunque entro 1180px. */
	body .dealer-am-wrap{
		width:var(--dp-vw,100vw) !important;max-width:var(--dp-vw,100vw) !important;
		margin-left:calc(50% - var(--dp-vw,100vw) / 2) !important;
		margin-right:calc(50% - var(--dp-vw,100vw) / 2) !important;
		padding:0 max(16px,calc((var(--dp-vw,100vw) - 1180px) / 2)) !important;}
	/* Ripiego applicato da dealer-layout.js se un contenitore del tema
	   ritaglia ciò che deborda: stretto ma integro. Stesso !important della
	   regola sopra: deve poterla annullare. */
	body .dealer-am-wrap.dp-no-bleed{
		width:auto !important;max-width:1180px !important;
		margin-left:auto !important;margin-right:auto !important;padding:0 !important;}
	.dealer-am-head{background:var(--am-navy);color:#fff;border-radius:var(--am-radius);
		padding:24px 28px;margin-bottom:20px;}
	.dealer-am-head h2{margin:0 0 6px;color:#fff;font-size:1.5rem;line-height:1.25;}
	.dealer-am-who{font-size:1rem;color:#c9d6e2;margin:0;}
	.dealer-am-stats{margin-top:16px;display:flex;flex-wrap:wrap;gap:10px;}
	.dealer-am-stat{background:rgba(255,255,255,.1);border-radius:var(--am-radius);padding:10px 14px;
		min-width:110px;}
	.dealer-am-stat b{display:block;font-size:1.32rem;color:#fff;line-height:1.1;}
	.dealer-am-stat span{font-size:.76rem;text-transform:uppercase;letter-spacing:.04em;color:#c9d6e2;}
	.dealer-am-tabs{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:22px;border-bottom:2px solid var(--am-border);}
	.dealer-am-tab{display:inline-block;padding:11px 18px;text-decoration:none;color:var(--am-muted);
		font-weight:600;font-size:.94rem;border:1px solid transparent;border-bottom:0;
		border-radius:var(--am-radius) var(--am-radius) 0 0;margin-bottom:-2px;}
	.dealer-am-tab:hover{color:var(--am-blue);background:var(--am-gray);}
	.dealer-am-tab.is-active{background:#fff;color:var(--am-navy);border-color:var(--am-border);
		border-bottom:2px solid #fff;}
	.dealer-am-msg{margin:0 0 22px;padding:14px 18px;border-radius:var(--am-radius);
		border:1px solid var(--am-border);border-left:4px solid var(--am-blue);background:#fff;}
	.dealer-am-msg-success{border-left-color:var(--am-ok);background:#f2faf5;}
	.dealer-am-msg-error{border-left-color:var(--am-danger);background:#fdf4f4;}
	.dealer-am-panel{background:#fff;border:1px solid var(--am-border);border-radius:var(--am-radius);
		box-shadow:var(--am-shadow);padding:22px 24px;margin-bottom:22px;}
	.dealer-am-panel h3{margin:0 0 6px;font-size:1.12rem;color:var(--am-navy);}
	.dealer-am-panel h4{margin:0 0 4px;font-size:1rem;color:var(--am-navy);}
	.dealer-am-hint{margin:0 0 16px;color:var(--am-muted);font-size:.9rem;}
	.dealer-am-note{color:var(--am-muted);font-size:.84rem;margin:8px 0 0;}
	.dealer-am-empty{color:var(--am-muted);font-style:italic;}
	.dealer-am-field{display:flex;flex-direction:column;gap:5px;margin-bottom:14px;}
	.dealer-am-field label{font-weight:600;font-size:.9rem;}
	.dealer-am-field input[type=text],.dealer-am-field input[type=email],
	.dealer-am-field input[type=search],.dealer-am-field input[type=date],
	.dealer-am-field select,.dealer-am-field textarea{
		padding:10px 12px;border:1px solid var(--am-border);border-radius:6px;font-size:.95rem;
		background:#fff;color:var(--am-text);width:100%;box-sizing:border-box;}
	.dealer-am-field input:focus,.dealer-am-field select:focus,.dealer-am-field textarea:focus{
		outline:none;border-color:var(--am-blue);box-shadow:0 0 0 3px rgba(30,111,168,.12);}
	.dealer-am-row{display:flex;flex-wrap:wrap;gap:16px;}
	.dealer-am-row .dealer-am-field{flex:1;min-width:200px;}
	.dealer-am-btn{display:inline-block;padding:10px 20px;border:0;border-radius:6px;
		background:var(--am-blue);color:#fff;font-weight:600;font-size:.92rem;cursor:pointer;
		text-decoration:none;line-height:1.3;}
	.dealer-am-btn:hover{background:var(--am-blue-dk);color:#fff;}
	.dealer-am-btn[disabled]{opacity:.55;cursor:not-allowed;}
	.dealer-am-btn-ghost{background:#fff;color:var(--am-blue);border:1px solid var(--am-blue);}
	.dealer-am-btn-ghost:hover{background:var(--am-gray);color:var(--am-blue-dk);}
	.dealer-am-btn-danger{background:#fff;color:var(--am-danger);border:1px solid #e0b4b4;}
	.dealer-am-btn-danger:hover{background:#fdf4f4;color:var(--am-danger);}
	.dealer-am-btn-sm{padding:7px 14px;font-size:.85rem;}
	.dealer-am-lines{border:1px solid var(--am-border);border-radius:6px;padding:12px 14px;
		background:var(--am-gray);max-height:280px;overflow:auto;}
	.dealer-am-brand{margin:0 0 12px;}
	.dealer-am-brand:last-child{margin-bottom:0;}
	.dealer-am-brand-name{font-weight:700;font-size:.85rem;text-transform:uppercase;
		letter-spacing:.03em;color:var(--am-blue);display:block;margin-bottom:6px;}
	.dealer-am-check{display:inline-flex;align-items:center;gap:6px;font-size:.88rem;
		margin:0 14px 6px 0;}
	.dealer-am-chip{display:inline-block;padding:3px 10px;border-radius:99px;font-size:.78rem;
		background:var(--am-gray);border:1px solid var(--am-border);color:var(--am-text);}
	.dealer-am-chip-none{background:#fdf4f4;border-color:#e0b4b4;color:var(--am-danger);}
	.dealer-am-chiplist{margin:10px 0 0;display:flex;flex-wrap:wrap;gap:6px;}
	.dealer-am-badge{display:inline-block;padding:3px 11px;border-radius:99px;font-size:.76rem;
		font-weight:600;background:var(--am-blue);color:#fff;}
	.dealer-am-badge-soft{background:var(--am-gray);color:var(--am-muted);border:1px solid var(--am-border);}
	.dealer-am-badge-warn{background:#fdf6e6;color:var(--am-warn);border:1px solid #ecdcb0;}
	.dealer-am-badge-danger{background:#fdf4f4;color:var(--am-danger);border:1px solid #e0b4b4;}
	.dealer-am-badge-ok{background:#f2faf5;color:var(--am-ok);border:1px solid #bfe0cc;}
	.dealer-am-tablewrap{overflow-x:auto;}
	.dealer-am-table{width:100%;border-collapse:collapse;font-size:.9rem;}
	.dealer-am-table th,.dealer-am-table td{padding:10px 12px;text-align:left;
		border-bottom:1px solid var(--am-border);vertical-align:top;}
	.dealer-am-table th{font-size:.76rem;text-transform:uppercase;letter-spacing:.04em;
		color:var(--am-muted);background:var(--am-gray);}
	.dealer-am-table tr:last-child td{border-bottom:0;}
	.dealer-am-doc-title{font-weight:600;color:var(--am-navy);display:block;}
	.dealer-am-doc-file{color:var(--am-muted);font-size:.8rem;word-break:break-all;}
	.dealer-am-doc-view{display:inline-flex;align-items:center;margin-left:6px;color:var(--am-blue);vertical-align:middle;}
	.dealer-am-doc-view:hover,.dealer-am-doc-view:focus{color:var(--am-blue-dk);}
	.dealer-am-doc-view .dashicons{font-size:16px;width:16px;height:16px;line-height:16px;}
	.dealer-am-doc-actions{display:flex;flex-wrap:wrap;gap:8px;}
	.dealer-am-pager{display:flex;flex-wrap:wrap;gap:6px;margin-top:16px;align-items:center;}
	.dealer-am-pager a,.dealer-am-pager span{padding:6px 12px;border-radius:6px;font-size:.86rem;
		text-decoration:none;border:1px solid var(--am-border);color:var(--am-blue);background:#fff;}
	.dealer-am-pager .is-current{background:var(--am-blue);color:#fff;border-color:var(--am-blue);}
	.dealer-am-member{border:1px solid var(--am-border);border-radius:var(--am-radius);
		background:#fff;padding:16px 18px;margin-bottom:12px;}
	.dealer-am-member-top{display:flex;flex-wrap:wrap;gap:12px;align-items:flex-start;
		justify-content:space-between;}
	.dealer-am-member-name{font-weight:700;font-size:1rem;color:var(--am-navy);}
	.dealer-am-member-mail{display:block;color:var(--am-muted);font-size:.86rem;}
	.dealer-am-meta{margin:10px 0 0;display:flex;flex-wrap:wrap;gap:20px;font-size:.85rem;
		color:var(--am-muted);}
	.dealer-am-meta b{display:block;color:var(--am-text);font-weight:600;font-size:.76rem;
		text-transform:uppercase;letter-spacing:.03em;}
	.dealer-am-actions{margin-top:14px;padding-top:12px;border-top:1px dashed var(--am-border);
		display:flex;flex-wrap:wrap;gap:12px;align-items:flex-start;}
	.dealer-am-actions details{flex:1;min-width:260px;}
	.dealer-am-actions summary,.dealer-am-org details summary{cursor:pointer;font-weight:600;
		font-size:.88rem;color:var(--am-blue);}
	.dealer-am-actions details[open] summary{margin-bottom:12px;}
	.dealer-am-org-head{display:flex;flex-wrap:wrap;gap:12px;align-items:baseline;
		justify-content:space-between;margin-bottom:4px;}
	/* Wizard */
	.dealer-am-steps{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:20px;}
	.dealer-am-step{flex:1;min-width:130px;padding:10px 12px;border-radius:var(--am-radius);
		background:var(--am-gray);border:1px solid var(--am-border);font-size:.82rem;color:var(--am-muted);}
	.dealer-am-step b{display:block;font-size:.72rem;text-transform:uppercase;letter-spacing:.05em;}
	.dealer-am-step.is-active{background:var(--am-blue);border-color:var(--am-blue);color:#fff;}
	.dealer-am-step.is-done{background:#f2faf5;border-color:#bfe0cc;color:var(--am-ok);}
	.dealer-am-pane{display:none;}
	.dealer-am-pane.is-active{display:block;}
	.dealer-am-nav{display:flex;flex-wrap:wrap;gap:10px;margin-top:18px;padding-top:16px;
		border-top:1px dashed var(--am-border);}
	.dealer-am-drop{border:2px dashed var(--am-border);border-radius:var(--am-radius);
		background:var(--am-gray);padding:26px 20px;text-align:center;}
	.dealer-am-drop.is-over{border-color:var(--am-blue);background:#eef5fa;}
	.dealer-am-filelabel{color:var(--am-blue);text-decoration:underline;cursor:pointer;font-weight:600;}
	.dealer-am-selected{margin-top:12px;font-size:.9rem;color:var(--am-navy);font-weight:600;}
	.dealer-am-error{color:var(--am-danger);font-size:.85rem;margin:6px 0 0;}
	.dealer-am-summary dt{font-weight:600;font-size:.78rem;text-transform:uppercase;
		letter-spacing:.03em;color:var(--am-muted);margin-top:10px;}
	.dealer-am-summary dd{margin:2px 0 0;color:var(--am-text);font-size:.92rem;}
	.dealer-am-noscript{margin:0 0 18px;padding:12px 16px;border-radius:var(--am-radius);
		background:#fdf6e6;border:1px solid #ecdcb0;color:#7a5c12;font-size:.88rem;}
	</style>

	<div class="dealer-am-head">
		<h2><?php echo esc_html( $title ); ?></h2>
		<p class="dealer-am-who">
			<?php echo esc_html( $user->display_name ); ?> — Area Manager
		</p>
		<div class="dealer-am-stats">
			<div class="dealer-am-stat">
				<b><?php echo esc_html( (string) $summary['documents'] ); ?></b>
				<span>Documenti seguiti</span>
			</div>
			<div class="dealer-am-stat">
				<b><?php echo esc_html( (string) $summary['editable'] ); ?></b>
				<span>Modificabili</span>
			</div>
			<div class="dealer-am-stat">
				<b><?php echo esc_html( (string) $summary['lines'] ); ?></b>
				<span>Linee prodotto</span>
			</div>
			<div class="dealer-am-stat">
				<b><?php echo esc_html( (string) $summary['orgs'] ); ?></b>
				<span>Organizzazioni</span>
			</div>
			<div class="dealer-am-stat">
				<b><?php echo esc_html( (string) $summary['people'] ); ?></b>
				<span>Persone seguite</span>
			</div>
		</div>
	</div>

	<nav class="dealer-am-tabs" aria-label="Sezioni dell’area di lavoro">
		<?php foreach ( $tabs as $slug => $label ) : ?>
			<a class="dealer-am-tab<?php echo $slug === $tab ? ' is-active' : ''; ?>"
				href="<?php echo esc_url( $this->tab_url( $slug ) ); ?>"
				<?php echo $slug === $tab ? 'aria-current="page"' : ''; ?>>
				<?php echo esc_html( $label ); ?>
			</a>
		<?php endforeach; ?>
	</nav>

	<?php if ( ! empty( $feedback['message'] ) ) : ?>
		<div class="dealer-am-msg <?php echo 'success' === ( $feedback['status'] ?? '' ) ? 'dealer-am-msg-success' : 'dealer-am-msg-error'; ?>">
			<?php echo esc_html( (string) $feedback['message'] ); ?>
		</div>
	<?php endif; ?>

	<?php
	switch ( $tab ) {
		case Dealer_Area_Manager::TAB_UPLOAD:
			require DEALER_PORTAL_PATH . 'templates/am-upload.php';
			break;

		case Dealer_Area_Manager::TAB_USERS:
			require DEALER_PORTAL_PATH . 'templates/am-users.php';
			break;

		case Dealer_Area_Manager::TAB_ACTIVITY:
			require DEALER_PORTAL_PATH . 'templates/am-activity.php';
			break;

		case Dealer_Area_Manager::TAB_DOCUMENTS:
		default:
			require DEALER_PORTAL_PATH . 'templates/am-documents.php';
			break;
	}
	?>

	<p class="dealer-am-note">
		L’eliminazione definitiva di un documento resta all’amministratore del portale:
		da quest’area un documento si sostituisce con una nuova versione oppure si segna
		come obsoleto, entrambe operazioni reversibili che lasciano traccia.
	</p>

</div>
