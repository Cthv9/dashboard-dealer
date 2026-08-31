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
 *  - redirect dopo il login verso la propria area.
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

	public function __construct() {
		add_action( 'admin_init', [ $this, 'block_admin_access' ], 1 );
		add_filter( 'show_admin_bar', [ $this, 'maybe_hide_admin_bar' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_layout_helper' ] );
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

		// Anche l'amministratore, che le pagine del portale può visitarle.
		if ( ! self::is_portal_user() && ! current_user_can( 'manage_options' ) ) {
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

		return (bool) array_intersect( self::PORTAL_ROLES, (array) $user->roles );
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
}
