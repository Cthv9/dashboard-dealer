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
		if ( get_option( 'dealer_portal_version' ) === DEALER_PORTAL_VERSION ) {
			return;
		}
		self::create_protected_upload_dir();
		self::setup_capability();
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

	private static function setup_capability(): void {
		$admin = get_role( 'administrator' );
		if ( $admin && ! $admin->has_cap( DEALER_PORTAL_CAP ) ) {
			$admin->add_cap( DEALER_PORTAL_CAP );
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

		// Costruiamo la WHERE con prepare() per ogni filtro opzionale.
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

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = "SELECT l.*, u.display_name, p.post_title
				FROM {$table} l
				LEFT JOIN {$wpdb->users} u ON u.ID = l.user_id
				LEFT JOIN {$wpdb->posts} p ON p.ID = l.post_id
				WHERE {$where}
				ORDER BY l.download_date DESC
				LIMIT 500";

		if ( ! empty( $params ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			return $wpdb->get_results( $wpdb->prepare( $sql, ...$params ) );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_results( $sql );
	}
}
