<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Tiene gli utenti del portale fuori dal backend di WordPress.
 *
 * Il portale è costruito su un principio preciso: chi lo usa non deve mai
 * vedere wp-admin. Vale per i dealer, per i titolari e — allo stesso modo —
 * per gli area manager: sono utenti operativi che caricano documenti e
 * gestiscono persone, non tecnici del sito. Fargli fare il proprio lavoro nel
 * pannello di amministrazione di WordPress li espone a un'interfaccia che non
 * è loro, mostra loro un contesto che non li riguarda, e rende difficile
 * distinguere ciò che possono toccare da ciò che non devono.
 *
 * Questa classe applica quel principio in modo uniforme:
 *  - accesso a wp-admin negato e reindirizzato all'area di competenza;
 *  - barra di amministrazione nascosta sul front-end;
 *  - redirect dopo il login verso la propria area;
 *  - un link di logout sempre raggiungibile, per compensare la barra che
 *    nascondiamo (vedi render_floating_logout()).
 *
 * Eccezioni deliberate:
 *  - `admin-ajax.php` e `admin-post.php`, che non sono interfaccia ma endpoint:
 *    bloccarli spegnerebbe la ricerca a faccette, i preferiti e i form del
 *    titolare, che passano tutti da lì;
 *  - `profile.php`, l'unica pagina che riguarda davvero l'utente stesso
 *    (password, email, preferenze di notifica). Non è "lavorare in wp-admin":
 *    senza, l'unico modo per cambiare password sarebbe la procedura di
 *    recupero via email.
 */
class Dealer_Access_Guard {

	/** Ruoli che non devono operare nel backend. */
	const PORTAL_ROLES = [ 'dealer', 'top_dealer', 'part_center', 'area_manager' ];

	/** Pagine di wp-admin comunque raggiungibili. */
	const ALLOWED_SCREENS = [ 'profile.php' ];

	/**
	 * True quando la pagina corrente ha già un proprio link di logout in vista
	 * (la dashboard, nell'header). Impostato da Dealer_Dashboard::render()
	 * prima di includere il proprio template, per evitare due link identici
	 * sulla stessa pagina.
	 */
	private static $logout_shown_elsewhere = false;

	public function __construct() {
		add_action( 'admin_init', [ $this, 'block_admin_access' ], 1 );
		add_filter( 'show_admin_bar', [ $this, 'maybe_hide_admin_bar' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_layout_helper' ] );
		add_action( 'wp_footer', [ $this, 'render_floating_logout' ] );
		add_filter( 'body_class', [ $this, 'add_body_class' ] );

		// Alcuni plugin di area riservata filtrano la query principale in base
		// al ruolo e trasformano in 404 anche pagine WordPress pubbliche. Le
		// pagine di Dealer Portal sono volutamente pubbliche come contenitore:
		// i dati riservati sono protetti dagli shortcode, non dallo stato della
		// pagina. Se la richiesta punta ESATTAMENTE a una nostra pagina
		// pubblicata, si ripristina solo quella. (Diagnosi e correzione
		// arrivate dalla build in produzione: era questa la causa reale dei 404
		// sul sito ufficiale, non lo stato delle pagine.)
		add_filter( 'the_posts',      [ $this, 'restore_plugin_page_query' ], PHP_INT_MAX, 2 );
		add_filter( 'pre_handle_404', [ $this, 'prevent_plugin_page_404' ],   PHP_INT_MAX, 2 );
		// Rete di sicurezza sul foglio di stile: vedi inline_stylesheet_fallback().
		add_filter( 'the_content', [ $this, 'inline_stylesheet_fallback' ], 4 );
		add_action( 'template_redirect', [ $this, 'route_dashboard' ] );
	}

	/**
	 * Smistamento sulla pagina Dashboard, fatto PRIMA che il tema cominci a
	 * stampare.
	 *
	 * Uno shortcode gira dentro il filtro the_content: a quel punto gli header
	 * sono già partiti e mezza pagina è già stata scritta. Lì un
	 * wp_safe_redirect() non reindirizza niente (header già inviati) e l'exit
	 * che lo accompagna tronca la pagina a metà — senza footer e, con una certa
	 * ironia, senza nemmeno il link di logout aggiunto apposta per non lasciare
	 * più nessuno in un vicolo cieco. L'unico punto dove un redirect funziona
	 * davvero è questo.
	 *
	 * Vale solo per la Dashboard, che è l'unica pagina del portale linkata dal
	 * sito e quindi l'unica dove ha senso mandare al login chi non è ancora
	 * autenticato: le altre quattro continuano a mostrare il proprio avviso con
	 * il link "Accedi", che funziona ed è meno brusco.
	 */
	public function route_dashboard(): void {
		// Niente is_page() qui: quando un filtro esterno azzera la query
		// principale quel controllo e' falso proprio sulla pagina che stiamo
		// cercando di salvare. L'identificazione passa dall'URL richiesto.
		if ( is_admin() ) {
			return;
		}

		$dashboard_id = (int) get_option( 'dealer_portal_dashboard_page_id' );
		$requested_id = self::requested_plugin_page_id();
		$current_id   = $requested_id ?: (int) get_queried_object_id();

		if ( ! $dashboard_id || $current_id !== $dashboard_id ) {
			return;
		}

		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( get_permalink( $dashboard_id ) ) );
			exit;
		}

		// L'area manager non ha una dashboard dealer: la sua area è un'altra,
		// ed è la stessa dove lo manda il redirect dopo il login.
		if ( ! current_user_can( 'manage_options' )
			&& Dealer_Identity::is_area_manager( wp_get_current_user() ) ) {
			wp_safe_redirect( Dealer_DB::area_manager_url() );
			exit;
		}
	}

	/**
	 * ID della pagina Dealer Portal richiesta dall'URL corrente.
	 *
	 * Il confronto avviene sul path del permalink reale, non sullo slug. Questo
	 * evita falsi positivi e continua a funzionare con WordPress in sottocartella
	 * o con pagine rinominate. Restituisce soltanto pagine ancora pubblicate.
	 */
	private static function requested_plugin_page_id(): int {
		// Memorizzata: la chiamano tre agganci diversi nella stessa richiesta
		// (the_posts, pre_handle_404, route_dashboard) e ognuno costerebbe fino
		// a sette get_permalink(). L'URL richiesto non cambia in corsa.
		static $memo = null;
		if ( null !== $memo ) {
			return $memo;
		}
		$memo = 0;

		if ( is_admin() || empty( $_SERVER['REQUEST_URI'] ) ) {
			return $memo;
		}

		$request_uri  = esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) );
		$request_path = (string) wp_parse_url( $request_uri, PHP_URL_PATH );
		$request_path = '/' . ltrim( rawurldecode( $request_path ), '/' );
		$request_path = untrailingslashit( $request_path );
		if ( '' === $request_path ) {
			$request_path = '/';
		}

		// Con i permalink "semplici" (?page_id=N) il PATH di ogni pagina del
		// sito e' identico: "/". Confrontare i soli path farebbe combaciare la
		// prima pagina dell'elenco — la dashboard — con qualunque richiesta, e
		// restore_plugin_page_query() riscriverebbe la query con quella: ogni
		// pagina del portale mostrerebbe la dashboard. E' successo davvero, nel
		// container di collaudo. Quando l'ID viaggia nella query string lo si
		// legge da li', che e' anche il confronto piu' diretto possibile.
		$request_args      = [];
		$request_query     = (string) wp_parse_url( $request_uri, PHP_URL_QUERY );
		if ( '' !== $request_query ) {
			wp_parse_str( $request_query, $request_args );
		}
		$requested_page_id = isset( $request_args['page_id'] ) ? absint( $request_args['page_id'] ) : 0;

		$options = [
			'dealer_portal_dashboard_page_id',
			'dealer_portal_search_page_id',
			'dealer_portal_team_page_id',
			'dealer_portal_am_page_id',
			'dealer_portal_fav_page_id',
			'dealer_portal_board_page_id',
			'dealer_portal_request_page_id',
		];

		foreach ( $options as $option ) {
			$page_id = (int) get_option( $option );
			if ( ! $page_id || 'page' !== get_post_type( $page_id ) || 'publish' !== get_post_status( $page_id ) ) {
				continue;
			}
			// Permalink semplici: l'identificazione e' esatta e non ambigua.
			if ( $requested_page_id && $requested_page_id === $page_id ) {
				$memo = $page_id;
				return $memo;
			}

			$permalink = get_permalink( $page_id );
			if ( ! $permalink ) {
				continue;
			}
			$page_path = (string) wp_parse_url( $permalink, PHP_URL_PATH );
			$page_path = '/' . ltrim( rawurldecode( $page_path ), '/' );
			$page_path = untrailingslashit( $page_path );
			if ( '' === $page_path ) {
				$page_path = '/';
			}

			// Un permalink che si riduce alla radice non identifica niente: o il
			// sito usa i permalink semplici (e allora vale il confronto sopra),
			// o quella pagina e' la home. In entrambi i casi confrontarlo
			// significherebbe far combaciare qualunque richiesta.
			if ( '/' === $page_path ) {
				continue;
			}

			if ( $request_path === $page_path ) {
				$memo = $page_id;
				return $memo;
			}
		}

		return $memo;
	}

	/**
	 * Ripristina esclusivamente una pagina del plugin che un filtro esterno ha
	 * rimosso dalla query principale. Non rende accessibile nessun altro post e
	 * non salta i controlli del portale: quelli restano dentro gli shortcode.
	 */
	public function restore_plugin_page_query( array $posts, \WP_Query $query ): array {
		if ( is_admin() || ! $query->is_main_query() ) {
			return $posts;
		}

		// Si interviene SOLO quando la query non ha restituito niente, che e' la
		// firma di "un filtro esterno ha rimosso la pagina". Se qualcosa c'e'
		// gia', non e' compito nostro sostituirlo: la versione precedente lo
		// faceva ogni volta che la nostra pagina non compariva fra i risultati,
		// e su un sito con i permalink semplici — dove l'identificazione della
		// pagina era ambigua — questo ha riscritto la query di ogni pagina del
		// portale con la dashboard. Meglio non ripristinare un caso raro che
		// rompere quello normale.
		if ( ! empty( $posts ) ) {
			return $posts;
		}

		$page_id = self::requested_plugin_page_id();
		if ( ! $page_id ) {
			return $posts;
		}

		$page = get_post( $page_id );
		if ( ! $page instanceof \WP_Post || 'page' !== $page->post_type || 'publish' !== $page->post_status ) {
			return $posts;
		}

		$query->posts             = [ $page ];
		$query->post_count        = 1;
		$query->found_posts       = 1;
		$query->max_num_pages     = 1;
		$query->queried_object    = $page;
		$query->queried_object_id = $page_id;
		$query->is_404            = false;
		$query->is_page           = true;
		$query->is_singular       = true;
		$query->is_home           = false;
		$query->is_archive        = false;
		$query->is_search         = false;
		$query->is_feed           = false;
		$query->set( 'page_id', $page_id );

		return [ $page ];
	}

	/** Impedisce a WP::handle_404() di riapplicare il 404 a una pagina ripristinata. */
	public function prevent_plugin_page_404( $preempt, \WP_Query $query ) {
		if ( is_admin() || ! $query->is_main_query() ) {
			return $preempt;
		}

		// Solo se la pagina c'e' davvero. Restituire true a prescindere
		// significherebbe impedire il 404 anche quando non c'e' niente da
		// mostrare, e la richiesta finirebbe su una pagina vuota invece che su
		// un errore onesto.
		if ( empty( $query->posts ) || ! self::requested_plugin_page_id() ) {
			return $preempt;
		}

		return true;
	}

	/**
	 * Riconosce le cinque pagine create da Dealer_DB::create_pages(), dall'ID
	 * salvato in opzione — mai dallo slug o dal titolo, che chi amministra il
	 * sito può cambiare senza che qui smetta di funzionare (stessa logica di
	 * Dealer_DB::resolve_page_url(), letta in senso inverso: lì un ID diventa
	 * un URL, qui un ID di pagina corrente viene confrontato con quelli
	 * salvati).
	 */
	public static function is_plugin_page( int $page_id ): bool {
		if ( ! $page_id ) {
			return false;
		}

		static $ids = null;
		if ( null === $ids ) {
			$ids = array_filter( array_map( 'absint', [
				get_option( 'dealer_portal_dashboard_page_id' ),
				get_option( 'dealer_portal_search_page_id' ),
				get_option( 'dealer_portal_team_page_id' ),
				get_option( 'dealer_portal_am_page_id' ),
				get_option( 'dealer_portal_fav_page_id' ),
				get_option( 'dealer_portal_board_page_id' ),
				get_option( 'dealer_portal_request_page_id' ),
			] ) );
		}

		return in_array( $page_id, $ids, true );
	}

	/**
	 * Classe sul <body> delle cinque pagine del portale, per agganciarci CSS
	 * che deve valere solo lì — es. centrare il titolo di pagina del tema
	 * (vedi dealer.css) — senza toccare il tema e senza indovinare uno slug.
	 */
	public function add_body_class( array $classes ): array {
		if ( is_page() && self::is_plugin_page( get_queried_object_id() ) ) {
			$classes[] = 'dealer-portal-page';
		}
		return $classes;
	}

	/** True quando il foglio di stile e' gia' stato messo in linea. */
	private static $css_inlined = false;

	/**
	 * Stampa dealer.css dentro il contenuto quando non e' arrivato nell'head.
	 *
	 * Su un'installazione reale e' emerso il caso peggiore possibile: la pagina
	 * dell'area riservata usciva con il contenuto giusto e SENZA stile. La
	 * barra di navigazione, unica cosa impaginata correttamente, e' anche
	 * l'unica il cui CSS e' stampato in linea insieme al proprio markup; tutto
	 * il resto dipende dal foglio accodato con wp_enqueue_style() e non
	 * arrivava. Il file c'era ed era quello giusto: semplicemente non
	 * raggiungeva la pagina.
	 *
	 * Le cause possibili sono molte e non si possono distinguere da qui: un
	 * tema o un plugin di area riservata che stampa la pagina senza passare da
	 * wp_head(), un ottimizzatore che concatena e perde gli stili accodati
	 * tardi, una CDN che non serve l'URL del plugin, un percorso di
	 * installazione che rende sbagliato plugin_dir_url(). Diagnosticarle a
	 * distanza significherebbe chiedere a chi amministra quel sito, e non e'
	 * una strada percorribile.
	 *
	 * La difesa che regge in tutti quei casi e' una sola, ed e' quella che sul
	 * sito vero gia' funziona: mettere il CSS dentro il contenuto. Si legge il
	 * file dal disco — quindi funziona anche quando l'URL non e' raggiungibile
	 * — e lo si stampa solo quando serve davvero: se il foglio e' gia' stato
	 * emesso nell'head, qui non si fa nulla e non si duplica niente.
	 *
	 * Priorita' 4: prima della barra di navigazione, che sta su 5.
	 */
	public function inline_stylesheet_fallback( $content ) {
		if ( is_admin() || self::$css_inlined || is_feed() || doing_action( 'wp_head' ) ) {
			return $content;
		}
		if ( ! is_page() ) {
			return $content;
		}

		$page_id = (int) get_the_ID();
		if ( ! $page_id || $page_id !== (int) get_queried_object_id() || ! self::is_plugin_page( $page_id ) ) {
			return $content;
		}

		// Gia' emesso nell'head dal normale accodamento: e' il caso sano, e qui
		// non c'e' niente da fare.
		if ( wp_style_is( 'dealer-portal-dealer', 'done' ) ) {
			self::$css_inlined = true;
			return $content;
		}

		$css = self::stylesheet_contents();
		if ( '' === $css ) {
			return $content;
		}

		self::$css_inlined = true;

		return '<style id="dealer-portal-inline-css">' . $css . '</style>' . $content;
	}

	/**
	 * Contenuto di dealer.css, letto una volta sola per richiesta.
	 *
	 * Nessun escape sul CSS: e' un file del plugin, non un dato di nessuno.
	 * Vengono tolti solo gli eventuali "</style>", che chiuderebbero il blocco
	 * in anticipo — non possono esserci in un foglio di stile valido, ma il
	 * costo del controllo e' nullo.
	 */
	private static function stylesheet_contents(): string {
		static $css = null;

		if ( null !== $css ) {
			return $css;
		}

		$file = DEALER_PORTAL_PATH . 'assets/css/dealer.css';
		$css  = is_readable( $file ) ? (string) file_get_contents( $file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		if ( '' !== $css ) {
			$css = str_ireplace( '</style', '', $css );
		}

		return $css;
	}

	/** Vedi $logout_shown_elsewhere. */
	public static function suppress_floating_logout(): void {
		self::$logout_shown_elsewhere = true;
	}

	/**
	 * Misura della larghezza reale della finestra, usata dal layout a tutta
	 * pagina (vedi assets/js/dealer-layout.js).
	 *
	 * Sta qui, e non nei singoli moduli, perché le pagine del portale sono
	 * cinque e con stili distribuiti fra dealer.css e blocchi <style> nei
	 * template: registrarlo una volta per l'utente del portale copre tutte
	 * quelle esistenti e quelle che verranno. Non si usa has_shortcode() per
	 * riconoscere la pagina — i page builder salvano il contenuto nei postmeta
	 * e quel controllo fallirebbe, che è già costato una volta.
	 *
	 * Sono poche centinaia di byte senza dipendenze: caricarlo su tutto il
	 * front-end di chi ha un ruolo del portale non ha un costo apprezzabile, e
	 * la sua assenza degraderebbe soltanto (il CSS ricade su 100vw).
	 */
	public function enqueue_layout_helper(): void {
		if ( ! is_user_logged_in() ) {
			return;
		}

		// Anche l'amministratore, che le pagine del portale può visitarle, e
		// chiunque si trovi su una di quelle pagine: la barra di navigazione
		// (Dealer_Portal_Nav) esce dal contenitore del tema come i wrapper del
		// portale, e senza --dp-vw ricadrebbe su 100vw — quindici pixel di
		// troppo e una barra di scorrimento orizzontale — proprio sulle
		// schermate di cortesia mostrate a chi un ruolo del portale non ce l'ha.
		if ( ! self::is_portal_user()
			&& ! current_user_can( 'manage_options' )
			&& ! ( is_page() && self::is_plugin_page( get_queried_object_id() ) ) ) {
			return;
		}

		wp_enqueue_script(
			'dealer-portal-layout',
			DEALER_PORTAL_URL . 'assets/js/dealer-layout.js',
			[],
			DEALER_PORTAL_VERSION,
			false // Nell'head: deve girare prima che la pagina venga disegnata.
		);
	}

	// ─── Riconoscimento ───────────────────────────────────────────────────────

	/**
	 * L'utente appartiene al portale e non ha poteri amministrativi?
	 *
	 * La verifica sulle capability serve a non chiudere fuori un
	 * amministratore a cui sia stato assegnato anche un ruolo del portale per
	 * fare delle prove: in quel caso wp-admin gli resta accessibile.
	 */
	public static function is_portal_user( ?\WP_User $user = null ): bool {
		$user = $user ?: wp_get_current_user();

		if ( ! $user || ! $user->exists() ) {
			return false;
		}
		if ( user_can( $user, 'manage_options' ) || user_can( $user, DEALER_PORTAL_CAP ) ) {
			return false;
		}

		return (bool) array_intersect( Dealer_Roles::portal_slugs(), (array) $user->roles );
	}

	/**
	 * Area di competenza dell'utente: dove va mandato al posto di wp-admin.
	 */
	public static function home_url_for( ?\WP_User $user = null ): string {
		$user = $user ?: wp_get_current_user();

		if ( $user && in_array( Dealer_Identity::ROLE_AREA_MANAGER, (array) $user->roles, true ) ) {
			return Dealer_DB::area_manager_url();
		}

		return Dealer_DB::dashboard_url();
	}

	// ─── Blocco del backend ───────────────────────────────────────────────────

	public function block_admin_access(): void {
		// Gli endpoint non sono interfaccia: bloccarli spegnerebbe AJAX e form.
		if ( wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}
		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			return;
		}

		$script = isset( $_SERVER['PHP_SELF'] ) ? basename( sanitize_text_field( wp_unslash( $_SERVER['PHP_SELF'] ) ) ) : '';
		if ( in_array( $script, [ 'admin-ajax.php', 'admin-post.php' ], true ) ) {
			return;
		}
		if ( in_array( $script, self::ALLOWED_SCREENS, true ) ) {
			return;
		}

		if ( ! self::is_portal_user() ) {
			return;
		}

		wp_safe_redirect( self::home_url_for() );
		exit;
	}

	public function maybe_hide_admin_bar( $show ) {
		return self::is_portal_user() ? false : $show;
	}

	// ─── Via d'uscita ─────────────────────────────────────────────────────────

	/**
	 * Link di logout sempre raggiungibile per chi non ha la barra di
	 * amministrazione.
	 *
	 * Nascondere la barra (sopra) toglie anche l'unico logout che WordPress
	 * offre di default sul front-end. L'unico rimpiazzo nel plugin era il
	 * link nell'header della dashboard: bastava cambiare ruolo durante un
	 * collaudo, finire su "quest'area è riservata a..." o su una qualunque
	 * delle altre quattro pagine del portale, e non c'era più modo di uscire
	 * se non con il tasto "indietro" del browser o cancellando i cookie —
	 * esattamente il vicolo cieco segnalato durante il collaudo reale.
	 *
	 * Un solo hook per tutte le pagine e per ogni schermata di cortesia
	 * (ruolo sbagliato, perimetro non configurato, organizzazione sospesa),
	 * invece di aggiungere il link a mano in ogni notice() e in ogni
	 * template: la condizione è la stessa della barra nascosta, quindi vive
	 * accanto ad essa. wp_footer gira dopo che lo shortcode ha già prodotto
	 * il contenuto — Dealer_Dashboard::render() ha quindi già avuto modo di
	 * chiamare suppress_floating_logout() se sta per mostrare il proprio link.
	 */
	public function render_floating_logout(): void {
		if ( is_admin() || self::$logout_shown_elsewhere || ! self::is_portal_user() ) {
			return;
		}

		printf(
			'<a href="%s" class="dealer-floating-logout">Esci</a>'
			. '<style>.dealer-floating-logout{position:fixed;bottom:16px;right:16px;z-index:99999;'
			. 'padding:8px 16px;background:#0a1628;color:#fff;border-radius:999px;font:600 13px/1.4 -apple-system,'
			. 'BlinkMacSystemFont,"Segoe UI",sans-serif;text-decoration:none;box-shadow:0 4px 14px rgba(0,0,0,.18);}'
			. '.dealer-floating-logout:hover,.dealer-floating-logout:focus{background:#155c91;color:#fff;}</style>',
			esc_url( wp_logout_url( home_url( '/' ) ) )
		);
	}
}
