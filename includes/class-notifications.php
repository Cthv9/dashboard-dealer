<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Notifiche email del Dealer Portal.
 *
 * Tre flussi:
 *  1. Nuovo documento / nuova versione  → ai soli dealer che possono vederlo.
 *  2. Digest settimanale scadenze       → a ogni dealer, i suoi documenti in scadenza.
 *  3. Report scadenze                   → agli amministratori (in scadenza + già scaduti).
 *
 * Strategia di invio (importante): nessuna email parte dentro la richiesta che
 * salva il documento. Il salvataggio si limita ad accodare un "job" in
 * un'opzione (self::QUEUE_OPTION) e a programmare un evento cron singolo
 * differito di pochi minuti. Il worker (process_queue) elabora un job alla
 * volta, a blocchi di BATCH_SIZE destinatari, riprogrammandosi finché la coda
 * non è vuota. Questo evita 200 wp_mail() sincroni nel wizard di upload e —
 * effetto collaterale utile — fa calcolare i destinatari quando tutti i meta
 * del documento (_doc_roles, _doc_lines) sono già stati salvati, cosa che al
 * momento di transition_post_status non è ancora vera.
 *
 * Regola non negoziabile: ogni destinatario passa da
 * Dealer_Search::user_can_access_post() immediatamente prima dell'invio, mai
 * su una lista precalcolata e basta. Se Dealer_Search non è disponibile non si
 * invia nulla (fail-closed).
 */
class Dealer_Notifications {

	// ─── Costanti ────────────────────────────────────────────────────────────

	/** Opzione unica con tutte le impostazioni del modulo. */
	const OPTION = 'dealer_portal_notifications';

	/** Opzione (autoload off) con la coda di invio. */
	const QUEUE_OPTION = 'dealer_portal_notification_queue';

	/** Hook cron: worker della coda (evento singolo, si riprogramma da solo). */
	const CRON_QUEUE = 'dealer_portal_process_mail_queue';

	/** Hook cron: digest settimanale scadenze ai dealer. */
	const CRON_DEALER_DIGEST = 'dealer_portal_dealer_expiry_digest';

	/** Hook cron: report giornaliero scadenze agli amministratori. */
	const CRON_ADMIN_REPORT = 'dealer_portal_admin_expiry_report';

	/** Post meta di guardia: notifica "nuovo documento" già accodata. */
	const META_NOTIFIED_NEW = '_dealer_notified_new';

	/** Post meta di guardia: notifica "nuova versione" già accodata. */
	const META_NOTIFIED_VERSION = '_dealer_notified_version';

	/** User meta delle preferenze (contratto condiviso fra moduli). */
	const PREF_NEW      = '_dealer_notify_new';
	const PREF_EXPIRING = '_dealer_notify_expiring';

	/** Nonce dedicati: nessuna sovrapposizione con quelli di Dealer_Admin. */
	const NONCE_PROFILE  = 'dealer_notifications_profile';
	const NONCE_SETTINGS = 'dealer_notifications_settings';
	const NONCE_TEST     = 'dealer_notifications_test';

	/** Slug del sottomenu impostazioni. */
	const MENU_SLUG = 'dealer-portal-notifications';

	/** Destinatari elaborati per ogni esecuzione del worker. */
	const BATCH_SIZE = 25;

	/** Ritardo del primo giro di coda dopo il salvataggio (secondi). */
	const QUEUE_DELAY = 300;

	/** Intervallo fra un blocco e il successivo (secondi). */
	const QUEUE_NEXT = 60;

	/** Finestra di preavviso scadenze (giorni). */
	const EXPIRY_WINDOW_DAYS = 30;

	/** Tetto di sicurezza sui documenti raccolti per i digest. */
	const MAX_DOCS = 300;

	/** Ruoli dealer (allineati a Dealer_Search). */
	const DEALER_ROLES = [ 'dealer', 'top_dealer', 'part_center' ];

	/** Testo alternativo dell'email in corso di invio (letto da phpmailer_init). */
	private static string $alt_body = '';

	// ─── Constructor ─────────────────────────────────────────────────────────

	public function __construct() {
		// Cron auto-riparanti: l'hook di attivazione non ci appartiene.
		if ( did_action( 'init' ) ) {
			self::maybe_schedule_events();
		} else {
			add_action( 'init', [ __CLASS__, 'maybe_schedule_events' ], 20 );
		}

		// Eventi che generano notifiche.
		add_action( 'transition_post_status',      [ $this, 'on_transition_post_status' ], 10, 3 );
		add_action( 'dealer_portal_new_version',   [ $this, 'on_new_version' ], 10, 3 );

		// Worker e cron periodici.
		add_action( self::CRON_QUEUE,         [ __CLASS__, 'process_queue' ] );
		add_action( self::CRON_DEALER_DIGEST, [ __CLASS__, 'run_dealer_digest' ] );
		add_action( self::CRON_ADMIN_REPORT,  [ __CLASS__, 'run_admin_report' ] );

		// Sezione dedicata nel profilo utente (separata da quella di Dealer_Admin).
		add_action( 'show_user_profile',         [ $this, 'render_profile_fields' ] );
		add_action( 'edit_user_profile',         [ $this, 'render_profile_fields' ] );
		add_action( 'personal_options_update',   [ $this, 'save_profile_fields' ] );
		add_action( 'edit_user_profile_update',  [ $this, 'save_profile_fields' ] );

		// Pagina impostazioni (priorità 20: il menu padre esiste già).
		add_action( 'admin_menu', [ $this, 'register_menu' ], 20 );
		add_action( 'admin_post_dealer_portal_notifications_save', [ $this, 'handle_save_settings' ] );
		add_action( 'admin_post_dealer_portal_notifications_test', [ $this, 'handle_test_email' ] );
	}

	// ─── Cron: registrazione e pulizia ───────────────────────────────────────

	/**
	 * Registra gli eventi ricorrenti solo se mancanti. Idempotente: gira a ogni
	 * caricamento e ripara una pianificazione persa (cron svuotato, migrazione,
	 * plugin aggiornato senza riattivazione).
	 */
	public static function maybe_schedule_events(): void {
		if ( ! wp_next_scheduled( self::CRON_DEALER_DIGEST ) ) {
			// Lunedì mattina: il dealer trova il digest a inizio settimana.
			wp_schedule_event( self::next_local_time( 8, 1 ), 'weekly', self::CRON_DEALER_DIGEST );
		}
		if ( ! wp_next_scheduled( self::CRON_ADMIN_REPORT ) ) {
			wp_schedule_event( self::next_local_time( 7 ), 'daily', self::CRON_ADMIN_REPORT );
		}
	}

	/**
	 * Rimuove tutti gli eventi cron del modulo (coda inclusa).
	 * L'orchestratore la collega a disattivazione e disinstallazione.
	 */
	public static function clear_scheduled_events(): void {
		foreach ( [ self::CRON_QUEUE, self::CRON_DEALER_DIGEST, self::CRON_ADMIN_REPORT ] as $hook ) {
			// wp_unschedule_hook rimuove anche gli eventi singoli con argomenti.
			if ( function_exists( 'wp_unschedule_hook' ) ) {
				wp_unschedule_hook( $hook );
			} else {
				wp_clear_scheduled_hook( $hook );
			}
		}
		delete_option( self::QUEUE_OPTION );
	}

	/**
	 * Timestamp UTC della prossima occorrenza di un orario locale.
	 *
	 * @param int      $hour     Ora locale (0-23).
	 * @param int|null $weekday  1 = lunedì … 7 = domenica. Null = domani/oggi.
	 */
	private static function next_local_time( int $hour, ?int $weekday = null ): int {
		$offset = (float) get_option( 'gmt_offset' ) * HOUR_IN_SECONDS;
		$now    = time();
		$local  = $now + $offset;

		$target = gmmktime(
			$hour, 0, 0,
			(int) gmdate( 'n', $local ),
			(int) gmdate( 'j', $local ),
			(int) gmdate( 'Y', $local )
		) - $offset;

		if ( null !== $weekday ) {
			$current_day = (int) gmdate( 'N', $local );
			$delta       = ( $weekday - $current_day + 7 ) % 7;
			$target     += $delta * DAY_IN_SECONDS;
		}

		while ( $target <= $now ) {
			$target += ( null !== $weekday ) ? WEEK_IN_SECONDS : DAY_IN_SECONDS;
		}
		return $target;
	}

	// ─── Impostazioni ────────────────────────────────────────────────────────

	public static function default_options(): array {
		return [
			'enable_new'       => 1,
			'enable_expiring'  => 1,
			'enable_admin'     => 1,
			'from_name'        => '',
			'from_email'       => '',
			'admin_recipients' => '',
			'search_url'       => '',
		];
	}

	public static function get_options(): array {
		$saved = get_option( self::OPTION, [] );
		if ( ! is_array( $saved ) ) {
			$saved = [];
		}
		return array_merge( self::default_options(), $saved );
	}

	private static function option_enabled( string $key ): bool {
		$options = self::get_options();
		return ! empty( $options[ $key ] );
	}

	// ─── Eventi che generano notifiche ───────────────────────────────────────

	/**
	 * Nuovo documento pubblicato.
	 *
	 * Si accoda solo il passaggio verso 'publish' da uno stato diverso: le bozze
	 * e i salvataggi successivi non generano nulla. Il post meta di guardia
	 * impedisce che un ciclo publish → draft → publish rimandi la stessa email.
	 */
	public function on_transition_post_status( $new_status, $old_status, $post ): void {
		if ( ! $post instanceof WP_Post || 'documento_dealer' !== $post->post_type ) {
			return;
		}
		if ( 'publish' !== $new_status || 'publish' === $old_status ) {
			return;
		}
		if ( wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! self::option_enabled( 'enable_new' ) ) {
			return;
		}
		if ( get_post_meta( $post->ID, self::META_NOTIFIED_NEW, true ) ) {
			return; // Già notificato una volta: mai due email per lo stesso documento.
		}

		update_post_meta( $post->ID, self::META_NOTIFIED_NEW, (string) time() );

		self::enqueue_job( 'doc:' . (int) $post->ID, [
			'type'       => 'document',
			'post_id'    => (int) $post->ID,
			'is_version' => false,
			'prev_id'    => 0,
			'recipients' => null,
			'cursor'     => 0,
			'created'    => time(),
		] );
	}

	/**
	 * Nuova versione di un documento esistente.
	 *
	 * L'action scatta nella stessa richiesta di transition_post_status, subito
	 * dopo: il job "nuovo documento" è ancora in coda e viene semplicemente
	 * promosso a "nuova versione" (testo diverso, nessuna doppia email).
	 */
	public function on_new_version( $new_id, $prev_id, $group_id ): void {
		$new_id  = (int) $new_id;
		$prev_id = (int) $prev_id;

		if ( ! $new_id || 'documento_dealer' !== get_post_type( $new_id ) ) {
			return;
		}
		if ( ! self::option_enabled( 'enable_new' ) ) {
			return;
		}
		if ( get_post_meta( $new_id, self::META_NOTIFIED_VERSION, true ) ) {
			return;
		}

		update_post_meta( $new_id, self::META_NOTIFIED_VERSION, (string) time() );

		$key   = 'doc:' . $new_id;
		$queue = self::get_queue();

		if ( isset( $queue[ $key ] ) ) {
			// Job ancora in attesa: si aggiorna in place.
			$queue[ $key ]['is_version'] = true;
			$queue[ $key ]['prev_id']    = $prev_id;
			self::save_queue( $queue );
			self::schedule_queue_run( self::QUEUE_DELAY );
			return;
		}

		// Nessun job pendente (documento pubblicato in un momento precedente).
		update_post_meta( $new_id, self::META_NOTIFIED_NEW, (string) time() );
		self::enqueue_job( $key, [
			'type'       => 'document',
			'post_id'    => $new_id,
			'is_version' => true,
			'prev_id'    => $prev_id,
			'recipients' => null,
			'cursor'     => 0,
			'created'    => time(),
		] );
	}

	// ─── Coda di invio ───────────────────────────────────────────────────────

	private static function get_queue(): array {
		$queue = get_option( self::QUEUE_OPTION, [] );
		return is_array( $queue ) ? $queue : [];
	}

	private static function save_queue( array $queue ): void {
		if ( empty( $queue ) ) {
			delete_option( self::QUEUE_OPTION );
			return;
		}
		update_option( self::QUEUE_OPTION, $queue, false );
	}

	private static function enqueue_job( string $key, array $job ): void {
		$queue         = self::get_queue();
		$queue[ $key ] = $job;
		self::save_queue( $queue );
		self::schedule_queue_run( self::QUEUE_DELAY );
	}

	/** Programma un solo evento singolo alla volta. */
	private static function schedule_queue_run( int $delay ): void {
		if ( wp_next_scheduled( self::CRON_QUEUE ) ) {
			return;
		}
		wp_schedule_single_event( time() + max( 30, $delay ), self::CRON_QUEUE );
	}

	/**
	 * Worker: elabora un blocco di destinatari del primo job in coda e, se
	 * resta lavoro, si riprogramma. Un solo job per esecuzione: la richiesta
	 * cron resta breve anche con centinaia di dealer.
	 */
	public static function process_queue(): void {
		$queue = self::get_queue();
		if ( empty( $queue ) ) {
			return;
		}

		$keys = array_keys( $queue );
		$key  = (string) $keys[0];
		$job  = $queue[ $key ];

		$done = ( 'digest' === ( $job['type'] ?? '' ) )
			? self::process_digest_job( $job )
			: self::process_document_job( $job );

		// Rilettura: durante l'invio potrebbero essere arrivati nuovi job.
		$queue = self::get_queue();
		if ( $done ) {
			unset( $queue[ $key ] );
		} else {
			$queue[ $key ] = $job;
		}
		self::save_queue( $queue );

		if ( ! empty( $queue ) ) {
			self::schedule_queue_run( self::QUEUE_NEXT );
		}
	}

	/**
	 * Job "nuovo documento / nuova versione".
	 *
	 * @return bool true quando il job è concluso (o da scartare).
	 */
	private static function process_document_job( array &$job ): bool {
		$post_id = (int) ( $job['post_id'] ?? 0 );

		if ( ! $post_id || ! self::option_enabled( 'enable_new' ) ) {
			return true;
		}
		// Il documento potrebbe essere stato ritirato fra l'accodamento e l'invio.
		if ( ! self::document_is_notifiable( $post_id ) ) {
			return true;
		}

		if ( ! is_array( $job['recipients'] ?? null ) ) {
			$job['recipients'] = self::get_dealer_user_ids();
			$job['cursor']     = 0;
		}

		$cursor = (int) ( $job['cursor'] ?? 0 );
		$slice  = array_slice( $job['recipients'], $cursor, self::BATCH_SIZE );

		foreach ( $slice as $user_id ) {
			$cursor++;

			$user = get_userdata( (int) $user_id );
			if ( ! $user instanceof WP_User ) {
				continue;
			}
			if ( ! self::user_wants( $user->ID, self::PREF_NEW ) ) {
				continue;
			}
			// Controllo accesso rifatto adesso, non al momento dell'accodamento.
			if ( ! self::user_can_receive_post( $user, $post_id ) ) {
				continue;
			}

			self::send_document_email( $user, $post_id, ! empty( $job['is_version'] ), (int) ( $job['prev_id'] ?? 0 ) );
		}

		$job['cursor'] = $cursor;

		return $cursor >= count( $job['recipients'] );
	}

	/**
	 * Job "digest scadenze dealer": la lista dei documenti candidati è calcolata
	 * una volta sola; l'accesso resta verificato utente per utente.
	 */
	private static function process_digest_job( array &$job ): bool {
		if ( ! self::option_enabled( 'enable_expiring' ) ) {
			return true;
		}

		$post_ids = array_map( 'intval', (array) ( $job['post_ids'] ?? [] ) );
		if ( empty( $post_ids ) ) {
			return true;
		}

		if ( ! is_array( $job['recipients'] ?? null ) ) {
			$job['recipients'] = self::get_dealer_user_ids();
			$job['cursor']     = 0;
		}

		$cursor = (int) ( $job['cursor'] ?? 0 );
		$slice  = array_slice( $job['recipients'], $cursor, self::BATCH_SIZE );

		foreach ( $slice as $user_id ) {
			$cursor++;

			$user = get_userdata( (int) $user_id );
			if ( ! $user instanceof WP_User ) {
				continue;
			}
			if ( ! self::user_wants( $user->ID, self::PREF_EXPIRING ) ) {
				continue;
			}

			$docs = [];
			foreach ( $post_ids as $pid ) {
				if ( ! self::document_is_notifiable( $pid ) ) {
					continue;
				}
				if ( ! self::user_can_receive_post( $user, $pid ) ) {
					continue;
				}
				$docs[] = self::document_data( $pid );
			}

			if ( empty( $docs ) ) {
				continue; // Niente email vuote.
			}

			self::send_digest_email( $user, $docs );
		}

		$job['cursor'] = $cursor;

		return $cursor >= count( $job['recipients'] );
	}

	// ─── Cron: digest dealer e report admin ──────────────────────────────────

	/**
	 * Digest settimanale: raccoglie i documenti in scadenza e accoda un job.
	 * Anche qui l'invio è differito, non sincrono dentro il cron.
	 */
	public static function run_dealer_digest(): void {
		if ( ! self::option_enabled( 'enable_expiring' ) ) {
			return;
		}

		$posts = self::query_expiring_documents();
		if ( empty( $posts ) ) {
			return;
		}

		self::enqueue_job( 'digest:' . gmdate( 'Y-m-d' ), [
			'type'       => 'digest',
			'post_ids'   => wp_list_pluck( $posts, 'ID' ),
			'recipients' => null,
			'cursor'     => 0,
			'created'    => time(),
		] );
	}

	/**
	 * Report agli amministratori: documenti in scadenza nei prossimi 30 giorni
	 * e documenti già scaduti (che i dealer non possono più scaricare).
	 */
	public static function run_admin_report(): void {
		if ( ! self::option_enabled( 'enable_admin' ) ) {
			return;
		}

		$expiring = [];
		foreach ( self::query_expiring_documents() as $post ) {
			$expiring[] = self::document_data( (int) $post->ID );
		}

		$expired = [];
		foreach ( self::query_expired_documents() as $post ) {
			$expired[] = self::document_data( (int) $post->ID );
		}

		if ( empty( $expiring ) && empty( $expired ) ) {
			return; // Nessuna email vuota.
		}

		$recipients = self::admin_recipients();
		if ( empty( $recipients ) ) {
			return;
		}

		$site_name = self::site_name();
		$subject   = sprintf( '[%s] Documenti in scadenza: %d in avviso, %d scaduti', $site_name, count( $expiring ), count( $expired ) );

		$body = self::render_template( 'email-admin-expiry', [
			'expiring'    => $expiring,
			'expired'     => $expired,
			'archive_url' => admin_url( 'admin.php?page=dealer-portal-archive' ),
			'window'      => self::EXPIRY_WINDOW_DAYS,
		] );

		$html = self::wrap_html( 'Documenti in scadenza', $body );
		$text = self::text_admin_report( $expiring, $expired );

		foreach ( $recipients as $to ) {
			self::send_html( $to, $subject, $html, $text );
		}
	}

	// ─── Query documenti ─────────────────────────────────────────────────────

	/**
	 * Documenti attivi e correnti che scadono da oggi ai prossimi N giorni.
	 *
	 * @return WP_Post[]
	 */
	private static function query_expiring_documents(): array {
		$today = gmdate( 'Y-m-d' );
		$limit = gmdate( 'Y-m-d', time() + ( self::EXPIRY_WINDOW_DAYS * DAY_IN_SECONDS ) );

		return self::query_documents( [
			'key'     => '_doc_expiry',
			'value'   => [ $today, $limit ],
			'compare' => 'BETWEEN',
			'type'    => 'DATE',
		] );
	}

	/**
	 * Documenti attivi e correnti con scadenza già superata.
	 *
	 * @return WP_Post[]
	 */
	private static function query_expired_documents(): array {
		return self::query_documents( [
			'relation' => 'AND',
			[ 'key' => '_doc_expiry', 'value' => '', 'compare' => '!=' ],
			[ 'key' => '_doc_expiry', 'value' => gmdate( 'Y-m-d' ), 'compare' => '<', 'type' => 'DATE' ],
		] );
	}

	/**
	 * Query comune: solo documenti pubblicati, non obsoleti e versione corrente.
	 *
	 * @return WP_Post[]
	 */
	private static function query_documents( array $expiry_clause ): array {
		$meta_query = [
			'relation' => 'AND',
			[
				'relation' => 'OR',
				[ 'key' => '_doc_status', 'value' => 'obsoleto', 'compare' => '!=' ],
				[ 'key' => '_doc_status', 'compare' => 'NOT EXISTS' ],
			],
			$expiry_clause,
		];

		// I cron girano in contesti diversi: la classe potrebbe non essere carica.
		if ( class_exists( 'Dealer_Versioning' ) ) {
			$meta_query[] = Dealer_Versioning::meta_query_current_only();
		}

		$posts = get_posts( [
			'post_type'              => 'documento_dealer',
			'post_status'            => 'publish',
			'numberposts'            => self::MAX_DOCS,
			'meta_query'             => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			'meta_key'               => '_doc_expiry', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'orderby'                => 'meta_value',
			'order'                  => 'ASC',
			'suppress_filters'       => false,
			'update_post_term_cache' => false,
		] );

		return is_array( $posts ) ? $posts : [];
	}

	/** Il documento è ancora notificabile? (pubblicato, attivo, non scaduto) */
	private static function document_is_notifiable( int $post_id ): bool {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post || 'documento_dealer' !== $post->post_type ) {
			return false;
		}
		if ( 'publish' !== $post->post_status ) {
			return false;
		}
		if ( 'obsoleto' === (string) get_post_meta( $post_id, '_doc_status', true ) ) {
			return false;
		}
		$expiry = (string) get_post_meta( $post_id, '_doc_expiry', true );
		if ( '' !== $expiry && strtotime( $expiry . ' 23:59:59' ) < time() ) {
			return false;
		}
		return true;
	}

	/** Dati leggibili di un documento, usati da email e digest. */
	private static function document_data( int $post_id ): array {
		$post   = get_post( $post_id );
		$brand  = (string) get_post_meta( $post_id, '_doc_brand', true );
		$line   = (string) get_post_meta( $post_id, '_doc_product_line', true );
		$type   = (string) get_post_meta( $post_id, '_doc_type', true );
		$expiry = (string) get_post_meta( $post_id, '_doc_expiry', true );

		$type_label = $type;
		if ( class_exists( 'Dealer_Admin' ) ) {
			$types      = Dealer_Admin::get_doc_types();
			$type_label = (string) ( $types[ $type ] ?? $type );
		}

		$days_left = null;
		if ( '' !== $expiry ) {
			$days_left = (int) floor( ( strtotime( $expiry . ' 23:59:59' ) - time() ) / DAY_IN_SECONDS );
		}

		return [
			'id'         => $post_id,
			'title'      => $post instanceof WP_Post ? (string) $post->post_title : '',
			'brand'      => $brand,
			'line'       => $line,
			'brand_line' => trim( $brand . ( '' !== $line ? ' › ' . $line : '' ) ),
			'type'       => $type,
			'type_label' => $type_label,
			'version'    => (string) get_post_meta( $post_id, '_doc_version', true ),
			'expiry'     => $expiry,
			'expiry_fmt' => '' !== $expiry ? (string) mysql2date( 'd/m/Y', $expiry . ' 00:00:00' ) : '',
			'days_left'  => $days_left,
		];
	}

	// ─── Destinatari e preferenze ────────────────────────────────────────────

	/** ID di tutti gli utenti con un ruolo dealer. */
	private static function get_dealer_user_ids(): array {
		$ids = get_users( [
			'role__in' => self::DEALER_ROLES,
			'fields'   => 'ID',
			'orderby'  => 'ID',
			'number'   => 5000,
		] );
		return array_values( array_map( 'intval', (array) $ids ) );
	}

	/**
	 * Preferenza utente: attiva quando il meta è assente (contratto condiviso),
	 * disattiva solo con il valore esplicito '0'.
	 */
	public static function user_wants( int $user_id, string $pref ): bool {
		$value = get_user_meta( $user_id, $pref, true );
		return '0' !== (string) $value;
	}

	/**
	 * Può questo utente ricevere una notifica su questo documento?
	 * Fail-closed: senza Dealer_Search non si invia nulla, mai "in dubbio sì".
	 */
	private static function user_can_receive_post( WP_User $user, int $post_id ): bool {
		if ( ! class_exists( 'Dealer_Search' ) ) {
			return false;
		}
		if ( ! is_email( $user->user_email ) ) {
			return false;
		}
		if ( ! Dealer_Search::user_is_dealer( $user ) ) {
			return false; // Abbonati, editor, admin: niente email dealer.
		}

		$user_lines = Dealer_Search::get_user_lines( $user->ID );

		return Dealer_Search::user_can_access_post( $user, $user_lines, $post_id );
	}

	/** Destinatari del report admin: opzione dedicata o admin_email del sito. */
	private static function admin_recipients(): array {
		$options = self::get_options();
		$raw     = (string) $options['admin_recipients'];

		$list = [];
		foreach ( preg_split( '/[,;\s]+/', $raw ) as $candidate ) {
			$candidate = sanitize_email( (string) $candidate );
			if ( is_email( $candidate ) ) {
				$list[] = $candidate;
			}
		}

		if ( empty( $list ) ) {
			$fallback = sanitize_email( (string) get_option( 'admin_email' ) );
			if ( is_email( $fallback ) ) {
				$list[] = $fallback;
			}
		}

		return array_values( array_unique( $list ) );
	}

	// ─── URL utili ───────────────────────────────────────────────────────────

	/** Pagina di ricerca documenti (override in impostazioni, poi opzione, poi slug). */
	public static function search_url(): string {
		$options = self::get_options();
		if ( '' !== (string) $options['search_url'] ) {
			return (string) $options['search_url'];
		}

		$page_id = (int) get_option( 'dealer_portal_search_page_id' );
		if ( $page_id ) {
			$permalink = get_permalink( $page_id );
			if ( $permalink ) {
				return $permalink;
			}
		}
		return home_url( '/dealer-search/' );
	}

	/** Dashboard dealer. */
	public static function dashboard_url(): string {
		$page_id = (int) get_option( 'dealer_portal_dashboard_page_id' );
		if ( $page_id ) {
			$permalink = get_permalink( $page_id );
			if ( $permalink ) {
				return $permalink;
			}
		}
		return home_url( '/dashboard-dealer/' );
	}

	private static function site_name(): string {
		return wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
	}

	// ─── Composizione e invio ────────────────────────────────────────────────

	/** Email "nuovo documento" / "nuova versione" a un singolo dealer. */
	private static function send_document_email( WP_User $user, int $post_id, bool $is_version, int $prev_id ): void {
		$doc        = self::document_data( $post_id );
		$prev_title = '';
		if ( $is_version && $prev_id ) {
			$prev       = get_post( $prev_id );
			$prev_title = $prev instanceof WP_Post ? (string) $prev->post_title : '';
		}

		$subject = $is_version
			? sprintf( '[%s] Versione aggiornata: %s', self::site_name(), $doc['title'] )
			: sprintf( '[%s] Nuovo documento disponibile: %s', self::site_name(), $doc['title'] );

		$body = self::render_template( 'email-new-document', [
			'user_name'     => (string) $user->display_name,
			'doc'           => $doc,
			'is_version'    => $is_version,
			'prev_title'    => $prev_title,
			'search_url'    => self::search_url(),
			'dashboard_url' => self::dashboard_url(),
		] );

		$html = self::wrap_html( $is_version ? 'Versione aggiornata' : 'Nuovo documento', $body );
		$text = self::text_document( $user, $doc, $is_version, $prev_title );

		self::send_html( $user->user_email, $subject, $html, $text );
	}

	/** Digest scadenze a un singolo dealer. */
	private static function send_digest_email( WP_User $user, array $docs ): void {
		$subject = sprintf(
			'[%s] %d %s in scadenza nei prossimi %d giorni',
			self::site_name(),
			count( $docs ),
			count( $docs ) === 1 ? 'documento' : 'documenti',
			self::EXPIRY_WINDOW_DAYS
		);

		$body = self::render_template( 'email-expiring-digest', [
			'user_name'     => (string) $user->display_name,
			'documents'     => $docs,
			'window'        => self::EXPIRY_WINDOW_DAYS,
			'search_url'    => self::search_url(),
			'dashboard_url' => self::dashboard_url(),
		] );

		$html = self::wrap_html( 'Documenti in scadenza', $body );
		$text = self::text_digest( $user, $docs );

		self::send_html( $user->user_email, $subject, $html, $text );
	}

	/**
	 * Invio HTML con testo alternativo.
	 *
	 * Il filtro wp_mail_content_type e l'action phpmailer_init vengono rimossi
	 * subito dopo l'invio (anche in caso di eccezione): lasciarli attivi
	 * cambierebbe il Content-Type di tutte le email del sito.
	 */
	private static function send_html( string $to, string $subject, string $html, string $text ): bool {
		if ( ! is_email( $to ) ) {
			return false;
		}

		$options = self::get_options();
		self::$alt_body = $text;

		add_filter( 'wp_mail_content_type', [ __CLASS__, 'filter_content_type' ] );
		add_action( 'phpmailer_init',       [ __CLASS__, 'filter_alt_body' ] );

		$has_from      = is_email( (string) $options['from_email'] );
		$has_from_name = '' !== (string) $options['from_name'];
		if ( $has_from ) {
			add_filter( 'wp_mail_from', [ __CLASS__, 'filter_from_email' ] );
		}
		if ( $has_from_name ) {
			add_filter( 'wp_mail_from_name', [ __CLASS__, 'filter_from_name' ] );
		}

		try {
			$sent = wp_mail( $to, $subject, $html );
		} finally {
			remove_filter( 'wp_mail_content_type', [ __CLASS__, 'filter_content_type' ] );
			remove_action( 'phpmailer_init',       [ __CLASS__, 'filter_alt_body' ] );
			if ( $has_from ) {
				remove_filter( 'wp_mail_from', [ __CLASS__, 'filter_from_email' ] );
			}
			if ( $has_from_name ) {
				remove_filter( 'wp_mail_from_name', [ __CLASS__, 'filter_from_name' ] );
			}
			self::$alt_body = '';
		}

		return (bool) $sent;
	}

	public static function filter_content_type(): string {
		return 'text/html';
	}

	/** Versione testuale multipart: leggibile anche dai client senza HTML. */
	public static function filter_alt_body( $phpmailer ): void {
		if ( is_object( $phpmailer ) && '' !== self::$alt_body ) {
			$phpmailer->AltBody = self::$alt_body; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		}
	}

	public static function filter_from_email(): string {
		$options = self::get_options();
		return (string) $options['from_email'];
	}

	public static function filter_from_name(): string {
		$options = self::get_options();
		return (string) $options['from_name'];
	}

	// ─── Template ────────────────────────────────────────────────────────────

	/** Rende un template email in templates/, con escape a carico del template. */
	private static function render_template( string $slug, array $data ): string {
		$file = DEALER_PORTAL_PATH . 'templates/' . $slug . '.php';
		if ( ! file_exists( $file ) ) {
			return '';
		}
		ob_start();
		require $file;
		return (string) ob_get_clean();
	}

	/** Involucro HTML comune (intestazione, corpo, piè di pagina). */
	private static function wrap_html( string $title, string $body ): string {
		return self::render_template( 'email-layout', [
			'email_title' => $title,
			'email_body'  => $body,
			'site_name'   => self::site_name(),
			'site_url'    => home_url( '/' ),
		] );
	}

	// ─── Versioni testuali ───────────────────────────────────────────────────

	private static function text_document( WP_User $user, array $doc, bool $is_version, string $prev_title ): string {
		$lines   = [];
		$lines[] = 'Gentile ' . $user->display_name . ',';
		$lines[] = '';
		$lines[] = $is_version
			? 'è disponibile una versione aggiornata di un documento a cui hai accesso:'
			: 'è stato pubblicato un nuovo documento a cui hai accesso:';
		$lines[] = '';
		$lines[] = '- Titolo: ' . $doc['title'];
		if ( '' !== $doc['brand_line'] ) {
			$lines[] = '- Brand / Linea: ' . $doc['brand_line'];
		}
		if ( '' !== $doc['type_label'] ) {
			$lines[] = '- Tipo: ' . $doc['type_label'];
		}
		if ( '' !== $doc['version'] ) {
			$lines[] = '- Versione: ' . $doc['version'];
		}
		if ( '' !== $doc['expiry_fmt'] ) {
			$lines[] = '- Valido fino al: ' . $doc['expiry_fmt'];
		}
		if ( $is_version && '' !== $prev_title ) {
			$lines[] = '- Sostituisce: ' . $prev_title;
		}
		$lines[] = '';
		$lines[] = 'Il documento è disponibile nell\'area riservata:';
		$lines[] = self::search_url();
		$lines[] = '';
		$lines[] = 'Per motivi di sicurezza il file non è allegato e non esiste un link diretto di download: accedi al portale per scaricarlo.';
		$lines[] = '';
		$lines[] = self::text_footer();

		return implode( "\n", $lines );
	}

	private static function text_digest( WP_User $user, array $docs ): string {
		$lines   = [];
		$lines[] = 'Gentile ' . $user->display_name . ',';
		$lines[] = '';
		$lines[] = 'i seguenti documenti a te accessibili scadono nei prossimi ' . self::EXPIRY_WINDOW_DAYS . ' giorni:';
		$lines[] = '';
		foreach ( $docs as $doc ) {
			$lines[] = '- ' . $doc['title']
				. ( '' !== $doc['brand_line'] ? ' (' . $doc['brand_line'] . ')' : '' )
				. ' — scade il ' . $doc['expiry_fmt'];
		}
		$lines[] = '';
		$lines[] = 'Dopo la scadenza il documento non sarà più scaricabile. Consulta l\'area riservata:';
		$lines[] = self::search_url();
		$lines[] = '';
		$lines[] = self::text_footer();

		return implode( "\n", $lines );
	}

	private static function text_admin_report( array $expiring, array $expired ): string {
		$lines   = [];
		$lines[] = 'Riepilogo scadenze documenti del Dealer Portal.';
		$lines[] = '';

		$lines[] = 'IN SCADENZA NEI PROSSIMI ' . self::EXPIRY_WINDOW_DAYS . ' GIORNI (' . count( $expiring ) . '):';
		if ( empty( $expiring ) ) {
			$lines[] = '- nessuno';
		} else {
			foreach ( $expiring as $doc ) {
				$lines[] = '- ' . $doc['title'] . ' — scade il ' . $doc['expiry_fmt']
					. ( null !== $doc['days_left'] ? ' (fra ' . max( 0, (int) $doc['days_left'] ) . ' giorni)' : '' );
			}
		}
		$lines[] = '';

		$lines[] = 'GIÀ SCADUTI (' . count( $expired ) . '):';
		if ( empty( $expired ) ) {
			$lines[] = '- nessuno';
		} else {
			foreach ( $expired as $doc ) {
				$lines[] = '- ' . $doc['title'] . ' — scaduto il ' . $doc['expiry_fmt'];
			}
		}
		$lines[] = '';
		$lines[] = 'Archivio documenti: ' . admin_url( 'admin.php?page=dealer-portal-archive' );

		return implode( "\n", $lines );
	}

	private static function text_footer(): string {
		return "—\n" . self::site_name() . ' — Area riservata dealer.' . "\n"
			. 'Puoi modificare le preferenze di notifica dal tuo profilo utente.';
	}

	// ─── Profilo utente: preferenze ──────────────────────────────────────────

	/** Sezione dedicata, indipendente da quella di Dealer_Admin. */
	public function render_profile_fields( $user ): void {
		if ( ! $user instanceof WP_User ) {
			return;
		}
		// Le preferenze hanno senso solo per i dealer.
		if ( class_exists( 'Dealer_Search' ) && ! Dealer_Search::user_is_dealer( $user ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_user', $user->ID ) ) {
			return;
		}

		$notify_new      = self::user_wants( $user->ID, self::PREF_NEW );
		$notify_expiring = self::user_wants( $user->ID, self::PREF_EXPIRING );
		?>
		<h2>Notifiche Dealer Portal</h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">Email da ricevere</th>
				<td>
					<?php wp_nonce_field( self::NONCE_PROFILE, 'dealer_notifications_nonce' ); ?>
					<label for="dealer_notify_new">
						<input type="checkbox" name="dealer_notify_new" id="dealer_notify_new" value="1" <?php checked( $notify_new ); ?> />
						Avvisami quando viene pubblicato un nuovo documento a me accessibile
					</label>
					<br />
					<label for="dealer_notify_expiring">
						<input type="checkbox" name="dealer_notify_expiring" id="dealer_notify_expiring" value="1" <?php checked( $notify_expiring ); ?> />
						Inviami il riepilogo settimanale dei documenti in scadenza
					</label>
					<p class="description">
						Le notifiche riguardano soltanto i documenti già visibili nell'area riservata:
						nessuna email segnala documenti riservati ad altre linee o ruoli.
					</p>
				</td>
			</tr>
		</table>
		<?php
	}

	public function save_profile_fields( $user_id ): void {
		$user_id = (int) $user_id;
		if ( ! $user_id ) {
			return;
		}

		// Nonce dedicato: se la nostra sezione non è stata inviata non si tocca nulla.
		if ( empty( $_POST['dealer_notifications_nonce'] ) ) {
			return;
		}
		$nonce = sanitize_text_field( wp_unslash( $_POST['dealer_notifications_nonce'] ) );
		if ( ! wp_verify_nonce( $nonce, self::NONCE_PROFILE ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}

		update_user_meta( $user_id, self::PREF_NEW,      empty( $_POST['dealer_notify_new'] ) ? '0' : '1' );
		update_user_meta( $user_id, self::PREF_EXPIRING, empty( $_POST['dealer_notify_expiring'] ) ? '0' : '1' );
	}

	// ─── Pagina impostazioni ─────────────────────────────────────────────────

	public function register_menu(): void {
		add_submenu_page(
			'dealer-portal',
			'Notifiche',
			'Notifiche',
			DEALER_PORTAL_CAP,
			self::MENU_SLUG,
			[ $this, 'render_settings_page' ]
		);
	}

	public function render_settings_page(): void {
		if ( ! current_user_can( DEALER_PORTAL_CAP ) ) {
			wp_die( esc_html__( 'Accesso non consentito.', 'dealer-portal' ) );
		}

		$options = self::get_options();
		$queue   = self::get_queue();

		// Solo lettura di un flag di stato dopo il redirect: nessun nonce necessario.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$notice = isset( $_GET['dp_notice'] ) ? sanitize_key( wp_unslash( $_GET['dp_notice'] ) ) : '';

		$messages = [
			'saved'       => [ 'notice-success', 'Impostazioni salvate.' ],
			'test-ok'     => [ 'notice-success', 'Email di prova inviata all\'indirizzo del tuo account.' ],
			'test-failed' => [ 'notice-error',   'Invio dell\'email di prova fallito: controlla la configurazione SMTP del sito.' ],
		];
		?>
		<div class="wrap">
			<h1>Notifiche Dealer Portal</h1>

			<?php if ( isset( $messages[ $notice ] ) ) : ?>
				<div class="notice <?php echo esc_attr( $messages[ $notice ][0] ); ?> is-dismissible">
					<p><?php echo esc_html( $messages[ $notice ][1] ); ?></p>
				</div>
			<?php endif; ?>

			<p>
				Le email vengono inviate solo agli utenti che hanno realmente accesso al documento
				(stesso controllo di ruolo e linea usato dalla ricerca) e che non hanno disattivato
				la notifica nel proprio profilo.
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="dealer_portal_notifications_save" />
				<?php wp_nonce_field( self::NONCE_SETTINGS ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">Tipi di notifica</th>
						<td>
							<label>
								<input type="checkbox" name="enable_new" value="1" <?php checked( ! empty( $options['enable_new'] ) ); ?> />
								Nuovo documento / nuova versione ai dealer
							</label><br />
							<label>
								<input type="checkbox" name="enable_expiring" value="1" <?php checked( ! empty( $options['enable_expiring'] ) ); ?> />
								Digest settimanale scadenze ai dealer
							</label><br />
							<label>
								<input type="checkbox" name="enable_admin" value="1" <?php checked( ! empty( $options['enable_admin'] ) ); ?> />
								Report scadenze agli amministratori
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="dp_from_name">Nome mittente</label></th>
						<td>
							<input type="text" class="regular-text" id="dp_from_name" name="from_name"
								value="<?php echo esc_attr( $options['from_name'] ); ?>" />
							<p class="description">Lascia vuoto per usare il mittente predefinito di WordPress.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="dp_from_email">Email mittente</label></th>
						<td>
							<input type="email" class="regular-text" id="dp_from_email" name="from_email"
								value="<?php echo esc_attr( $options['from_email'] ); ?>" />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="dp_admin_recipients">Destinatari report admin</label></th>
						<td>
							<input type="text" class="regular-text" id="dp_admin_recipients" name="admin_recipients"
								value="<?php echo esc_attr( $options['admin_recipients'] ); ?>" />
							<p class="description">
								Indirizzi separati da virgola. Vuoto = <?php echo esc_html( (string) get_option( 'admin_email' ) ); ?>.
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="dp_search_url">URL pagina di ricerca</label></th>
						<td>
							<input type="url" class="regular-text" id="dp_search_url" name="search_url"
								value="<?php echo esc_attr( $options['search_url'] ); ?>" />
							<p class="description">
								Lascia vuoto per rilevarla automaticamente. Attuale:
								<code><?php echo esc_html( self::search_url() ); ?></code>
							</p>
						</td>
					</tr>
				</table>

				<?php submit_button( 'Salva impostazioni' ); ?>
			</form>

			<h2>Pianificazione</h2>
			<table class="widefat striped" style="max-width:820px">
				<thead>
					<tr><th>Evento</th><th>Hook cron</th><th>Prossimo invio</th></tr>
				</thead>
				<tbody>
					<?php
					$rows = [
						'Digest settimanale scadenze (dealer)' => self::CRON_DEALER_DIGEST,
						'Report scadenze (amministratori)'     => self::CRON_ADMIN_REPORT,
						'Elaborazione coda di invio'           => self::CRON_QUEUE,
					];
					foreach ( $rows as $label => $hook ) :
						$next = wp_next_scheduled( $hook );
						?>
						<tr>
							<td><?php echo esc_html( $label ); ?></td>
							<td><code><?php echo esc_html( $hook ); ?></code></td>
							<td>
								<?php
								echo $next
									? esc_html( wp_date( 'd/m/Y H:i', (int) $next ) )
									: '<span style="color:#777">non pianificato</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
								?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description">
				Job attualmente in coda: <strong><?php echo esc_html( (string) count( $queue ) ); ?></strong>.
				Gli invii massivi non avvengono durante il salvataggio del documento ma in blocchi
				da <?php echo esc_html( (string) self::BATCH_SIZE ); ?> destinatari via WP-Cron.
			</p>

			<h2>Email di prova</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="dealer_portal_notifications_test" />
				<?php wp_nonce_field( self::NONCE_TEST ); ?>
				<p>
					Invia un'email di prova a
					<strong><?php echo esc_html( (string) wp_get_current_user()->user_email ); ?></strong>
					per verificare formattazione e recapito.
				</p>
				<?php submit_button( 'Invia email di prova', 'secondary' ); ?>
			</form>
		</div>
		<?php
	}

	public function handle_save_settings(): void {
		if ( ! current_user_can( DEALER_PORTAL_CAP ) ) {
			wp_die( esc_html__( 'Accesso non consentito.', 'dealer-portal' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( self::NONCE_SETTINGS );

		$options = self::get_options();

		$options['enable_new']       = empty( $_POST['enable_new'] ) ? 0 : 1;
		$options['enable_expiring']  = empty( $_POST['enable_expiring'] ) ? 0 : 1;
		$options['enable_admin']     = empty( $_POST['enable_admin'] ) ? 0 : 1;
		$options['from_name']        = sanitize_text_field( wp_unslash( $_POST['from_name'] ?? '' ) );

		$from_email             = sanitize_email( wp_unslash( $_POST['from_email'] ?? '' ) );
		$options['from_email']  = is_email( $from_email ) ? $from_email : '';

		$options['admin_recipients'] = sanitize_text_field( wp_unslash( $_POST['admin_recipients'] ?? '' ) );

		$search_url            = esc_url_raw( wp_unslash( $_POST['search_url'] ?? '' ) );
		$options['search_url'] = $search_url;

		update_option( self::OPTION, $options );

		// Le pianificazioni restano attive anche a notifiche disattivate: i
		// callback controllano i flag, così riattivare non richiede altro.
		self::maybe_schedule_events();

		wp_safe_redirect( add_query_arg( 'dp_notice', 'saved', admin_url( 'admin.php?page=' . self::MENU_SLUG ) ) );
		exit;
	}

	public function handle_test_email(): void {
		if ( ! current_user_can( DEALER_PORTAL_CAP ) ) {
			wp_die( esc_html__( 'Accesso non consentito.', 'dealer-portal' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( self::NONCE_TEST );

		$user = wp_get_current_user();

		$body = '<p>Questa è un\'email di prova del modulo notifiche del Dealer Portal.</p>'
			. '<p>Se la ricevi correttamente formattata, la configurazione di invio del sito è funzionante.</p>'
			. '<p><a class="dp-button" href="' . esc_url( self::search_url() ) . '">Vai alla ricerca documenti</a></p>';

		$text = "Email di prova del modulo notifiche del Dealer Portal.\n\n"
			. "Ricerca documenti: " . self::search_url() . "\n\n"
			. self::text_footer();

		$sent = self::send_html(
			(string) $user->user_email,
			sprintf( '[%s] Email di prova — notifiche Dealer Portal', self::site_name() ),
			self::wrap_html( 'Email di prova', $body ),
			$text
		);

		wp_safe_redirect( add_query_arg(
			'dp_notice',
			$sent ? 'test-ok' : 'test-failed',
			admin_url( 'admin.php?page=' . self::MENU_SLUG )
		) );
		exit;
	}
}
