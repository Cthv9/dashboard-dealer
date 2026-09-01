<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Dealer_DB {

	// ─── URL delle pagine del plugin ────────────────────────────────────────
	//
	// Le due pagine create da create_pages() vanno sempre raggiunte tramite
	// l'ID salvato in opzione + get_permalink(), MAI con un percorso fisso
	// come site_url('/dealer-search/'). Un percorso fisso presuppone che lo
	// slug reale coincida esattamente con quello previsto: si rompe con
	// permalink non "nome articolo", un'installazione in sottocartella, un
	// amministratore che rinomina la pagina, o — il caso più comune in
	// sviluppo — un secondo inserimento con slug suffisso (-2, -3…) dopo
	// riattivazioni ripetute del plugin. Questo è l'unico punto che risolve
	// questi URL: nessun altro punto del plugin deve costruirli a mano.

	/** URL della pagina Dashboard Dealer, risolto dall'ID pagina reale. */
	public static function dashboard_url(): string {
		return self::resolve_page_url( 'dealer_portal_dashboard_page_id', '/dashboard-dealer/' );
	}

	/** URL della pagina Cerca Documenti, risolto dall'ID pagina reale. */
	public static function search_url(): string {
		return self::resolve_page_url( 'dealer_portal_search_page_id', '/dealer-search/' );
	}

	/** URL dell'area di gestione collaboratori del titolare. */
	public static function team_url(): string {
		return self::resolve_page_url( 'dealer_portal_team_page_id', '/dealer-team/' );
	}

	/** URL dell'area di lavoro dell'area manager. */
	public static function area_manager_url(): string {
		return self::resolve_page_url( 'dealer_portal_am_page_id', '/dealer-area-manager/' );
	}

	/** URL della pagina Preferiti. */
	public static function favorites_url(): string {
		return self::resolve_page_url( 'dealer_portal_fav_page_id', '/dealer-preferiti/' );
	}

	/**
	 * Legge l'ID salvato in opzione e ne risolve il permalink attuale.
	 * Il percorso fisso resta solo come ultima risorsa, se la pagina non
	 * esiste più o l'opzione non è mai stata popolata.
	 */
	private static function resolve_page_url( string $option, string $fallback_path ): string {
		$page_id = (int) get_option( $option );
		// La pagina deve essere PUBBLICATA: get_permalink() restituisce
		// volentieri un URL anche per una bozza o un elemento nel cestino, e
		// quell'URL da' 404 a chiunque non possa modificare il contenuto —
		// cioe' esattamente ai dealer. Verificare l'ID non basta.
		if ( $page_id && 'publish' === get_post_status( $page_id ) ) {
			$permalink = get_permalink( $page_id );
			if ( $permalink ) {
				return $permalink;
			}
		}
		return home_url( $fallback_path );
	}

	// ─── Activation ──────────────────────────────────────────────────────────

	public static function install(): void {
		self::create_log_table();
		// L'attivazione allinea di per sé lo schema e le pagine: registrare le
		// revisioni evita che maybe_upgrade() rifaccia subito lo stesso lavoro.
		update_option( 'dealer_portal_schema_revision', self::SCHEMA_REVISION );
		self::create_pages();
		update_option( 'dealer_portal_pages_revision', self::PAGES_REVISION );
		self::create_protected_upload_dir();
		self::setup_capability();
		update_option( 'dealer_portal_version', DEALER_PORTAL_VERSION );
	}

	/**
	 * Runs on plugins_loaded every time the stored version is behind the
	 * current one, ensuring upgrade paths don't rely on reactivation.
	 */
	public static function maybe_upgrade(): void {
		// Le capability hanno un contatore di revisione proprio (CAPS_REVISION):
		// vanno riparate anche quando la versione del plugin non cambia, perché
		// aggiungerne una nuova al codice non basta a darla ai ruoli esistenti.
		// Non esce mai sul solo contatore: riconcilia davvero, perché se le
		// capability spariscono l'amministratore perde le schermate con cui
		// potrebbe rimediare (vedi il commento in setup_capability()).
		self::setup_capability();

		// L'avviso vive qui perché setup_capability() gira su 'plugins_loaded',
		// prima di 'admin_notices'.
		if ( is_admin() ) {
			add_action( 'admin_notices', [ __CLASS__, 'maybe_notice_caps_repaired' ] );
		}

		// Stessa logica per lo schema della tabella di log: create_log_table()
		// gira solo all'attivazione, quindi un'installazione che si limita ad
		// aggiornare i file non vedrebbe mai una colonna nuova.
		self::maybe_upgrade_schema();

		// E per le pagine: senza questo, un plugin aggiornato sostituendo i
		// file (il modo normale in cui si aggiorna un sito reale, senza
		// disattivare e riattivare) non crea mai le pagine introdotte in una
		// versione successiva alla prima installazione.
		self::maybe_upgrade_pages();

		if ( get_option( 'dealer_portal_version' ) === DEALER_PORTAL_VERSION ) {
			return;
		}
		self::create_protected_upload_dir();
		update_option( 'dealer_portal_version', DEALER_PORTAL_VERSION );
	}

	/**
	 * Revisione dello schema della tabella di log: va incrementata ogni volta
	 * che la definizione in create_log_table() cambia. È l'equivalente di
	 * CAPS_REVISION per il database.
	 *
	 * 1 = colonna access_context (titolo con cui è avvenuto il download).
	 */
	const SCHEMA_REVISION = 1;

	/**
	 * Applica lo schema corrente se l'installazione è indietro.
	 * Idempotente: dbDelta() confronta la struttura reale con quella dichiarata
	 * e si limita alle differenze, quindi aggiunge la colonna mancante senza
	 * toccare le righe già presenti.
	 */
	private static function maybe_upgrade_schema(): void {
		if ( (int) get_option( 'dealer_portal_schema_revision' ) === self::SCHEMA_REVISION ) {
			return;
		}

		self::create_log_table();
		update_option( 'dealer_portal_schema_revision', self::SCHEMA_REVISION );
	}

	/**
	 * Revisione dell'elenco delle pagine create in automatico: va incrementata
	 * ogni volta che create_pages() guadagna una voce. Stesso principio di
	 * CAPS_REVISION e SCHEMA_REVISION.
	 *
	 * create_pages() girava solo in install(), cioè solo all'attivazione: su
	 * un sito reale un plugin si aggiorna quasi sempre sostituendo i file
	 * senza disattivare e riattivare, quindi una pagina aggiunta in una
	 * versione successiva (qui: Gestione Collaboratori e Area Manager) non
	 * veniva mai creata. Chi ci contava — il titolare, l'area manager — si
	 * trovava un 404 e nessun modo di capire perché, perché non era stato
	 * commesso alcun errore da parte loro: mancava solo la pagina.
	 *
	 * 1 = Dashboard Dealer, Cerca Documenti (dalla release iniziale).
	 * 2 = Gestione Collaboratori, Area Manager.
	 * 3 = Preferiti.
	 */
	const PAGES_REVISION = 3;

	/**
	 * Crea le pagine mancanti anche su un'installazione già attiva.
	 * create_pages() è già idempotente (adotta per slug, non duplica), quindi
	 * richiamarla di nuovo su un sito che le ha già tutte non ha alcun
	 * effetto collaterale.
	 */
	private static function maybe_upgrade_pages(): void {
		if ( (int) get_option( 'dealer_portal_pages_revision' ) === self::PAGES_REVISION ) {
			return;
		}

		self::create_pages();
		update_option( 'dealer_portal_pages_revision', self::PAGES_REVISION );
	}

	private static function create_log_table(): void {
		global $wpdb;

		$table           = $wpdb->prefix . 'dealer_download_log';
		$charset_collate = $wpdb->get_charset_collate();

		// dbDelta() crea la tabella se non esiste; se esiste verifica e aggiorna la struttura.
		//
		// access_context: con quale titolo è avvenuto il download (admin,
		// area_manager, dealer). Le righe scritte prima di questa colonna
		// restano a stringa vuota: non sappiamo con che titolo furono
		// scaricate, e inventarlo a posteriori con un UPDATE massivo
		// falsificherebbe il registro. La visualizzazione tratta il vuoto come
		// "dealer", che era l'unico caso possibile prima dell'area manager.
		$sql = "CREATE TABLE {$table} (
			id            BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id       BIGINT(20) UNSIGNED NOT NULL,
			post_id       BIGINT(20) UNSIGNED NOT NULL,
			download_date DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
			ip_address    VARCHAR(45)         NOT NULL DEFAULT '',
			access_context VARCHAR(20)        NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY user_id  (user_id),
			KEY post_id  (post_id),
			KEY download_date (download_date)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Crea automaticamente le due pagine WordPress necessarie al plugin,
	 * se non esistono già. Sicuro da richiamare più volte (idempotente).
	 */
	/**
	 * Elenco delle pagine create in automatico. Aggiungerne una nuova al
	 * codice qui non basta a farla comparire su un'installazione già attiva:
	 * vedi PAGES_REVISION più sotto.
	 */
	private static function create_pages(): void {
		$pages = [
			[
				'title'     => 'Dashboard Dealer',
				'slug'      => 'dashboard-dealer',
				'shortcode' => '[dealer_dashboard]',
				'option'    => 'dealer_portal_dashboard_page_id',
			],
			[
				'title'     => 'Cerca Documenti',
				'slug'      => 'dealer-search',
				'shortcode' => '[dealer_search]',
				'option'    => 'dealer_portal_search_page_id',
			],
			// Aree operative dei ruoli con deleghe. Vengono create in automatico
			// come le altre: se dovessero essere costruite a mano, dimenticarne
			// una farebbe sparire la funzione senza alcun errore visibile —
			// il titolare semplicemente non vedrebbe la card, l'area manager
			// resterebbe senza un posto dove lavorare.
			[
				'title'     => 'Gestione Collaboratori',
				'slug'      => 'dealer-team',
				'shortcode' => '[dealer_team]',
				'option'    => 'dealer_portal_team_page_id',
			],
			[
				'title'     => 'Area Manager',
				'slug'      => 'dealer-area-manager',
				'shortcode' => '[dealer_area_manager]',
				'option'    => 'dealer_portal_am_page_id',
			],
			[
				'title'     => 'Preferiti',
				'slug'      => 'dealer-preferiti',
				'shortcode' => '[dealer_favorites]',
				'option'    => 'dealer_portal_fav_page_id',
			],
		];

		foreach ( $pages as $page ) {
			// Controlla se la pagina esiste già (per slug). Adottiamo solo una
			// pagina PUBBLICATA: get_page_by_path() restituisce anche bozze e
			// pagine in attesa di revisione, e adottarne una lascerebbe i
			// dealer davanti a un 404 con l'opzione apparentemente a posto.
			$existing = get_page_by_path( $page['slug'], OBJECT, 'page' );
			if ( $existing && 'publish' === get_post_status( $existing ) ) {
				update_option( $page['option'], $existing->ID );
				continue;
			}

			// Controlla se l'ID salvato in precedenza punta a una pagina ancora
			// pubblicata. get_post_status() e' vero anche per 'draft' e 'trash':
			// senza il confronto esplicito, una pagina cestinata verrebbe
			// considerata valida e non ne verrebbe creata una nuova.
			$saved_id = (int) get_option( $page['option'] );
			if ( $saved_id && 'publish' === get_post_status( $saved_id ) ) {
				continue;
			}

			// Crea la pagina.
			$page_id = wp_insert_post( [
				'post_title'   => $page['title'],
				'post_name'    => $page['slug'],
				'post_content' => $page['shortcode'],
				'post_status'  => 'publish',
				'post_type'    => 'page',
				'post_author'  => get_current_user_id() ?: 1,
			] );

			if ( ! is_wp_error( $page_id ) ) {
				update_option( $page['option'], $page_id );
			}
		}
	}

	private static function create_protected_upload_dir(): void {
		$uploads  = wp_upload_dir();
		$dir      = $uploads['basedir'] . '/dealer-docs';

		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		// ── Difesa 1: Apache ──────────────────────────────────────────────
		$htaccess = $dir . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			// Blocca l'accesso HTTP diretto. I file vengono serviti via PHP (readfile).
			$rules = "# Apache 2.4\n"
				   . "Require all denied\n"
				   . "# Apache 2.2 fallback\n"
				   . "<IfModule !mod_authz_core.c>\n"
				   . "    Deny from all\n"
				   . "</IfModule>\n";
			file_put_contents( $htaccess, $rules );
		}

		// ── Difesa 2: IIS ─────────────────────────────────────────────────
		$webconfig = $dir . '/web.config';
		if ( ! file_exists( $webconfig ) ) {
			$xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
				 . "<configuration>\n"
				 . "  <system.webServer>\n"
				 . "    <authorization>\n"
				 . "      <deny users=\"*\" />\n"
				 . "    </authorization>\n"
				 . "  </system.webServer>\n"
				 . "</configuration>\n";
			file_put_contents( $webconfig, $xml );
		}

		// ── Difesa 3: nessun listing della cartella ───────────────────────
		$index = $dir . '/index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, '<?php // Silence is golden.' );
		}

		// ── Difesa 4, quella che regge ovunque ────────────────────────────
		// Le tre difese sopra dipendono dal server: nginx ignora .htaccess,
		// Apache ignora web.config, e nessuna delle due impedisce l'accesso
		// diretto dove non è configurata. Poiché il plugin viene consegnato
		// senza sapere su cosa girerà, la protezione che DEVE reggere è
		// un'altra: i nomi dei file su disco contengono un token casuale
		// (vedi Dealer_Admin::storage_filename()), quindi l'URL diretto non è
		// indovinabile su nessun server. Le regole qui sopra restano come
		// difesa in profondità dove il server le onora.
	}

	/**
	 * Il server in uso onora i file .htaccess?
	 *
	 * Euristica volutamente prudente: risponde false solo quando riconosce con
	 * certezza un server che li ignora. Serve a mostrare un avviso a chi
	 * installa il plugin, non a decidere una misura di sicurezza — quella non
	 * dipende mai da questa risposta.
	 */
	public static function server_honours_htaccess(): bool {
		$software = isset( $_SERVER['SERVER_SOFTWARE'] )
			? strtolower( sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) )
			: '';

		if ( '' === $software ) {
			return true; // Sconosciuto: non allarmiamo senza motivo.
		}

		foreach ( [ 'nginx', 'iis', 'microsoft-iis', 'caddy', 'lighttpd' ] as $needle ) {
			if ( false !== strpos( $software, $needle ) ) {
				return false;
			}
		}

		return true;
	}

	/** Percorso assoluto della cartella protetta dei documenti. */
	public static function protected_dir(): string {
		$uploads = wp_upload_dir();
		return $uploads['basedir'] . '/dealer-docs';
	}

	/**
	 * Distribuzione delle capability ai ruoli.
	 *
	 * Mappa esplicita: nessuna capability ne implica un'altra, quindi
	 * l'amministratore le riceve tutte una per una. Se domani si aggiungesse
	 * una capability e la si assegnasse solo all'area manager dimenticando
	 * l'amministratore, quest'ultimo ne resterebbe fuori: la tabella qui sotto
	 * è l'unico posto in cui guardare.
	 *
	 * @return array<string, string[]> ruolo => capability
	 */
	private static function capability_map(): array {
		return [
			'administrator' => array_merge(
				[
					DEALER_PORTAL_CAP,        // Controllo completo.
					DEALER_PORTAL_CAP_UPLOAD,
					DEALER_PORTAL_CAP_LOGS,
					DEALER_PORTAL_CAP_ORGS,
				],
				// Capability proprie del CPT documenti: senza queste nessuno,
				// nemmeno l'amministratore, aprirebbe l'editor nativo di un
				// documento. Vanno al solo amministratore — l'area manager
				// lavora dalle schermate del plugin, che applicano il controllo
				// di perimetro che l'editor nativo non ha.
				Dealer_CPT::primitive_caps()
			),
			'area_manager'  => [
				DEALER_PORTAL_CAP_UPLOAD, // Carica e versiona, dentro il proprio perimetro.
				DEALER_PORTAL_CAP_LOGS,   // Legge log e statistiche.
				// NON riceve DEALER_PORTAL_CAP (eliminazioni, richieste di
				// accesso, notifiche), DEALER_PORTAL_CAP_ORGS, né le capability
				// del CPT: l'editor nativo di WordPress aggirerebbe il perimetro.
			],
		];
	}

	/**
	 * Revisione della mappa: va incrementata ogni volta che capability_map()
	 * cambia. È ciò che rende l'assegnazione auto-riparante anche quando la
	 * versione del plugin non cambia — senza, un'installazione già aggiornata
	 * non riceverebbe mai le capability nuove.
	 */
	const CAPS_REVISION = 3;

	/**
	 * Idempotente: aggiunge solo ciò che manca e non tocca nulla se la mappa
	 * corrente è già stata applicata. Richiamata sia all'attivazione sia da
	 * maybe_upgrade() a ogni caricamento.
	 */
	/**
	 * Un amministratore di WordPress ha SEMPRE le capability del plugin, a
	 * runtime, senza dipendere da cosa c'e' scritto nel ruolo sul database.
	 *
	 * Perche' non basta assegnarle al ruolo: assegnarle e' una scrittura una
	 * tantum, e qualunque cosa le tolga dopo (un plugin di gestione ruoli che
	 * riscrive il ruolo administrator a ogni caricamento, un ripristino
	 * parziale, una copia fra ambienti, una sincronizzazione) le fa sparire
	 * senza lasciare traccia. Il sintomo e' spietato: add_submenu_page()
	 * restituisce false quando la capability non passa, quindi le voci di menu
	 * spariscono in silenzio — e spariscono proprio quelle da cui si
	 * rimedierebbe. E' successo davvero, su un'installazione di collaudo, e
	 * riassegnare le capability al ruolo non e' bastato: venivano tolte di
	 * nuovo prima che il menu si costruisse.
	 *
	 * Questo filtro non scrive niente da nessuna parte: risponde alla domanda
	 * "questo utente puo'?" nel momento in cui viene posta. Chi e'
	 * amministratore di WordPress (manage_options) e' per definizione titolare
	 * di tutte le capability di questo plugin — lo dice gia' capability_map() —
	 * quindi qui non si concede nulla di nuovo, si rende solo la stessa regola
	 * indipendente dallo stato del database.
	 *
	 * Il ruolo area_manager continua invece ad avere le proprie capability
	 * scritte davvero: non e' amministratore e non passa da qui.
	 *
	 * @param array $allcaps Capability risolte dell'utente.
	 * @return array
	 */
	/**
	 * "Puo' fare questa cosa?", con la regola che nel progetto e' sempre stata
	 * implicita e che va detta a voce alta: l'amministratore di WordPress e'
	 * sopra ogni ruolo del portale, area manager compreso.
	 *
	 * grant_admin_caps() gliele concede gia' a runtime, ma quel filtro puo'
	 * essere scavalcato da terzi (e su un sito reale lo e' stato). Questo
	 * controllo non passa da nessun filtro sulle nostre capability: chi
	 * amministra WordPress entra, punto. Ogni schermata riservata del plugin
	 * passa di qui invece di interrogare current_user_can() da sola.
	 */
	public static function user_can( string $cap ): bool {
		return current_user_can( $cap ) || current_user_can( 'manage_options' );
	}

	public static function grant_admin_caps( $allcaps ) {
		// Registrato a priorita' PHP_INT_MAX: la diagnostica su un sito reale ha
		// mostrato il ruolo administrator CON le capability e l'utente
		// administrator SENZA — cioe' un altro plugin che le nega a runtime con
		// questo stesso filtro. Chi risponde per ultimo vince: per le capability
		// di questo plugin l'ultima parola deve essere del plugin.
		if ( ! is_array( $allcaps ) || empty( $allcaps['manage_options'] ) ) {
			return $allcaps;
		}

		foreach ( [ DEALER_PORTAL_CAP, DEALER_PORTAL_CAP_UPLOAD, DEALER_PORTAL_CAP_LOGS, DEALER_PORTAL_CAP_ORGS ] as $cap ) {
			$allcaps[ $cap ] = true;
		}

		foreach ( Dealer_CPT::primitive_caps() as $cap ) {
			$allcaps[ $cap ] = true;
		}

		return $allcaps;
	}

	/**
	 * Alcuni plugin mappano le capability che non conoscono su 'do_not_allow',
	 * che in WP_User::has_cap() scavalca QUALUNQUE filtro user_has_cap: il
	 * rifiuto e' deciso prima ancora di chiedere. Per le quattro capability di
	 * questo plugin la mappatura la decidiamo noi: identita', come da default
	 * di WordPress per le capability primitive.
	 *
	 * Limitato alle quattro del portale e non alle capability del CPT: quelle
	 * passano dalla mappatura di WordPress per i post type, che un
	 * 'do_not_allow' puo' usarlo legittimamente.
	 *
	 * @param array  $caps Capability primitive richieste.
	 * @param string $cap  Capability richiesta in origine.
	 * @return array
	 */
	public static function unmap_do_not_allow( $caps, $cap ) {
		$ours = [ DEALER_PORTAL_CAP, DEALER_PORTAL_CAP_UPLOAD, DEALER_PORTAL_CAP_LOGS, DEALER_PORTAL_CAP_ORGS ];

		if ( in_array( $cap, $ours, true ) && is_array( $caps ) && in_array( 'do_not_allow', $caps, true ) ) {
			return [ $cap ];
		}

		return $caps;
	}

	private static function setup_capability(): void {
		// NESSUNA uscita anticipata sul contatore di revisione.
		//
		// Il contatore dice "questa mappa e' gia' stata applicata una volta",
		// non "le capability ci sono adesso". Se per qualunque ragione
		// spariscono — un ruolo ricreato, un plugin di gestione ruoli, un
		// ripristino parziale del database, una copia fra ambienti — succede
		// questo: l'amministratore perde manage_dealer_portal e
		// manage_dealer_orgs, e con esse spariscono dal menu proprio le
		// schermate da cui si potrebbe rimediare (Organizzazioni, Area Manager,
		// Ruoli e Linee). Un vicolo cieco perfetto, per giunta silenzioso:
		// WordPress nasconde le voci di menu per cui manca la capability, non
		// le segnala. Uscire qui sul contatore rendeva quello stato definitivo.
		//
		// Riconciliare a ogni caricamento non costa nulla: i ruoli sono gia' in
		// memoria (wp_roles() li carica una volta per richiesta), has_cap() e'
		// una lettura di array, e add_cap() tocca il database solo quando manca
		// davvero qualcosa.
		$already_applied = ( (int) get_option( 'dealer_portal_caps_revision' ) === self::CAPS_REVISION );

		$complete = true;
		$repaired = [];

		foreach ( self::capability_map() as $role_name => $caps ) {
			$role = get_role( $role_name );

			// Il ruolo area_manager è creato da Dealer_Roles su 'init', mentre
			// maybe_upgrade() gira su 'plugins_loaded'. Se non c'è ancora non
			// registriamo la revisione: al caricamento successivo si riprova.
			if ( ! $role ) {
				$complete = false;
				continue;
			}

			foreach ( $caps as $cap ) {
				if ( ! $role->has_cap( $cap ) ) {
					$role->add_cap( $cap );
					$repaired[] = $role_name . ' -> ' . $cap;
				}
			}
		}

		if ( $complete ) {
			update_option( 'dealer_portal_caps_revision', self::CAPS_REVISION );
		}

		// Riparare al primo giro e' la normalita' (installazione o
		// aggiornamento). Riparare quando la revisione risultava GIA' applicata
		// significa che qualcosa le aveva tolte: l'amministratore ha appena
		// riavuto voci di menu che non vedeva, e va detto invece di lasciarlo
		// pensare che siano comparse da sole.
		if ( $repaired && $already_applied ) {
			set_transient( 'dealer_portal_caps_repaired', $repaired, 5 * MINUTE_IN_SECONDS );
		}
	}

	/**
	 * Avviso quando le capability sono state ripristinate a posteriori.
	 * Vedi setup_capability() per il perche' non basta il contatore.
	 */
	public static function maybe_notice_caps_repaired(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$repaired = get_transient( 'dealer_portal_caps_repaired' );
		if ( ! is_array( $repaired ) || empty( $repaired ) ) {
			return;
		}
		delete_transient( 'dealer_portal_caps_repaired' );

		printf(
			'<div class="notice notice-warning is-dismissible"><p><strong>Dealer Portal:</strong> '
			. 'mancavano %d permessi del plugin e sono stati ripristinati automaticamente. '
			. 'Se nel menu non vedevi <em>Organizzazioni</em>, <em>Area Manager</em>, <em>Ruoli e Linee</em>, '
			. '<em>Notifiche</em> o <em>Richieste Accesso</em>, ricarica la pagina: adesso ci sono.</p></div>',
			(int) count( $repaired )
		);
	}

	// ─── Write ───────────────────────────────────────────────────────────────

	public static function log_download( int $post_id ): void {
		global $wpdb;

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return;
		}

		// Recupera IP reale, gestisce proxy (solo primo IP della catena).
		$ip = '';
		if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$parts = explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) );
			$ip    = trim( $parts[0] );
		} elseif ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}

		// Scarta IP non validi (prevenzione log injection).
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			$ip = '';
		}

		// Titolo con cui l'utente sta scaricando: un download fatto da un area
		// manager non è un download di rete e non deve confondersi con quello
		// di un dealer nelle statistiche e negli export. Si risolve qui, dal
		// solo utente corrente, così ogni call site lo registra senza doverlo
		// sapere.
		$context = '';
		if ( class_exists( 'Dealer_Identity' ) ) {
			$user = wp_get_current_user();
			if ( $user instanceof \WP_User && $user->exists() ) {
				$context = Dealer_Identity::get_access_context( $user );
			}
		}

		$result = $wpdb->insert(
			$wpdb->prefix . 'dealer_download_log',
			[
				'user_id'        => $user_id,
				'post_id'        => $post_id,
				'download_date'  => current_time( 'mysql' ),
				'ip_address'     => $ip,
				'access_context' => $context,
			],
			[ '%d', '%d', '%s', '%s', '%s' ]
		);

		if ( false === $result ) {
			error_log( sprintf(
				'[Dealer Portal] log_download() failed for user_id=%d post_id=%d — DB error: %s',
				$user_id,
				$post_id,
				$wpdb->last_error
			) );
		}
	}

	/**
	 * Etichetta leggibile del titolo di accesso registrato nel log.
	 *
	 * Il valore vuoto sono le righe scritte prima dell'introduzione della
	 * colonna: allora scaricava solo la rete dealer, quindi è quello il titolo
	 * che mostriamo — senza però riscriverlo nel database, perché resta un
	 * dato che non abbiamo mai raccolto.
	 */
	public static function access_context_label( string $context ): string {
		switch ( $context ) {
			case 'admin':
				return 'Amministratore';
			case 'area_manager':
				return 'Area Manager';
			default:
				return 'Dealer';
		}
	}

	// ─── Read ────────────────────────────────────────────────────────────────

	public static function get_logs_for_post( int $post_id, int $limit = 50 ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'dealer_download_log';

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT l.id, l.user_id, u.display_name, l.download_date, l.ip_address, l.access_context
				 FROM {$table} l
				 LEFT JOIN {$wpdb->users} u ON u.ID = l.user_id
				 WHERE l.post_id = %d
				 ORDER BY l.download_date DESC
				 LIMIT %d",
				$post_id,
				$limit
			)
		);
	}

	public static function get_all_logs( array $args = [] ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'dealer_download_log';

		[ $where, $params ] = self::build_log_where( $args );

		// Limite e offset sono sempre presenti: la query resta preparata anche
		// quando non è stato passato alcun filtro.
		$limit  = isset( $args['limit'] ) ? max( 1, absint( $args['limit'] ) ) : 500;
		$offset = isset( $args['offset'] ) ? max( 0, absint( $args['offset'] ) ) : 0;

		$params[] = $limit;
		$params[] = $offset;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = "SELECT l.*, u.display_name, u.user_email, p.post_title
				FROM {$table} l
				LEFT JOIN {$wpdb->users} u ON u.ID = l.user_id
				LEFT JOIN {$wpdb->posts} p ON p.ID = l.post_id
				WHERE {$where}
				ORDER BY l.download_date DESC
				LIMIT %d OFFSET %d";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_results( $wpdb->prepare( $sql, ...$params ) );
	}

	/**
	 * Conta le righe di log che corrispondono ai filtri (per la paginazione).
	 */
	public static function count_logs( array $args = [] ): int {
		global $wpdb;
		$table = $wpdb->prefix . 'dealer_download_log';

		[ $where, $params ] = self::build_log_where( $args );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = "SELECT COUNT(*) FROM {$table} l WHERE {$where}";

		if ( empty( $params ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			return (int) $wpdb->get_var( $sql );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, ...$params ) );
	}

	/**
	 * Costruisce WHERE + parametri condivisi da get_all_logs() e count_logs().
	 *
	 * @return array{0:string,1:array}
	 */
	private static function build_log_where( array $args ): array {
		$where  = '1=1';
		$params = [];

		if ( ! empty( $args['post_id'] ) ) {
			$where   .= ' AND l.post_id = %d';
			$params[] = absint( $args['post_id'] );
		}
		if ( ! empty( $args['user_id'] ) ) {
			$where   .= ' AND l.user_id = %d';
			$params[] = absint( $args['user_id'] );
		}
		if ( ! empty( $args['date_from'] ) ) {
			$where   .= ' AND l.download_date >= %s';
			$params[] = sanitize_text_field( $args['date_from'] );
		}
		if ( ! empty( $args['date_to'] ) ) {
			$where   .= ' AND l.download_date <= %s';
			$params[] = sanitize_text_field( $args['date_to'] );
		}
		// Tetto sull'id: congela il set di righe su cui si sta paginando, così un
		// export a blocchi non slitta se arrivano nuovi download mentre gira.
		if ( ! empty( $args['max_id'] ) ) {
			$where   .= ' AND l.id <= %d';
			$params[] = absint( $args['max_id'] );
		}

		// Perimetro di visibilità: insieme chiuso di documenti. Diverso da
		// post_id (filtro scelto dall'utente) perché non è opzionale — un
		// array vuoto significa "nessun documento visibile", quindi nessuna
		// riga, mai "nessun filtro".
		if ( array_key_exists( 'post_ids', $args ) && null !== $args['post_ids'] ) {
			$where .= self::post_ids_clause( (array) $args['post_ids'], $params, 'l.' );
		}

		return [ $where, $params ];
	}

	/**
	 * Clausola "AND post_id IN (…)" per un insieme chiuso di documenti.
	 *
	 * Un insieme vuoto diventa una condizione sempre falsa: chi non ha nessun
	 * documento nel proprio perimetro non deve vedere l'intero archivio, che è
	 * esattamente ciò che accadrebbe saltando la clausola.
	 *
	 * @param int[]  $post_ids
	 * @param array  $params   Parametri di prepare(), estesi per riferimento.
	 * @param string $alias    Prefisso di tabella (es. 'l.'), '' se non c'è alias.
	 */
	private static function post_ids_clause( array $post_ids, array &$params, string $alias = '' ): string {
		$post_ids = array_values( array_unique( array_filter( array_map( 'absint', $post_ids ) ) ) );

		if ( empty( $post_ids ) ) {
			return ' AND 1=0';
		}

		$placeholders = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );
		foreach ( $post_ids as $id ) {
			$params[] = $id;
		}

		return ' AND ' . $alias . 'post_id IN (' . $placeholders . ')';
	}

	/**
	 * Id più alto presente nel log, da usare come tetto per una paginazione
	 * stabile su più richieste.
	 */
	public static function get_max_log_id(): int {
		global $wpdb;
		$table = $wpdb->prefix . 'dealer_download_log';

		return (int) $wpdb->get_var( "SELECT MAX(id) FROM {$table}" );
	}

	// ─── Read: cronologia personale del dealer ───────────────────────────────

	/**
	 * Ultimi download effettuati da un singolo utente, un record per documento
	 * (il più recente), così la cronologia non ripete lo stesso file.
	 */
	public static function get_user_downloads( int $user_id, int $limit = 20 ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'dealer_download_log';

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT l.post_id,
						MAX(l.download_date) AS last_download,
						COUNT(*)             AS download_count
				 FROM {$table} l
				 WHERE l.user_id = %d
				 GROUP BY l.post_id
				 ORDER BY last_download DESC
				 LIMIT %d",
				$user_id,
				max( 1, $limit )
			)
		);
	}

	// ─── Read: statistiche admin ─────────────────────────────────────────────

	/**
	 * Documenti più scaricati. $since accetta una data MySQL ('Y-m-d H:i:s').
	 *
	 * @param int[]|null $post_ids Perimetro di visibilità: null = nessuna
	 *                             restrizione (amministratore), array = solo
	 *                             questi documenti (array vuoto = nessuno).
	 */
	public static function get_top_documents( int $limit = 10, string $since = '', ?array $post_ids = null ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'dealer_download_log';

		$where  = '1=1';
		$params = [];

		if ( $since ) {
			$where   .= ' AND l.download_date >= %s';
			$params[] = sanitize_text_field( $since );
		}
		if ( null !== $post_ids ) {
			$where .= self::post_ids_clause( $post_ids, $params, 'l.' );
		}

		$params[] = max( 1, $limit );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = "SELECT l.post_id, p.post_title, COUNT(*) AS downloads
				FROM {$table} l
				LEFT JOIN {$wpdb->posts} p ON p.ID = l.post_id
				WHERE {$where}
				GROUP BY l.post_id, p.post_title
				ORDER BY downloads DESC
				LIMIT %d";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_results( $wpdb->prepare( $sql, ...$params ) );
	}

	/**
	 * Dealer più attivi per numero di download.
	 *
	 * @param int[]|null $post_ids Perimetro di visibilità (vedi get_top_documents()).
	 *                             La classifica conta solo i download dei
	 *                             documenti visibili a chi guarda.
	 */
	public static function get_top_users( int $limit = 10, string $since = '', ?array $post_ids = null ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'dealer_download_log';

		$where  = '1=1';
		$params = [];

		if ( $since ) {
			$where   .= ' AND l.download_date >= %s';
			$params[] = sanitize_text_field( $since );
		}
		if ( null !== $post_ids ) {
			$where .= self::post_ids_clause( $post_ids, $params, 'l.' );
		}

		$params[] = max( 1, $limit );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = "SELECT l.user_id, u.display_name, COUNT(*) AS downloads, MAX(l.download_date) AS last_download
				FROM {$table} l
				LEFT JOIN {$wpdb->users} u ON u.ID = l.user_id
				WHERE {$where}
				GROUP BY l.user_id, u.display_name
				ORDER BY downloads DESC
				LIMIT %d";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_results( $wpdb->prepare( $sql, ...$params ) );
	}

	/**
	 * Mappa user_id => datetime dell'ultimo download, per individuare i dealer
	 * inattivi incrociandola con l'elenco utenti.
	 *
	 * @param int[]|null $post_ids Perimetro di visibilità (vedi get_top_documents()).
	 * @return array<int,string>
	 */
	public static function get_last_download_per_user( ?array $post_ids = null ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'dealer_download_log';

		$where  = '1=1';
		$params = [];
		if ( null !== $post_ids ) {
			$where .= self::post_ids_clause( $post_ids, $params, '' );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = "SELECT user_id, MAX(download_date) AS last_download
				FROM {$table}
				WHERE {$where}
				GROUP BY user_id";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = empty( $params ) ? $wpdb->get_results( $sql ) : $wpdb->get_results( $wpdb->prepare( $sql, ...$params ) );

		$map = [];
		foreach ( (array) $rows as $row ) {
			$map[ (int) $row->user_id ] = (string) $row->last_download;
		}
		return $map;
	}

	/**
	 * Numero totale di download registrati (opzionalmente da una data in poi).
	 *
	 * @param int[]|null $post_ids Perimetro di visibilità (vedi get_top_documents()).
	 */
	public static function get_total_downloads( string $since = '', ?array $post_ids = null ): int {
		global $wpdb;
		$table = $wpdb->prefix . 'dealer_download_log';

		$where  = '1=1';
		$params = [];

		if ( $since ) {
			$where   .= ' AND download_date >= %s';
			$params[] = sanitize_text_field( $since );
		}
		if ( null !== $post_ids ) {
			$where .= self::post_ids_clause( $post_ids, $params, '' );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = "SELECT COUNT(*) FROM {$table} WHERE {$where}";

		if ( empty( $params ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			return (int) $wpdb->get_var( $sql );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, ...$params ) );
	}

	/**
	 * Conteggio download per un insieme di documenti.
	 *
	 * @param int[] $post_ids
	 * @return array<int,int> post_id => downloads
	 */
	public static function get_download_counts_for_posts( array $post_ids ): array {
		global $wpdb;

		$post_ids = array_values( array_filter( array_map( 'absint', $post_ids ) ) );
		if ( empty( $post_ids ) ) {
			return [];
		}

		$table        = $wpdb->prefix . 'dealer_download_log';
		$placeholders = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = "SELECT post_id, COUNT(*) AS downloads
				FROM {$table}
				WHERE post_id IN ({$placeholders})
				GROUP BY post_id";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$post_ids ) );

		$counts = [];
		foreach ( (array) $rows as $row ) {
			$counts[ (int) $row->post_id ] = (int) $row->downloads;
		}
		return $counts;
	}
}
