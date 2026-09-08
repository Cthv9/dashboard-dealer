<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Bacheca della rete: domanda e offerta fra le aziende del portale.
 *
 * Nasce da un bisogno reale raccontato dalla rete: quando serve un pezzo che il
 * canale ordinario non ha, o quando se ne hanno troppi uguali, oggi si telefona
 * alle persone che si immagina possano averlo. Questa pagina mette quella
 * telefonata in un posto solo.
 *
 * ─── Il vincolo che decide tutto: non ci sono moderatori ───────────────────
 *
 * Il progetto e' costruito perche' moderare non serva, non perche' qualcuno
 * moderi. Quattro meccanismi, in ordine di importanza:
 *
 *  1. NESSUN THREAD PUBBLICO. E' la decisione principale. I commenti sono
 *     esattamente cio' che richiede un moderatore: e' li' che nascono le
 *     discussioni sui prezzi, le frecciate fra concorrenti, il chiarimento che
 *     degenera. Qui l'annuncio e' una scheda: si pubblica, si risponde in
 *     privato, si chiude. Toglie da solo la quasi totalita' del bisogno di
 *     controllo senza togliere nulla all'uso reale.
 *
 *  2. OGNI ANNUNCIO HA UN NOME SOPRA. Non e' internet: gli account li crea
 *     l'amministratore e appartengono ad aziende con una ragione sociale. La
 *     scheda mostra l'azienda, non un soprannome. In una rete dove tutti si
 *     conoscono e continueranno a lavorare insieme, la reputazione fa il
 *     lavoro del moderatore. E' il motivo per cui questa bacheca puo' reggere
 *     senza controllo e una bacheca aperta no.
 *
 *  3. SCADENZA AUTOMATICA. Una bacheca non moderata non muore di abusi, muore
 *     di annunci vecchi: chi telefona per un pezzo venduto sei mesi prima
 *     smette di fidarsi, e dopo due volte non torna. Ogni annuncio nasce con
 *     una data, l'autore riceve un promemoria prima della scadenza con due
 *     link (rinnova / risolto), e se non fa nulla l'annuncio si archivia da
 *     solo. Stesso principio di _doc_expiry sui documenti, stesso cron.
 *
 *  4. SEGNALAZIONE CON AUTO-NASCONDIMENTO. Oltre soglia segnalazioni da utenti
 *     DIVERSI l'annuncio si nasconde da solo ed entra in una coda admin, come
 *     le richieste di accesso. La rete si modera da sola; l'amministratore
 *     vede solo cio' che e' gia' stato fermato.
 *
 * A questo si aggiungono i tetti (annunci attivi per utente, pubblicazioni al
 * giorno) e la struttura obbligata dei campi: un modulo con dei campi limita
 * cio' che si puo' pubblicare molto piu' di qualunque regolamento.
 *
 * ─── Perimetro ─────────────────────────────────────────────────────────────
 *
 * E' l'unica pagina trasversale del portale: la vedono tutti gli utenti
 * registrati, qualunque sia il ruolo, area manager e amministratore compresi.
 * L'unica esclusione e' l'azienda sospesa, per la stessa ragione per cui non
 * vede i documenti.
 */
class Dealer_Board {

	// ─── Costanti ─────────────────────────────────────────────────────────────

	/** CPT privato che archivia gli annunci. */
	const CPT = 'dealer_listing';

	/** Shortcode della pagina. */
	const SHORTCODE = 'dealer_bacheca';

	/** Sottomenu admin (figlio di `dealer-portal`). */
	const MENU_SLUG = 'dealer-portal-board';

	/** Opzione con la configurazione della bacheca. */
	const OPTION = 'dealer_portal_board';

	/** Tipi di annuncio. */
	const TYPE_WANTED  = 'cerco';
	const TYPE_OFFERED = 'offro';

	/** Stati. */
	const STATUS_ACTIVE  = 'attivo';
	const STATUS_SOLVED  = 'risolto';
	const STATUS_EXPIRED = 'scaduto';
	const STATUS_HIDDEN  = 'nascosto';

	/** Condizioni dell'articolo. */
	const CONDITIONS = [
		'nuovo'       => 'Nuovo',
		'usato'       => 'Usato',
		'revisionato' => 'Revisionato',
		'na'          => 'Non applicabile',
	];

	/** Numero massimo di immagini per annuncio. */
	const MAX_IMAGES = 3;

	/** Lato lungo massimo delle immagini, in pixel. Vedi store_image(). */
	const IMAGE_MAX_SIDE = 1600;

	/** Estensioni accettate per le immagini. */
	const IMAGE_MIMES = [
		'jpg|jpeg' => 'image/jpeg',
		'png'      => 'image/png',
		'webp'     => 'image/webp',
	];

	/** Annunci per pagina. */
	const PER_PAGE = 12;

	/** Cron della manutenzione (scadenze e promemoria). */
	const CRON_SWEEP = 'dealer_portal_board_sweep';

	/** Marcatore sugli allegati della bacheca, come per i documenti. */
	const IMAGE_META = '_dealer_board_image';

	// ─── Meta ─────────────────────────────────────────────────────────────────

	const META_TYPE      = '_lst_type';
	const META_CODE      = '_lst_code';
	const META_BRAND     = '_lst_brand';
	const META_LINE      = '_lst_line';
	const META_QTY       = '_lst_qty';
	const META_CONDITION = '_lst_condition';
	const META_AREA      = '_lst_area';
	const META_IMAGES    = '_lst_images';
	const META_SHOW_CONT = '_lst_show_contacts';
	const META_EXPIRY    = '_lst_expiry';
	const META_STATUS    = '_lst_status';
	const META_ORG       = '_lst_org';
	const META_ORG_NAME  = '_lst_org_name';
	const META_REPORTS   = '_lst_reports';
	const META_REMINDED  = '_lst_reminded';

	// ─── Constructor ──────────────────────────────────────────────────────────

	public function __construct() {
		if ( did_action( 'init' ) ) {
			$this->register_cpt();
		} else {
			add_action( 'init', [ $this, 'register_cpt' ] );
		}

		add_shortcode( self::SHORTCODE, [ $this, 'render' ] );

		// Gli handler stanno su admin_post e non dentro lo shortcode: uno
		// shortcode gira quando gli header sono gia' partiti e li' un redirect
		// non reindirizza nulla. Stessa ragione gia' documentata in
		// Dealer_Access_Guard::route_dashboard().
		add_action( 'admin_post_dealer_board_publish', [ $this, 'handle_publish' ] );
		add_action( 'admin_post_dealer_board_update',  [ $this, 'handle_update' ] );
		add_action( 'admin_post_dealer_board_reply',   [ $this, 'handle_reply' ] );
		add_action( 'admin_post_dealer_board_report',  [ $this, 'handle_report' ] );

		// Le immagini non stanno in una cartella pubblica: si servono da qui,
		// dopo aver verificato chi sta guardando. Vedi serve_image().
		add_filter( 'query_vars',        [ $this, 'add_query_vars' ] );
		add_action( 'template_redirect', [ $this, 'serve_image' ] );

		// Manutenzione: scadenze e promemoria.
		add_action( self::CRON_SWEEP, [ __CLASS__, 'run_sweep' ] );

		// Admin: coda segnalazioni, elenco e impostazioni.
		add_action( 'admin_menu', [ $this, 'register_menu' ], 25 );
		add_action( 'admin_post_dealer_board_moderate', [ $this, 'handle_moderate' ] );
		add_action( 'admin_post_dealer_board_settings', [ $this, 'handle_settings' ] );
	}

	// ─── Configurazione ───────────────────────────────────────────────────────

	/**
	 * Valori predefiniti, scelti per una rete di concessionari.
	 *
	 * `duration_days` 45: abbastanza perche' un pezzo poco richiesto trovi il
	 * suo interlocutore, abbastanza poco perche' la bacheca non diventi un
	 * archivio. `max_active` 10 non serve a limitare chi lavora ma a evitare
	 * che una sola azienda occupi da sola una pagina di risultati; il tetto che
	 * conta davvero contro il flooding e' `max_per_day`.
	 */
	public static function default_options(): array {
		return [
			'enabled'          => 1,
			'duration_days'    => 45,
			'reminder_days'    => 5,
			'max_active'       => 10,
			'max_per_day'      => 5,
			'report_threshold' => 3,
			'disclaimer'       => 'Gli annunci sono pubblicati dalle aziende della rete sotto la propria '
				. 'responsabilita\'. Il portale ospita l\'annuncio e non prende parte alla trattativa: '
				. 'accordi, prezzo, garanzia, trasporto e fatturazione restano fra le due aziende.',
		];
	}

	public static function get_options(): array {
		$stored = get_option( self::OPTION );
		$stored = is_array( $stored ) ? $stored : [];

		return array_merge( self::default_options(), $stored );
	}

	/** @return mixed */
	public static function option( string $key ) {
		$options = self::get_options();
		return $options[ $key ] ?? null;
	}

	/** La bacheca puo' essere spenta senza disinstallare nulla. */
	public static function is_enabled(): bool {
		return (bool) self::option( 'enabled' );
	}

	// ─── CPT ──────────────────────────────────────────────────────────────────

	/**
	 * CPT privato: nessun URL pubblico, nessuna UI, nessun endpoint REST.
	 * I permessi sono mappati sulla capability del plugin e non su quelle
	 * generiche dei post: gli annunci contengono dati di aziende terze e non
	 * devono essere leggibili da chiunque amministri i contenuti del sito.
	 */
	public function register_cpt(): void {
		if ( post_type_exists( self::CPT ) ) {
			return;
		}

		register_post_type( self::CPT, [
			'labels'              => [
				'name'          => 'Annunci Bacheca',
				'singular_name' => 'Annuncio',
			],
			'public'              => false,
			'publicly_queryable'  => false,
			'exclude_from_search' => true,
			'show_ui'             => false,
			'show_in_menu'        => false,
			'show_in_nav_menus'   => false,
			'show_in_rest'        => false,
			'has_archive'         => false,
			'rewrite'             => false,
			'query_var'           => false,
			'supports'            => [ 'title', 'editor', 'author' ],
			'map_meta_cap'        => true,
			'capabilities'        => Dealer_Organization::post_type_capabilities( DEALER_PORTAL_CAP ),
		] );
	}

	// ─── Chi puo' fare cosa ───────────────────────────────────────────────────

	/**
	 * Chi vede e usa la bacheca.
	 *
	 * Trasversale per definizione: qualunque utente del portale, piu'
	 * l'amministratore. L'unica esclusione e' l'azienda sospesa — la stessa
	 * regola dei documenti: se l'accesso dell'azienda e' sospeso non e' il
	 * momento di trattare con la rete.
	 */
	public static function user_can_use( ?\WP_User $user = null ): bool {
		$user = $user ?: wp_get_current_user();

		if ( ! $user || ! $user->exists() ) {
			return false;
		}
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		if ( ! Dealer_Access_Guard::is_portal_user( $user ) ) {
			return false;
		}

		return Dealer_Identity::is_active( $user );
	}

	/** L'autore, o l'amministratore, possono intervenire sull'annuncio. */
	public static function user_owns( int $post_id, ?\WP_User $user = null ): bool {
		$user = $user ?: wp_get_current_user();
		$post = get_post( $post_id );

		if ( ! $post || self::CPT !== $post->post_type ) {
			return false;
		}
		if ( Dealer_DB::user_can( DEALER_PORTAL_CAP ) ) {
			return true;
		}

		return (int) $post->post_author === (int) $user->ID;
	}

	// ─── Lettura ──────────────────────────────────────────────────────────────

	/**
	 * Annunci visibili, con i filtri della pagina.
	 *
	 * Gli annunci nascosti dalle segnalazioni e quelli chiusi non compaiono mai
	 * nell'elenco: chi li ha scritti li ritrova nella propria sezione, che e'
	 * l'unico posto dove ha senso vederli.
	 *
	 * @return array{items:WP_Post[],total:int,pages:int}
	 */
	public static function query_listings( array $filters, int $paged = 1 ): array {
		$meta_query = [
			[ 'key' => self::META_STATUS, 'value' => self::STATUS_ACTIVE, 'compare' => '=' ],
		];

		if ( ! empty( $filters['type'] ) && in_array( $filters['type'], [ self::TYPE_WANTED, self::TYPE_OFFERED ], true ) ) {
			$meta_query[] = [ 'key' => self::META_TYPE, 'value' => $filters['type'], 'compare' => '=' ];
		}
		if ( ! empty( $filters['brand'] ) ) {
			$meta_query[] = [ 'key' => self::META_BRAND, 'value' => $filters['brand'], 'compare' => '=' ];
		}
		if ( ! empty( $filters['line'] ) ) {
			$meta_query[] = [ 'key' => self::META_LINE, 'value' => $filters['line'], 'compare' => '=' ];
		}
		if ( ! empty( $filters['mine'] ) ) {
			// "I miei annunci": qui servono anche chiusi e scaduti, quindi il
			// filtro sullo stato attivo salta.
			array_shift( $meta_query );
		}

		$args = [
			'post_type'      => self::CPT,
			'post_status'    => 'publish',
			'posts_per_page' => self::PER_PAGE,
			'paged'          => max( 1, $paged ),
			'orderby'        => 'date',
			'order'          => 'DESC',
			'meta_query'     => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		];

		if ( ! empty( $filters['mine'] ) ) {
			$args['author'] = get_current_user_id();
		}
		if ( ! empty( $filters['s'] ) ) {
			$args['s'] = $filters['s'];
		}

		$query = new WP_Query( $args );

		return [
			'items' => $query->posts,
			'total' => (int) $query->found_posts,
			'pages' => (int) $query->max_num_pages,
		];
	}

	/**
	 * Dati pronti da stampare per un annuncio.
	 *
	 * Il nome dell'azienda viene salvato sull'annuncio al momento della
	 * pubblicazione (META_ORG_NAME) e non risolto ogni volta: se un'azienda
	 * viene rinominata o l'utente cambia organizzazione, l'annuncio deve
	 * continuare a dire da chi e' stato pubblicato allora. I RECAPITI invece si
	 * leggono adesso dal profilo, mai copiati: se cambia il referente, cambia
	 * ovunque.
	 */
	public static function view_data( \WP_Post $post ): array {
		$author = get_userdata( (int) $post->post_author );

		$show_contacts = (bool) get_post_meta( $post->ID, self::META_SHOW_CONT, true );
		$contacts      = [ 'email' => '', 'phone' => '' ];
		if ( $show_contacts && $author ) {
			$contacts['email'] = (string) get_user_meta( $author->ID, '_referente_email',    true );
			$contacts['phone'] = (string) get_user_meta( $author->ID, '_referente_telefono', true );
			if ( '' === $contacts['email'] ) {
				$contacts['email'] = (string) $author->user_email;
			}
		}

		$expiry = (string) get_post_meta( $post->ID, self::META_EXPIRY, true );

		return [
			'id'            => (int) $post->ID,
			'title'         => (string) $post->post_title,
			'body'          => (string) $post->post_content,
			'type'          => (string) get_post_meta( $post->ID, self::META_TYPE, true ),
			'code'          => (string) get_post_meta( $post->ID, self::META_CODE, true ),
			'brand'         => (string) get_post_meta( $post->ID, self::META_BRAND, true ),
			'line'          => (string) get_post_meta( $post->ID, self::META_LINE, true ),
			'qty'           => (int) get_post_meta( $post->ID, self::META_QTY, true ),
			'condition'     => (string) get_post_meta( $post->ID, self::META_CONDITION, true ),
			'area'          => (string) get_post_meta( $post->ID, self::META_AREA, true ),
			'status'        => (string) get_post_meta( $post->ID, self::META_STATUS, true ),
			'org_name'      => (string) get_post_meta( $post->ID, self::META_ORG_NAME, true ),
			'author_name'   => $author ? (string) $author->display_name : '',
			'images'        => self::image_urls( (int) $post->ID ),
			'show_contacts' => $show_contacts,
			'contacts'      => $contacts,
			'expiry'        => $expiry,
			'published'     => (string) get_the_date( 'd/m/Y', $post ),
			'is_mine'       => (int) $post->post_author === get_current_user_id(),
		];
	}

	/** Etichetta leggibile del tipo. */
	public static function type_label( string $type ): string {
		return self::TYPE_WANTED === $type ? 'Cerco' : 'Offro';
	}

	/** Quanti annunci attivi ha gia' l'utente. */
	public static function active_count( int $user_id ): int {
		$q = new WP_Query( [
			'post_type'      => self::CPT,
			'post_status'    => 'publish',
			'author'         => $user_id,
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				[ 'key' => self::META_STATUS, 'value' => self::STATUS_ACTIVE, 'compare' => '=' ],
			],
		] );

		return (int) $q->found_posts;
	}

	// ─── Pagina ───────────────────────────────────────────────────────────────

	/** URL della pagina Bacheca, dall'ID salvato in opzione. */
	public static function page_url(): string {
		return Dealer_DB::board_url();
	}

	public function render( $atts ): string {
		self::enqueue_assets();

		if ( ! is_user_logged_in() ) {
			return '<p class="dealer-notice"><a href="' . esc_url( wp_login_url( get_permalink() ) ) . '">Accedi</a> per vedere la bacheca della rete.</p>';
		}

		if ( ! self::is_enabled() ) {
			return '<p class="dealer-notice">La bacheca non è al momento attiva.</p>';
		}

		$user = wp_get_current_user();

		if ( ! self::user_can_use( $user ) ) {
			if ( Dealer_Access_Guard::is_portal_user( $user ) ) {
				return '<p class="dealer-notice">L’accesso della tua azienda è attualmente sospeso: '
					. 'la bacheca della rete non è disponibile. Contatta il tuo referente commerciale.</p>';
			}
			return '<p class="dealer-notice">Quest’area è riservata agli utenti del portale.</p>';
		}

		// Filtri dalla query string: nessuna azione distruttiva, nonce non necessario.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$filters = [
			'type'  => sanitize_key( $_GET['b_type'] ?? '' ),
			'brand' => sanitize_text_field( wp_unslash( $_GET['b_brand'] ?? '' ) ),
			'line'  => sanitize_text_field( wp_unslash( $_GET['b_line'] ?? '' ) ),
			's'     => sanitize_text_field( wp_unslash( $_GET['b_s'] ?? '' ) ),
			'mine'  => ! empty( $_GET['b_mine'] ),
		];
		$paged  = max( 1, absint( $_GET['b_page'] ?? 1 ) );
		$notice = sanitize_key( $_GET['b_notice'] ?? '' );
		$open   = absint( $_GET['b_open'] ?? 0 );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$result     = self::query_listings( $filters, $paged );
		$base_url   = self::page_url();
		$post_url   = admin_url( 'admin-post.php' );
		$lines      = Dealer_Admin::get_product_lines();
		$options    = self::get_options();
		$active     = self::active_count( (int) $user->ID );
		$can_post   = $active < (int) $options['max_active'] && ! self::daily_limit_reached( (int) $user->ID );
		$org_name   = self::current_org_name( $user );
		$messages   = self::notice_text( $notice );

		ob_start();
		require DEALER_PORTAL_PATH . 'templates/dealer-board.php';
		return ob_get_clean();
	}

	/** Nome dell'azienda con cui l'utente pubblica. */
	public static function current_org_name( \WP_User $user ): string {
		$org_id = Dealer_Identity::get_org_id( $user );
		$name   = $org_id ? Dealer_Organization::get_name( $org_id ) : '';

		// Chi non appartiene a un'organizzazione (un amministratore che prova
		// la pagina, un utente non ancora assegnato) pubblica comunque con un
		// nome visibile: senza, l'annuncio sarebbe anonimo, che e' esattamente
		// cio' che questa bacheca non deve permettere.
		return '' !== $name ? $name : (string) $user->display_name;
	}

	public static function enqueue_assets(): void {
		wp_enqueue_style( 'dashicons' );
		wp_enqueue_style( 'dealer-portal-dealer', DEALER_PORTAL_URL . 'assets/css/dealer.css', [], DEALER_PORTAL_VERSION );
	}

	/** Testo dell'avviso dopo un'azione (pattern PRG). */
	private static function notice_text( string $key ): string {
		$map = [
			'published' => 'Annuncio pubblicato. Resterà in bacheca fino alla data di scadenza.',
			'updated'   => 'Annuncio aggiornato.',
			'solved'    => 'Annuncio chiuso: non è più visibile agli altri.',
			'renewed'   => 'Annuncio rinnovato.',
			'deleted'   => 'Annuncio eliminato.',
			'replied'   => 'Messaggio inviato. Chi ha pubblicato l’annuncio ti risponderà direttamente.',
			'reported'  => 'Segnalazione registrata. Grazie.',
			'err_limit' => 'Hai raggiunto il numero massimo di annunci: chiudine uno prima di pubblicarne un altro.',
			'err_day'   => 'Hai pubblicato troppi annunci per oggi: riprova domani.',
			'err_field' => 'Mancano dei dati obbligatori: l’annuncio non è stato pubblicato.',
			'err_perm'  => 'Non puoi intervenire su questo annuncio.',
			'err_image' => 'Una o più immagini non sono state accettate: sono ammessi JPG, PNG e WEBP.',
			'err_reply' => 'Il messaggio non è stato inviato: riprova.',
		];

		return $map[ $key ] ?? '';
	}

	// ─── Pubblicazione ────────────────────────────────────────────────────────

	/**
	 * Tetto giornaliero, con lo stesso meccanismo a transient gia' usato dal
	 * modulo pubblico di richiesta accesso. Qui la chiave e' l'utente e non
	 * l'IP: chi pubblica e' autenticato, quindi non serve indovinare chi sia.
	 */
	private static function daily_limit_key( int $user_id ): string {
		return 'dealer_board_day_' . $user_id . '_' . gmdate( 'Ymd' );
	}

	public static function daily_limit_reached( int $user_id ): bool {
		return (int) get_transient( self::daily_limit_key( $user_id ) ) >= (int) self::option( 'max_per_day' );
	}

	private static function daily_limit_bump( int $user_id ): void {
		$key = self::daily_limit_key( $user_id );
		set_transient( $key, (int) get_transient( $key ) + 1, DAY_IN_SECONDS );
	}

	public function handle_publish(): void {
		check_admin_referer( 'dealer_board_publish' );

		$user = wp_get_current_user();
		if ( ! self::is_enabled() || ! self::user_can_use( $user ) ) {
			self::redirect( 'err_perm' );
		}

		if ( self::active_count( (int) $user->ID ) >= (int) self::option( 'max_active' ) ) {
			self::redirect( 'err_limit' );
		}
		if ( self::daily_limit_reached( (int) $user->ID ) ) {
			self::redirect( 'err_day' );
		}

		$fields = self::posted_fields();
		if ( '' === $fields['title'] || '' === $fields['body'] ) {
			self::redirect( 'err_field' );
		}

		$post_id = wp_insert_post( [
			'post_type'    => self::CPT,
			'post_status'  => 'publish',
			'post_title'   => $fields['title'],
			'post_content' => $fields['body'],
			'post_author'  => (int) $user->ID,
		], true );

		if ( is_wp_error( $post_id ) ) {
			self::redirect( 'err_field' );
		}

		$org_id = Dealer_Identity::get_org_id( $user );

		update_post_meta( $post_id, self::META_TYPE,      $fields['type'] );
		update_post_meta( $post_id, self::META_CODE,      $fields['code'] );
		update_post_meta( $post_id, self::META_BRAND,     $fields['brand'] );
		update_post_meta( $post_id, self::META_LINE,      $fields['line'] );
		update_post_meta( $post_id, self::META_QTY,       $fields['qty'] );
		update_post_meta( $post_id, self::META_CONDITION, $fields['condition'] );
		update_post_meta( $post_id, self::META_AREA,      $fields['area'] );
		update_post_meta( $post_id, self::META_SHOW_CONT, $fields['show_contacts'] );
		update_post_meta( $post_id, self::META_STATUS,    self::STATUS_ACTIVE );
		update_post_meta( $post_id, self::META_ORG,       $org_id );
		update_post_meta( $post_id, self::META_ORG_NAME,  self::current_org_name( $user ) );
		update_post_meta( $post_id, self::META_EXPIRY,    self::default_expiry() );
		update_post_meta( $post_id, self::META_REPORTS,   [] );

		$stored = self::store_images( (int) $post_id );
		update_post_meta( $post_id, self::META_IMAGES, $stored['ids'] );

		self::daily_limit_bump( (int) $user->ID );
		self::ensure_sweep_scheduled();

		self::redirect( $stored['failed'] ? 'err_image' : 'published', (int) $post_id );
	}

	/**
	 * Modifica, chiusura, rinnovo ed eliminazione: un solo handler, perche'
	 * condividono per intero il controllo su chi sta agendo. L'azione arriva
	 * dal campo `op` e ogni ramo la rivalida.
	 */
	public function handle_update(): void {
		check_admin_referer( 'dealer_board_update' );

		$post_id = absint( $_POST['listing'] ?? 0 );
		$op      = sanitize_key( $_POST['op'] ?? '' );

		if ( ! $post_id || ! self::user_owns( $post_id ) ) {
			self::redirect( 'err_perm' );
		}

		switch ( $op ) {
			case 'solve':
				update_post_meta( $post_id, self::META_STATUS, self::STATUS_SOLVED );
				self::redirect( 'solved' );
				break;

			case 'renew':
				update_post_meta( $post_id, self::META_STATUS, self::STATUS_ACTIVE );
				update_post_meta( $post_id, self::META_EXPIRY, self::default_expiry() );
				delete_post_meta( $post_id, self::META_REMINDED );
				self::redirect( 'renewed' );
				break;

			case 'delete':
				self::delete_listing( $post_id );
				self::redirect( 'deleted' );
				break;

			case 'edit':
				$fields = self::posted_fields();
				if ( '' === $fields['title'] || '' === $fields['body'] ) {
					self::redirect( 'err_field', $post_id );
				}
				wp_update_post( [
					'ID'           => $post_id,
					'post_title'   => $fields['title'],
					'post_content' => $fields['body'],
				] );
				update_post_meta( $post_id, self::META_TYPE,      $fields['type'] );
				update_post_meta( $post_id, self::META_CODE,      $fields['code'] );
				update_post_meta( $post_id, self::META_BRAND,     $fields['brand'] );
				update_post_meta( $post_id, self::META_LINE,      $fields['line'] );
				update_post_meta( $post_id, self::META_QTY,       $fields['qty'] );
				update_post_meta( $post_id, self::META_CONDITION, $fields['condition'] );
				update_post_meta( $post_id, self::META_AREA,      $fields['area'] );
				update_post_meta( $post_id, self::META_SHOW_CONT, $fields['show_contacts'] );
				self::redirect( 'updated' );
				break;

			default:
				self::redirect( 'err_perm' );
		}
	}

	/**
	 * Campi del modulo, ripuliti.
	 *
	 * La descrizione passa da wp_strip_all_tags: nessun HTML, e di conseguenza
	 * nessun link cliccabile inserito da chi pubblica. In una bacheca senza
	 * moderatori il link e' il vettore piu' comodo, e qui non serve a niente
	 * che non si possa scrivere in chiaro.
	 */
	private static function posted_fields(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing — nonce verificato dal chiamante
		$type = sanitize_key( $_POST['b_type'] ?? '' );
		if ( ! in_array( $type, [ self::TYPE_WANTED, self::TYPE_OFFERED ], true ) ) {
			$type = self::TYPE_WANTED;
		}

		$condition = sanitize_key( $_POST['b_condition'] ?? '' );
		if ( ! array_key_exists( $condition, self::CONDITIONS ) ) {
			$condition = 'na';
		}

		$brand = sanitize_text_field( wp_unslash( $_POST['b_brand'] ?? '' ) );
		$line  = sanitize_text_field( wp_unslash( $_POST['b_line'] ?? '' ) );

		// Brand e linea devono esistere nel catalogo: sono gli stessi valori
		// con cui sono classificati i documenti, e accettarne di inventati
		// renderebbe i filtri inutili nel giro di poche settimane.
		$catalog = Dealer_Admin::get_product_lines();
		if ( '' !== $brand && ! isset( $catalog[ $brand ] ) ) {
			$brand = '';
			$line  = '';
		}
		if ( '' !== $line && ( '' === $brand || ! in_array( $line, (array) ( $catalog[ $brand ] ?? [] ), true ) ) ) {
			$line = '';
		}

		$fields = [
			'type'          => $type,
			'title'         => sanitize_text_field( wp_unslash( $_POST['b_title'] ?? '' ) ),
			'body'          => trim( wp_strip_all_tags( (string) wp_unslash( $_POST['b_body'] ?? '' ) ) ),
			'code'          => sanitize_text_field( wp_unslash( $_POST['b_code'] ?? '' ) ),
			'brand'         => $brand,
			'line'          => $line,
			'qty'           => max( 0, absint( $_POST['b_qty'] ?? 0 ) ),
			'condition'     => $condition,
			'area'          => sanitize_text_field( wp_unslash( $_POST['b_area'] ?? '' ) ),
			'show_contacts' => empty( $_POST['b_show_contacts'] ) ? 0 : 1,
		];
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$fields['body'] = mb_substr( $fields['body'], 0, 1500 );

		return $fields;
	}

	private static function default_expiry(): string {
		$days = max( 7, min( 180, (int) self::option( 'duration_days' ) ) );
		return gmdate( 'Y-m-d', time() + ( $days * DAY_IN_SECONDS ) );
	}

	/** Elimina l'annuncio e le sue immagini. */
	private static function delete_listing( int $post_id ): void {
		foreach ( (array) get_post_meta( $post_id, self::META_IMAGES, true ) as $attachment_id ) {
			$attachment_id = (int) $attachment_id;
			if ( $attachment_id && 'attachment' === get_post_type( $attachment_id ) ) {
				wp_delete_attachment( $attachment_id, true );
			}
		}

		wp_delete_post( $post_id, true );
	}

	/** Redirect PRG verso la pagina, con l'esito. */
	private static function redirect( string $notice, int $open = 0 ): void {
		$args = [ 'b_notice' => $notice ];
		if ( $open ) {
			$args['b_open'] = $open;
		}

		wp_safe_redirect( add_query_arg( $args, self::page_url() ) );
		exit;
	}

	// ─── Immagini ─────────────────────────────────────────────────────────────

	/**
	 * Salva le immagini dell'annuncio.
	 *
	 * Stessa pipeline indurita dei documenti — allowlist di estensioni,
	 * wp_handle_upload con `mimes`, secondo controllo con
	 * wp_check_filetype_and_ext sul file GIA' scritto, cartella dealer-docs/
	 * protetta, token casuale nel nome — piu' una cosa che i documenti non
	 * richiedono: il ridimensionamento.
	 *
	 * Ridimensionare non serve solo a non riempire il disco. La foto di un
	 * pezzo scattata col telefono in magazzino porta dentro i dati EXIF, e fra
	 * quelli ci sono le coordinate GPS del magazzino: riscrivere l'immagine li
	 * elimina. In una bacheca vista da tutta la rete non e' un dettaglio.
	 *
	 * @return array{ids:int[],failed:bool}
	 */
	private static function store_images( int $post_id ): array {
		if ( empty( $_FILES['b_images'] ) || ! is_array( $_FILES['b_images']['name'] ?? null ) ) {
			return [ 'ids' => [], 'failed' => false ];
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$ids    = [];
		$failed = false;
		$count  = min( self::MAX_IMAGES, count( $_FILES['b_images']['name'] ) );

		$dir_filter = static function ( array $dirs ): array {
			$dirs['subdir'] = '/dealer-docs';
			$dirs['path']   = $dirs['basedir'] . '/dealer-docs';
			$dirs['url']    = $dirs['baseurl'] . '/dealer-docs';
			return $dirs;
		};

		for ( $i = 0; $i < $count; $i++ ) {
			if ( UPLOAD_ERR_OK !== (int) $_FILES['b_images']['error'][ $i ] ) {
				continue;
			}

			$name = sanitize_file_name( (string) $_FILES['b_images']['name'][ $i ] );
			$ext  = strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) );
			if ( ! self::extension_allowed( $ext ) ) {
				$failed = true;
				continue;
			}

			// Nome sul disco con un token casuale: l'unica difesa che regge
			// anche dove il server ignora il .htaccess della cartella.
			$file = [
				'name'     => pathinfo( $name, PATHINFO_FILENAME ) . '-' . wp_generate_password( 20, false, false ) . '.' . $ext,
				'type'     => (string) $_FILES['b_images']['type'][ $i ],
				'tmp_name' => (string) $_FILES['b_images']['tmp_name'][ $i ],
				'error'    => 0,
				'size'     => (int) $_FILES['b_images']['size'][ $i ],
			];

			add_filter( 'upload_dir', $dir_filter );
			$uploaded = wp_handle_upload( $file, [ 'test_form' => false, 'mimes' => self::IMAGE_MIMES ] );
			remove_filter( 'upload_dir', $dir_filter );

			if ( ! is_array( $uploaded ) || isset( $uploaded['error'] ) || empty( $uploaded['file'] ) ) {
				$failed = true;
				continue;
			}

			// Secondo controllo sul file scritto: l'estensione e l'header
			// dichiarati dal browser non bastano.
			$check = wp_check_filetype_and_ext( $uploaded['file'], basename( $uploaded['file'] ), self::IMAGE_MIMES );
			if ( empty( $check['ext'] ) || empty( $check['type'] ) ) {
				wp_delete_file( $uploaded['file'] );
				$failed = true;
				continue;
			}

			self::downscale( $uploaded['file'] );

			$attachment_id = wp_insert_attachment( [
				'post_mime_type' => $check['type'],
				'post_title'     => preg_replace( '/\.[^.]+$/', '', basename( $uploaded['file'] ) ),
				'post_status'    => 'inherit',
			], $uploaded['file'], $post_id );

			if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
				wp_delete_file( $uploaded['file'] );
				$failed = true;
				continue;
			}

			wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $uploaded['file'] ) );
			// Fuori dalla Libreria Media come gli allegati dei documenti: sono
			// file di aziende terze, non materiale del sito.
			update_post_meta( $attachment_id, Dealer_Admin::DOC_ATTACHMENT_META, 1 );
			update_post_meta( $attachment_id, self::IMAGE_META, 1 );

			$ids[] = (int) $attachment_id;
		}

		return [ 'ids' => $ids, 'failed' => $failed ];
	}

	private static function extension_allowed( string $ext ): bool {
		foreach ( array_keys( self::IMAGE_MIMES ) as $pattern ) {
			if ( in_array( $ext, explode( '|', $pattern ), true ) ) {
				return true;
			}
		}
		return false;
	}

	/** Riscrive l'immagine entro IMAGE_MAX_SIDE. Silenzioso se non è possibile. */
	private static function downscale( string $path ): void {
		$editor = wp_get_image_editor( $path );
		if ( is_wp_error( $editor ) ) {
			return;
		}

		$size = $editor->get_size();
		if ( ! is_array( $size ) ) {
			return;
		}
		if ( (int) ( $size['width'] ?? 0 ) <= self::IMAGE_MAX_SIDE && (int) ( $size['height'] ?? 0 ) <= self::IMAGE_MAX_SIDE ) {
			// Anche senza ridimensionare conviene riscrivere il file, perche' e'
			// la riscrittura a togliere l'EXIF. resize() con i valori attuali
			// non farebbe nulla: si salva e basta.
			$editor->save( $path );
			return;
		}

		$editor->resize( self::IMAGE_MAX_SIDE, self::IMAGE_MAX_SIDE, false );
		$editor->save( $path );
	}

	/** URL interni delle immagini di un annuncio. */
	public static function image_urls( int $post_id ): array {
		$out = [];

		foreach ( (array) get_post_meta( $post_id, self::META_IMAGES, true ) as $id ) {
			$id = (int) $id;
			if ( $id ) {
				$out[] = add_query_arg( 'dealer_board_image', $id, home_url( '/' ) );
			}
		}

		return $out;
	}

	public function add_query_vars( array $vars ): array {
		$vars[] = 'dealer_board_image';
		return $vars;
	}

	/**
	 * Serve un'immagine della bacheca.
	 *
	 * I file stanno nella cartella protetta e non hanno un URL pubblico
	 * utilizzabile: passano da qui, dopo che si e' verificato che a guardarli
	 * sia un utente del portale. Stesso contenimento con realpath() del
	 * download dei documenti: il percorso arriva da un allegato nostro, ma il
	 * controllo si fa lo stesso.
	 */
	public function serve_image(): void {
		$attachment_id = (int) get_query_var( 'dealer_board_image' );
		if ( ! $attachment_id ) {
			return;
		}

		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url() );
			exit;
		}
		if ( ! self::user_can_use() ) {
			wp_die( esc_html__( 'Accesso non consentito.', 'dealer-portal' ), '', [ 'response' => 403 ] );
		}
		if ( 'attachment' !== get_post_type( $attachment_id )
			|| ! get_post_meta( $attachment_id, self::IMAGE_META, true ) ) {
			wp_die( esc_html__( 'Immagine non trovata.', 'dealer-portal' ), '', [ 'response' => 404 ] );
		}

		$path = get_attached_file( $attachment_id );
		$real = $path ? realpath( $path ) : false;
		$base = realpath( wp_upload_dir()['basedir'] );

		if ( ! $real || ! $base || 0 !== strpos( $real, $base . DIRECTORY_SEPARATOR ) || ! is_file( $real ) ) {
			wp_die( esc_html__( 'Immagine non trovata.', 'dealer-portal' ), '', [ 'response' => 404 ] );
		}

		$mime = get_post_mime_type( $attachment_id );
		if ( ! in_array( $mime, array_values( self::IMAGE_MIMES ), true ) ) {
			wp_die( esc_html__( 'Immagine non valida.', 'dealer-portal' ), '', [ 'response' => 403 ] );
		}

		while ( ob_get_level() ) {
			ob_end_clean();
		}

		header( 'Content-Type: ' . $mime );
		header( 'Content-Length: ' . (int) filesize( $real ) );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Disposition: inline' );
		header( 'Cache-Control: private, max-age=3600' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		readfile( $real );
		exit;
	}

	// ─── Risposta privata ─────────────────────────────────────────────────────

	/**
	 * Risposta a un annuncio: una email a chi l'ha pubblicato, e basta.
	 *
	 * E' il punto in cui la conversazione ESCE dalla bacheca, ed e' voluto: un
	 * thread pubblico sotto l'annuncio sarebbe la cosa che richiede un
	 * moderatore. Qui il portale fa da tramite una volta sola, poi le due
	 * aziende si parlano direttamente come gia' fanno oggi al telefono.
	 *
	 * Il recapito di chi ha pubblicato non passa mai dal browser di chi
	 * risponde: l'email parte dal server. Chi risponde invece si presenta —
	 * nome, azienda e recapito — perche' senza quelli il messaggio sarebbe
	 * inservibile.
	 */
	public function handle_reply(): void {
		check_admin_referer( 'dealer_board_reply' );

		$user = wp_get_current_user();
		if ( ! self::is_enabled() || ! self::user_can_use( $user ) ) {
			self::redirect( 'err_perm' );
		}

		$post_id = absint( $_POST['listing'] ?? 0 );
		$post    = $post_id ? get_post( $post_id ) : null;

		if ( ! $post || self::CPT !== $post->post_type
			|| self::STATUS_ACTIVE !== get_post_meta( $post_id, self::META_STATUS, true ) ) {
			self::redirect( 'err_reply' );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing — nonce sopra
		$message = trim( wp_strip_all_tags( (string) wp_unslash( $_POST['b_message'] ?? '' ) ) );
		$message = mb_substr( $message, 0, 1500 );

		if ( '' === $message ) {
			self::redirect( 'err_reply', $post_id );
		}

		$author = get_userdata( (int) $post->post_author );
		if ( ! $author ) {
			self::redirect( 'err_reply', $post_id );
		}

		$to = (string) get_user_meta( $author->ID, '_referente_email', true );
		if ( ! is_email( $to ) ) {
			$to = (string) $author->user_email;
		}

		$sender_email = (string) get_user_meta( $user->ID, '_referente_email', true );
		if ( ! is_email( $sender_email ) ) {
			$sender_email = (string) $user->user_email;
		}

		$body = Dealer_Notifications::render_shared_template( 'email-board-reply', [
			'listing_title' => (string) $post->post_title,
			'listing_type'  => self::type_label( (string) get_post_meta( $post_id, self::META_TYPE, true ) ),
			'message'       => $message,
			'sender_name'   => (string) $user->display_name,
			'sender_org'    => self::current_org_name( $user ),
			'sender_email'  => $sender_email,
			'sender_phone'  => (string) get_user_meta( $user->ID, '_referente_telefono', true ),
			'board_url'     => self::page_url(),
		] );

		$sent = Dealer_Notifications::send_transactional(
			$to,
			sprintf( 'Risposta al tuo annuncio: %s', $post->post_title ),
			'Risposta a un tuo annuncio',
			$body,
			sprintf(
				"%s (%s) ha risposto al tuo annuncio \"%s\".\n\n%s\n\nRecapiti: %s %s",
				$user->display_name,
				self::current_org_name( $user ),
				$post->post_title,
				$message,
				$sender_email,
				(string) get_user_meta( $user->ID, '_referente_telefono', true )
			)
		);

		self::redirect( $sent ? 'replied' : 'err_reply', $post_id );
	}

	// ─── Segnalazioni ─────────────────────────────────────────────────────────

	/**
	 * Segnalazione di un annuncio.
	 *
	 * Il conteggio e' per UTENTE, non per click: una sola persona non puo'
	 * nascondere l'annuncio di un concorrente premendo il pulsante tre volte.
	 * Raggiunta la soglia l'annuncio si nasconde da solo e finisce nella coda
	 * dell'amministratore, che e' l'unico a poterlo riattivare. Nessuno viene
	 * avvisato di chi ha segnalato: e' un segnale, non un'accusa.
	 */
	public function handle_report(): void {
		check_admin_referer( 'dealer_board_report' );

		$user = wp_get_current_user();
		if ( ! self::is_enabled() || ! self::user_can_use( $user ) ) {
			self::redirect( 'err_perm' );
		}

		$post_id = absint( $_POST['listing'] ?? 0 );
		if ( ! $post_id || self::CPT !== get_post_type( $post_id ) ) {
			self::redirect( 'err_perm' );
		}

		// Il proprio annuncio non si segnala: per quello c'e' "Elimina".
		if ( (int) get_post_field( 'post_author', $post_id ) === (int) $user->ID ) {
			self::redirect( 'err_perm' );
		}

		$reports = (array) get_post_meta( $post_id, self::META_REPORTS, true );
		$reports = array_values( array_unique( array_map( 'intval', array_filter( $reports ) ) ) );

		if ( ! in_array( (int) $user->ID, $reports, true ) ) {
			$reports[] = (int) $user->ID;
			update_post_meta( $post_id, self::META_REPORTS, $reports );
		}

		if ( count( $reports ) >= (int) self::option( 'report_threshold' ) ) {
			update_post_meta( $post_id, self::META_STATUS, self::STATUS_HIDDEN );
		}

		self::redirect( 'reported' );
	}

	/** Annunci nascosti dalle segnalazioni, per la coda admin. */
	public static function reported_listings(): array {
		$q = new WP_Query( [
			'post_type'      => self::CPT,
			'post_status'    => 'publish',
			'posts_per_page' => 50,
			'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				[ 'key' => self::META_STATUS, 'value' => self::STATUS_HIDDEN, 'compare' => '=' ],
			],
		] );

		return $q->posts;
	}

	// ─── Manutenzione: scadenze e promemoria ──────────────────────────────────

	public static function ensure_sweep_scheduled(): void {
		if ( ! wp_next_scheduled( self::CRON_SWEEP ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_SWEEP );
		}
	}

	public static function clear_scheduled_events(): void {
		$timestamp = wp_next_scheduled( self::CRON_SWEEP );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_SWEEP );
		}
	}

	/**
	 * Passata quotidiana: archivia gli scaduti e avvisa chi sta per scadere.
	 *
	 * E' il meccanismo che tiene in vita la bacheca. Senza, dopo sei mesi
	 * l'elenco sarebbe fatto per la maggior parte di annunci risolti, e la
	 * prima telefonata a vuoto convincerebbe chi chiama a non tornarci piu'.
	 */
	public static function run_sweep(): void {
		$today    = gmdate( 'Y-m-d' );
		$reminder = gmdate( 'Y-m-d', time() + ( max( 1, (int) self::option( 'reminder_days' ) ) * DAY_IN_SECONDS ) );

		// Ordinati per scadenza crescente, non per data di pubblicazione: la
		// passata lavora un blocco alla volta, e con piu' annunci del blocco
		// quelli davvero scaduti resterebbero fuori — cioe' non scadrebbero
		// mai, che e' esattamente il difetto che questo cron esiste per
		// evitare.
		$q = new WP_Query( [
			'post_type'      => self::CPT,
			'post_status'    => 'publish',
			'posts_per_page' => 200,
			'meta_key'       => self::META_EXPIRY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'orderby'        => 'meta_value',
			'order'          => 'ASC',
			'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				[ 'key' => self::META_STATUS, 'value' => self::STATUS_ACTIVE, 'compare' => '=' ],
			],
		] );

		foreach ( $q->posts as $post ) {
			$expiry = (string) get_post_meta( $post->ID, self::META_EXPIRY, true );
			if ( '' === $expiry ) {
				continue;
			}

			if ( $expiry < $today ) {
				update_post_meta( $post->ID, self::META_STATUS, self::STATUS_EXPIRED );
				continue;
			}

			// Un solo promemoria per annuncio: se lo si rinnova il marcatore
			// viene tolto e il ciclo puo' ricominciare.
			if ( $expiry <= $reminder && ! get_post_meta( $post->ID, self::META_REMINDED, true ) ) {
				self::send_expiry_reminder( $post );
				update_post_meta( $post->ID, self::META_REMINDED, 1 );
			}
		}
	}

	private static function send_expiry_reminder( \WP_Post $post ): void {
		$author = get_userdata( (int) $post->post_author );
		if ( ! $author ) {
			return;
		}

		$to = (string) get_user_meta( $author->ID, '_referente_email', true );
		if ( ! is_email( $to ) ) {
			$to = (string) $author->user_email;
		}

		$body = Dealer_Notifications::render_shared_template( 'email-board-expiring', [
			'user_name'     => (string) $author->display_name,
			'listing_title' => (string) $post->post_title,
			'expiry'        => (string) get_post_meta( $post->ID, self::META_EXPIRY, true ),
			'board_url'     => add_query_arg( 'b_mine', 1, self::page_url() ),
		] );

		Dealer_Notifications::send_transactional(
			$to,
			sprintf( 'Il tuo annuncio sta per scadere: %s', $post->post_title ),
			'Annuncio in scadenza',
			$body,
			sprintf(
				"Il tuo annuncio \"%s\" scade il %s.\nRinnovalo o chiudilo dalla bacheca: %s",
				$post->post_title,
				(string) get_post_meta( $post->ID, self::META_EXPIRY, true ),
				add_query_arg( 'b_mine', 1, self::page_url() )
			)
		);
	}

	// ─── Amministrazione ──────────────────────────────────────────────────────

	public function register_menu(): void {
		// 'manage_options' e non la capability del plugin, per la stessa
		// ragione documentata in Dealer_Org_Admin::register_menu(): quando un
		// terzo nega a runtime una nostra capability, add_submenu_page() non
		// registra la voce e non segnala nulla — il menu si accorcia in
		// silenzio. Questa schermata e' comunque riservata all'amministratore.
		// Il controllo vero resta dentro la pagina.
		add_submenu_page(
			'dealer-portal',
			'Bacheca',
			'Bacheca',
			'manage_options',
			self::MENU_SLUG,
			[ $this, 'render_admin_page' ]
		);
	}

	public function render_admin_page(): void {
		if ( ! Dealer_DB::user_can( DEALER_PORTAL_CAP ) ) {
			wp_die( esc_html__( 'Accesso non consentito.', 'dealer-portal' ) );
		}

		$options  = self::get_options();
		$reported = self::reported_listings();
		$recent   = get_posts( [
			'post_type'      => self::CPT,
			'post_status'    => 'publish',
			'posts_per_page' => 30,
			'orderby'        => 'date',
			'order'          => 'DESC',
		] );
		$post_url = admin_url( 'admin-post.php' );
		$notice   = get_transient( 'dealer_board_notice_' . get_current_user_id() );
		delete_transient( 'dealer_board_notice_' . get_current_user_id() );

		require DEALER_PORTAL_PATH . 'templates/admin-board.php';
	}

	/**
	 * Riattiva o elimina un annuncio segnalato.
	 *
	 * E' l'unico intervento discrezionale previsto: la bacheca si regge sui
	 * meccanismi automatici, l'amministratore arriva solo dove quelli hanno
	 * gia' fermato qualcosa.
	 */
	public function handle_moderate(): void {
		check_admin_referer( 'dealer_board_moderate' );

		if ( ! Dealer_DB::user_can( DEALER_PORTAL_CAP ) ) {
			wp_die( esc_html__( 'Accesso non consentito.', 'dealer-portal' ) );
		}

		$post_id = absint( $_POST['listing'] ?? 0 );
		$op      = sanitize_key( $_POST['op'] ?? '' );
		$message = '';

		if ( $post_id && self::CPT === get_post_type( $post_id ) ) {
			if ( 'restore' === $op ) {
				// Le segnalazioni si azzerano: senza, il primo che ripassa
				// riporterebbe l'annuncio oltre soglia da solo.
				update_post_meta( $post_id, self::META_REPORTS, [] );
				update_post_meta( $post_id, self::META_STATUS, self::STATUS_ACTIVE );
				$message = 'Annuncio ripristinato e segnalazioni azzerate.';
			} elseif ( 'delete' === $op ) {
				self::delete_listing( $post_id );
				$message = 'Annuncio eliminato.';
			}
		}

		set_transient( 'dealer_board_notice_' . get_current_user_id(), $message, 60 );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG ) );
		exit;
	}

	public function handle_settings(): void {
		check_admin_referer( 'dealer_board_settings' );

		if ( ! Dealer_DB::user_can( DEALER_PORTAL_CAP ) ) {
			wp_die( esc_html__( 'Accesso non consentito.', 'dealer-portal' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing — nonce sopra
		$options = [
			'enabled'          => empty( $_POST['b_enabled'] ) ? 0 : 1,
			'duration_days'    => max( 7,  min( 180, absint( $_POST['b_duration'] ?? 45 ) ) ),
			'reminder_days'    => max( 1,  min( 30,  absint( $_POST['b_reminder'] ?? 5 ) ) ),
			'max_active'       => max( 1,  min( 100, absint( $_POST['b_max_active'] ?? 10 ) ) ),
			'max_per_day'      => max( 1,  min( 50,  absint( $_POST['b_max_day'] ?? 5 ) ) ),
			'report_threshold' => max( 2,  min( 20,  absint( $_POST['b_threshold'] ?? 3 ) ) ),
			'disclaimer'       => trim( wp_strip_all_tags( (string) wp_unslash( $_POST['b_disclaimer'] ?? '' ) ) ),
		];
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( '' === $options['disclaimer'] ) {
			$defaults              = self::default_options();
			$options['disclaimer'] = $defaults['disclaimer'];
		}

		update_option( self::OPTION, $options );

		if ( $options['enabled'] ) {
			self::ensure_sweep_scheduled();
		} else {
			self::clear_scheduled_events();
		}

		set_transient( 'dealer_board_notice_' . get_current_user_id(), 'Impostazioni della bacheca salvate.', 60 );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG ) );
		exit;
	}
}
