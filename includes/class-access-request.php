<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Richieste di accesso self-service dei dealer.
 *
 * Flusso completo:
 *   1. Il visitatore compila lo shortcode [dealer_access_request] (form pubblico,
 *      non autenticato) → viene creata una richiesta nel CPT privato
 *      `dealer_access_req` con stato "in attesa". Nessun utente viene creato qui.
 *   2. Gli amministratori ricevono una notifica e trovano la richiesta in
 *      Dealer Portal → Richieste Accesso.
 *   3. Un utente con DEALER_PORTAL_CAP approva scegliendo ruolo e linee prodotto
 *      definitive (quelle richieste sono solo una proposta) oppure rifiuta.
 *   4. Solo all'approvazione viene creato l'utente WordPress, con `_dealer_lines`
 *      valorizzato, e gli viene inviato un link per impostare la password.
 *      Nessuna password in chiaro viene mai generata né inviata.
 *
 * Superficie pubblica non autenticata: nonce, honeypot, controllo temporale,
 * rate limiting per IP e nessuna user enumeration.
 */
class Dealer_Access_Request {

	// ─── Costanti ─────────────────────────────────────────────────────────────

	/** CPT privato che archivia le richieste. */
	const CPT = 'dealer_access_req';

	/** Slug del sottomenu admin (figlio di `dealer-portal`). */
	const MENU_SLUG = 'dealer-portal-access-requests';

	/** Shortcode del form pubblico. */
	const SHORTCODE = 'dealer_access_request';

	/** Stati possibili di una richiesta. */
	const STATUS_PENDING  = 'pending';
	const STATUS_APPROVED = 'approved';
	const STATUS_REJECTED = 'rejected';

	/** Ruoli assegnabili in approvazione. Mai accettare altro dal POST. */
	const ALLOWED_ROLES = [ 'dealer', 'top_dealer', 'part_center' ];

	/** Anti-spam: massimo di richieste per IP nella finestra temporale. */
	const RATE_LIMIT_MAX    = 3;
	const RATE_LIMIT_WINDOW = HOUR_IN_SECONDS;

	/** Anti-bot: secondi minimi fra rendering del form e invio. */
	const MIN_FORM_SECONDS = 4;

	/** Anti-replay: il form scade dopo questo intervallo. */
	const MAX_FORM_SECONDS = 6 * HOUR_IN_SECONDS;

	/** Finestra entro cui una richiesta con la stessa email è considerata doppia. */
	const DUPLICATE_WINDOW = DAY_IN_SECONDS;

	/** Nome del campo honeypot (invisibile agli umani, allettante per i bot). */
	const HONEYPOT_FIELD = 'dar_website_url';

	/** Richieste per pagina nella coda admin. */
	const PER_PAGE = 20;

	/** Cache interna del conteggio richieste in attesa. */
	private static $pending_count_cache = null;

	// ─── Constructor ──────────────────────────────────────────────────────────

	public function __construct() {
		// La classe viene istanziata su 'init': se l'hook è già partito
		// registriamo il CPT subito, altrimenti agganciamo normalmente.
		if ( did_action( 'init' ) ) {
			$this->register_cpt();
		} else {
			add_action( 'init', [ $this, 'register_cpt' ] );
		}

		add_shortcode( self::SHORTCODE, [ $this, 'render_form_shortcode' ] );

		// Front-end: intercetta l'invio prima di qualsiasi output (pattern PRG).
		add_action( 'template_redirect', [ $this, 'maybe_handle_submission' ] );

		// Admin: menu + handler approvazione/rifiuto.
		add_action( 'admin_menu', [ $this, 'register_menu' ], 20 );
		add_action( 'admin_post_dealer_access_approve', [ $this, 'handle_approve' ] );
		add_action( 'admin_post_dealer_access_reject',  [ $this, 'handle_reject' ] );
	}

	// ─── CPT ──────────────────────────────────────────────────────────────────

	/**
	 * CPT privato: nessun URL pubblico, nessuna UI, nessun endpoint REST.
	 * Stesso principio già applicato a `documento_dealer`.
	 */
	public function register_cpt(): void {
		if ( post_type_exists( self::CPT ) ) {
			return;
		}

		register_post_type( self::CPT, [
			'labels'              => [
				'name'          => 'Richieste Accesso',
				'singular_name' => 'Richiesta Accesso',
			],
			'public'              => false,   // Nessun URL pubblico
			'publicly_queryable'  => false,
			'exclude_from_search' => true,
			'show_ui'             => false,   // Gestita solo dalla nostra pagina admin
			'show_in_menu'        => false,
			'show_in_nav_menus'   => false,
			'show_in_rest'        => false,   // Nessun endpoint REST
			'has_archive'         => false,
			'rewrite'             => false,
			'query_var'           => false,
			'supports'            => [ 'title' ],
			// Permessi mappati sulla capability del plugin invece che su
			// quelle generiche dei post: le richieste contengono dati
			// anagrafici di aziende terze e non devono essere leggibili da
			// chiunque abbia i permessi sui contenuti del sito.
			'map_meta_cap'        => true,
			'capabilities'        => Dealer_Organization::post_type_capabilities( DEALER_PORTAL_CAP ),
		] );
	}

	// ─── Front-end: shortcode ─────────────────────────────────────────────────

	/**
	 * Rende il form pubblico di richiesta accesso.
	 */
	public function render_form_shortcode( $atts ): string {
		$atts = shortcode_atts( [
			'titolo' => 'Richiedi l’accesso all’area riservata',
		], is_array( $atts ) ? $atts : [], self::SHORTCODE );

		// Feedback dal ciclo PRG precedente (token monouso in query string).
		$feedback = $this->consume_feedback();

		$title          = (string) $atts['titolo'];
		$is_logged_in   = is_user_logged_in();
		$lines_by_brand = Dealer_Admin::get_product_lines();
		$prefill        = isset( $feedback['data'] ) && is_array( $feedback['data'] ) ? $feedback['data'] : [];
		$form_time      = time();
		$form_time_hash = self::time_hash( $form_time );
		$form_action    = $this->current_url();
		$honeypot       = self::HONEYPOT_FIELD;

		ob_start();
		require DEALER_PORTAL_PATH . 'templates/access-request-form.php';
		return (string) ob_get_clean();
	}

	// ─── Front-end: gestione invio (PRG) ──────────────────────────────────────

	/**
	 * Intercetta il POST del form pubblico. Nessun output: si conclude sempre
	 * con un redirect, così un refresh non reinvia la richiesta.
	 */
	public function maybe_handle_submission(): void {
		if ( empty( $_POST['dar_action'] ) ) {
			return;
		}
		if ( 'submit_access_request' !== sanitize_key( self::post_string( 'dar_action' ) ) ) {
			return;
		}

		$redirect_base = $this->current_url();

		// 1. Nonce.
		$nonce = sanitize_text_field( self::post_string( 'dar_nonce' ) );
		if ( ! wp_verify_nonce( $nonce, 'dealer_access_request' ) ) {
			$this->redirect_with_feedback( $redirect_base, 'error', 'La sessione del modulo è scaduta. Ricarica la pagina e riprova.', [] );
		}

		// 2. Honeypot: i bot compilano il campo nascosto. Risposta identica a
		//    quella di successo, così il bot non impara nulla.
		if ( ! empty( $_POST[ self::HONEYPOT_FIELD ] ) ) {
			$this->redirect_with_feedback( $redirect_base, 'success', $this->success_message(), [] );
		}

		// 3. Controllo temporale: un umano non compila il form in meno di
		//    MIN_FORM_SECONDS, e un form vecchio ore è un replay.
		$form_time = absint( self::post_string( 'dar_time' ) );
		$time_hash = sanitize_text_field( self::post_string( 'dar_time_hash' ) );
		$elapsed   = time() - $form_time;
		if ( ! $form_time || ! hash_equals( self::time_hash( $form_time ), $time_hash ) ) {
			$this->redirect_with_feedback( $redirect_base, 'error', 'Modulo non valido. Ricarica la pagina e riprova.', [] );
		}
		if ( $elapsed < self::MIN_FORM_SECONDS ) {
			$this->redirect_with_feedback( $redirect_base, 'error', 'Invio troppo rapido: attendi qualche secondo e riprova.', $this->collect_input() );
		}
		if ( $elapsed > self::MAX_FORM_SECONDS ) {
			$this->redirect_with_feedback( $redirect_base, 'error', 'Il modulo è scaduto. Ricarica la pagina e riprova.', $this->collect_input() );
		}

		// 4. Rate limiting per IP.
		if ( $this->rate_limit_exceeded() ) {
			$this->redirect_with_feedback(
				$redirect_base,
				'error',
				'Hai già inviato diverse richieste di recente. Riprova fra un’ora oppure scrivi direttamente al nostro supporto.',
				[]
			);
		}

		// 5. Sanitizzazione + validazione dei campi.
		$data   = $this->collect_input();
		$errors = [];

		if ( '' === $data['company'] ) {
			$errors[] = 'La ragione sociale è obbligatoria.';
		}
		if ( '' === $data['contact'] ) {
			$errors[] = 'Il nome del referente è obbligatorio.';
		}
		if ( '' === $data['email'] || ! is_email( $data['email'] ) ) {
			$errors[] = 'Inserisci un indirizzo email valido.';
		}
		if ( '' === $data['phone'] ) {
			$errors[] = 'Il numero di telefono è obbligatorio.';
		}
		if ( '' === $data['vat'] ) {
			$errors[] = 'La partita IVA è obbligatoria.';
		} elseif ( ! preg_match( '/^[A-Za-z0-9]{8,20}$/', str_replace( [ ' ', '.', '-' ], '', $data['vat'] ) ) ) {
			$errors[] = 'La partita IVA non sembra valida (da 8 a 20 caratteri alfanumerici).';
		}
		if ( empty( $data['lines'] ) ) {
			$errors[] = 'Seleziona almeno una linea prodotto di interesse.';
		}

		if ( $errors ) {
			$this->redirect_with_feedback( $redirect_base, 'error', implode( ' ', $errors ), $data );
		}

		// Da qui in poi la richiesta è formalmente accettabile: conta nel rate limit
		// anche se poi risulta un duplicato (evita di aggirare il limite).
		$this->rate_limit_bump();

		// 6. Doppio invio: una richiesta ancora in attesa con la stessa email nelle
		//    ultime 24 ore non ne genera una seconda. Messaggio identico.
		if ( $this->has_recent_pending_request( $data['email'] ) ) {
			$this->redirect_with_feedback( $redirect_base, 'success', $this->success_message(), [] );
		}

		// 7. Nessuna user enumeration: se l'email appartiene già a un utente, la
		//    richiesta viene comunque archiviata e segnalata all'admin, ma il
		//    visitatore vede esattamente lo stesso messaggio di conferma.
		$existing_user = (bool) email_exists( $data['email'] );

		$request_id = $this->create_request( $data, $existing_user );

		if ( $request_id ) {
			$this->notify_admins_new_request( $request_id, $data, $existing_user );
			/**
			 * Nuova richiesta di accesso archiviata.
			 *
			 * @param int   $request_id ID del post della richiesta.
			 * @param array $data       Dati sanitizzati della richiesta.
			 */
			do_action( 'dealer_access_request_created', $request_id, $data );
		}

		$this->redirect_with_feedback( $redirect_base, 'success', $this->success_message(), [] );
	}

	/**
	 * Messaggio di conferma. Identico in ogni scenario accettato: richiesta nuova,
	 * doppio invio, email già presente a sistema.
	 */
	private function success_message(): string {
		return 'Richiesta inviata correttamente. Il nostro staff la esaminerà e ti contatterà '
			. 'all’indirizzo email indicato. Se il tuo account risulta già attivo riceverai comunque '
			. 'istruzioni via email.';
	}

	/**
	 * Sanitizza tutti i campi in ingresso. Le linee sono sempre validate contro
	 * la whitelist server-side: quello che arriva dal client non è affidabile.
	 */
	private function collect_input(): array {
		$raw_lines = isset( $_POST['dar_lines'] ) ? (array) wp_unslash( $_POST['dar_lines'] ) : [];
		$lines     = array_values( array_intersect(
			array_map( 'sanitize_text_field', $raw_lines ),
			Dealer_Admin::get_valid_lines()
		) );

		return [
			'company' => sanitize_text_field(     self::post_string( 'dar_company' ) ),
			'contact' => sanitize_text_field(     self::post_string( 'dar_contact' ) ),
			'email'   => sanitize_email(          self::post_string( 'dar_email' ) ),
			'phone'   => sanitize_text_field(     self::post_string( 'dar_phone' ) ),
			'vat'     => sanitize_text_field(     self::post_string( 'dar_vat' ) ),
			'notes'   => sanitize_textarea_field( self::post_string( 'dar_notes' ) ),
			'lines'   => $lines,
		];
	}

	/**
	 * Legge un campo POST come stringa già unslashed. Un array inviato al posto
	 * di uno scalare (trucco classico per far esplodere le sanitize_*) diventa
	 * stringa vuota.
	 */
	private static function post_string( string $key ): string {
		if ( ! isset( $_POST[ $key ] ) || ! is_scalar( $_POST[ $key ] ) ) {
			return '';
		}
		return (string) wp_unslash( $_POST[ $key ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitizzato dal chiamante.
	}

	/** Come post_string(), ma per la query string. */
	private static function get_string( string $key ): string {
		if ( ! isset( $_GET[ $key ] ) || ! is_scalar( $_GET[ $key ] ) ) {
			return '';
		}
		return (string) wp_unslash( $_GET[ $key ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitizzato dal chiamante.
	}

	/**
	 * Crea il post della richiesta con tutti i meta sanitizzati.
	 * Nessun utente viene creato in questo percorso.
	 */
	private function create_request( array $data, bool $existing_user ): int {
		$post_id = wp_insert_post( [
			'post_type'   => self::CPT,
			'post_status' => 'publish',
			'post_title'  => $data['company'] . ' — ' . $data['email'],
			'post_author' => 0,
		], true );

		if ( is_wp_error( $post_id ) || ! $post_id ) {
			return 0;
		}

		$meta = [
			'_dar_company'       => $data['company'],
			'_dar_contact'       => $data['contact'],
			'_dar_email'         => $data['email'],
			'_dar_phone'         => $data['phone'],
			'_dar_vat'           => $data['vat'],
			'_dar_notes'         => $data['notes'],
			'_dar_lines'         => $data['lines'],
			'_dar_status'        => self::STATUS_PENDING,
			'_dar_submitted'     => current_time( 'mysql' ),
			'_dar_ip'            => $this->client_ip(),
			'_dar_existing_user' => $existing_user ? '1' : '0',
		];
		foreach ( $meta as $key => $value ) {
			update_post_meta( (int) $post_id, $key, $value );
		}

		self::$pending_count_cache = null;

		return (int) $post_id;
	}

	// ─── Anti-abuso ───────────────────────────────────────────────────────────

	/**
	 * IP del client, validato con FILTER_VALIDATE_IP come in Dealer_DB::log_download().
	 */
	private function client_ip(): string {
		$ip = '';
		if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$parts = explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) );
			$ip    = trim( $parts[0] );
		} elseif ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}

		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			$ip = '';
		}
		return $ip;
	}

	private function rate_limit_key(): string {
		$ip = $this->client_ip();
		// IP assente o non valido: bucket condiviso, non un lasciapassare.
		return 'dar_rl_' . md5( '' !== $ip ? $ip : 'invalid-ip' );
	}

	private function rate_limit_exceeded(): bool {
		$count = (int) get_transient( $this->rate_limit_key() );
		return $count >= self::RATE_LIMIT_MAX;
	}

	private function rate_limit_bump(): void {
		$key   = $this->rate_limit_key();
		$count = (int) get_transient( $key );
		// Finestra scorrevole: ogni invio accettato rinnova la TTL, quindi dopo
		// RATE_LIMIT_MAX richieste il blocco dura un'ora dall'ultimo tentativo.
		set_transient( $key, $count + 1, self::RATE_LIMIT_WINDOW );
	}

	/** Hash firmato del timestamp di rendering: impedisce di falsificare il tempo. */
	private static function time_hash( int $timestamp ): string {
		return wp_hash( $timestamp . '|dealer_access_request_form' );
	}

	/**
	 * Esiste già una richiesta in attesa con questa email nelle ultime 24 ore?
	 */
	private function has_recent_pending_request( string $email ): bool {
		if ( '' === $email ) {
			return false;
		}

		$query = new WP_Query( [
			'post_type'      => self::CPT,
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'date_query'     => [
				[
					'column'    => 'post_date_gmt',
					'after'     => gmdate( 'Y-m-d H:i:s', time() - self::DUPLICATE_WINDOW ),
					'inclusive' => true,
				],
			],
			'meta_query'     => [
				'relation' => 'AND',
				[ 'key' => '_dar_email',  'value' => $email,               'compare' => '=' ],
				[ 'key' => '_dar_status', 'value' => self::STATUS_PENDING, 'compare' => '=' ],
			],
		] );

		return ! empty( $query->posts );
	}

	// ─── PRG: feedback via transient monouso ──────────────────────────────────

	/**
	 * Salva il feedback in un transient e redirige. Nessun dato sensibile finisce
	 * in query string: solo un token opaco.
	 */
	private function redirect_with_feedback( string $url, string $status, string $message, array $data ): void {
		// Token in hex minuscolo: sopravvive intatto a sanitize_key() in lettura.
		$token = md5( wp_generate_password( 32, true, true ) . microtime( true ) );
		set_transient( 'dar_fb_' . $token, [
			'status'  => ( 'success' === $status ) ? 'success' : 'error',
			'message' => $message,
			'data'    => $data,
		], 10 * MINUTE_IN_SECONDS );

		wp_safe_redirect( add_query_arg( 'dar_ref', $token, $url ) . '#dealer-access-request' );
		exit;
	}

	/** Legge e cancella il feedback (monouso). */
	private function consume_feedback(): array {
		$token = sanitize_key( self::get_string( 'dar_ref' ) );
		if ( '' === $token ) {
			return [];
		}
		$feedback = get_transient( 'dar_fb_' . $token );
		delete_transient( 'dar_fb_' . $token );

		return is_array( $feedback ) ? $feedback : [];
	}

	/** URL corrente senza il token di feedback già consumato. */
	private function current_url(): string {
		$permalink = is_singular() ? get_permalink() : '';

		if ( ! $permalink ) {
			// Fallback (shortcode fuori da una pagina singola). L'host viene
			// comunque validato da wp_safe_redirect().
			$scheme = is_ssl() ? 'https://' : 'http://';
			$host   = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
			$uri    = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
			$permalink = '' !== $host ? esc_url_raw( $scheme . $host . $uri ) : home_url( '/' );
		}

		return remove_query_arg( 'dar_ref', $permalink );
	}

	// ─── Notifica agli amministratori ─────────────────────────────────────────

	private function notify_admins_new_request( int $request_id, array $data, bool $existing_user ): void {
		$recipients = apply_filters(
			'dealer_access_request_admin_recipients',
			[ get_option( 'admin_email' ) ],
			$request_id
		);
		$recipients = array_filter( array_map( 'sanitize_email', (array) $recipients ) );
		if ( ! $recipients ) {
			return;
		}

		$queue_url = admin_url( 'admin.php?page=' . self::MENU_SLUG );
		$lines     = $data['lines'] ? implode( ', ', array_map( [ __CLASS__, 'line_label' ], $data['lines'] ) ) : '—';

		$subject = sprintf( '[%s] Nuova richiesta di accesso dealer', wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );

		$body  = "È arrivata una nuova richiesta di accesso all'area riservata.\n\n";
		$body .= 'Ragione sociale: ' . $data['company'] . "\n";
		$body .= 'Referente: '       . $data['contact'] . "\n";
		$body .= 'Email: '           . $data['email']   . "\n";
		$body .= 'Telefono: '        . $data['phone']   . "\n";
		$body .= 'Partita IVA: '     . $data['vat']     . "\n";
		$body .= 'Linee richieste: ' . $lines . "\n";
		if ( '' !== $data['notes'] ) {
			$body .= "Note:\n" . $data['notes'] . "\n";
		}
		if ( $existing_user ) {
			$body .= "\nATTENZIONE: esiste già un utente WordPress con questa email. "
				. "La richiesta è stata comunque archiviata; valutala manualmente.\n";
		}
		$body .= "\nEsamina la richiesta qui:\n" . $queue_url . "\n";

		wp_mail( $recipients, $subject, $body );
	}

	// ─── Admin: menu ──────────────────────────────────────────────────────────

	public function register_menu(): void {
		$pending = self::get_pending_count();

		$title = 'Richieste Accesso';
		if ( $pending > 0 ) {
			// Pattern nativo WordPress per il badge dei contenuti in attesa.
			$title .= sprintf(
				' <span class="awaiting-mod count-%1$d"><span class="pending-count">%1$d</span></span>',
				$pending
			);
		}

		add_submenu_page(
			'dealer-portal',
			'Richieste Accesso',
			$title,
			DEALER_PORTAL_CAP,
			self::MENU_SLUG,
			[ $this, 'render_admin_page' ]
		);
	}

	// ─── Admin: coda di approvazione ──────────────────────────────────────────

	public function render_admin_page(): void {
		if ( ! current_user_can( DEALER_PORTAL_CAP ) ) {
			wp_die( esc_html__( 'Accesso non consentito.', 'dealer-portal' ) );
		}

		$filter_status = sanitize_key( self::get_string( 'dar_status' ) );
		if ( ! in_array( $filter_status, [ self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_REJECTED, 'all' ], true ) ) {
			$filter_status = self::STATUS_PENDING;
		}
		$paged = max( 1, absint( $_GET['paged'] ?? 1 ) );

		$meta_query = [];
		if ( 'all' !== $filter_status ) {
			$meta_query[] = [ 'key' => '_dar_status', 'value' => $filter_status, 'compare' => '=' ];
		}

		$args = [
			'post_type'      => self::CPT,
			'post_status'    => 'publish',
			'posts_per_page' => self::PER_PAGE,
			'paged'          => $paged,
			'orderby'        => 'date',
			'order'          => 'DESC',
		];
		if ( $meta_query ) {
			$args['meta_query'] = $meta_query;
		}

		$query       = new WP_Query( $args );
		$requests    = $query->posts;
		$total_pages = (int) $query->max_num_pages;

		$counts         = self::get_status_counts();
		$lines_by_brand = Dealer_Admin::get_product_lines();
		$roles          = self::get_role_labels();
		$notice         = $this->admin_notice_from_query();

		require DEALER_PORTAL_PATH . 'templates/admin-access-requests.php';
	}

	/** Traduce il codice di esito passato in query string in un avviso admin. */
	private function admin_notice_from_query(): array {
		$code = sanitize_key( self::get_string( 'dar_msg' ) );
		if ( '' === $code ) {
			return [];
		}

		$map = [
			'approved'      => [ 'success', 'Richiesta approvata: l’utente è stato creato e ha ricevuto il link per impostare la password.' ],
			'approved_nomail' => [ 'warning', 'Utente creato correttamente, ma l’invio dell’email personalizzata non è riuscito: è stata tentata la notifica standard di WordPress. Verifica che il dealer abbia ricevuto il link per impostare la password.' ],
			'rejected'      => [ 'success', 'Richiesta rifiutata.' ],
			'err_role'      => [ 'error',   'Ruolo non valido. Operazione annullata.' ],
			'err_lines'     => [ 'error',   'Seleziona almeno una linea prodotto valida prima di approvare.' ],
			'err_request'   => [ 'error',   'Richiesta non trovata o già evasa.' ],
			'err_email'     => [ 'error',   'Email non valida: impossibile creare l’utente.' ],
			'err_exists'    => [ 'error',   'Esiste già un utente WordPress con questa email. Assegna ruolo e linee manualmente dal profilo utente, poi rifiuta questa richiesta per archiviarla.' ],
			'err_user'      => [ 'error',   'Creazione dell’utente non riuscita. Controlla i log del sito.' ],
		];

		if ( ! isset( $map[ $code ] ) ) {
			return [];
		}
		return [ 'type' => $map[ $code ][0], 'message' => $map[ $code ][1] ];
	}

	// ─── Admin: approvazione ──────────────────────────────────────────────────

	/**
	 * Approva una richiesta: crea l'utente con il ruolo scelto dall'admin e le
	 * linee prodotto confermate/corrette dall'admin, poi invia il link per
	 * impostare la password. Nessuna password in chiaro viene mai generata.
	 */
	public function handle_approve(): void {
		$request_id = absint( $_POST['request_id'] ?? 0 );

		check_admin_referer( 'dealer_access_approve_' . $request_id );

		if ( ! current_user_can( DEALER_PORTAL_CAP ) ) {
			wp_die( esc_html__( 'Accesso non consentito.', 'dealer-portal' ), '', [ 'response' => 403 ] );
		}

		if ( ! $request_id || get_post_type( $request_id ) !== self::CPT ) {
			$this->admin_redirect( 'err_request' );
		}
		if ( self::STATUS_PENDING !== get_post_meta( $request_id, '_dar_status', true ) ) {
			$this->admin_redirect( 'err_request' );
		}

		// Ruolo: solo uno dei tre consentiti. Mai fidarsi del POST.
		$role = sanitize_key( self::post_string( 'approved_role' ) );
		if ( ! in_array( $role, self::ALLOWED_ROLES, true ) ) {
			$this->admin_redirect( 'err_role' );
		}

		// Linee: whitelist server-side. Le linee chieste dal dealer sono solo una
		// proposta, qui vale la decisione dell'admin.
		$raw_lines = isset( $_POST['approved_lines'] ) ? (array) wp_unslash( $_POST['approved_lines'] ) : [];
		$lines     = array_values( array_intersect(
			array_map( 'sanitize_text_field', $raw_lines ),
			Dealer_Admin::get_valid_lines()
		) );
		if ( ! $lines ) {
			$this->admin_redirect( 'err_lines', $request_id );
		}

		$email   = sanitize_email( (string) get_post_meta( $request_id, '_dar_email', true ) );
		$company = (string) get_post_meta( $request_id, '_dar_company', true );
		$contact = (string) get_post_meta( $request_id, '_dar_contact', true );
		$phone   = (string) get_post_meta( $request_id, '_dar_phone', true );
		$vat     = (string) get_post_meta( $request_id, '_dar_vat', true );

		if ( '' === $email || ! is_email( $email ) ) {
			$this->admin_redirect( 'err_email', $request_id );
		}
		if ( email_exists( $email ) ) {
			// Non tocchiamo utenti esistenti: cambiare ruolo a un account già a
			// sistema (potenzialmente un amministratore) sarebbe pericoloso.
			$this->admin_redirect( 'err_exists', $request_id );
		}

		// Password casuale mai comunicata: l'utente la imposta dal link di reset.
		$user_id = wp_insert_user( [
			'user_login'   => $this->generate_login( $email ),
			'user_email'   => $email,
			'user_pass'    => wp_generate_password( 32, true, true ),
			'display_name' => '' !== $company ? $company : $contact,
			'nickname'     => '' !== $company ? $company : $contact,
			'first_name'   => $contact,
			'role'         => $role,
		] );

		if ( is_wp_error( $user_id ) || ! $user_id ) {
			$this->admin_redirect( 'err_user', $request_id );
		}
		$user_id = (int) $user_id;

		// Meta utente: linee approvate + scheda referente (stesso modello dati
		// già usato dal profilo utente in Dealer_Admin).
		update_user_meta( $user_id, '_dealer_lines',      $lines );
		update_user_meta( $user_id, '_referente_nome',     $contact );
		update_user_meta( $user_id, '_referente_email',    $email );
		update_user_meta( $user_id, '_referente_telefono', $phone );
		update_user_meta( $user_id, '_dar_company',        $company );
		update_user_meta( $user_id, '_dar_vat',            $vat );

		// Stato della richiesta.
		update_post_meta( $request_id, '_dar_status',          self::STATUS_APPROVED );
		update_post_meta( $request_id, '_dar_approved_role',   $role );
		update_post_meta( $request_id, '_dar_approved_lines',  $lines );
		update_post_meta( $request_id, '_dar_user_id',         $user_id );
		update_post_meta( $request_id, '_dar_reviewed_by',     get_current_user_id() );
		update_post_meta( $request_id, '_dar_reviewed_at',     current_time( 'mysql' ) );
		self::$pending_count_cache = null;

		$sent = $this->send_password_setup_email( $user_id, $lines );

		/**
		 * Richiesta approvata e utente creato.
		 *
		 * @param int   $request_id ID della richiesta.
		 * @param int   $user_id    ID del nuovo utente.
		 * @param string $role      Ruolo assegnato.
		 * @param array $lines      Linee prodotto approvate.
		 */
		do_action( 'dealer_access_request_approved', $request_id, $user_id, $role, $lines );

		$this->admin_redirect( $sent ? 'approved' : 'approved_nomail' );
	}

	/**
	 * Username univoco derivato dall'email. Mai esposto prima dell'approvazione.
	 */
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

	/**
	 * Invia il link per impostare la password usando il meccanismo nativo
	 * (chiave di reset). Nessuna password in chiaro viene generata o spedita.
	 */
	private function send_password_setup_email( int $user_id, array $lines ): bool {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return false;
		}

		$key = get_password_reset_key( $user );
		if ( is_wp_error( $key ) ) {
			// Fallback sul meccanismo nativo di WordPress.
			wp_new_user_notification( $user_id, null, 'user' );
			return true;
		}

		$reset_url = network_site_url(
			'wp-login.php?action=rp&key=' . rawurlencode( $key ) . '&login=' . rawurlencode( $user->user_login ),
			'login'
		);

		$site_name  = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$dashboard  = Dealer_DB::dashboard_url();
		$line_list  = $lines ? implode( "\n  - ", array_map( [ __CLASS__, 'line_label' ], $lines ) ) : '—';

		$subject = sprintf( '[%s] Il tuo accesso all’area riservata è attivo', $site_name );

		$body  = 'Ciao ' . $user->display_name . ",\n\n";
		$body .= 'la tua richiesta di accesso all’area riservata di ' . $site_name . " è stata approvata.\n\n";
		$body .= "Nome utente: " . $user->user_login . "\n\n";
		$body .= "Imposta la tua password da questo link (valido per un tempo limitato):\n";
		$body .= $reset_url . "\n\n";
		$body .= "Dopo aver impostato la password potrai accedere qui:\n" . $dashboard . "\n\n";
		$body .= "Linee prodotto abilitate:\n  - " . $line_list . "\n\n";
		$body .= "Se non hai richiesto questo accesso, ignora questa email.\n";

		if ( wp_mail( $user->user_email, $subject, $body ) ) {
			return true;
		}

		// Ultimo tentativo con la notifica nativa di WordPress (anch'essa a chiave
		// di reset, mai una password in chiaro). L'admin viene comunque avvisato.
		wp_new_user_notification( $user_id, null, 'user' );
		return false;
	}

	// ─── Admin: rifiuto ───────────────────────────────────────────────────────

	public function handle_reject(): void {
		$request_id = absint( $_POST['request_id'] ?? 0 );

		check_admin_referer( 'dealer_access_reject_' . $request_id );

		if ( ! current_user_can( DEALER_PORTAL_CAP ) ) {
			wp_die( esc_html__( 'Accesso non consentito.', 'dealer-portal' ), '', [ 'response' => 403 ] );
		}

		if ( ! $request_id || get_post_type( $request_id ) !== self::CPT ) {
			$this->admin_redirect( 'err_request' );
		}
		if ( self::STATUS_PENDING !== get_post_meta( $request_id, '_dar_status', true ) ) {
			$this->admin_redirect( 'err_request' );
		}

		$reason = sanitize_textarea_field( self::post_string( 'reject_reason' ) );
		$notify = ! empty( $_POST['notify_applicant'] );

		update_post_meta( $request_id, '_dar_status',        self::STATUS_REJECTED );
		update_post_meta( $request_id, '_dar_reject_reason', $reason );
		update_post_meta( $request_id, '_dar_reviewed_by',   get_current_user_id() );
		update_post_meta( $request_id, '_dar_reviewed_at',   current_time( 'mysql' ) );
		self::$pending_count_cache = null;

		if ( $notify ) {
			$email = sanitize_email( (string) get_post_meta( $request_id, '_dar_email', true ) );
			if ( $email && is_email( $email ) ) {
				$site_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
				$subject   = sprintf( '[%s] Esito della tua richiesta di accesso', $site_name );
				$body      = "Ciao,\n\ngrazie per il tuo interesse verso l’area riservata di " . $site_name . ".\n";
				$body     .= "Al momento non possiamo attivare il tuo accesso.\n";
				if ( '' !== $reason ) {
					$body .= "\nNota dallo staff:\n" . $reason . "\n";
				}
				$body .= "\nPer qualsiasi chiarimento puoi rispondere a questa email.\n";
				wp_mail( $email, $subject, $body );
			}
		}

		/**
		 * Richiesta rifiutata.
		 *
		 * @param int    $request_id ID della richiesta.
		 * @param string $reason     Motivazione (può essere vuota).
		 */
		do_action( 'dealer_access_request_rejected', $request_id, $reason );

		$this->admin_redirect( 'rejected' );
	}

	/** Redirect verso la coda admin con un codice di esito. */
	private function admin_redirect( string $code, int $highlight = 0 ): void {
		$args = [ 'page' => self::MENU_SLUG, 'dar_msg' => $code ];
		if ( $highlight ) {
			$args['dar_open'] = $highlight;
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	// ─── Helper statici ───────────────────────────────────────────────────────

	/**
	 * Numero di richieste ancora in attesa (usato dal badge del menu).
	 */
	public static function get_pending_count(): int {
		if ( null !== self::$pending_count_cache ) {
			return self::$pending_count_cache;
		}

		$query = new WP_Query( [
			'post_type'      => self::CPT,
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_query'     => [
				[ 'key' => '_dar_status', 'value' => self::STATUS_PENDING, 'compare' => '=' ],
			],
		] );

		self::$pending_count_cache = (int) $query->found_posts;
		return self::$pending_count_cache;
	}

	/** Conteggi per stato, per i filtri della coda admin. */
	public static function get_status_counts(): array {
		$counts = [ self::STATUS_PENDING => 0, self::STATUS_APPROVED => 0, self::STATUS_REJECTED => 0, 'all' => 0 ];

		foreach ( [ self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_REJECTED ] as $status ) {
			$query = new WP_Query( [
				'post_type'      => self::CPT,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => [
					[ 'key' => '_dar_status', 'value' => $status, 'compare' => '=' ],
				],
			] );
			$counts[ $status ] = (int) $query->found_posts;
			$counts['all']    += $counts[ $status ];
		}

		return $counts;
	}

	/** Etichette leggibili dei ruoli assegnabili. */
	public static function get_role_labels(): array {
		return [
			'dealer'      => 'Dealer',
			'top_dealer'  => 'Top Dealer',
			'part_center' => 'Parts Center',
		];
	}

	/** Etichette leggibili degli stati. */
	public static function get_status_labels(): array {
		return [
			self::STATUS_PENDING  => 'In attesa',
			self::STATUS_APPROVED => 'Approvata',
			self::STATUS_REJECTED => 'Rifiutata',
		];
	}

	/** "Brand|Linea" → "Brand › Linea". */
	public static function line_label( string $line ): string {
		return str_replace( '|', ' › ', $line );
	}

	/** Dati completi di una richiesta, già normalizzati per i template. */
	public static function get_request_data( int $post_id ): array {
		$lines = get_post_meta( $post_id, '_dar_lines', true );
		if ( ! is_array( $lines ) ) { $lines = []; }

		$approved_lines = get_post_meta( $post_id, '_dar_approved_lines', true );
		if ( ! is_array( $approved_lines ) ) { $approved_lines = []; }

		return [
			'id'             => $post_id,
			'company'        => (string) get_post_meta( $post_id, '_dar_company', true ),
			'contact'        => (string) get_post_meta( $post_id, '_dar_contact', true ),
			'email'          => (string) get_post_meta( $post_id, '_dar_email', true ),
			'phone'          => (string) get_post_meta( $post_id, '_dar_phone', true ),
			'vat'            => (string) get_post_meta( $post_id, '_dar_vat', true ),
			'notes'          => (string) get_post_meta( $post_id, '_dar_notes', true ),
			'lines'          => $lines,
			'status'         => (string) get_post_meta( $post_id, '_dar_status', true ),
			'submitted'      => (string) get_post_meta( $post_id, '_dar_submitted', true ),
			'ip'             => (string) get_post_meta( $post_id, '_dar_ip', true ),
			'existing_user'  => '1' === (string) get_post_meta( $post_id, '_dar_existing_user', true ),
			'approved_role'  => (string) get_post_meta( $post_id, '_dar_approved_role', true ),
			'approved_lines' => $approved_lines,
			'user_id'        => (int) get_post_meta( $post_id, '_dar_user_id', true ),
			'reviewed_by'    => (int) get_post_meta( $post_id, '_dar_reviewed_by', true ),
			'reviewed_at'    => (string) get_post_meta( $post_id, '_dar_reviewed_at', true ),
			'reject_reason'  => (string) get_post_meta( $post_id, '_dar_reject_reason', true ),
		];
	}
}
