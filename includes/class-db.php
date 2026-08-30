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
		// L'attivazione allinea di per sé lo schema: registrarne la revisione
		// evita che maybe_upgrade() rifaccia subito lo stesso dbDelta().
		update_option( 'dealer_portal_schema_revision', self::SCHEMA_REVISION );
		self::create_pages();
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
		// La chiamata esce subito se la mappa corrente è già stata applicata.
		self::setup_capability();

		// Stessa logica per lo schema della tabella di log: create_log_table()
		// gira solo all'attivazione, quindi un'installazione che si limita ad
		// aggiornare i file non vedrebbe mai una colonna nuova.
		self::maybe_upgrade_schema();

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

		$index = $dir . '/index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, '<?php // Silence is golden.' );
		}
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
			'administrator' => [
				DEALER_PORTAL_CAP,        // Controllo completo.
				DEALER_PORTAL_CAP_UPLOAD,
				DEALER_PORTAL_CAP_LOGS,
				DEALER_PORTAL_CAP_ORGS,
			],
			'area_manager'  => [
				DEALER_PORTAL_CAP_UPLOAD, // Carica e versiona, dentro il proprio perimetro.
				DEALER_PORTAL_CAP_LOGS,   // Legge log e statistiche.
				// NON riceve DEALER_PORTAL_CAP (eliminazioni, richieste di
				// accesso, notifiche) né DEALER_PORTAL_CAP_ORGS.
			],
		];
	}

	/**
	 * Revisione della mappa: va incrementata ogni volta che capability_map()
	 * cambia. È ciò che rende l'assegnazione auto-riparante anche quando la
	 * versione del plugin non cambia — senza, un'installazione già aggiornata
	 * non riceverebbe mai le capability nuove.
	 */
	const CAPS_REVISION = 2;

	/**
	 * Idempotente: aggiunge solo ciò che manca e non tocca nulla se la mappa
	 * corrente è già stata applicata. Richiamata sia all'attivazione sia da
	 * maybe_upgrade() a ogni caricamento.
	 */
	private static function setup_capability(): void {
		if ( (int) get_option( 'dealer_portal_caps_revision' ) === self::CAPS_REVISION ) {
			return;
		}

		$complete = true;

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
				}
			}
		}

		if ( $complete ) {
			update_option( 'dealer_portal_caps_revision', self::CAPS_REVISION );
		}
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
