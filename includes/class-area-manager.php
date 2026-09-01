<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Area di lavoro dell'area manager, interamente sul front-end.
 *
 * Fino a ieri l'area manager lavorava dentro wp-admin. Era un difetto, non una
 * scorciatoia: il portale e' costruito sul principio che chi lo usa non veda
 * mai il backend di WordPress, e l'area manager e' un utente operativo — carica
 * documenti e gestisce persone — non un tecnico del sito. Dealer_Access_Guard
 * chiude ora wp-admin anche a lui e lo reindirizza qui: questo modulo e' cio'
 * che gli restituisce la possibilita' di fare il proprio mestiere.
 *
 * Cosa puo' fare (shortcode [dealer_area_manager]):
 *   - caricare un documento con un wizard equivalente a quello admin;
 *   - vedere e mantenere i documenti del proprio perimetro (nuova versione,
 *     marcatura come obsoleto);
 *   - gestire i collaboratori delle organizzazioni che segue (invito,
 *     limitazione alle linee, disattivazione);
 *   - leggere l'attivita' di download del proprio perimetro.
 *
 * REGOLE DI CONTENIMENTO — sono il motivo per cui questa classe puo' esistere:
 *
 *  1. Ogni handler ricontrolla Dealer_Identity::is_area_manager() piu' il
 *     perimetro: il rendering non e' un'autorizzazione, e un dealer che scopre
 *     l'endpoint deve sbatterci contro comunque.
 *  2. L'ID che arriva dal POST non prova nulla — ne' quello di un utente ne'
 *     quello di un'organizzazione. Prima di ogni scrittura passano da
 *     resolve_target() e resolve_org(), che verificano l'appartenenza al
 *     perimetro (Dealer_Identity::get_scope_orgs(), sottoalberi inclusi).
 *  3. Le linee si scrivono solo con Dealer_Identity::set_line_limit(), che
 *     interseca gia' con le linee dell'organizzazione. Qui dentro non si scrive
 *     mai a mano _dealer_line_limit ne' _dealer_lines.
 *  4. Un invito produce SEMPRE un collaboratore: la funzione e' una costante
 *     scritta nel codice e il ruolo WordPress deriva dal livello commerciale
 *     dell'organizzazione, filtrato contro la whitelist dei tre ruoli dealer.
 *     Nessun percorso di questo modulo puo' produrre un titolare, un area
 *     manager o un amministratore.
 *  5. Nessuna password in chiaro: link di reset, come in Dealer_Team.
 *  6. Disattivare non e' eliminare: si revoca il ruolo e si toglie l'utente
 *     dall'organizzazione, l'account resta con tutto il suo storico di download.
 *  7. Organizzazione sospesa: sola lettura, nessuna operazione.
 *  8. Nessuna eliminazione definitiva di documenti: resta all'amministratore
 *     (vedi Dealer_Admin::ajax_delete_document(), che pretende DEALER_PORTAL_CAP).
 *
 * UPLOAD — perche' qui non c'e' logica di salvataggio:
 *
 * Il wizard invia a `dealer_save_document`, `dealer_get_lines` e
 * `dealer_mark_obsolete`, gli endpoint gia' registrati da Dealer_Admin, che
 * applicano DEALER_PORTAL_CAP_UPLOAD *piu'* la validazione di perimetro
 * (can_publish_to_lines() / can_edit_document()). Riscriverne una seconda copia
 * qui significherebbe creare due controlli di accesso destinati a divergere: e'
 * esattamente l'errore da evitare in un sistema di autorizzazione. Gli endpoint
 * vivono su admin-ajax.php, che Dealer_Access_Guard lascia deliberatamente
 * passare perche' e' un endpoint e non un'interfaccia.
 *
 * Progressive enhancement: consultazione e gestione utenti funzionano senza
 * JavaScript (link GET e form POST con pattern PRG, come Dealer_Team). Solo il
 * wizard di caricamento richiede JS, esattamente come in admin.
 */
class Dealer_Area_Manager {

	// ─── Costanti ─────────────────────────────────────────────────────────────

	/** Shortcode dell'area di lavoro. */
	const SHORTCODE = 'dealer_area_manager';

	/** Schede dell'interfaccia. La chiave finisce in query string (am_tab). */
	const TAB_DOCUMENTS = 'documenti';
	const TAB_UPLOAD    = 'carica';
	const TAB_USERS     = 'persone';
	const TAB_ACTIVITY  = 'attivita';

	const TABS = [
		self::TAB_DOCUMENTS => 'I miei documenti',
		self::TAB_UPLOAD    => 'Carica documento',
		self::TAB_USERS     => 'Persone',
		self::TAB_ACTIVITY  => 'Attività',
	];

	/**
	 * Azioni nonce del modulo. Quelle su un bersaglio includono l'ID nel
	 * contesto, cosi' un nonce ottenuto per un utente non vale per un altro.
	 */
	const NONCE_INVITE     = 'dealer_am_invite';
	const NONCE_LINES      = 'dealer_am_lines';
	const NONCE_DEACTIVATE = 'dealer_am_deactivate';

	/**
	 * Tetto alle scansioni che filtrano in PHP: il criterio per linea vive su un
	 * meta serializzato e non e' esprimibile in meta_query. Stesso limite di
	 * Dealer_Admin, per la stessa ragione.
	 */
	const SCOPE_SCAN_LIMIT = 2000;

	/** Documenti per pagina nell'elenco. */
	const DOCS_PER_PAGE = 20;

	/** Righe di log mostrate nella scheda attività. */
	const LOG_LIMIT = 25;

	/** Finestra temporale dei numeri di sintesi. */
	const ACTIVITY_WINDOW_DAYS = 30;

	/** Rate limit inviti: massimo per area manager nella finestra. */
	const INVITE_RATE_MAX    = 10;
	const INVITE_RATE_WINDOW = HOUR_IN_SECONDS;

	/** Etichette dei livelli commerciali. */
	const TIER_LABELS = [
		'dealer'      => 'Dealer',
		'top_dealer'  => 'Top Dealer',
		'part_center' => 'Parts Center',
	];

	/**
	 * Perimetro documenti gia' risolto, per utente e per criterio.
	 * La scansione e' costosa: nella stessa richiesta la si fa una volta sola.
	 *
	 * @var array<string,int[]>
	 */
	private static $scope_cache = [];

	/**
	 * Perimetro organizzazioni gia' risolto, per utente.
	 *
	 * get_scope_orgs() espande i sottoalberi con una query per organizzazione:
	 * richiamarlo una volta per ogni membro di ogni azienda seguita moltiplica
	 * le query senza cambiare il risultato, che dentro una singola richiesta e'
	 * costante.
	 *
	 * @var array<int,int[]>
	 */
	private static $orgs_cache = [];

	// ─── Constructor ──────────────────────────────────────────────────────────

	public function __construct() {
		add_shortcode( self::SHORTCODE, [ $this, 'render' ] );

		// Gli invii si intercettano prima di qualsiasi output, cosi' ogni
		// operazione si chiude con un redirect (PRG) e un refresh non la ripete.
		add_action( 'template_redirect', [ $this, 'maybe_handle_submission' ] );
	}

	// ─── Contesto autorizzativo ───────────────────────────────────────────────

	/**
	 * L'area manager corrente e il suo perimetro, oppure null.
	 *
	 * Unico punto in cui si stabilisce "questa richiesta puo' agire": ogni
	 * handler riparte da qui, non dal fatto che la pagina si sia disegnata.
	 *
	 * Il criterio non e' MAI manage_options ne' DEALER_PORTAL_CAP —
	 * l'amministratore ha le sue schermate. E' il ruolo area_manager piu' il
	 * perimetro assegnatogli.
	 *
	 * @return array{user:\WP_User,orgs:int[],lines:string[]}|null
	 */
	private function manager_context(): ?array {
		if ( ! is_user_logged_in() ) {
			return null;
		}

		$user = wp_get_current_user();
		if ( ! $user instanceof \WP_User || ! $user->ID ) {
			return null;
		}

		if ( ! Dealer_Identity::is_area_manager( $user ) ) {
			return null;
		}

		return [
			'user'  => $user,
			'orgs'  => self::scope_orgs( $user ),
			'lines' => Dealer_Identity::get_scope_lines( $user ),
		];
	}

	/**
	 * Perimetro organizzazioni, memorizzato per la durata della richiesta.
	 *
	 * Resta un semplice passaggio a Dealer_Identity::get_scope_orgs(): l'unica
	 * fonte di verita' sul perimetro non cambia, si evita solo di ricalcolarla
	 * decine di volte nella stessa pagina.
	 *
	 * @return int[]
	 */
	private static function scope_orgs( \WP_User $user ): array {
		$key = (int) $user->ID;
		if ( ! isset( self::$orgs_cache[ $key ] ) ) {
			self::$orgs_cache[ $key ] = Dealer_Identity::get_scope_orgs( $user );
		}
		return self::$orgs_cache[ $key ];
	}

	/**
	 * Valida l'organizzazione bersaglio di un'operazione.
	 *
	 * Un ID arrivato dal POST non prova nulla: qui si verifica, PRIMA di
	 * qualunque scrittura, che l'organizzazione esista e stia nel perimetro
	 * dell'area manager (sottoalberi gia' espansi da get_scope_orgs()).
	 *
	 * @return int L'ID validato, oppure 0.
	 */
	private function resolve_org( int $org_id, \WP_User $manager, bool $require_active = true ): int {
		if ( $org_id <= 0 || ! Dealer_Organization::exists( $org_id ) ) {
			return 0;
		}

		if ( ! in_array( $org_id, self::scope_orgs( $manager ), true ) ) {
			return 0;
		}

		// Organizzazione sospesa: sola lettura, nessuna operazione.
		if ( $require_active && ! Dealer_Organization::is_active( $org_id ) ) {
			return 0;
		}

		return $org_id;
	}

	/**
	 * Valida l'utente bersaglio di un'operazione.
	 *
	 * Rifiuta: utenti inesistenti, se stesso, utenti senza organizzazione o di
	 * un'organizzazione fuori perimetro, titolari (promuovere e declassare resta
	 * dell'amministratore), amministratori, gestori del portale e altri area
	 * manager.
	 */
	private function resolve_target( int $target_id, \WP_User $manager, bool $require_active = true ): ?\WP_User {
		if ( $target_id <= 0 ) {
			return null;
		}

		$target = get_user_by( 'id', $target_id );
		if ( ! $target instanceof \WP_User ) {
			return null;
		}

		if ( (int) $target->ID === (int) $manager->ID ) {
			return null;
		}

		// Appartenenza: il cuore della regola. get_org_id() ritorna 0 se
		// l'organizzazione non esiste piu', quindi il confronto e' sempre
		// contro un ID reale, e resolve_org() lo pesa contro il perimetro.
		$org_id = Dealer_Identity::get_org_id( $target );
		if ( ! $this->resolve_org( $org_id, $manager, $require_active ) ) {
			return null;
		}

		// Il titolare non e' gestibile dall'area manager: la funzione la
		// assegna e la toglie solo l'amministratore del portale.
		if ( Dealer_Identity::is_titolare( $target ) ) {
			return null;
		}

		// Account privilegiati: mai, nemmeno se risultassero assegnati a
		// un'organizzazione del perimetro.
		if ( user_can( $target, 'manage_options' )
			|| user_can( $target, DEALER_PORTAL_CAP )
			|| Dealer_Identity::is_area_manager( $target ) ) {
			return null;
		}

		return $target;
	}

	// ─── Asset ────────────────────────────────────────────────────────────────

	/**
	 * Il JS del wizard, caricato dallo shortcode durante il rendering.
	 *
	 * Non ci si aggancia a wp_enqueue_scripts con has_shortcode(): i page
	 * builder salvano il contenuto nei postmeta e quel controllo fallirebbe,
	 * lasciando il wizard muto senza nessun errore visibile. Il CSS vive in un
	 * blocco <style> del template: questo modulo non tocca dealer.css.
	 *
	 * NON si riusa assets/js/admin-upload.js: dipende dall'oggetto dealerAdmin
	 * e dal markup della pagina admin.
	 */
	private static function enqueue_assets(): void {
		wp_enqueue_script(
			'dealer-portal-am',
			DEALER_PORTAL_URL . 'assets/js/dealer-am.js',
			[ 'jquery' ],
			DEALER_PORTAL_VERSION,
			true
		);

		wp_localize_script( 'dealer-portal-am', 'dealerAM', [
			// Unico uso legittimo di admin_url() in questo modulo: e' un
			// endpoint, non una schermata di wp-admin.
			'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
			// Nonce attesi dagli handler di Dealer_Admin, invariati.
			'nonce'        => wp_create_nonce( 'dealer_upload_nonce' ),
			'archiveNonce' => wp_create_nonce( 'dealer_archive_nonce' ),
			// Comodita' dell'interfaccia: l'autorizzazione vera resta in
			// ajax_save_document() (can_publish_to_lines()).
			'productLines' => Dealer_Admin::allowed_product_lines( wp_get_current_user() ),
			'i18n'         => [
				'noFile'         => 'Seleziona un file prima di continuare.',
				'tooBig'         => 'Il file supera i 50 MB consentiti.',
				'badType'        => 'Formati accettati: PDF, XLSX, DOCX.',
				'noBrand'        => 'Seleziona il brand.',
				'noType'         => 'Seleziona il tipo di documento.',
				'noRoles'        => 'Seleziona almeno un livello di destinatari.',
				'noLines'        => 'Seleziona almeno una linea prodotto del tuo perimetro: '
					. 'un documento senza linee sarebbe visibile a tutta la rete e il server lo rifiuta.',
				'noCustomName'   => 'Indica il nuovo nome del file.',
				'noLinesForBrand' => '— Nessuna linea disponibile per questo brand —',
				'selectBrand'    => '— Prima seleziona il brand —',
				'uploading'      => 'Caricamento in corso…',
				'saveLabel'      => 'Salva documento',
				'saveSuccess'    => 'Documento salvato.',
				'saveError'      => 'Errore durante il salvataggio. Controlla i campi e riprova.',
				'confirmObsolete' => 'Segnare questo documento come obsoleto? Uscirà dalla ricerca dei dealer.',
				'obsoleteLabel'  => 'Segna obsoleto',
				'obsoleteError'  => 'Non è stato possibile completare l’operazione.',
				'working'        => 'Attendi…',
				'none'           => '—',
			],
		] );
	}

	// ─── Front-end: rendering ─────────────────────────────────────────────────

	/**
	 * Rende l'area di lavoro. Il rendering non autorizza nulla: e' solo la
	 * faccia visibile dei controlli che ogni handler rifa' da capo.
	 */
	public function render( $atts ): string {
		$atts = shortcode_atts( [
			'titolo' => 'Area di lavoro',
		], is_array( $atts ) ? $atts : [], self::SHORTCODE );

		$title    = (string) $atts['titolo'];
		$feedback = $this->consume_feedback();

		if ( ! is_user_logged_in() ) {
			return $this->notice(
				'Per accedere a quest’area devi prima effettuare l’accesso.',
				wp_login_url( $this->current_url() ),
				'Accedi'
			);
		}

		$context = $this->manager_context();
		if ( null === $context ) {
			// Stesso ragionamento di Dealer_Team::render(): l'amministratore
			// non ha di per sé un perimetro (organizzazioni seguite, linee),
			// quindi non c'è un'anteprima sensata da costruirgli al volo. Un
			// muro generico non gli direbbe come arrivarci davvero.
			if ( current_user_can( 'manage_options' ) ) {
				return $this->notice(
					'Sei amministratore: questa pagina è riservata agli area manager del portale, '
					. 'e per te non c\'è nulla da mostrare in anteprima perché il tuo account non ha un '
					. 'perimetro assegnato. Per collaudarla, assegna al tuo utente il ruolo "Area Manager" '
					. '(Utenti → il tuo profilo), poi assegnagli un perimetro da Dealer Portal → Organizzazioni '
					. '→ Area Manager. Restando anche amministratore, potrai comunque tornare in wp-admin in '
					. 'qualsiasi momento — Dealer_Access_Guard lascia sempre passare chi ha "manage_options", '
					. 'anche con un ruolo del portale in più.',
					admin_url( 'admin.php?page=dealer-portal-orgs&view=area_managers' ),
					'Vai a Organizzazioni → Area Manager'
				);
			}
			return $this->notice( 'Quest’area è riservata agli area manager del portale.' );
		}

		$user        = $context['user'];
		$scope_orgs  = $context['orgs'];
		$scope_lines = $context['lines'];

		// Perimetro vuoto: l'area manager esiste ma non gli e' stato assegnato
		// nulla. Meglio dirlo che mostrargli schermate vuote senza spiegazione.
		if ( empty( $scope_lines ) && empty( $scope_orgs ) ) {
			return $this->notice(
				'Il tuo perimetro non è ancora stato configurato: non ti risultano assegnate '
				. 'né linee prodotto né organizzazioni. Contatta l’amministratore del portale.'
			);
		}

		// Rete di sicurezza sugli asset: se lo shortcode gira, la pagina ne ha
		// bisogno, qualunque sia il sistema che l'ha costruita.
		self::enqueue_assets();

		$tab         = $this->current_tab();
		$tabs        = self::TABS;
		$base_url    = $this->current_url();
		$form_action = $this->current_url();

		// Dati comuni all'intestazione.
		$summary = $this->collect_summary( $user );

		// Ogni scheda carica solo cio' che le serve: la scansione dei documenti
		// e' costosa e non ha senso pagarla per la scheda "Persone".
		$documents   = [];
		$doc_filters = $this->document_filters();
		$doc_paging  = [ 'total' => 0, 'pages' => 1, 'current' => 1 ];
		$brands      = [];
		$organizations = [];
		$activity      = [];
		$upload        = [];

		switch ( $tab ) {
			case self::TAB_UPLOAD:
				$upload = $this->collect_upload_data( $user );
				break;

			case self::TAB_USERS:
				$organizations = $this->collect_organizations( $user );
				break;

			case self::TAB_ACTIVITY:
				$activity = $this->collect_activity( $user );
				break;

			case self::TAB_DOCUMENTS:
			default:
				$rows        = $this->collect_documents( $user, $doc_filters );
				$doc_paging  = $rows['paging'];
				$documents   = $rows['rows'];
				$brands      = $rows['brands'];
				break;
		}

		$doc_types = Dealer_Admin::get_doc_types();

		ob_start();
		require DEALER_PORTAL_PATH . 'templates/am-workspace.php';
		return (string) ob_get_clean();
	}

	/** Scheda richiesta, validata contro l'elenco. */
	private function current_tab(): string {
		$tab = sanitize_key( self::get_string( 'am_tab' ) );
		return array_key_exists( $tab, self::TABS ) ? $tab : self::TAB_DOCUMENTS;
	}

	/** URL di una scheda, conservando la pagina corrente. */
	public function tab_url( string $tab ): string {
		return add_query_arg( 'am_tab', $tab, $this->current_url() );
	}

	// ─── Perimetro documenti ──────────────────────────────────────────────────

	/**
	 * Documenti del perimetro, come insieme chiuso di ID.
	 *
	 * Due criteri distinti, gli stessi che valgono ovunque nel plugin e che qui
	 * non vengono reinventati:
	 *  - lettura  → Dealer_Search::user_can_view_document()  (sovrapposizione)
	 *  - modifica → Dealer_Identity::can_edit_document()     (contenimento)
	 *
	 * @return int[] eventualmente vuoto: chi non ha perimetro non vede nulla.
	 */
	private static function scoped_document_ids( \WP_User $user, bool $for_edit = false ): array {
		$key = (int) $user->ID . ( $for_edit ? ':edit' : ':view' );
		if ( isset( self::$scope_cache[ $key ] ) ) {
			return self::$scope_cache[ $key ];
		}

		if ( ! Dealer_Identity::is_area_manager( $user ) ) {
			self::$scope_cache[ $key ] = [];
			return self::$scope_cache[ $key ];
		}

		$scope = Dealer_Identity::get_scope_lines( $user );
		if ( empty( $scope ) ) {
			self::$scope_cache[ $key ] = [];
			return self::$scope_cache[ $key ];
		}

		// I documenti senza _doc_lines sono visibili a tutta la rete e restano
		// dell'amministratore: escluderli gia' in query riduce la scansione.
		$candidates = get_posts( [
			'post_type'      => 'documento_dealer',
			'post_status'    => [ 'publish', 'draft' ],
			'numberposts'    => self::SCOPE_SCAN_LIMIT,
			'fields'         => 'ids',
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'no_found_rows'  => true,
			'meta_query'     => [
				[ 'key' => '_doc_lines', 'compare' => 'EXISTS' ],
			],
		] );

		$matched = [];
		foreach ( (array) $candidates as $candidate_id ) {
			$candidate_id = (int) $candidate_id;
			$ok = $for_edit
				? Dealer_Identity::can_edit_document( $user, $candidate_id )
				: Dealer_Search::user_can_view_document( $user, $candidate_id, $scope );
			if ( $ok ) {
				$matched[] = $candidate_id;
			}
		}

		self::$scope_cache[ $key ] = $matched;
		return $matched;
	}

	// ─── Scheda: i miei documenti ─────────────────────────────────────────────

	/**
	 * Filtri dell'elenco documenti, letti dalla query string e validati.
	 *
	 * @return array{q:string,brand:string,type:string,stato:string,paged:int}
	 */
	private function document_filters(): array {
		$brand = sanitize_text_field( self::get_string( 'am_brand' ) );
		if ( '' !== $brand && ! array_key_exists( $brand, Dealer_Admin::get_product_lines() ) ) {
			$brand = '';
		}

		$type = sanitize_key( self::get_string( 'am_type' ) );
		if ( '' !== $type && ! array_key_exists( $type, Dealer_Admin::get_doc_types() ) ) {
			$type = '';
		}

		$stato = sanitize_key( self::get_string( 'am_stato' ) );
		if ( ! in_array( $stato, [ 'corrente', 'storico', 'obsoleto', 'scadenza' ], true ) ) {
			$stato = '';
		}

		return [
			'q'     => trim( sanitize_text_field( self::get_string( 'am_q' ) ) ),
			'brand' => $brand,
			'type'  => $type,
			'stato' => $stato,
			'paged' => max( 1, absint( self::get_string( 'am_paged' ) ) ),
		];
	}

	/**
	 * Righe dell'elenco documenti, gia' normalizzate per il template.
	 *
	 * @return array{rows:array<int,array<string,mixed>>,paging:array,brands:string[]}
	 */
	private function collect_documents( \WP_User $user, array $filters ): array {
		$ids = self::scoped_document_ids( $user, false );
		if ( empty( $ids ) ) {
			return [
				'rows'   => [],
				'paging' => [ 'total' => 0, 'pages' => 1, 'current' => 1 ],
				'brands' => [],
			];
		}

		$posts = get_posts( [
			'post_type'      => 'documento_dealer',
			'post_status'    => [ 'publish', 'draft' ],
			'post__in'       => $ids,
			'numberposts'    => self::SCOPE_SCAN_LIMIT,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		] );

		$doc_types = Dealer_Admin::get_doc_types();
		$today     = current_time( 'Y-m-d' );
		$in30      = gmdate( 'Y-m-d', (int) current_time( 'timestamp' ) + ( 30 * DAY_IN_SECONDS ) );
		$needle    = '' !== $filters['q'] ? self::lower( $filters['q'] ) : '';

		$brands = [];
		$rows   = [];

		foreach ( $posts as $post ) {
			$post_id = (int) $post->ID;

			$brand = (string) get_post_meta( $post_id, '_doc_brand', true );
			if ( '' !== $brand ) {
				$brands[ $brand ] = true;
			}

			$type   = (string) get_post_meta( $post_id, '_doc_type', true );
			$status = (string) get_post_meta( $post_id, '_doc_status', true );
			$expiry = (string) get_post_meta( $post_id, '_doc_expiry', true );

			$is_obsolete = ( 'obsoleto' === $status ) || ( 'publish' !== $post->post_status );
			$is_current  = Dealer_Versioning::is_current( $post_id );
			$is_expired  = ( '' !== $expiry && $expiry < $today );
			$is_expiring = ( '' !== $expiry && ! $is_expired && $expiry <= $in30 );

			// ── Filtri (in PHP: il criterio per linea vive su un meta
			//    serializzato, quindi l'insieme e' gia' stato risolto qui) ──
			if ( '' !== $filters['brand'] && $brand !== $filters['brand'] ) {
				continue;
			}
			if ( '' !== $filters['type'] && $type !== $filters['type'] ) {
				continue;
			}
			if ( 'corrente' === $filters['stato'] && ( ! $is_current || $is_obsolete ) ) {
				continue;
			}
			if ( 'storico' === $filters['stato'] && ( $is_current || $is_obsolete ) ) {
				continue;
			}
			if ( 'obsoleto' === $filters['stato'] && ! $is_obsolete ) {
				continue;
			}
			if ( 'scadenza' === $filters['stato'] && ! $is_expiring && ! $is_expired ) {
				continue;
			}

			$filename = (string) get_post_meta( $post_id, '_doc_filename', true );
			$keywords = (string) get_post_meta( $post_id, '_doc_keywords', true );

			if ( '' !== $needle ) {
				$haystack = self::lower( $post->post_title . ' ' . $filename . ' ' . $keywords . ' ' . $brand );
				if ( false === strpos( $haystack, $needle ) ) {
					continue;
				}
			}

			$doc_lines = get_post_meta( $post_id, '_doc_lines', true );

			$rows[] = [
				'id'         => $post_id,
				'title'      => (string) $post->post_title,
				'brand'      => $brand,
				'line'       => (string) get_post_meta( $post_id, '_doc_product_line', true ),
				'type'       => $doc_types[ $type ] ?? $type,
				'year'       => (string) get_post_meta( $post_id, '_doc_year', true ),
				'version'    => (string) get_post_meta( $post_id, '_doc_version', true ),
				'seq'        => Dealer_Versioning::get_sequence( $post_id ),
				'previous'   => Dealer_Versioning::count_previous_versions( $post_id ),
				'is_current' => $is_current,
				'obsolete'   => $is_obsolete,
				'expired'    => $is_expired,
				'expiring'   => $is_expiring,
				'expiry'     => $expiry,
				'filename'   => $filename,
				'date'       => (string) $post->post_date,
				'lines'      => is_array( $doc_lines ) ? $doc_lines : [],
				// Visibile ma non modificabile: il documento tocca anche linee
				// fuori perimetro. Il criterio e' can_edit_document(), non una
				// regola inventata qui.
				'editable'   => Dealer_Identity::can_edit_document( $user, $post_id ),
				'downloads'  => 0,
			];
		}

		$total = count( $rows );
		$pages = max( 1, (int) ceil( $total / self::DOCS_PER_PAGE ) );
		$paged = min( max( 1, (int) $filters['paged'] ), $pages );
		$rows  = array_slice( $rows, ( $paged - 1 ) * self::DOCS_PER_PAGE, self::DOCS_PER_PAGE );

		// Conteggio download solo per la pagina mostrata: una query sola.
		$page_ids = array_map( static function ( array $row ): int {
			return (int) $row['id'];
		}, $rows );

		$counts = $page_ids ? Dealer_DB::get_download_counts_for_posts( $page_ids ) : [];
		foreach ( $rows as $index => $row ) {
			$rows[ $index ]['downloads'] = (int) ( $counts[ $row['id'] ] ?? 0 );
		}

		$brand_list = array_keys( $brands );
		sort( $brand_list );

		return [
			'rows'   => $rows,
			'paging' => [ 'total' => $total, 'pages' => $pages, 'current' => $paged ],
			'brands' => $brand_list,
		];
	}

	// ─── Scheda: carica documento ─────────────────────────────────────────────

	/**
	 * Dati del wizard: brand e linee del perimetro, tipi documento e i soli
	 * documenti che l'area manager puo' davvero sostituire.
	 *
	 * Proporre un documento fuori perimetro significherebbe mostrare un'azione
	 * che ajax_save_document() rifiuta e — peggio — rivelare l'esistenza di
	 * documenti altrui.
	 */
	private function collect_upload_data( \WP_User $user ): array {
		$editable = self::scoped_document_ids( $user, true );

		$versionable = [];
		if ( $editable ) {
			$posts = get_posts( [
				'post_type'      => 'documento_dealer',
				'post_status'    => 'publish',
				'post__in'       => $editable,
				'numberposts'    => 300,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'no_found_rows'  => true,
				'meta_query'     => [ Dealer_Versioning::meta_query_current_only() ],
			] );

			foreach ( $posts as $post ) {
				$post_id = (int) $post->ID;
				$versionable[] = [
					'id'       => $post_id,
					'title'    => (string) $post->post_title,
					'version'  => (string) get_post_meta( $post_id, '_doc_version', true ),
					'filename' => (string) get_post_meta( $post_id, '_doc_filename', true ),
					'seq'      => Dealer_Versioning::get_sequence( $post_id ),
					'date'     => (string) $post->post_date,
				];
			}
		}

		// Preselezione arrivata dall'elenco documenti ("Carica nuova versione").
		// E' solo una comodita': se l'ID non e' fra quelli modificabili viene
		// semplicemente ignorato, e comunque il server rivaluta tutto.
		$preselect = absint( self::get_string( 'am_prev' ) );
		if ( $preselect && ! in_array( $preselect, $editable, true ) ) {
			$preselect = 0;
		}

		return [
			'lines_by_brand' => Dealer_Admin::allowed_product_lines( $user ),
			'doc_types'      => Dealer_Admin::get_doc_types(),
			'versionable'    => $versionable,
			'preselect'      => $preselect,
			'year'           => (int) current_time( 'Y' ),
		];
	}

	// ─── Scheda: persone ──────────────────────────────────────────────────────

	/**
	 * Organizzazioni del perimetro con i rispettivi membri, gia' normalizzate.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function collect_organizations( \WP_User $manager ): array {
		$out = [];

		foreach ( self::scope_orgs( $manager ) as $org_id ) {
			$org_id = (int) $org_id;
			if ( ! Dealer_Organization::exists( $org_id ) ) {
				continue;
			}

			$active    = Dealer_Organization::is_active( $org_id );
			$org_lines = Dealer_Organization::get_effective_lines( $org_id );
			$tier      = Dealer_Organization::get_tier( $org_id );

			$members = [];
			foreach ( Dealer_Organization::get_users( $org_id ) as $member ) {
				if ( ! $member instanceof \WP_User ) {
					continue;
				}

				$is_titolare = Dealer_Identity::is_titolare( $member );
				$limit       = get_user_meta( $member->ID, Dealer_Identity::META_LINE_LIMIT, true );
				$limit       = is_array( $limit ) ? array_values( array_filter( $limit ) ) : [];

				// Gestibile = esattamente cio' che l'handler accettera'. Cosi'
				// l'interfaccia non propone comandi che il server rifiuta.
				$manageable = ( null !== $this->resolve_target( (int) $member->ID, $manager ) );

				$members[] = [
					'id'          => (int) $member->ID,
					'name'        => (string) $member->display_name,
					'email'       => (string) $member->user_email,
					'is_titolare' => $is_titolare,
					'function'    => $is_titolare ? 'Titolare' : 'Collaboratore',
					'lines'       => Dealer_Identity::get_effective_lines( $member ),
					'limit'       => $limit,
					'restricted'  => ! empty( $limit ),
					'last_login'  => (string) get_user_meta( $member->ID, '_dealer_last_login', true ),
					'invited_at'  => (string) get_user_meta( $member->ID, Dealer_Team::META_INVITED_AT, true ),
					'manageable'  => $manageable,
				];
			}

			usort( $members, static function ( array $a, array $b ): int {
				if ( $a['is_titolare'] !== $b['is_titolare'] ) {
					return $a['is_titolare'] ? -1 : 1;
				}
				return strcasecmp( $a['name'], $b['name'] );
			} );

			$out[] = [
				'id'             => $org_id,
				'name'           => Dealer_Organization::get_name( $org_id ),
				'tier'           => $tier,
				'tier_label'     => self::TIER_LABELS[ $tier ] ?? $tier,
				'active'         => $active,
				'lines'          => $org_lines,
				'lines_by_brand' => Dealer_Team::group_lines( $org_lines ),
				'members'        => $members,
				'members_count'  => count( $members ),
				'max_members'    => Dealer_Team::MAX_MEMBERS,
				'can_invite'     => $active
					&& count( $members ) < Dealer_Team::MAX_MEMBERS
					&& ! empty( $org_lines ),
			];
		}

		usort( $out, static function ( array $a, array $b ): int {
			return strcasecmp( (string) $a['name'], (string) $b['name'] );
		} );

		return $out;
	}

	// ─── Scheda: attività ─────────────────────────────────────────────────────

	/**
	 * Riepilogo essenziale dell'attività del perimetro.
	 *
	 * Non replica la pagina statistiche dell'amministratore: servono i download
	 * dei propri documenti e pochi numeri utili a chi segue quell'area. Ogni
	 * helper di Dealer_DB accetta il filtro post_ids, che qui e' il perimetro:
	 * un insieme vuoto significa "nessun documento", mai "nessun filtro".
	 */
	private function collect_activity( \WP_User $user ): array {
		$visible = self::scoped_document_ids( $user, false );
		$since   = self::days_ago_mysql( self::ACTIVITY_WINDOW_DAYS );

		$logs = Dealer_DB::get_all_logs( [
			'post_ids' => $visible,
			'limit'    => self::LOG_LIMIT,
		] );

		return [
			'window'         => self::ACTIVITY_WINDOW_DAYS,
			'downloads_all'  => Dealer_DB::get_total_downloads( '', $visible ),
			'downloads_recent' => Dealer_DB::get_total_downloads( $since, $visible ),
			'top_documents'  => Dealer_DB::get_top_documents( 5, $since, $visible ),
			'logs'           => is_array( $logs ) ? $logs : [],
		];
	}

	/**
	 * Numeri di intestazione, comuni a tutte le schede.
	 */
	private function collect_summary( \WP_User $user ): array {
		$orgs      = self::scope_orgs( $user );
		$lines     = Dealer_Identity::get_scope_lines( $user );
		$visible   = self::scoped_document_ids( $user, false );
		$editable  = self::scoped_document_ids( $user, true );

		$people = 0;
		foreach ( $orgs as $org_id ) {
			$people += count( Dealer_Organization::get_users( (int) $org_id ) );
		}

		return [
			'orgs'      => count( $orgs ),
			'lines'     => count( $lines ),
			'documents' => count( $visible ),
			'editable'  => count( $editable ),
			'people'    => $people,
		];
	}

	// ─── Front-end: gestione invii (PRG) ──────────────────────────────────────

	/**
	 * Intercetta i POST dell'area di lavoro. Nessun output: si conclude sempre
	 * con un redirect.
	 *
	 * Ordine dei controlli — deliberato: nonce, poi autorizzazione, poi merito.
	 * Il nonce da solo non e' un permesso (chiunque ne ottiene uno valido
	 * caricando una pagina), quindi is_area_manager() e il perimetro vengono
	 * ricontrollati qui e non si fidano di nulla che arrivi dal client.
	 */
	public function maybe_handle_submission(): void {
		if ( empty( $_POST['am_action'] ) ) {
			return;
		}

		$action = sanitize_key( self::post_string( 'am_action' ) );
		if ( ! in_array( $action, [ 'invite', 'set_lines', 'deactivate' ], true ) ) {
			return;
		}

		$redirect = add_query_arg( 'am_tab', self::TAB_USERS, $this->current_url() );

		$context = $this->manager_context();
		if ( null === $context ) {
			// Messaggio unico: non diciamo a un dealer se l'endpoint esiste o
			// se gli manca il ruolo.
			$this->redirect_with_feedback( $redirect, 'error', 'Operazione non consentita.' );
		}

		$user = $context['user'];

		switch ( $action ) {
			case 'invite':
				$this->handle_invite( $user, $redirect );
				break;
			case 'set_lines':
				$this->handle_set_lines( $user, $redirect );
				break;
			case 'deactivate':
				$this->handle_deactivate( $user, $redirect );
				break;
		}
	}

	// ─── Azione: invito ───────────────────────────────────────────────────────

	/**
	 * Crea un collaboratore in un'organizzazione del perimetro e gli manda il
	 * link per impostare la password. Nessuna password in chiaro viene
	 * generata, mostrata o spedita.
	 */
	private function handle_invite( \WP_User $manager, string $redirect ): void {
		// Ricontrollo difensivo: nessun handler si fida di essere stato
		// raggiunto solo dal dispatcher.
		if ( ! Dealer_Identity::is_area_manager( $manager ) ) {
			$this->redirect_with_feedback( $redirect, 'error', 'Operazione non consentita.' );
		}

		$org_id = absint( self::post_string( 'am_org' ) );

		// Nonce contestualizzato sull'organizzazione bersaglio.
		if ( ! wp_verify_nonce( self::post_string( 'am_nonce' ), self::NONCE_INVITE . '_' . $org_id ) ) {
			$this->redirect_with_feedback( $redirect, 'error', 'La sessione del modulo è scaduta. Ricarica la pagina e riprova.' );
		}

		// L'ID arriva dal POST: non prova nulla finche' non passa di qui.
		$org_id = $this->resolve_org( $org_id, $manager, true );
		if ( ! $org_id ) {
			$this->redirect_with_feedback( $redirect, 'error', 'Organizzazione non valida o non nel tuo perimetro.' );
		}

		// Stesso tetto del titolare: un area manager non e' un canale
		// illimitato di account WordPress.
		if ( count( Dealer_Organization::get_users( $org_id ) ) >= Dealer_Team::MAX_MEMBERS ) {
			$this->redirect_with_feedback(
				$redirect,
				'error',
				sprintf( 'Questa organizzazione ha già il numero massimo di %d membri.', Dealer_Team::MAX_MEMBERS )
			);
		}

		if ( $this->invite_rate_exceeded( (int) $manager->ID ) ) {
			$this->redirect_with_feedback( $redirect, 'error', 'Hai inviato diversi inviti nell’ultima ora. Riprova più tardi.' );
		}

		$email = sanitize_email( self::post_string( 'am_email' ) );
		$name  = sanitize_text_field( self::post_string( 'am_name' ) );

		if ( '' === $email || ! is_email( $email ) ) {
			$this->redirect_with_feedback( $redirect, 'error', 'Indirizzo email non valido.' );
		}
		if ( '' === trim( $name ) ) {
			$this->redirect_with_feedback( $redirect, 'error', 'Indica il nome del collaboratore.' );
		}

		// Account gia' esistente: non lo tocchiamo. Cambiare ruolo o
		// organizzazione a un utente gia' a sistema — potenzialmente di
		// un'altra azienda, o un amministratore — sarebbe il buco piu' grosso
		// dell'intero modulo.
		if ( email_exists( $email ) ) {
			$this->redirect_with_feedback(
				$redirect,
				'error',
				'Esiste già un account con questa email. Per collegarlo a questa organizzazione scrivi all’amministratore del portale.'
			);
		}

		// Ruolo: deriva dal livello commerciale dell'ORGANIZZAZIONE, non
		// dall'input, e passa comunque da una whitelist di tre ruoli dealer.
		// Non esiste nessun percorso che produca un titolare, un area manager
		// o un amministratore.
		$role = Dealer_Organization::get_tier( $org_id );
		if ( ! in_array( $role, Dealer_Team::ALLOWED_ROLES, true ) ) {
			$role = 'dealer';
		}

		$user_id = wp_insert_user( [
			'user_login'   => $this->generate_login( $email ),
			'user_email'   => $email,
			// Password casuale mai comunicata: si imposta dal link di reset.
			'user_pass'    => wp_generate_password( 32, true, true ),
			'display_name' => $name,
			'nickname'     => $name,
			'first_name'   => $name,
			'role'         => $role,
		] );

		if ( is_wp_error( $user_id ) || ! $user_id ) {
			$this->redirect_with_feedback( $redirect, 'error', 'Non è stato possibile creare l’utente. Riprova più tardi.' );
		}
		$user_id = (int) $user_id;

		// Sempre collaboratore: la costante e' scritta qui, non arriva dal POST.
		Dealer_Identity::set_org( $user_id, $org_id, Dealer_Identity::FUNCTION_COLLABORATORE );

		// Unico canale ammesso per le linee: interseca gia' con quelle
		// dell'organizzazione, quindi non c'e' modo di concedere di piu'.
		Dealer_Identity::set_line_limit( $user_id, $this->posted_lines() );

		update_user_meta( $user_id, Dealer_Team::META_INVITED_BY, (int) $manager->ID );
		update_user_meta( $user_id, Dealer_Team::META_INVITED_AT, current_time( 'mysql' ) );
		update_user_meta( $user_id, '_referente_nome',  $name );
		update_user_meta( $user_id, '_referente_email', $email );

		$this->invite_rate_bump( (int) $manager->ID );

		$sent = $this->send_password_setup_email( $user_id, $org_id, $manager );

		/**
		 * Un area manager ha aggiunto un collaboratore a un'organizzazione del
		 * proprio perimetro.
		 *
		 * @param int $user_id    Nuovo utente.
		 * @param int $org_id     Organizzazione.
		 * @param int $manager_id Area manager che ha invitato.
		 */
		do_action( 'dealer_am_member_invited', $user_id, $org_id, (int) $manager->ID );

		$this->redirect_with_feedback(
			$redirect,
			'success',
			$sent
				? sprintf( 'Invito inviato a %s: riceverà un’email per impostare la password.', $email )
				: sprintf( 'Collaboratore creato, ma l’email a %s non è partita.', $email )
		);
	}

	// ─── Azione: limitazione delle linee ──────────────────────────────────────

	/**
	 * Restringe un collaboratore a un sottoinsieme delle linee della sua
	 * organizzazione. Nessuna casella selezionata = nessuna restrizione, che e'
	 * esattamente la semantica di get_effective_lines().
	 */
	private function handle_set_lines( \WP_User $manager, string $redirect ): void {
		if ( ! Dealer_Identity::is_area_manager( $manager ) ) {
			$this->redirect_with_feedback( $redirect, 'error', 'Operazione non consentita.' );
		}

		$target_id = absint( self::post_string( 'am_user' ) );

		if ( ! wp_verify_nonce( self::post_string( 'am_nonce' ), self::NONCE_LINES . '_' . $target_id ) ) {
			$this->redirect_with_feedback( $redirect, 'error', 'La sessione del modulo è scaduta. Ricarica la pagina e riprova.' );
		}

		$target = $this->resolve_target( $target_id, $manager, true );
		if ( null === $target ) {
			$this->redirect_with_feedback( $redirect, 'error', 'Collaboratore non valido o fuori dal tuo perimetro.' );
		}

		// set_line_limit() filtra da solo contro le linee dell'organizzazione:
		// una linea non posseduta sparisce anche se arriva dal POST.
		Dealer_Identity::set_line_limit( (int) $target->ID, $this->posted_lines() );

		$effective = Dealer_Identity::get_effective_lines( $target );

		do_action( 'dealer_am_member_limited', (int) $target->ID, Dealer_Identity::get_org_id( $target ), (int) $manager->ID );

		$this->redirect_with_feedback(
			$redirect,
			'success',
			sprintf( 'Linee aggiornate per %s: %d linee accessibili.', $target->display_name, count( $effective ) )
		);
	}

	// ─── Azione: disattivazione ───────────────────────────────────────────────

	/**
	 * Disattiva un collaboratore che ha lasciato l'azienda.
	 *
	 * NON cancella l'account WordPress: il registro dei download e' legato allo
	 * user ID e la tracciabilita' e' il motivo per cui questo portale esiste.
	 * Si toglie l'utente dall'organizzazione e gli si revoca il ruolo dealer,
	 * che e' il cancello vero (Dealer_Search::user_is_dealer()).
	 */
	private function handle_deactivate( \WP_User $manager, string $redirect ): void {
		if ( ! Dealer_Identity::is_area_manager( $manager ) ) {
			$this->redirect_with_feedback( $redirect, 'error', 'Operazione non consentita.' );
		}

		$target_id = absint( self::post_string( 'am_user' ) );

		if ( ! wp_verify_nonce( self::post_string( 'am_nonce' ), self::NONCE_DEACTIVATE . '_' . $target_id ) ) {
			$this->redirect_with_feedback( $redirect, 'error', 'La sessione del modulo è scaduta. Ricarica la pagina e riprova.' );
		}

		$target = $this->resolve_target( $target_id, $manager, true );
		if ( null === $target ) {
			$this->redirect_with_feedback( $redirect, 'error', 'Collaboratore non valido o fuori dal tuo perimetro.' );
		}

		$name   = (string) $target->display_name;
		$org_id = Dealer_Identity::get_org_id( $target );

		// 1. Fuori dall'organizzazione: cadono con essa linee e funzione.
		delete_user_meta( $target->ID, Dealer_Identity::META_ORG );
		delete_user_meta( $target->ID, Dealer_Identity::META_FUNCTION );
		delete_user_meta( $target->ID, Dealer_Identity::META_LINE_LIMIT );

		// 2. Revoca dei soli ruoli dealer: l'account resta, con i suoi log.
		//    Un ruolo alla volta, per non toccare eventuali ruoli non nostri.
		foreach ( Dealer_Team::ALLOWED_ROLES as $role ) {
			if ( in_array( $role, (array) $target->roles, true ) ) {
				$target->remove_role( $role );
			}
		}

		// 3. Traccia della disattivazione, per l'amministratore.
		update_user_meta( $target->ID, Dealer_Team::META_DEACTIVATED_BY, (int) $manager->ID );
		update_user_meta( $target->ID, Dealer_Team::META_DEACTIVATED_AT, current_time( 'mysql' ) );

		/**
		 * Collaboratore disattivato da un area manager. L'account WordPress
		 * esiste ancora: chi si aggancia qui non dia per scontato il contrario.
		 */
		do_action( 'dealer_am_member_deactivated', (int) $target->ID, $org_id, (int) $manager->ID );

		$this->redirect_with_feedback(
			$redirect,
			'success',
			sprintf( '%s non ha più accesso all’area riservata. Lo storico dei suoi download resta consultabile.', $name )
		);
	}

	// ─── Rate limit inviti ────────────────────────────────────────────────────

	private function invite_rate_key( int $manager_id ): string {
		return 'am_inv_rl_' . $manager_id;
	}

	private function invite_rate_exceeded( int $manager_id ): bool {
		return (int) get_transient( $this->invite_rate_key( $manager_id ) ) >= self::INVITE_RATE_MAX;
	}

	private function invite_rate_bump( int $manager_id ): void {
		$key   = $this->invite_rate_key( $manager_id );
		$count = (int) get_transient( $key );
		set_transient( $key, $count + 1, self::INVITE_RATE_WINDOW );
	}

	// ─── Email di invito ──────────────────────────────────────────────────────

	/**
	 * Link per impostare la password con il meccanismo nativo (chiave di reset),
	 * stesso pattern di Dealer_Team. Nessuna password in chiaro.
	 */
	private function send_password_setup_email( int $user_id, int $org_id, \WP_User $manager ): bool {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return false;
		}

		$key = get_password_reset_key( $user );
		if ( is_wp_error( $key ) ) {
			// Fallback sul meccanismo nativo di WordPress (anch'esso a chiave
			// di reset, mai una password in chiaro).
			wp_new_user_notification( $user_id, null, 'user' );
			return true;
		}

		$reset_url = network_site_url(
			'wp-login.php?action=rp&key=' . rawurlencode( $key ) . '&login=' . rawurlencode( $user->user_login ),
			'login'
		);

		$site_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$dashboard = Dealer_DB::dashboard_url();
		$org_name  = Dealer_Organization::get_name( $org_id );
		$lines     = Dealer_Identity::get_effective_lines( $user );
		$line_list = $lines
			? implode( "\n  - ", array_map( [ 'Dealer_Team', 'line_label' ], $lines ) )
			: '—';

		$subject = sprintf( '[%s] Sei stato aggiunto all’area riservata', $site_name );

		$body  = 'Ciao ' . $user->display_name . ",\n\n";
		$body .= $manager->display_name . ' ti ha aggiunto all’area riservata di ' . $site_name;
		$body .= '' !== $org_name ? ' per conto di ' . $org_name . ".\n\n" : ".\n\n";
		$body .= 'Nome utente: ' . $user->user_login . "\n\n";
		$body .= "Imposta la tua password da questo link (valido per un tempo limitato):\n";
		$body .= $reset_url . "\n\n";
		$body .= "Dopo aver impostato la password potrai accedere qui:\n" . $dashboard . "\n\n";
		$body .= "Linee prodotto abilitate:\n  - " . $line_list . "\n\n";
		$body .= "Se non ti aspettavi questa email, ignorala.\n";

		if ( wp_mail( $user->user_email, $subject, $body ) ) {
			return true;
		}

		wp_new_user_notification( $user_id, null, 'user' );
		return false;
	}

	/** Username univoco derivato dall'email, come in Dealer_Team. */
	private function generate_login( string $email ): string {
		$parts = explode( '@', $email );
		$base  = sanitize_user( $parts[0], true );
		$base  = trim( preg_replace( '/[^A-Za-z0-9._\-]/', '', $base ) ?? '' );
		if ( '' === $base ) {
			$base = 'dealer';
		}
		$base  = substr( $base, 0, 40 );
		$login = $base;
		$i     = 1;
		while ( username_exists( $login ) && $i < 1000 ) {
			$login = $base . $i;
			$i++;
		}
		return $login;
	}

	// ─── PRG: feedback via transient monouso ──────────────────────────────────

	/**
	 * Salva il feedback in un transient e redirige: in query string finisce solo
	 * un token opaco, mai un'email o un ID.
	 */
	private function redirect_with_feedback( string $url, string $status, string $message ): void {
		$token = md5( wp_generate_password( 32, true, true ) . microtime( true ) );
		set_transient( 'am_fb_' . $token, [
			'status'  => ( 'success' === $status ) ? 'success' : 'error',
			'message' => $message,
		], 10 * MINUTE_IN_SECONDS );

		wp_safe_redirect( add_query_arg( 'am_ref', $token, $url ) . '#dealer-am' );
		exit;
	}

	/** Legge e cancella il feedback (monouso). */
	private function consume_feedback(): array {
		$token = sanitize_key( self::get_string( 'am_ref' ) );
		if ( '' === $token ) {
			return [];
		}
		$feedback = get_transient( 'am_fb_' . $token );
		delete_transient( 'am_fb_' . $token );

		return is_array( $feedback ) ? $feedback : [];
	}

	/**
	 * URL corrente senza i parametri gia' consumati.
	 *
	 * Nessun admin_url() qui: la navigazione di quest'area vive interamente sul
	 * front-end (vedi il docblock della classe).
	 */
	private function current_url(): string {
		$permalink = is_singular() ? get_permalink() : '';

		if ( ! $permalink ) {
			$page_url = Dealer_DB::area_manager_url();
			if ( $page_url ) {
				$permalink = $page_url;
			} else {
				$permalink = home_url( '/' );
			}
		}

		return remove_query_arg( [ 'am_ref', 'am_prev' ], $permalink );
	}

	// ─── Utility ──────────────────────────────────────────────────────────────

	/**
	 * Minuscolo per il confronto di ricerca. mbstring non e' garantita su ogni
	 * installazione: senza, si ricade su strtolower() e la ricerca resta
	 * corretta sull'ASCII.
	 */
	private static function lower( string $text ): string {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
	}

	/** Data MySQL di N giorni fa, in ora locale (come i log). */
	private static function days_ago_mysql( int $days ): string {
		$now = (int) current_time( 'timestamp' );
		return gmdate( 'Y-m-d H:i:s', $now - ( max( 0, $days ) * DAY_IN_SECONDS ) );
	}

	/** Etichetta leggibile di una linea "Brand|Linea". */
	public static function line_label( string $line ): string {
		return Dealer_Team::line_label( $line );
	}

	/** Data leggibile, con trattino se assente. */
	public static function format_datetime( string $mysql_date ): string {
		return Dealer_Team::format_datetime( $mysql_date );
	}

	/**
	 * Linee arrivate dal POST, solo sanificate.
	 *
	 * NON e' un'autorizzazione: il filtro contro le linee dell'organizzazione lo
	 * fa Dealer_Identity::set_line_limit(), unico punto di verita'.
	 *
	 * @return string[]
	 */
	private function posted_lines(): array {
		if ( ! isset( $_POST['am_lines'] ) || ! is_array( $_POST['am_lines'] ) ) {
			return [];
		}
		$raw = (array) wp_unslash( $_POST['am_lines'] );
		return array_values( array_filter( array_map( 'sanitize_text_field', $raw ) ) );
	}

	private static function post_string( string $key ): string {
		return isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] )
			? (string) wp_unslash( $_POST[ $key ] )
			: '';
	}

	private static function get_string( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return isset( $_GET[ $key ] ) && is_scalar( $_GET[ $key ] )
			? (string) wp_unslash( $_GET[ $key ] )
			: '';
	}

	/** Avviso semplice, con eventuale collegamento. */
	private function notice( string $message, string $url = '', string $label = '' ): string {
		$html = '<div class="dealer-am-notice">' . esc_html( $message );
		if ( '' !== $url && '' !== $label ) {
			$html .= ' <a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
		}
		$html .= '</div>';
		$html .= '<style>.dealer-am-notice{margin:24px 0;padding:16px 20px;background:#f4f6f8;'
			. 'border:1px solid #dce5ea;border-left:4px solid #1e6fa8;border-radius:8px;color:#1a2535;}</style>';

		return $html;
	}
}
