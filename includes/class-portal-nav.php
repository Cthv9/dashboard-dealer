<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Barra di navigazione unica dell'area riservata.
 *
 * Le pagine del portale sono cinque e fino ad ora ognuna sapeva tornare solo
 * alla dashboard, con un link scritto a mano nel proprio template: dalla
 * ricerca non si raggiungevano i preferiti, dall'area del titolare non si
 * raggiungeva la ricerca, e dall'area manager non si tornava da nessuna parte.
 * Il logout, poi, compariva solo dove qualcuno si era ricordato di metterlo.
 *
 * Qui la navigazione diventa una cosa sola per tutte le pagine e per tutti i
 * ruoli:
 *
 *  - si aggancia a `the_content` sulle pagine riconosciute dal plugin (le
 *    stesse cinque, riconosciute per ID salvato in opzione), quindi vale anche
 *    per le schermate di cortesia — "accesso non autorizzato", "perimetro non
 *    configurato", "organizzazione sospesa" — che sono solo una stringa
 *    restituita dallo shortcode e non passano da nessun template;
 *  - mostra a ciascuno soltanto le voci che per lui funzionano davvero: un
 *    link che porta a "accesso non autorizzato" è peggio di un link assente;
 *  - contiene sempre "Esci". Il link fluttuante di Dealer_Access_Guard è
 *    condizionato a is_portal_user(), che è falsa per l'amministratore: chi
 *    collauda cambiando ruolo si ritrovava senza via d'uscita proprio nel
 *    momento in cui gli serviva. Qui la condizione è solo "sei autenticato".
 *
 * Il CSS è stampato inline con la barra, una volta sola: i template del
 * portale non condividono un unico foglio di stile (dealer-team.php ha il
 * proprio blocco <style>) e una barra senza stile sarebbe indistinguibile dal
 * contenuto della pagina.
 */
class Dealer_Portal_Nav {

	/** True quando la barra è già stata stampata in questa richiesta. */
	private static $rendered = false;

	public function __construct() {
		// Priorità bassa: prima dell'espansione degli shortcode (11), così la
		// barra resta il primo elemento del contenuto qualunque cosa produca
		// lo shortcode sotto di essa.
		add_filter( 'the_content', [ $this, 'prepend_nav' ], 5 );
	}

	/**
	 * Antepone la barra al contenuto delle pagine del portale.
	 *
	 * La condizione e' "questo contenuto e' quello della pagina richiesta":
	 * l'ID del post in lavorazione deve coincidere con quello della pagina
	 * interrogata, che deve essere una delle nostre. Cosi' la barra non
	 * compare dentro un widget di articoli correlati o un blocco Query Loop
	 * che includa una di queste pagine.
	 *
	 * Non si usa in_the_loop(): nei temi a blocchi il blocco core/post-content
	 * applica il filtro 'the_content' fuori dal loop di WordPress, e quel
	 * controllo — che su un tema classico sarebbe la scelta ovvia — farebbe
	 * sparire la barra proprio sui temi su cui gira questo portale.
	 */
	public function prepend_nav( $content ) {
		if ( is_admin() || self::$rendered || ! is_user_logged_in() ) {
			return $content;
		}
		if ( ! is_page() ) {
			return $content;
		}

		$page_id = (int) get_the_ID();
		if ( ! $page_id || $page_id !== (int) get_queried_object_id() ) {
			return $content;
		}
		if ( ! Dealer_Access_Guard::is_plugin_page( $page_id ) ) {
			return $content;
		}

		$nav = self::render( $page_id );
		if ( '' === $nav ) {
			return $content;
		}

		self::$rendered = true;

		// La barra contiene già "Esci": il link fluttuante in fondo alla
		// pagina sarebbe un secondo logout identico a due centimetri di
		// distanza.
		Dealer_Access_Guard::suppress_floating_logout();

		return $nav . $content;
	}

	// ─── Voci ─────────────────────────────────────────────────────────────────

	/**
	 * Le voci visibili all'utente, nell'ordine in cui vanno mostrate.
	 *
	 * Ogni condizione riproduce il controllo d'accesso della pagina di
	 * destinazione, non una sua approssimazione: la ricerca e i preferiti sono
	 * riservati ai ruoli dealer (Dealer_Search::user_is_dealer), l'area del
	 * titolare a chi è titolare di un'organizzazione, quella dell'area manager
	 * a chi è area manager. L'amministratore vede le aree che il plugin gli
	 * lascia aprire — dashboard e area manager, dove trova una schermata
	 * esplicativa invece di un rifiuto — e non quelle che gli risponderebbero
	 * "accesso non autorizzato" perché non ha linee prodotto assegnate.
	 *
	 * @return array<int,array{key:string,label:string,url:string,page_id:int}>
	 */
	public static function items( ?\WP_User $user = null ): array {
		$user = $user ?: wp_get_current_user();
		if ( ! $user || ! $user->exists() ) {
			return [];
		}

		$is_admin  = user_can( $user, 'manage_options' );
		$is_dealer = Dealer_Search::user_is_dealer( $user );
		$is_am     = Dealer_Identity::is_area_manager( $user );

		$candidates = [
			[
				'key'     => 'dashboard',
				'label'   => 'Dashboard',
				'url'     => Dealer_DB::dashboard_url(),
				'option'  => 'dealer_portal_dashboard_page_id',
				// L'area manager non ha una dashboard dealer: route_dashboard()
				// lo rimanderebbe indietro. Un link che rimbalza è un link rotto.
				'visible' => $is_dealer || ( $is_admin && ! $is_am ),
			],
			[
				'key'     => 'search',
				'label'   => 'Cerca Documenti',
				'url'     => Dealer_DB::search_url(),
				'option'  => 'dealer_portal_search_page_id',
				'visible' => $is_dealer,
			],
			[
				'key'     => 'favorites',
				'label'   => 'Preferiti',
				'url'     => Dealer_DB::favorites_url(),
				'option'  => 'dealer_portal_fav_page_id',
				'visible' => $is_dealer,
			],
			[
				'key'     => 'team',
				'label'   => 'Collaboratori',
				'url'     => Dealer_DB::team_url(),
				'option'  => 'dealer_portal_team_page_id',
				'visible' => Dealer_Identity::is_titolare( $user ) || $is_admin,
			],
			[
				'key'     => 'area_manager',
				'label'   => 'Area Manager',
				'url'     => Dealer_DB::area_manager_url(),
				'option'  => 'dealer_portal_am_page_id',
				'visible' => $is_am || $is_admin,
			],
		];

		$items = [];
		foreach ( $candidates as $item ) {
			if ( ! $item['visible'] ) {
				continue;
			}
			$items[] = [
				'key'     => $item['key'],
				'label'   => $item['label'],
				'url'     => $item['url'],
				'page_id' => (int) get_option( $item['option'] ),
			];
		}

		return $items;
	}

	// ─── Rendering ────────────────────────────────────────────────────────────

	/**
	 * HTML della barra. Restituisce stringa vuota per chi non è autenticato:
	 * su quelle pagine lo shortcode mostra già il proprio invito ad accedere.
	 */
	public static function render( int $current_page_id = 0 ): string {
		if ( ! is_user_logged_in() ) {
			return '';
		}

		$user  = wp_get_current_user();
		$items = self::items( $user );

		$links = '';
		foreach ( $items as $item ) {
			$is_current = $current_page_id && $item['page_id'] === $current_page_id;
			$links     .= sprintf(
				'<a class="dealer-nav-item%1$s" href="%2$s"%3$s>%4$s</a>',
				$is_current ? ' is-current' : '',
				esc_url( $item['url'] ),
				$is_current ? ' aria-current="page"' : '',
				esc_html( $item['label'] )
			);
		}

		// Anche senza nessuna voce la barra si stampa: contiene il logout, che
		// è esattamente ciò che serve a chi è finito su una pagina che il suo
		// ruolo non può usare.
		$name = $user->display_name ? $user->display_name : $user->user_login;

		return '<nav class="dealer-portal-nav" aria-label="Area riservata"'
			. ' style="--dp-nav-max:' . self::content_max_width( $current_page_id ) . 'px">'
			. self::styles()
			. '<div class="dealer-nav-inner">'
			. '<div class="dealer-nav-links">' . $links . '</div>'
			. '<div class="dealer-nav-user">'
			. '<span class="dealer-nav-name">' . esc_html( $name ) . '</span>'
			. '<a class="dealer-nav-logout" href="' . esc_url( wp_logout_url( home_url( '/' ) ) ) . '">Esci</a>'
			. '</div>'
			. '</div>'
			. '</nav>';
	}

	/**
	 * Larghezza massima del contenuto della pagina su cui la barra viene
	 * stampata.
	 *
	 * Le pagine del portale non hanno tutte lo stesso tetto: dashboard,
	 * ricerca e preferiti si fermano a 1440px (dealer.css), l'area del
	 * titolare a 1080 e quella dell'area manager a 1180 (blocchi <style> nei
	 * rispettivi template). Usarne uno solo lascerebbe la barra più larga del
	 * contenuto proprio sulle due pagine più strette, con le voci scollate dal
	 * bordo sinistro di ciò che intestano.
	 */
	private static function content_max_width( int $page_id ): int {
		if ( $page_id && $page_id === (int) get_option( 'dealer_portal_team_page_id' ) ) {
			return 1080;
		}
		if ( $page_id && $page_id === (int) get_option( 'dealer_portal_am_page_id' ) ) {
			return 1180;
		}
		return 1440;
	}

	/**
	 * Stile della barra, stampato con essa.
	 *
	 * Non sta in dealer.css perché non tutte le pagine del portale caricano
	 * quel foglio (l'area del titolare ha il proprio blocco <style>) e perché
	 * le schermate di cortesia sono restituite prima che gli asset vengano
	 * accodati. Poche centinaia di byte, una volta per pagina.
	 */
	private static function styles(): string {
		return '<style>'
			// Stessa uscita dal contenitore del tema applicata ai wrapper del
			// portale (vedi il blocco "Wrapper globale" in dealer.css): senza,
			// la barra resterebbe stretta sopra un contenuto largo quanto la
			// finestra, disallineata proprio nel punto in cui deve fare da
			// intestazione. !important e "body" davanti per la stessa ragione
			// spiegata la': il selettore .is-layout-constrained > * dei temi a
			// blocchi impone margin:auto !important su ogni figlio diretto del
			// contenuto, e senza questi due accorgimenti vincerebbe lui.
			. 'body .dealer-portal-nav{box-sizing:border-box !important;'
			. 'width:var(--dp-vw,100vw) !important;max-width:var(--dp-vw,100vw) !important;'
			. 'margin-left:calc(50% - var(--dp-vw,100vw) / 2) !important;'
			. 'margin-right:calc(50% - var(--dp-vw,100vw) / 2) !important;'
			. 'margin-top:0 !important;margin-bottom:0 !important;'
			. 'border-bottom:1px solid #e2e8f0;background:#fff;}'
			// Ripiego di dealer-layout.js quando un contenitore del tema ritaglia
			// cio' che deborda: la barra rientra insieme al contenuto.
			. 'body .dealer-portal-nav.dp-no-bleed{width:auto !important;max-width:none !important;'
			. 'margin-left:0 !important;margin-right:0 !important;}'
			// Lo stesso tetto di 1440px e lo stesso margine minimo del contenuto
			// sotto: le voci risultano incolonnate con esso.
			. '.dealer-nav-inner{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;'
			. 'gap:10px;padding:8px max(20px,calc((var(--dp-vw,100vw) - var(--dp-nav-max,1440px)) / 2));'
			. 'font:500 14px/1.4 -apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;}'
			. '.dp-no-bleed .dealer-nav-inner{padding-left:20px;padding-right:20px;}'
			. '.dealer-nav-links{display:flex;flex-wrap:wrap;gap:4px;}'
			. '.dealer-nav-item{display:inline-block;padding:7px 14px;border-radius:999px;color:#334155;'
			. 'text-decoration:none;transition:background .15s,color .15s;}'
			. '.dealer-nav-item:hover,.dealer-nav-item:focus{background:#eef2f7;color:#0a1628;text-decoration:none;}'
			. '.dealer-nav-item.is-current{background:#0a1628;color:#fff;}'
			. '.dealer-nav-user{display:flex;align-items:center;gap:10px;}'
			. '.dealer-nav-name{color:#64748b;font-size:13px;}'
			. '.dealer-nav-logout{display:inline-block;padding:7px 16px;border-radius:999px;border:1px solid #cbd5e1;'
			. 'color:#0a1628;text-decoration:none;}'
			. '.dealer-nav-logout:hover,.dealer-nav-logout:focus{background:#0a1628;color:#fff;'
			. 'border-color:#0a1628;text-decoration:none;}'
			. '@media(max-width:600px){.dealer-nav-inner{padding-left:12px;padding-right:12px;}'
			. '.dealer-nav-name{display:none;}}'
			. '</style>';
	}
}
