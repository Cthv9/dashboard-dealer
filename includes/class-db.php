<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Dealer_DB {

	// ─── Activation ──────────────────────────────────────────────────────────

	public static function install(): void {
		self::create_log_table();
		self::create_pages();
		self::create_protected_upload_dir();
		self::setup_capability();
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

		if ( get_option( 'dealer_portal_version' ) === DEALER_PORTAL_VERSION ) {
			return;
		}
		self::create_protected_upload_dir();
		update_option( 'dealer_portal_version', DEALER_PORTAL_VERSION );
	}

	private static function create_log_table(): void {
		global $wpdb;

		$table           = $wpdb->prefix . 'dealer_download_log';
		$charset_collate = $wpdb->get_charset_collate();

		// dbDelta() crea la tabella se non esiste; se esiste verifica e aggiorna la struttura.
		$sql = "CREATE TABLE {$table} (
			id            BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id       BIGINT(20) UNSIGNED NOT NULL,
			post_id       BIGINT(20) UNSIGNED NOT NULL,
			download_date DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
			ip_address    VARCHAR(45)         NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY user_id  (user_id),
			KEY post_id  (post_id),
			KEY download_date (download_date)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( 'dealer_portal_version', DEALER_PORTAL_VERSION );
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
			// Controlla se la pagina esiste già (per slug).
			$existing = get_page_by_path( $page['slug'], OBJECT, 'page' );
			if ( $existing ) {
				update_option( $page['option'], $existing->ID );
				continue;
			}

			// Controlla se l'ID salvato in precedenza è ancora valido.
			$saved_id = (int) get_option( $page['option'] );
			if ( $saved_id && get_post_status( $saved_id ) ) {
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

		$result = $wpdb->insert(
			$wpdb->prefix . 'dealer_download_log',
			[
				'user_id'       => $user_id,
				'post_id'       => $post_id,
				'download_date' => current_time( 'mysql' ),
				'ip_address'    => $ip,
			],
			[ '%d', '%d', '%s', '%s' ]
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

	// ─── Read ────────────────────────────────────────────────────────────────

	public static function get_logs_for_post( int $post_id, int $limit = 50 ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'dealer_download_log';

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT l.id, l.user_id, u.display_name, l.download_date, l.ip_address
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

		return [ $where, $params ];
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
	 */
	public static function get_top_documents( int $limit = 10, string $since = '' ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'dealer_download_log';

		$where  = '1=1';
		$params = [];

		if ( $since ) {
			$where   .= ' AND l.download_date >= %s';
			$params[] = sanitize_text_field( $since );
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
	 */
	public static function get_top_users( int $limit = 10, string $since = '' ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'dealer_download_log';

		$where  = '1=1';
		$params = [];

		if ( $since ) {
			$where   .= ' AND l.download_date >= %s';
			$params[] = sanitize_text_field( $since );
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
	 * @return array<int,string>
	 */
	public static function get_last_download_per_user(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'dealer_download_log';

		$rows = $wpdb->get_results(
			"SELECT user_id, MAX(download_date) AS last_download
			 FROM {$table}
			 GROUP BY user_id"
		);

		$map = [];
		foreach ( (array) $rows as $row ) {
			$map[ (int) $row->user_id ] = (string) $row->last_download;
		}
		return $map;
	}

	/**
	 * Numero totale di download registrati (opzionalmente da una data in poi).
	 */
	public static function get_total_downloads( string $since = '' ): int {
		global $wpdb;
		$table = $wpdb->prefix . 'dealer_download_log';

		if ( $since ) {
			return (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE download_date >= %s", sanitize_text_field( $since ) )
			);
		}

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
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
