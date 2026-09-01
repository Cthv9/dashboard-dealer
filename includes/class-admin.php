<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Dealer_Admin {

	// ─── Brand / Linee / Tipi ─────────────────────────────────────────────────

	const PRODUCT_LINES = [
		'Mercury'          => [ 'FourStroke', 'SeaPro', 'Racing', 'Jet', 'Ricambi & Accessori' ],
		'Yamaha'           => [ 'F-Series', 'T-Series High Thrust', 'Jet Drive', 'V-MAX SHO', 'Ricambi & Accessori' ],
		'Honda'            => [ 'BF-Series', 'BFP-Series', 'Ricambi & Accessori' ],
		'Suzuki'           => [ 'DF-Series', 'Ricambi & Accessori' ],
		'Tohatsu'          => [ 'MFS-Series', 'BFT-Series', 'Ricambi & Accessori' ],
		'Evinrude'         => [ 'E-TEC G2', 'E-TEC', 'Ricambi & Accessori' ],
		'BRP'              => [ 'Rotax Marine', 'Sea-Doo', 'Accessori' ],
		'Yanmar'           => [ 'SD-Series', 'JH-Series', 'Ricambi & Accessori' ],
		'Volvo Penta'      => [ 'IPS', 'D-Series', 'AQ-Series', 'Ricambi & Accessori' ],
		'Mercruiser'       => [ 'Alpha', 'Bravo', 'CMD', 'TRS', 'Ricambi & Accessori' ],
		'ZF'               => [ 'Pod Drive', 'Stern Drive', 'Transmission', 'Propulsion' ],
		'Ultraflex'        => [ 'Sistemi Sterzo', 'Cavi', 'Pompe Timone', 'Sterzo Idraulico' ],
		'Garmin'           => [ 'ECHOMAP', 'STRIKER', 'GPSMAP', 'VHF', 'Autopilota', 'Trasduttori' ],
		'Lowrance'         => [ 'HDS LIVE', 'HOOK Reveal', 'ELITE', 'Accessori' ],
		'Simrad'           => [ 'GO Series', 'NSS evo', 'NSX', 'AP Series', 'VHF' ],
		'Raymarine'        => [ 'Axiom', 'Element', 'Lighthouse', 'Autopilota', 'VHF' ],
		'Furuno'           => [ 'GP-Series', 'FCV-Series', 'FAR-Series', 'NavNet TZtouch' ],
		'B&G'              => [ 'Zeus', 'Vulcan', 'Triton', 'Autopilota' ],
		'Navionics'        => [ 'Navionics+', 'Gold Charts', 'Platinum+ Charts' ],
		'Humminbird'       => [ 'HELIX', 'SOLIX', 'ION', 'Accessori' ],
		'Standard Horizon' => [ 'Matrix', 'Explorer', 'Eclipse', 'VHF' ],
		'Icom'             => [ 'M-Series VHF', 'IC-AH Series', 'AIS' ],
		'Cobra'            => [ 'HH-Series', 'MR-Series', 'F-Series' ],
		'Torqeedo'         => [ 'Travel', 'Cruise', 'Deep Blue', 'Accessori' ],
		'ePropulsion'      => [ 'Spirit', 'Navy', 'Evo', 'Accessori' ],
		'Beneteau'         => [ 'Oceanis', 'Antares', 'Swift Trawler', 'Flyer' ],
		'Quicksilver'      => [ 'Activ', 'Weekend', 'Captur', 'Pilothouse' ],
		'Sealine'          => [ 'C-Series', 'F-Series', 'S-Series', 'SC-Series' ],
	];

	const DOC_TYPES = [
		'scheda_tecnica'  => 'Scheda Tecnica',
		'listino'         => 'Listino',
		'manuale'         => 'Manuale',
		'certificazione'  => 'Certificazione',
		'marketing'       => 'Marketing',
		'altro'           => 'Altro',
	];

	// ─── Constructor ─────────────────────────────────────────────────────────

	/**
	 * Documenti modificabili dall'utente corrente, risolti da render_upload()
	 * e letti dall'hook che restringe le query del wizard.
	 * null = nessuna restrizione (amministratore) o wizard non in corso.
	 *
	 * @var int[]|null
	 */
	private $wizard_document_scope = null;

	public function __construct() {
		add_action( 'admin_menu',             [ $this, 'register_menus' ] );
		add_action( 'admin_enqueue_scripts',  [ $this, 'enqueue_assets' ] );
		add_action( 'admin_notices',          [ $this, 'render_server_notice' ] );
		add_action( 'wp_ajax_dealer_save_document',    [ $this, 'ajax_save_document' ] );
		add_action( 'wp_ajax_dealer_get_lines',        [ $this, 'ajax_get_lines' ] );
		add_action( 'wp_ajax_dealer_mark_obsolete',    [ $this, 'ajax_mark_obsolete' ] );
		add_action( 'wp_ajax_dealer_delete_document',  [ $this, 'ajax_delete_document' ] );
		add_action( 'wp_ajax_dealer_bulk_action',      [ $this, 'ajax_bulk_action' ] );
		// Export CSV: handler admin_post (non AJAX) — deve poter scrivere header
		// e corpo del file senza che nulla venga emesso prima.
		add_action( 'admin_post_dealer_export_logs',   [ $this, 'handle_export_logs' ] );
		add_action( 'show_user_profile',      [ $this, 'render_user_fields' ] );
		add_action( 'edit_user_profile',      [ $this, 'render_user_fields' ] );
		add_action( 'personal_options_update',   [ $this, 'save_user_fields' ] );
		add_action( 'edit_user_profile_update',  [ $this, 'save_user_fields' ] );
	}

	// ─── Avviso di configurazione server ─────────────────────────────────────

	/**
	 * Avvisa chi amministra il sito quando il server ignora i file .htaccess.
	 *
	 * Non è una misura di sicurezza — quella è il token casuale nei nomi dei
	 * file, che regge su qualunque server. Questo avviso serve a chi installa
	 * il plugin per aggiungere, se vuole, la difesa in profondità che sul suo
	 * server manca: il plugin viene consegnato a un webmaster che non
	 * conosciamo, quindi l'informazione deve arrivargli da sola.
	 */
	public function render_server_notice(): void {
		if ( ! current_user_can( DEALER_PORTAL_CAP ) ) {
			return;
		}
		if ( Dealer_DB::server_honours_htaccess() ) {
			return;
		}

		$dir = str_replace( ABSPATH, '', Dealer_DB::protected_dir() );
		?>
		<div class="notice notice-warning">
			<p>
				<strong>Dealer Portal — nota per l'amministratore di sistema.</strong>
				Questo server non usa i file <code>.htaccess</code>, quindi la regola che
				il plugin scrive in <code><?php echo esc_html( $dir ); ?></code> non ha effetto.
			</p>
			<p>
				I documenti restano protetti perché i nomi dei file contengono un token
				casuale e non sono indovinabili, e ogni download passa comunque dai
				controlli del plugin. Per aggiungere una difesa in più, negare l'accesso
				diretto a quella cartella nella configurazione del server. Con nginx:
			</p>
			<p><code>location ~* /uploads/dealer-docs/ { deny all; }</code></p>
		</div>
		<?php
	}

	// ─── Menu ────────────────────────────────────────────────────────────────

	/**
	 * Menu del portale.
	 *
	 * Dettaglio WordPress che va gestito a mano: la pagina di primo livello usa
	 * la capability passata a add_menu_page(). Se restasse DEALER_PORTAL_CAP,
	 * l'area manager non vedrebbe il menu — e quindi nemmeno i sottomenu per cui
	 * ha capability. La capability del contenitore è perciò quella della prima
	 * pagina che l'utente corrente può davvero aprire, e il callback di primo
	 * livello instrada di conseguenza.
	 *
	 * I sottomenu registrati altrove a priorità 20 (Notifiche, Richieste
	 * Accesso) restano su DEALER_PORTAL_CAP: WordPress li omette da sé per chi
	 * non la possiede, quindi all'area manager non compaiono.
	 */
	public function register_menus(): void {
		$can_upload = current_user_can( DEALER_PORTAL_CAP_UPLOAD );
		$can_logs   = current_user_can( DEALER_PORTAL_CAP_LOGS );

		// Nessuna capability del portale: nessun menu.
		if ( ! $can_upload && ! $can_logs && ! current_user_can( DEALER_PORTAL_CAP ) ) {
			return;
		}

		if ( $can_upload ) {
			$top_cap = DEALER_PORTAL_CAP_UPLOAD;
		} elseif ( $can_logs ) {
			$top_cap = DEALER_PORTAL_CAP_LOGS;
		} else {
			$top_cap = DEALER_PORTAL_CAP;
		}

		add_menu_page(
			'Dealer Portal',
			'Dealer Portal',
			$top_cap,
			'dealer-portal',
			[ $this, 'render_landing' ],
			'dashicons-portfolio',
			30
		);

		// La voce omonima del primo livello ha senso solo se l'utente può
		// caricare: altrimenti il contenitore resta con la sola etichetta
		// "Dealer Portal" e render_landing() lo porta sulla prima pagina utile.
		if ( $can_upload ) {
			add_submenu_page( 'dealer-portal', 'Carica Documento',   'Carica Documento',   DEALER_PORTAL_CAP_UPLOAD, 'dealer-portal',         [ $this, 'render_landing' ] );
		}
		add_submenu_page( 'dealer-portal', 'Archivio Documenti', 'Archivio Documenti', DEALER_PORTAL_CAP_UPLOAD, 'dealer-portal-archive', [ $this, 'render_archive' ] );
		add_submenu_page( 'dealer-portal', 'Log Download',       'Log Download',       DEALER_PORTAL_CAP_LOGS,   'dealer-portal-logs',    [ $this, 'render_logs' ] );
		add_submenu_page( 'dealer-portal', 'Statistiche',        'Statistiche',        DEALER_PORTAL_CAP_LOGS,   'dealer-portal-stats',   [ $this, 'render_stats' ] );

		// Diagnostica. Registrata con 'manage_options' e NON con una capability
		// del plugin, di proposito: e' la pagina che serve proprio quando le
		// capability del plugin mancano — se dipendesse da loro sparirebbe
		// insieme a cio' che deve diagnosticare. Registrata inoltre da questa
		// classe, l'unica che sappiamo arrivare in fondo quando le altre non
		// compaiono nel menu.
		add_submenu_page( 'dealer-portal', 'Diagnostica', 'Diagnostica', 'manage_options', 'dealer-portal-diagnostics', [ $this, 'render_diagnostics' ] );
	}

	/**
	 * Stato reale dell'installazione: cosa e' caricato, cosa e' registrato,
	 * chi puo' cosa. Serve a rispondere con i fatti quando una voce di menu
	 * non compare — WordPress in quel caso non segnala nulla, la nasconde e
	 * basta, e senza questa pagina si finisce a tirare a indovinare.
	 */
	public function render_diagnostics(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Accesso non consentito.', 'dealer-portal' ) );
		}

		$classes = [
			'Dealer_CPT', 'Dealer_Roles', 'Dealer_DB', 'Dealer_Versioning',
			'Dealer_Organization', 'Dealer_Identity', 'Dealer_Admin', 'Dealer_Search',
			'Dealer_Dashboard', 'Dealer_SearchWP', 'Dealer_Notifications',
			'Dealer_Access_Request', 'Dealer_Org_Admin', 'Dealer_Team',
			'Dealer_Area_Manager', 'Dealer_Favorites', 'Dealer_Access_Guard',
		];

		$caps = [ DEALER_PORTAL_CAP, DEALER_PORTAL_CAP_UPLOAD, DEALER_PORTAL_CAP_LOGS, DEALER_PORTAL_CAP_ORGS ];

		$pages = [
			'dealer_portal_dashboard_page_id' => 'Dashboard',
			'dealer_portal_search_page_id'    => 'Cerca Documenti',
			'dealer_portal_fav_page_id'       => 'Preferiti',
			'dealer_portal_team_page_id'      => 'Gestione Collaboratori',
			'dealer_portal_am_page_id'        => 'Area Manager',
		];

		// Callback effettivamente agganciate ad admin_menu: se una classe non
		// compare qui, non e' stata istanziata (o il suo costruttore non e'
		// arrivato in fondo).
		$hooked = [];
		if ( isset( $GLOBALS['wp_filter']['admin_menu'] ) ) {
			foreach ( $GLOBALS['wp_filter']['admin_menu']->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $callback ) {
					$fn = $callback['function'] ?? null;
					if ( is_array( $fn ) ) {
						$name = ( is_object( $fn[0] ) ? get_class( $fn[0] ) : (string) $fn[0] ) . '::' . (string) $fn[1];
					} elseif ( is_string( $fn ) ) {
						$name = $fn;
					} else {
						$name = '(closure)';
					}
					if ( false !== stripos( $name, 'dealer' ) ) {
						$hooked[] = (int) $priority . ' — ' . $name;
					}
				}
			}
		}

		// Sottomenu realmente registrati sotto 'dealer-portal', con la
		// capability con cui sono stati registrati: e' il dato che dice se una
		// voce manca perche' non registrata o perche' nascosta dai permessi.
		$registered = isset( $GLOBALS['submenu']['dealer-portal'] ) ? (array) $GLOBALS['submenu']['dealer-portal'] : [];
		?>
		<div class="wrap">
			<h1>Dealer Portal — Diagnostica</h1>
			<p class="description">
				Stato reale di questa installazione. Se una voce di menu non compare, la risposta e' qui:
				o la classe non e' stata caricata, o il sottomenu non e' stato registrato, o manca la capability.
			</p>

			<h2>Versioni</h2>
			<table class="widefat striped" style="max-width:760px;"><tbody>
				<tr><td style="width:340px;">Versione nel codice</td><td><code><?php echo esc_html( DEALER_PORTAL_VERSION ); ?></code></td></tr>
				<tr><td>Versione registrata nel database</td><td><code><?php echo esc_html( (string) get_option( 'dealer_portal_version', '(assente)' ) ); ?></code></td></tr>
				<tr><td>Revisione capability</td><td><code><?php echo esc_html( (string) get_option( 'dealer_portal_caps_revision', '(assente)' ) ); ?></code> (attesa: <code><?php echo esc_html( (string) Dealer_DB::CAPS_REVISION ); ?></code>)</td></tr>
				<tr><td>Revisione pagine</td><td><code><?php echo esc_html( (string) get_option( 'dealer_portal_pages_revision', '(assente)' ) ); ?></code></td></tr>
				<tr><td>Revisione schema log</td><td><code><?php echo esc_html( (string) get_option( 'dealer_portal_schema_revision', '(assente)' ) ); ?></code></td></tr>
			</tbody></table>

			<h2>Sottomenu registrati sotto "Dealer Portal"</h2>
			<?php if ( empty( $registered ) ) : ?>
				<p><strong>Nessuno.</strong></p>
			<?php else : ?>
				<table class="widefat striped" style="max-width:760px;">
					<thead><tr><th>Voce</th><th>Capability richiesta</th><th>Tu ce l'hai?</th></tr></thead>
					<tbody>
					<?php foreach ( $registered as $item ) : ?>
						<tr>
							<td><?php echo esc_html( wp_strip_all_tags( (string) ( $item[0] ?? '' ) ) ); ?></td>
							<td><code><?php echo esc_html( (string) ( $item[1] ?? '' ) ); ?></code></td>
							<td><?php echo current_user_can( (string) ( $item[1] ?? '' ) ) ? '<span style="color:#00a32a;">si</span>' : '<strong style="color:#d63638;">NO</strong>'; ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<h2>Callback agganciate ad <code>admin_menu</code></h2>
			<?php if ( empty( $hooked ) ) : ?>
				<p><strong>Nessuna del plugin.</strong></p>
			<?php else : ?>
				<ul style="list-style:disc;margin-left:22px;">
					<?php foreach ( $hooked as $line ) : ?>
						<li><code><?php echo esc_html( $line ); ?></code></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<h2>Classi caricate e istanziate</h2>
			<table class="widefat striped" style="max-width:760px;">
				<thead><tr><th>Classe</th><th>File caricato</th></tr></thead>
				<tbody>
				<?php foreach ( $classes as $class ) : ?>
					<tr>
						<td><code><?php echo esc_html( $class ); ?></code></td>
						<td><?php echo class_exists( $class ) ? '<span style="color:#00a32a;">si</span>' : '<strong style="color:#d63638;">NO</strong>'; ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<h2>Capability del tuo utente</h2>
			<table class="widefat striped" style="max-width:760px;">
				<thead><tr><th>Capability</th><th>Tu</th><th>Ruolo amministratore</th></tr></thead>
				<tbody>
				<?php
				$admin_role = get_role( 'administrator' );
				foreach ( $caps as $cap ) :
					?>
					<tr>
						<td><code><?php echo esc_html( $cap ); ?></code></td>
						<td><?php echo current_user_can( $cap ) ? '<span style="color:#00a32a;">si</span>' : '<strong style="color:#d63638;">NO</strong>'; ?></td>
						<td><?php echo ( $admin_role && $admin_role->has_cap( $cap ) ) ? '<span style="color:#00a32a;">si</span>' : '<strong style="color:#d63638;">NO</strong>'; ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description">
				I tuoi ruoli: <code><?php echo esc_html( implode( ', ', (array) wp_get_current_user()->roles ) ); ?></code>
			</p>

			<h2>Ruoli del portale</h2>
			<table class="widefat striped" style="max-width:760px;"><tbody>
			<?php foreach ( [ 'dealer', 'top_dealer', 'part_center', 'area_manager' ] as $role_slug ) : ?>
				<tr>
					<td style="width:340px;"><code><?php echo esc_html( $role_slug ); ?></code></td>
					<td><?php echo get_role( $role_slug ) ? '<span style="color:#00a32a;">esiste</span>' : '<strong style="color:#d63638;">MANCA</strong>'; ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody></table>

			<h2>Pagine del portale</h2>
			<table class="widefat striped" style="max-width:760px;"><tbody>
			<?php foreach ( $pages as $option => $label ) :
				$page_id = (int) get_option( $option );
				$status  = $page_id ? get_post_status( $page_id ) : '';
				?>
				<tr>
					<td style="width:340px;"><?php echo esc_html( $label ); ?></td>
					<td>
						<?php if ( ! $page_id ) : ?>
							<strong style="color:#d63638;">non creata</strong>
						<?php else : ?>
							ID <code><?php echo esc_html( (string) $page_id ); ?></code> —
							<?php echo 'publish' === $status ? '<span style="color:#00a32a;">pubblicata</span>' : '<strong style="color:#d63638;">' . esc_html( $status ? $status : 'inesistente' ) . '</strong>'; ?>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody></table>
		</div>
		<?php
	}

	/**
	 * Pagina di primo livello: instrada verso la prima pagina che l'utente può
	 * aprire. Serve perché lo slug 'dealer-portal' è condiviso fra chi carica
	 * documenti e chi ha solo la lettura dei log.
	 */
	public function render_landing(): void {
		if ( current_user_can( DEALER_PORTAL_CAP_UPLOAD ) ) {
			$this->render_upload();
			return;
		}
		if ( current_user_can( DEALER_PORTAL_CAP_LOGS ) ) {
			$this->render_logs();
			return;
		}
		wp_die( esc_html__( 'Accesso non consentito.', 'dealer-portal' ) );
	}

	// ─── Assets ──────────────────────────────────────────────────────────────

	/**
	 * Gli hook di pagina non dipendono dalla capability con cui la pagina è
	 * stata registrata: restano questi anche quando ad aprirle è un area
	 * manager, quindi CSS e JS vengono caricati per lui esattamente come per
	 * l'amministratore. Nessun controllo di capability va aggiunto qui: chi
	 * arriva su questi hook ha già superato il controllo del menu.
	 */
	public function enqueue_assets( string $hook ): void {
		$plugin_hooks = [
			'toplevel_page_dealer-portal',
			'dealer-portal_page_dealer-portal-archive',
			'dealer-portal_page_dealer-portal-logs',
			'dealer-portal_page_dealer-portal-stats',
		];
		if ( ! in_array( $hook, $plugin_hooks, true ) ) {
			return;
		}

		wp_enqueue_style(
			'dealer-portal-admin',
			DEALER_PORTAL_URL . 'assets/css/admin.css',
			[],
			DEALER_PORTAL_VERSION
		);

		wp_enqueue_script(
			'dealer-portal-admin',
			DEALER_PORTAL_URL . 'assets/js/admin-upload.js',
			[ 'jquery' ],
			DEALER_PORTAL_VERSION,
			true
		);

		// Dati passati in modo sicuro via wp_localize_script (nessun inline JS con dati grezzi).
		wp_localize_script( 'dealer-portal-admin', 'dealerAdmin', [
			'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
			'nonce'        => wp_create_nonce( 'dealer_upload_nonce' ),
			'archiveNonce' => wp_create_nonce( 'dealer_archive_nonce' ),
			'bulkNonce'    => wp_create_nonce( 'dealer_bulk_nonce' ),
			'currentYear'  => (int) gmdate( 'Y' ),
			// Il wizard propone solo le linee che l'utente può davvero
			// pubblicare. È comodità, non sicurezza: la verifica che conta è
			// quella di ajax_save_document().
			'productLines' => self::allowed_product_lines( wp_get_current_user() ),
			'docTypes'     => self::DOC_TYPES,
			// L'eliminazione definitiva resta dell'amministratore: senza questo
			// flag l'area manager vedrebbe pulsanti che il server rifiuta.
			'canDelete'    => current_user_can( DEALER_PORTAL_CAP ),
			'i18n'         => [
				'confirmDelete'   => 'Eliminare definitivamente questo documento? Il file verrà cancellato dal server.',
				'confirmObsolete' => 'Segnare questo documento come obsoleto? Non sarà più visibile ai dealer.',
				'saveSuccess'     => 'Documento salvato con successo.',
				'saveError'       => 'Errore durante il salvataggio. Controlla i campi e riprova.',
				'uploading'       => 'Caricamento in corso…',
				'noPrevious'      => 'Nessuno — verrà creato un nuovo documento',
				// Perimetro: il wizard non deve proporre brand e linee che il
				// server rifiuterebbe (vedi allowed_product_lines()).
				'noBrands'        => '— Nessun brand nel tuo perimetro —',
				'noLines'         => '— Nessuna linea disponibile —',
				'docsAvailable'   => 'documenti disponibili',
				'noMatch'         => 'Nessun documento corrisponde al filtro.',
				'selectedPrefix'  => 'Selezionato:',
				// Operazioni bulk (archivio). %d viene sostituito lato JS.
				'bulkNoAction'    => 'Seleziona prima un\'azione di gruppo.',
				'bulkNoSelection' => 'Seleziona almeno un documento.',
				'bulkConfirmObs'  => 'Segnare come obsoleti %d documenti? Non saranno più visibili ai dealer '
					. 'e usciranno dalla ricerca; dove esiste uno storico, la versione precedente tornerà corrente.',
				'bulkConfirmDel'  => 'Eliminare definitivamente %d documenti? L\'operazione è IRREVERSIBILE: '
					. 'i documenti e i relativi file verranno cancellati dal server e non potranno essere recuperati.',
				'bulkWorking'     => 'Operazione in corso…',
				'bulkApply'       => 'Applica',
				'bulkError'       => 'Errore durante l\'operazione di gruppo.',
			],
		] );
	}

	// ─── Perimetro di visibilità ─────────────────────────────────────────────

	/**
	 * Tetto alle scansioni che filtrano in PHP: i criteri per linea vivono su
	 * un meta serializzato e non sono esprimibili in meta_query, quindi vanno
	 * applicati sui risultati. Senza un tetto, un archivio molto grande
	 * caricherebbe tutto in memoria a ogni apertura di pagina.
	 */
	const SCOPE_SCAN_LIMIT = 2000;

	/**
	 * Documenti del perimetro dell'utente, come insieme di ID.
	 *
	 * Due criteri distinti, gli stessi che valgono ovunque nel plugin:
	 *  - lettura  → sovrapposizione: basta una linea nel perimetro;
	 *  - modifica → contenimento: tutte le linee devono starci.
	 *
	 * @param bool $for_edit false = documenti visibili, true = modificabili.
	 * @return int[]|null null = nessuna restrizione (amministratore);
	 *                    array = insieme chiuso, eventualmente vuoto.
	 */
	private static function scoped_document_ids( \WP_User $user, bool $for_edit = false ): ?array {
		static $cache = [];

		if ( Dealer_Search::has_unrestricted_scope( $user ) ) {
			return null;
		}

		$key = (int) $user->ID . ( $for_edit ? ':edit' : ':view' );
		if ( isset( $cache[ $key ] ) ) {
			return $cache[ $key ];
		}

		// Chi non è né amministratore né area manager non vede nulla: meglio
		// un elenco vuoto che un perimetro assente scambiato per "tutto".
		if ( ! Dealer_Identity::is_area_manager( $user ) ) {
			$cache[ $key ] = [];
			return $cache[ $key ];
		}

		$scope = Dealer_Identity::get_scope_lines( $user );
		if ( empty( $scope ) ) {
			$cache[ $key ] = [];
			return $cache[ $key ];
		}

		// I documenti senza _doc_lines non sono comunque di competenza
		// dell'area manager: escluderli già in query riduce la scansione.
		$candidates = get_posts( [
			'post_type'      => 'documento_dealer',
			'post_status'    => [ 'publish', 'draft' ],
			'numberposts'    => self::SCOPE_SCAN_LIMIT,
			'fields'         => 'ids',
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'meta_query'     => [
				[ 'key' => '_doc_lines', 'compare' => 'EXISTS' ],
			],
		] );

		$matched = [];
		foreach ( (array) $candidates as $candidate_id ) {
			$candidate_id = (int) $candidate_id;
			$ok = $for_edit
				? Dealer_Identity::can_edit_document( $user, $candidate_id )
				: Dealer_Search::user_can_view_document( $user, $candidate_id, $scope );
			if ( $ok ) {
				$matched[] = $candidate_id;
			}
		}

		$cache[ $key ] = $matched;
		return $cache[ $key ];
	}

	/**
	 * Documenti che l'utente può vedere nelle viste amministrative (archivio,
	 * log, statistiche, export).
	 *
	 * @return int[]|null
	 */
	private static function visible_document_ids( \WP_User $user ): ?array {
		return self::scoped_document_ids( $user, false );
	}

	/**
	 * Dealer dentro il perimetro dell'utente: le organizzazioni che segue,
	 * sottoalberi inclusi. Usato dalle statistiche al posto dell'anagrafica
	 * completa della rete.
	 *
	 * @return \WP_User[] ordinati per nome visualizzato.
	 */
	private static function scoped_dealer_users( \WP_User $user ): array {
		$orgs = Dealer_Identity::get_scope_orgs( $user );
		if ( empty( $orgs ) ) {
			return [];
		}

		$users = [];
		foreach ( $orgs as $org_id ) {
			foreach ( Dealer_Organization::get_users( (int) $org_id ) as $member ) {
				if ( ! $member instanceof \WP_User || isset( $users[ $member->ID ] ) ) {
					continue;
				}
				// Solo i dealer: dentro un'organizzazione possono esserci
				// utenze che non appartengono alla rete commerciale.
				if ( ! Dealer_Search::user_is_dealer( $member ) ) {
					continue;
				}
				$users[ $member->ID ] = $member;
			}
		}

		usort( $users, static function ( \WP_User $a, \WP_User $b ): int {
			return strcasecmp( (string) $a->display_name, (string) $b->display_name );
		} );

		return $users;
	}

	// ─── Page renderers ──────────────────────────────────────────────────────

	public function render_upload(): void {
		if ( ! current_user_can( DEALER_PORTAL_CAP_UPLOAD ) ) {
			wp_die( esc_html__( 'Accesso non consentito.', 'dealer-portal' ) );
		}

		// Il selettore "Questo documento sostituisce…" è popolato dal template
		// con Dealer_Versioning::get_versionable_documents(). Per un area
		// manager deve elencare solo i documenti che può davvero modificare:
		// proporne uno fuori perimetro significherebbe mostrare un'azione che
		// ajax_save_document() rifiuta — e, peggio, rivelare l'esistenza di
		// documenti altrui.
		//
		// Il criterio sta su un meta serializzato, quindi si risolve prima in
		// PHP e si inietta come post__in: 'pre_get_posts' è l'unico aggancio
		// che scatta anche per get_posts(), che di default sopprime i filtri
		// sui risultati.
		//
		// L'insieme va risolto PRIMA di agganciare l'hook: la scansione usa a
		// sua volta get_posts(), e con l'hook già attivo si richiamerebbe da
		// sola all'infinito.
		$this->wizard_document_scope = self::scoped_document_ids( wp_get_current_user(), true );
		$restrict                    = ( null !== $this->wizard_document_scope );

		if ( $restrict ) {
			add_action( 'pre_get_posts', [ $this, 'limit_documents_to_editable' ] );
		}

		require DEALER_PORTAL_PATH . 'templates/admin-upload.php';

		if ( $restrict ) {
			remove_action( 'pre_get_posts', [ $this, 'limit_documents_to_editable' ] );
		}

		$this->wizard_document_scope = null;
	}

	/**
	 * Restringe ogni query di documenti a quelli modificabili dall'utente
	 * corrente. Attivo per la sola durata del wizard (vedi render_upload()):
	 * resta una comodità dell'interfaccia, l'autorizzazione vera è in
	 * ajax_save_document().
	 *
	 * @param mixed $query WP_Query in costruzione.
	 */
	public function limit_documents_to_editable( $query ): void {
		if ( ! $query instanceof WP_Query ) {
			return;
		}

		$post_type = $query->get( 'post_type' );
		$types     = is_array( $post_type ) ? $post_type : [ $post_type ];
		if ( ! in_array( 'documento_dealer', $types, true ) ) {
			return;
		}

		$allowed = $this->wizard_document_scope;
		if ( null === $allowed ) {
			return; // Amministratore, o wizard non in corso: nessuna restrizione.
		}

		// Un post__in già presente non va allargato, solo ristretto.
		$existing = array_filter( array_map( 'absint', (array) $query->get( 'post__in' ) ) );
		if ( $existing ) {
			$allowed = array_values( array_intersect( $existing, $allowed ) );
		}

		// Insieme vuoto: 'post__in' => [ 0 ] non corrisponde a nessun post.
		// Lasciarlo vuoto significherebbe invece "nessun filtro".
		$query->set( 'post__in', $allowed ?: [ 0 ] );
	}

	public function render_archive(): void {
		if ( ! current_user_can( DEALER_PORTAL_CAP_UPLOAD ) ) {
			wp_die( esc_html__( 'Accesso non consentito.', 'dealer-portal' ) );
		}

		// Filtri dalla GET (tutti sanitizzati).
		$filter_brand  = sanitize_text_field( wp_unslash( $_GET['filter_brand']  ?? '' ) );
		$filter_type   = sanitize_key( $_GET['filter_type']   ?? '' );
		$filter_role   = sanitize_key( $_GET['filter_role']   ?? '' );
		$filter_line   = sanitize_text_field( wp_unslash( $_GET['filter_line']   ?? '' ) );
		$filter_status = sanitize_key( $_GET['filter_status'] ?? '' );
		$paged         = max( 1, absint( $_GET['paged'] ?? 1 ) );

		// Filtro versioni: dimensione indipendente da _doc_status (obsoleto ≠ superato).
		// Default = solo versioni correnti; 'all' include anche le versioni superate.
		$filter_versions = sanitize_key( $_GET['filter_versions'] ?? '' );
		if ( 'all' !== $filter_versions ) {
			$filter_versions = '';
		}

		$meta_query = [ 'relation' => 'AND' ];
		if ( $filter_brand ) {
			$meta_query[] = [ 'key' => '_doc_brand', 'value' => $filter_brand, 'compare' => '=' ];
		}
		if ( $filter_type ) {
			$meta_query[] = [ 'key' => '_doc_type', 'value' => $filter_type, 'compare' => '=' ];
		}
		if ( $filter_status === 'obsoleto' ) {
			$meta_query[] = [ 'key' => '_doc_status', 'value' => 'obsoleto', 'compare' => '=' ];
			$post_status  = [ 'publish', 'draft' ];
		} else {
			$meta_query[] = [
				'relation' => 'OR',
				[ 'key' => '_doc_status', 'value' => 'obsoleto', 'compare' => '!=' ],
				[ 'key' => '_doc_status', 'compare' => 'NOT EXISTS' ],
			];
			$post_status = 'publish';
		}

		// Versioni superate: escluse salvo richiesta esplicita.
		if ( 'all' !== $filter_versions ) {
			$meta_query[] = Dealer_Versioning::meta_query_current_only();
		}

		$per_page     = 20;
		$current_user = wp_get_current_user();

		// Perimetro: l'area manager vede solo i documenti che ricadono sulle
		// proprie linee. Il criterio (meta serializzato) non è esprimibile in
		// meta_query, quindi si applica in PHP — come già per ruolo e linea.
		$restrict_scope = ! Dealer_Search::has_unrestricted_scope( $current_user );
		$scope_lines    = $restrict_scope ? Dealer_Identity::get_scope_lines( $current_user ) : [];

		$query_args = [
			'post_type'   => 'documento_dealer',
			'post_status' => $post_status,
			'orderby'     => 'date',
			'order'       => 'DESC',
			'meta_query'  => $meta_query,
		];

		$scan_truncated = false;

		if ( $restrict_scope || $filter_role || $filter_line ) {
			// Almeno un filtro lavora dopo la query: paginare nel database
			// darebbe pagine mezze vuote e un conteggio sbagliato. Si scandisce
			// l'insieme filtrato (con un tetto), si filtra, e solo allora si
			// impagina — così i conteggi e i link di pagina restano coerenti
			// con ciò che l'utente vede davvero.
			$scan = new WP_Query( array_merge( $query_args, [
				'posts_per_page' => self::SCOPE_SCAN_LIMIT,
				'paged'          => 1,
				'fields'         => 'ids',
				'no_found_rows'  => false,
			] ) );

			$ids            = array_map( 'absint', (array) $scan->posts );
			$scan_truncated = (int) $scan->found_posts > count( $ids );

			if ( $restrict_scope ) {
				$ids = array_values( array_filter( $ids, static function ( $id ) use ( $current_user, $scope_lines ) {
					return Dealer_Search::user_can_view_document( $current_user, $id, $scope_lines );
				} ) );
			}
			if ( $filter_role ) {
				$ids = array_values( array_filter( $ids, static function ( $id ) use ( $filter_role ) {
					$roles = get_post_meta( $id, '_doc_roles', true );
					return is_array( $roles ) && in_array( $filter_role, $roles, true );
				} ) );
			}
			if ( $filter_line ) {
				$ids = array_values( array_filter( $ids, static function ( $id ) use ( $filter_line ) {
					$lines = get_post_meta( $id, '_doc_lines', true );
					return is_array( $lines ) && in_array( $filter_line, $lines, true );
				} ) );
			}

			$total_pages = (int) ceil( count( $ids ) / $per_page );
			if ( $total_pages > 0 && $paged > $total_pages ) {
				$paged = $total_pages;
			}

			$page_ids  = array_slice( $ids, ( $paged - 1 ) * $per_page, $per_page );
			$documents = $page_ids
				? get_posts( [
					'post_type'   => 'documento_dealer',
					'post_status' => $post_status,
					'post__in'    => $page_ids,
					'orderby'     => 'post__in',
					'numberposts' => $per_page,
				] )
				: [];
		} else {
			$wp_query    = new WP_Query( array_merge( $query_args, [
				'posts_per_page' => $per_page,
				'paged'          => $paged,
			] ) );
			$documents   = $wp_query->posts;
			$total_pages = (int) $wp_query->max_num_pages;
		}

		// Riepilogo dell'ultima operazione di gruppo: il JS ricarica la pagina
		// con questi parametri (solo interi + una chiave whitelistata) così la
		// notice è renderizzata lato server con il markup WordPress nativo.
		$bulk_summary = self::parse_bulk_summary();

		require DEALER_PORTAL_PATH . 'templates/admin-archive.php';
	}

	/**
	 * Legge dalla query string il riepilogo dell'ultima operazione bulk.
	 *
	 * @return array|null null se non c'è nessun riepilogo da mostrare.
	 */
	private static function parse_bulk_summary(): ?array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET['bulk_done'] ) ) {
			return null;
		}

		$op = sanitize_key( $_GET['bulk_op'] ?? '' );
		if ( ! in_array( $op, [ 'obsolete', 'delete' ], true ) ) {
			return null;
		}

		return [
			'op'        => $op,
			'processed' => absint( $_GET['bulk_ok']      ?? 0 ),
			'invalid'   => absint( $_GET['bulk_invalid'] ?? 0 ),
			'already'   => absint( $_GET['bulk_already'] ?? 0 ),
			'no_succ'   => absint( $_GET['bulk_nosucc']  ?? 0 ),
			'failed'    => absint( $_GET['bulk_failed']  ?? 0 ),
			'promoted'  => absint( $_GET['bulk_promo']   ?? 0 ),
		];
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	public function render_logs(): void {
		if ( ! current_user_can( DEALER_PORTAL_CAP_LOGS ) ) {
			wp_die( esc_html__( 'Accesso non consentito.', 'dealer-portal' ) );
		}

		$current_user = wp_get_current_user();

		$filters  = self::get_log_filters();
		$per_page = 50;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$paged    = max( 1, absint( $_GET['paged'] ?? 1 ) );

		// Perimetro: si filtra per documento, non per utente. È il documento a
		// portare l'informazione di linea, ed è lì che passa la competenza.
		// Un filtro_doc scelto fuori perimetro non allarga nulla: le due
		// condizioni si sommano e la seconda non ha righe.
		$filters['args']['post_ids'] = self::visible_document_ids( $current_user );

		$total_logs  = Dealer_DB::count_logs( $filters['args'] );
		$total_pages = (int) ceil( $total_logs / $per_page );
		if ( $total_pages > 0 && $paged > $total_pages ) {
			$paged = $total_pages;
		}

		$logs = Dealer_DB::get_all_logs( array_merge( $filters['args'], [
			'limit'  => $per_page,
			'offset' => ( $paged - 1 ) * $per_page,
		] ) );

		// Opzioni per i menu a tendina dei filtri.
		$log_documents = self::get_filterable_documents( $filters['args']['post_ids'] );

		// Il menu "dealer" segue lo stesso perimetro delle statistiche: per un
		// area manager elenca solo i dealer delle organizzazioni che segue.
		// L'elenco completo qui esporrebbe nome ed email dell'intera rete —
		// anagrafica altrui — anche se le righe di log restano filtrate.
		// post_ids === null significa "nessuna restrizione": amministratore.
		$log_dealers = ( null === $filters['args']['post_ids'] )
			? self::get_filterable_dealers()
			: self::scoped_dealer_users( $current_user );

		// URL di export: stessi filtri della vista + nonce dedicato.
		$export_url = self::build_export_url( $filters );

		require DEALER_PORTAL_PATH . 'templates/admin-logs.php';
	}

	public function render_stats(): void {
		if ( ! current_user_can( DEALER_PORTAL_CAP_LOGS ) ) {
			wp_die( esc_html__( 'Accesso non consentito.', 'dealer-portal' ) );
		}

		// ─── Periodo selezionato ─────────────────────────────────────────────
		$periods = [
			'30'  => 'Ultimi 30 giorni',
			'90'  => 'Ultimi 90 giorni',
			'365' => 'Ultimo anno',
			'all' => 'Sempre',
		];
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$period = sanitize_key( $_GET['periodo'] ?? '30' );
		if ( ! isset( $periods[ $period ] ) ) {
			$period = '30';
		}

		$since = ( 'all' === $period ) ? '' : self::days_ago_mysql( (int) $period );

		// ─── Perimetro ───────────────────────────────────────────────────────
		// Ogni numero di questa pagina è calcolato sul sottoinsieme di documenti
		// visibile a chi guarda: classifiche, mai scaricati e dealer inattivi
		// devono raccontare lo stesso archivio che l'utente vede nell'elenco,
		// altrimenti i totali rivelano l'esistenza di ciò che è filtrato.
		$current_user = wp_get_current_user();
		$visible_ids  = self::visible_document_ids( $current_user );
		$restricted   = ( null !== $visible_ids );

		// ─── Riquadri riassuntivi ────────────────────────────────────────────
		$total_downloads  = Dealer_DB::get_total_downloads( '', $visible_ids );
		$downloads_30     = Dealer_DB::get_total_downloads( self::days_ago_mysql( 30 ), $visible_ids );
		$downloads_period = ( 'all' === $period ) ? $total_downloads : Dealer_DB::get_total_downloads( $since, $visible_ids );

		// ─── Documenti attivi (correnti, non obsoleti) ───────────────────────
		$docs_query = new WP_Query( [
			'post_type'      => 'documento_dealer',
			'post_status'    => 'publish',
			'posts_per_page' => 1000,
			'fields'         => 'ids',
			'no_found_rows'  => false,
			'meta_query'     => [
				'relation' => 'AND',
				[
					'relation' => 'OR',
					[ 'key' => '_doc_status', 'value' => 'obsoleto', 'compare' => '!=' ],
					[ 'key' => '_doc_status', 'compare' => 'NOT EXISTS' ],
				],
				Dealer_Versioning::meta_query_current_only(),
			],
		] );
		$active_doc_ids   = array_map( 'absint', (array) $docs_query->posts );
		$active_docs      = (int) $docs_query->found_posts;
		$docs_truncated   = $active_docs > count( $active_doc_ids );

		if ( $restricted ) {
			// Il conteggio diventa quello del perimetro: mostrare il totale di
			// rete accanto a un elenco filtrato sarebbe fuorviante.
			$active_doc_ids = array_values( array_intersect( $active_doc_ids, $visible_ids ) );
			$active_docs    = count( $active_doc_ids );
		}

		// ─── Dealer registrati ───────────────────────────────────────────────
		// Amministratore: tutta la rete. Area manager: i dealer delle
		// organizzazioni che segue — l'altro asse del suo perimetro. Fuori di
		// lì non gli servono, e l'anagrafica altrui non è affar suo.
		if ( $restricted ) {
			$dealer_users       = self::scoped_dealer_users( $current_user );
			$registered_dealers = count( $dealer_users );
		} else {
			$dealer_roles  = [ 'dealer', 'top_dealer', 'part_center' ];
			$dealer_query  = new WP_User_Query( [
				'role__in' => $dealer_roles,
				'number'   => 1000,
				'orderby'  => 'display_name',
				'order'    => 'ASC',
				'fields'   => [ 'ID', 'display_name', 'user_email' ],
			] );
			$dealer_users       = (array) $dealer_query->get_results();
			$registered_dealers = (int) $dealer_query->get_total();
		}

		// ─── Classifiche ─────────────────────────────────────────────────────
		$top_documents = Dealer_DB::get_top_documents( 10, $since, $visible_ids );
		$top_users     = Dealer_DB::get_top_users( 10, $since, $visible_ids );

		// ─── Documenti mai scaricati ─────────────────────────────────────────
		// Confronta i documenti pubblicati con quelli presenti nel log.
		$download_counts  = Dealer_DB::get_download_counts_for_posts( $active_doc_ids );
		$never_downloaded = [];
		foreach ( $active_doc_ids as $doc_id ) {
			if ( empty( $download_counts[ $doc_id ] ) ) {
				$never_downloaded[] = $doc_id;
			}
		}
		$never_downloaded_total = count( $never_downloaded );
		$never_downloaded       = array_slice( $never_downloaded, 0, 25 );

		// ─── Dealer inattivi (>90 giorni o mai) ──────────────────────────────
		// "Inattivo" è relativo a ciò che si vede: per un area manager significa
		// "non scarica i documenti di mia competenza", non "non scarica nulla".
		$last_downloads = Dealer_DB::get_last_download_per_user( $visible_ids );
		$threshold      = self::days_ago_mysql( 90 );
		$inactive_dealers = [];
		foreach ( $dealer_users as $dealer ) {
			$uid  = (int) $dealer->ID;
			$last = $last_downloads[ $uid ] ?? '';
			if ( $last && $last >= $threshold ) {
				continue;
			}
			$inactive_dealers[] = [
				'id'            => $uid,
				'name'          => (string) $dealer->display_name,
				'email'         => (string) $dealer->user_email,
				'last_download' => $last,
				'last_login'    => (string) get_user_meta( $uid, '_dealer_last_login', true ),
			];
		}
		// I "mai scaricato" per primi, poi dal più vecchio al più recente.
		usort( $inactive_dealers, static function ( array $a, array $b ): int {
			return strcmp( $a['last_download'], $b['last_download'] );
		} );
		$inactive_total   = count( $inactive_dealers );
		$inactive_dealers = array_slice( $inactive_dealers, 0, 25 );

		// "Attivi" = hanno scaricato almeno una volta negli ultimi 90 giorni.
		$active_dealers = max( 0, count( $dealer_users ) - $inactive_total );

		require DEALER_PORTAL_PATH . 'templates/admin-stats.php';
	}

	/**
	 * Data MySQL di N giorni fa nel fuso orario del sito (i log usano
	 * current_time( 'mysql' ), quindi il confronto deve restare in ora locale).
	 */
	private static function days_ago_mysql( int $days ): string {
		$now = (int) current_time( 'timestamp' );
		return gmdate( 'Y-m-d H:i:s', $now - ( max( 0, $days ) * DAY_IN_SECONDS ) );
	}

	// ─── Log download: filtri, opzioni, URL di export ────────────────────────

	/**
	 * Legge e valida i filtri della pagina Log Download.
	 * Restituisce sia i valori grezzi (per ripopolare il form e costruire gli
	 * URL) sia gli argomenti pronti per Dealer_DB::get_all_logs()/count_logs().
	 *
	 * @param array|null $source Sorgente da cui leggere (default: $_GET).
	 * @return array{doc:int,user:int,from:string,to:string,args:array}
	 */
	private static function get_log_filters( ?array $source = null ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$source = ( null === $source ) ? $_GET : $source;

		$doc  = absint( $source['filter_doc']  ?? 0 );
		$user = absint( $source['filter_user'] ?? 0 );
		$from = sanitize_text_field( wp_unslash( (string) ( $source['filter_from'] ?? '' ) ) );
		$to   = sanitize_text_field( wp_unslash( (string) ( $source['filter_to']   ?? '' ) ) );

		if ( ! self::is_valid_date( $from ) ) { $from = ''; }
		if ( ! self::is_valid_date( $to ) )   { $to   = ''; }

		// Intervallo invertito: si scambiano invece di restituire zero righe.
		if ( $from && $to && $from > $to ) {
			$swap = $from;
			$from = $to;
			$to   = $swap;
		}

		$args = [];
		if ( $doc )  { $args['post_id']   = $doc; }
		if ( $user ) { $args['user_id']   = $user; }
		if ( $from ) { $args['date_from'] = $from . ' 00:00:00'; }
		if ( $to )   { $args['date_to']   = $to . ' 23:59:59'; }

		return [
			'doc'  => $doc,
			'user' => $user,
			'from' => $from,
			'to'   => $to,
			'args' => $args,
		];
	}

	/** Valida una data nel formato Y-m-d (giorno realmente esistente). */
	private static function is_valid_date( string $date ): bool {
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return false;
		}
		$dt = DateTime::createFromFormat( 'Y-m-d', $date );
		return ( $dt && $dt->format( 'Y-m-d' ) === $date );
	}

	/**
	 * Documenti selezionabili nel filtro della pagina log.
	 *
	 * @param int[]|null $visible_ids Perimetro: null = tutti, array = solo questi.
	 * @return \WP_Post[]
	 */
	private static function get_filterable_documents( ?array $visible_ids = null ): array {
		$args = [
			'post_type'      => 'documento_dealer',
			'post_status'    => [ 'publish', 'draft' ],
			'numberposts'    => 300,
			'orderby'        => 'title',
			'order'          => 'ASC',
		];

		if ( null !== $visible_ids ) {
			if ( empty( $visible_ids ) ) {
				return []; // Nessun documento nel perimetro: tendina vuota.
			}
			$args['post__in'] = $visible_ids;
		}

		return get_posts( $args );
	}

	/**
	 * Dealer selezionabili nel filtro della pagina log.
	 *
	 * @return array<int,object>
	 */
	private static function get_filterable_dealers(): array {
		$query = new WP_User_Query( [
			'role__in' => [ 'dealer', 'top_dealer', 'part_center' ],
			'number'   => 500,
			'orderby'  => 'display_name',
			'order'    => 'ASC',
			'fields'   => [ 'ID', 'display_name', 'user_email' ],
		] );
		return (array) $query->get_results();
	}

	/** URL dell'export CSV con i filtri correnti e il nonce dedicato. */
	private static function build_export_url( array $filters ): string {
		$url = admin_url( 'admin-post.php?action=dealer_export_logs' );

		if ( $filters['doc'] )  { $url = add_query_arg( 'filter_doc',  $filters['doc'],  $url ); }
		if ( $filters['user'] ) { $url = add_query_arg( 'filter_user', $filters['user'], $url ); }
		if ( $filters['from'] ) { $url = add_query_arg( 'filter_from', $filters['from'], $url ); }
		if ( $filters['to'] )   { $url = add_query_arg( 'filter_to',   $filters['to'],   $url ); }

		return wp_nonce_url( $url, 'dealer_export_logs', 'dealer_export_nonce' );
	}

	// ─── Export CSV dei log download (admin_post, non AJAX) ──────────────────

	/**
	 * Scrive il CSV direttamente sull'output.
	 *
	 * Nessun byte viene emesso prima della verifica di capability e nonce e
	 * prima degli header: qualunque output anticipato romperebbe il download.
	 * Le righe sono lette a blocchi via limit/offset per non caricare in
	 * memoria un archivio di log arbitrariamente grande.
	 */
	public function handle_export_logs(): void {
		if ( ! current_user_can( DEALER_PORTAL_CAP_LOGS ) ) {
			wp_die( esc_html__( 'Accesso non consentito.', 'dealer-portal' ), '', [ 'response' => 403 ] );
		}

		// Nonce dedicato all'export (non riusa quelli di upload/archivio).
		check_admin_referer( 'dealer_export_logs', 'dealer_export_nonce' );

		$filters = self::get_log_filters();

		// Perimetro, ricalcolato qui lato server: l'export deve restituire
		// esattamente ciò che l'utente vede nella pagina log, né una riga di
		// più. È il punto più delicato di tutto il filtro — un export che
		// ignorasse il perimetro sarebbe il modo più semplice per aggirarlo,
		// e non passa nemmeno dalla query string (dove sarebbe manomettibile).
		$filters['args']['post_ids'] = self::visible_document_ids( wp_get_current_user() );

		// Congela il set esportato all'id più alto presente adesso: i download
		// registrati mentre l'export gira sposterebbero altrimenti le righe fra
		// un blocco e il successivo (ORDER BY data + OFFSET), con il rischio di
		// saltarne o duplicarne.
		$filters['args']['max_id'] = Dealer_DB::get_max_log_id();

		$total = Dealer_DB::count_logs( $filters['args'] );

		$filename = 'log-download-dealer-' . gmdate( 'Ymd-His', (int) current_time( 'timestamp' ) ) . '.csv';

		// Svuota eventuali buffer aperti: nulla deve precedere gli header.
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		$out = fopen( 'php://output', 'w' );

		// BOM UTF-8: senza, Excel interpreta il file come ANSI e rovina gli accenti.
		fwrite( $out, "\xEF\xBB\xBF" );

		// Separatore ';' — è quello atteso da Excel con impostazioni italiane.
		$sep = ';';

		fputcsv( $out, [ 'Documento', 'ID documento', 'Dealer', 'Email dealer', 'Titolo di accesso', 'Data e ora', 'Indirizzo IP' ], $sep );

		$chunk  = 1000;
		$offset = 0;

		while ( $offset < $total ) {
			$rows = Dealer_DB::get_all_logs( array_merge( $filters['args'], [
				'limit'  => $chunk,
				'offset' => $offset,
			] ) );

			if ( empty( $rows ) ) {
				break;
			}

			foreach ( $rows as $row ) {
				$title = $row->post_title ? $row->post_title : 'Documento eliminato (ID ' . (int) $row->post_id . ')';
				$name  = $row->display_name ? $row->display_name : 'Utente #' . (int) $row->user_id;

				fputcsv( $out, [
					self::csv_cell( $title ),
					(int) $row->post_id,
					self::csv_cell( $name ),
					self::csv_cell( (string) $row->user_email ),
					// Le righe anteriori alla colonna hanno il valore vuoto:
					// l'etichetta le riporta a "Dealer", che era l'unico titolo
					// possibile allora.
					self::csv_cell( Dealer_DB::access_context_label( (string) ( $row->access_context ?? '' ) ) ),
					self::csv_cell( gmdate( 'd/m/Y H:i:s', strtotime( (string) $row->download_date ) ) ),
					self::csv_cell( (string) $row->ip_address ),
				], $sep );
			}

			$offset += $chunk;

			// Svuota il buffer di rete: export lunghi non gonfiano la memoria.
			if ( ob_get_level() > 0 ) {
				ob_flush();
			}
			flush();
		}

		fclose( $out );
		exit;
	}

	/**
	 * Neutralizza la CSV injection.
	 *
	 * Un valore che inizia con '=', '+', '-' o '@' viene eseguito come formula
	 * da Excel/LibreOffice all'apertura del file: il prefisso con apostrofo lo
	 * forza a testo. Vale per ogni campo di testo, anche quelli "innocui":
	 * titoli, nomi utente ed email sono controllati (anche) dall'esterno.
	 */
	private static function csv_cell( string $value ): string {
		// Rimuove i caratteri di controllo che spezzerebbero la cella.
		$value = str_replace( [ "\r", "\n", "\t" ], ' ', $value );
		$value = trim( $value );

		if ( '' === $value ) {
			return '';
		}

		if ( in_array( $value[0], [ '=', '+', '-', '@' ], true ) ) {
			return "'" . $value;
		}

		return $value;
	}

	// ─── AJAX: operazioni di gruppo sull'archivio ────────────────────────────

	/**
	 * Applica un'azione bulk a una selezione di documenti.
	 *
	 * La conferma è già stata data una volta sola lato client per l'intera
	 * selezione: qui non c'è il flusso interattivo a due fasi di
	 * ajax_mark_obsolete(), che non avrebbe senso documento per documento.
	 */
	public function ajax_bulk_action(): void {
		check_ajax_referer( 'dealer_bulk_nonce', 'nonce' );

		// Azione di gruppo: opera su elenchi arbitrari di ID senza verifica di
		// perimetro documento per documento, e una delle due azioni è
		// l'eliminazione definitiva. Resta interamente all'amministratore.
		if ( ! current_user_can( DEALER_PORTAL_CAP ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
		}

		$bulk_action = sanitize_key( $_POST['bulk_action'] ?? '' );
		if ( ! in_array( $bulk_action, [ 'obsolete', 'delete' ], true ) ) {
			wp_send_json_error( [ 'message' => 'Azione di gruppo non valida.' ] );
		}

		// Ogni ID viene validato singolarmente: quelli non validi vengono
		// ignorati e conteggiati, non fanno fallire l'intera operazione.
		$raw_ids = isset( $_POST['post_ids'] ) ? (array) wp_unslash( $_POST['post_ids'] ) : [];
		$ids     = [];
		$invalid = 0;

		foreach ( $raw_ids as $raw ) {
			$id = absint( $raw );
			if ( ! $id || get_post_type( $id ) !== 'documento_dealer' ) {
				$invalid++;
				continue;
			}
			if ( ! in_array( $id, $ids, true ) ) {
				$ids[] = $id;
			}
		}

		if ( empty( $ids ) ) {
			wp_send_json_error( [
				'message' => $invalid
					? 'Nessun documento valido nella selezione.'
					: 'Nessun documento selezionato.',
			] );
		}

		$summary = ( 'delete' === $bulk_action )
			? $this->bulk_delete( $ids )
			: $this->bulk_mark_obsolete( $ids );

		$summary['invalid'] = $invalid;
		$summary['op']      = $bulk_action;

		wp_send_json_success( $summary );
	}

	/**
	 * Segna obsoleti più documenti rispettando la catena di versioni.
	 *
	 * @param int[] $ids
	 * @return array
	 */
	private function bulk_mark_obsolete( array $ids ): array {
		$processed = 0;
		$already   = 0;
		$no_succ   = 0;
		$promoted  = 0;
		$reindex   = [];

		foreach ( $ids as $id ) {
			if ( get_post_meta( $id, '_doc_status', true ) === 'obsoleto' ) {
				$already++;
				continue;
			}

			if ( Dealer_Versioning::is_current( $id ) ) {
				$chain = Dealer_Versioning::get_chain( $id );

				// L'intera selezione va esclusa dai candidati: quei documenti
				// stanno per diventare obsoleti a loro volta in questo stesso
				// ciclo. Il resto della viabilità (pubblicato, non obsoleto) lo
				// applica find_best_successor().
				if ( Dealer_Versioning::demote( $id, $ids ) ) {
					$promoted++;
					foreach ( $chain as $chain_post ) {
						if ( (int) $chain_post->ID !== $id ) {
							$reindex[ (int) $chain_post->ID ] = true;
						}
					}
				} elseif ( count( $chain ) > 1 ) {
					// Catena con storico ma senza successore promuovibile: il
					// documento viene comunque marcato obsoleto, ma nessuno
					// prende il suo posto.
					$no_succ++;
				}
			}

			update_post_meta( $id, '_doc_status', 'obsoleto' );
			wp_update_post( [ 'ID' => $id, 'post_status' => 'draft' ] );
			$reindex[ $id ] = true;
			$processed++;
		}

		foreach ( array_keys( $reindex ) as $reindex_id ) {
			do_action( 'searchwp\index\post', $reindex_id );
		}

		return [
			'processed' => $processed,
			'already'   => $already,
			'no_succ'   => $no_succ,
			'promoted'  => $promoted,
			'failed'    => 0,
		];
	}

	/**
	 * Elimina definitivamente più documenti (post + file) mantenendo coerenti
	 * le catene di versioni.
	 *
	 * @param int[] $ids
	 * @return array
	 */
	private function bulk_delete( array $ids ): array {
		$processed = 0;
		$failed    = 0;
		$promoted  = 0;
		$reindex   = [];

		foreach ( $ids as $id ) {
			$chain    = Dealer_Versioning::get_chain( $id );
			$in_chain = count( $chain ) > 1;

			// Sempre PRIMA di wp_delete_post(): sgancia dalla catena e, se era
			// la versione corrente, promuove la più recente fra le rimaste.
			//
			// Il conteggio viene da quello che detach() ha davvero fatto, non
			// da una previsione: "era corrente e la catena aveva altri
			// elementi" non basta più a garantire una promozione, perché il
			// successore dev'essere anche pubblicato e non obsoleto. Dedurlo
			// significava annunciare all'amministratore N versioni tornate
			// correnti quando la catena era rimasta senza nessuna.
			$promotes = (bool) Dealer_Versioning::detach( $id );

			$attachment_id = (int) get_post_meta( $id, '_doc_file_id', true );
			if ( $attachment_id ) {
				wp_delete_attachment( $attachment_id, true );
			}

			if ( ! wp_delete_post( $id, true ) ) {
				$failed++;
				continue;
			}

			$processed++;
			if ( $promotes ) {
				$promoted++;
			}
			if ( $in_chain ) {
				foreach ( $chain as $chain_post ) {
					if ( (int) $chain_post->ID !== $id ) {
						$reindex[ (int) $chain_post->ID ] = true;
					}
				}
			}
		}

		// I documenti eliminati non vanno reindicizzati.
		foreach ( array_keys( $reindex ) as $reindex_id ) {
			if ( in_array( (int) $reindex_id, $ids, true ) ) {
				continue;
			}
			do_action( 'searchwp\index\post', (int) $reindex_id );
		}

		return [
			'processed' => $processed,
			'already'   => 0,
			'no_succ'   => 0,
			'promoted'  => $promoted,
			'failed'    => $failed,
		];
	}

	// ─── AJAX: linee prodotto per brand ──────────────────────────────────────

	public function ajax_get_lines(): void {
		check_ajax_referer( 'dealer_upload_nonce', 'nonce' );

		if ( ! current_user_can( DEALER_PORTAL_CAP_UPLOAD ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
		}

		$brand = sanitize_text_field( wp_unslash( $_POST['brand'] ?? '' ) );

		// Stesso elenco filtrato che riceve il wizard: l'endpoint non deve
		// diventare la scorciatoia per scoprire (e proporre) linee fuori
		// perimetro. Resta comunque comodità, non autorizzazione.
		$allowed = self::allowed_product_lines( wp_get_current_user() );
		$lines   = $allowed[ $brand ] ?? [];

		wp_send_json_success( [ 'lines' => array_values( $lines ) ] );
	}

	// ─── AJAX: salva documento ────────────────────────────────────────────────

	public function ajax_save_document(): void {
		check_ajax_referer( 'dealer_upload_nonce', 'nonce' );

		if ( ! current_user_can( DEALER_PORTAL_CAP_UPLOAD ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
		}

		$current_user = wp_get_current_user();

		// ─── Perimetro ───────────────────────────────────────────────────────
		// Validato PRIMA di toccare il filesystem: un rifiuto dopo l'upload
		// lascerebbe un file orfano nella cartella protetta. Le linee arrivano
		// dal client e vengono decise qui, lato server: che l'interfaccia le
		// abbia mostrate o no non conta nulla.
		$raw_lines = isset( $_POST['doc_lines'] ) ? (array) wp_unslash( $_POST['doc_lines'] ) : [];
		$lines     = array_values( array_intersect(
			array_map( 'sanitize_text_field', $raw_lines ),
			self::get_valid_lines()
		) );

		// can_publish_to_lines() respinge anche il documento SENZA linee, che
		// sarebbe visibile a tutta la rete: per un area manager è la via più
		// diretta per pubblicare fuori dal proprio perimetro.
		if ( ! Dealer_Identity::can_publish_to_lines( $current_user, $lines ) ) {
			wp_send_json_error( [
				'message' => 'Non hai i permessi per pubblicare su queste linee prodotto. '
					. 'Seleziona almeno una linea del tuo perimetro.',
			], 403 );
		}

		// Nuova versione di un documento esistente: l'operazione modifica anche
		// il predecessore (esce dalla ricerca), quindi serve il diritto su di
		// esso, non solo sulle linee del documento nuovo.
		$previous_id = absint( $_POST['doc_previous_version'] ?? 0 );

		// Non fidarsi del client: il predecessore deve essere un documento reale.
		if ( $previous_id && get_post_type( $previous_id ) !== 'documento_dealer' ) {
			$previous_id = 0;
		}
		if ( $previous_id && ! Dealer_Identity::can_edit_document( $current_user, $previous_id ) ) {
			wp_send_json_error( [
				'message' => 'Non hai i permessi per sostituire il documento indicato come versione precedente.',
			], 403 );
		}

		// Verifica file caricato.
		if ( empty( $_FILES['doc_file'] ) || (int) $_FILES['doc_file']['error'] !== UPLOAD_ERR_OK ) {
			wp_send_json_error( [ 'message' => 'Nessun file valido caricato. Verifica che il file sia stato selezionato.' ] );
		}

		// Verifica che sia un upload HTTP genuino (non un path locale arbitrario).
		if ( ! is_uploaded_file( $_FILES['doc_file']['tmp_name'] ) ) {
			wp_send_json_error( [ 'message' => 'File non valido.' ] );
		}

		// Limite dimensione server-side (50 MB).
		if ( $_FILES['doc_file']['size'] > 50 * MB_IN_BYTES ) {
			wp_send_json_error( [ 'message' => 'File troppo grande (max 50 MB).' ] );
		}

		// Whitelist MIME esplicita: solo PDF, XLSX, DOCX.
		$allowed_mimes = [
			'pdf'  => 'application/pdf',
			'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
			'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		];

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		// Valida estensione e contenuto reale prima dell'upload.
		$check = wp_check_filetype_and_ext(
			$_FILES['doc_file']['tmp_name'],
			$_FILES['doc_file']['name'],
			$allowed_mimes
		);
		if ( empty( $check['type'] ) || ! array_search( $check['type'], $allowed_mimes, true ) ) {
			wp_send_json_error( [ 'message' => 'Tipo di file non consentito. Sono accettati solo PDF, XLSX e DOCX.' ] );
		}

		$keep_original = ! empty( $_POST['keep_original_name'] );

		// Rename del file se richiesto.
		if ( ! $keep_original && ! empty( $_POST['doc_custom_name'] ) ) {
			$custom_name = sanitize_text_field( wp_unslash( $_POST['doc_custom_name'] ) );
			$ext         = strtolower( pathinfo( $_FILES['doc_file']['name'], PATHINFO_EXTENSION ) );
			// Solo caratteri sicuri nel nome.
			$clean_name = preg_replace( '/[^a-zA-Z0-9._\-]/', '_', $custom_name );
			$clean_name = trim( $clean_name, '_' );
			if ( $clean_name ) {
				$_FILES['doc_file']['name'] = $clean_name . '.' . $ext;
			}
		}

		// Nome che il dealer vedrà scaricando il file: quello "pulito", senza
		// il token aggiunto sotto. Va salvato in _doc_filename e finisce
		// nell'header Content-Disposition.
		$display_filename = sanitize_file_name( (string) $_FILES['doc_file']['name'] );

		// Token casuale nel nome su disco.
		//
		// La cartella dealer-docs/ è protetta da .htaccess (Apache) e
		// web.config (IIS), ma nginx e altri server ignorano entrambi e il
		// plugin viene consegnato senza sapere su cosa girerà. Poiché i nomi
		// derivano da brand, linea e tipo documento, sarebbero prevedibili:
		// chiunque potrebbe tentare l'URL diretto e, su un server che non
		// onora quelle regole, scaricare il file scavalcando nonce, login e
		// controllo di linea. Con un token casuale l'URL non è indovinabile
		// su NESSUN server, che è l'unica garanzia che possiamo dare qui.
		// L'estensione viene da wp_check_filetype_and_ext(), che l'ha appena
		// validata contro la whitelist confrontando anche il contenuto reale
		// del file — non dal nome fornito da chi carica. Chi carica documenti
		// non è più il solo amministratore: l'area manager è un utente
		// operativo, e l'estensione su disco è ciò che decide se un file verrà
		// mai interpretato dal server. Deve essere una delle tre ammesse, non
		// una stringa scelta da chi invia la richiesta.
		$disk_ext  = strtolower( (string) ( $check['ext'] ?? '' ) );
		if ( ! array_key_exists( $disk_ext, $allowed_mimes ) ) {
			wp_send_json_error( [ 'message' => 'Tipo di file non consentito. Sono accettati solo PDF, XLSX e DOCX.' ] );
		}

		$disk_base = pathinfo( $display_filename, PATHINFO_FILENAME );
		$_FILES['doc_file']['name'] = $disk_base . '-' . wp_generate_password( 20, false, false ) . '.' . $disk_ext;

		// Anche il nome mostrato al dealer porta l'estensione validata: senza,
		// un documento potrebbe presentarsi con un'estensione diversa da ciò
		// che è davvero.
		$display_filename = $disk_base . '.' . $disk_ext;

		// Redirige l'upload nella sottocartella protetta dealer-docs/.
		$upload_dir_filter = static function ( array $dirs ): array {
			$dirs['subdir'] = '/dealer-docs';
			$dirs['path']   = $dirs['basedir'] . '/dealer-docs';
			$dirs['url']    = $dirs['baseurl'] . '/dealer-docs';
			return $dirs;
		};
		add_filter( 'upload_dir', $upload_dir_filter );

		$uploaded = wp_handle_upload( $_FILES['doc_file'], [
			'test_form' => false,
			'mimes'     => $allowed_mimes,
		] );

		remove_filter( 'upload_dir', $upload_dir_filter );

		if ( isset( $uploaded['error'] ) ) {
			wp_send_json_error( [ 'message' => $uploaded['error'] ] );
		}

		// Sanitizza e valida tutti i campi meta.
		$brand    = sanitize_text_field( wp_unslash( $_POST['doc_brand']        ?? '' ) );
		$line     = sanitize_text_field( wp_unslash( $_POST['doc_product_line'] ?? '' ) );
		$type     = sanitize_key( $_POST['doc_type']    ?? 'altro' );
		$year     = absint( $_POST['doc_year']     ?? gmdate( 'Y' ) );
		$version  = sanitize_text_field( wp_unslash( $_POST['doc_version']      ?? '' ) );
		$keywords = sanitize_textarea_field( wp_unslash( $_POST['doc_keywords']       ?? '' ) );
		$notes    = sanitize_textarea_field( wp_unslash( $_POST['doc_internal_notes'] ?? '' ) );
		$expiry   = sanitize_text_field( wp_unslash( $_POST['doc_expiry'] ?? '' ) );

		// Valida type contro lista consentita.
		if ( ! array_key_exists( $type, self::DOC_TYPES ) ) {
			$type = 'altro';
		}

		// Valida anno in range ragionevole.
		$current_year = (int) gmdate( 'Y' );
		if ( $year < 1990 || $year > $current_year + 1 ) {
			$year = $current_year;
		}

		// Valida ruoli contro lista consentita.
		$raw_roles     = isset( $_POST['doc_roles'] ) ? (array) $_POST['doc_roles'] : [];
		$allowed_roles = [ 'dealer', 'top_dealer', 'part_center' ];
		$roles         = array_values( array_intersect( array_map( 'sanitize_key', $raw_roles ), $allowed_roles ) );

		// Le linee ($lines) sono già state validate e autorizzate in apertura
		// di questo handler, prima dell'upload del file.

		// Valida formato e valore della data di scadenza.
		if ( $expiry ) {
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $expiry ) ) {
				$expiry = '';
			} else {
				$dt = DateTime::createFromFormat( 'Y-m-d', $expiry );
				if ( ! $dt || $dt->format( 'Y-m-d' ) !== $expiry ) {
					$expiry = '';
				}
			}
		}

		// Crea il post documento_dealer.
		$post_title = $brand;
		if ( $line ) { $post_title .= ' – ' . $line; }
		if ( isset( self::DOC_TYPES[ $type ] ) ) { $post_title .= ' – ' . self::DOC_TYPES[ $type ]; }
		if ( $year ) { $post_title .= ' ' . $year; }

		$post_id = wp_insert_post( [
			'post_title'  => $post_title,
			'post_type'   => 'documento_dealer',
			'post_status' => 'publish',
			'post_author' => get_current_user_id(),
		], true );

		if ( is_wp_error( $post_id ) ) {
			// Rimuovi il file caricato se il post non viene creato.
			wp_delete_file( $uploaded['file'] );
			wp_send_json_error( [ 'message' => $post_id->get_error_message() ] );
		}

		// Inserisce il file nella Media Library collegato al post.
		$wp_filetype   = wp_check_filetype( basename( $uploaded['file'] ) );
		$attachment_id = wp_insert_attachment( [
			'guid'           => $uploaded['url'],
			'post_mime_type' => $wp_filetype['type'],
			'post_title'     => preg_replace( '/\.[^.]+$/', '', basename( $uploaded['file'] ) ),
			'post_content'   => '',
			'post_status'    => 'inherit',
		], $uploaded['file'], $post_id );

		if ( ! is_wp_error( $attachment_id ) ) {
			wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $uploaded['file'] ) );
		} else {
			$attachment_id = 0;
		}

		// Salva tutti i meta.
		$meta = [
			'_doc_brand'              => $brand,
			'_doc_product_line'       => $line,
			'_doc_type'               => $type,
			'_doc_year'               => $year,
			'_doc_version'            => $version,
			'_doc_roles'              => $roles,
			'_doc_lines'              => $lines,
			'_doc_expiry'             => $expiry,
			'_doc_keywords'           => $keywords,
			'_doc_internal_notes'     => $notes,
			'_doc_file_id'            => $attachment_id,
			'_doc_keep_original_name' => $keep_original ? 1 : 0,
			'_doc_status'             => 'active',
			// Nome pulito, senza il token casuale presente sul disco: è quello
			// che il dealer vede scaricando il documento.
			'_doc_filename'           => $display_filename ?: basename( $uploaded['file'] ),
		];
		foreach ( $meta as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}

		// ─── Versionamento ───────────────────────────────────────────────────
		// Va eseguito DOPO il salvataggio di tutti gli altri meta: l'action
		// 'dealer_portal_new_version' deve vedere un documento completo.
		// $previous_id è già stato validato (esistenza + diritto di modifica)
		// in apertura di questo handler.
		$attached = false;
		if ( $previous_id && $previous_id !== (int) $post_id ) {
			$attached = Dealer_Versioning::attach_as_new_version( (int) $post_id, $previous_id );
		}

		if ( ! $attached ) {
			// Fallback: il documento non resta mai senza meta di versione.
			$previous_id = 0;
			Dealer_Versioning::init_root( (int) $post_id );
		}

		// Trigger reindex SearchWP (no-op se SearchWP non è attivo).
		do_action( 'searchwp\index\post', $post_id );
		if ( $attached ) {
			// La versione precedente è uscita dalla ricerca: va reindicizzata.
			do_action( 'searchwp\index\post', $previous_id );
		}

		wp_send_json_success( [
			'message'     => $attached
				? 'Documento salvato come nuova versione.'
				: 'Documento salvato con successo.',
			'post_id'     => $post_id,
			'version_seq' => Dealer_Versioning::get_sequence( (int) $post_id ),
			'supersedes'  => $previous_id,
		] );
	}

	// ─── AJAX: segna obsoleto ─────────────────────────────────────────────────

	public function ajax_mark_obsolete(): void {
		check_ajax_referer( 'dealer_archive_nonce', 'nonce' );

		if ( ! current_user_can( DEALER_PORTAL_CAP_UPLOAD ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
		}

		$post_id = absint( $_POST['post_id'] ?? 0 );
		if ( ! $post_id || get_post_type( $post_id ) !== 'documento_dealer' ) {
			wp_send_json_error( [ 'message' => 'Post non valido.' ] );
		}

		// Marcare obsoleto è una modifica del documento: vale il perimetro.
		// Un documento senza restrizione di linea, o a cavallo di linee altrui,
		// resta dell'amministratore (vedi Dealer_Identity::can_edit_document()).
		if ( ! Dealer_Identity::can_edit_document( wp_get_current_user(), $post_id ) ) {
			wp_send_json_error( [
				'message' => 'Non hai i permessi per intervenire su questo documento.',
			], 403 );
		}

		// Marcare obsoleta la versione corrente di una catena con storico
		// lascerebbe l'intera catena senza nessun documento visibile in ricerca.
		// L'admin deve confermare esplicitamente: alla conferma la versione
		// precedente torna corrente e il documento resta nello storico.
		$previous_count = Dealer_Versioning::count_previous_versions( $post_id );
		$is_current     = Dealer_Versioning::is_current( $post_id );
		$breaks_chain   = ( $is_current && $previous_count > 0 );
		$confirmed      = ! empty( $_POST['confirm_chain'] );

		// Il successore si calcola prima di chiedere conferma: promettere che
		// "la versione precedente tornerà corrente" quando nessuna è
		// promuovibile — perché già obsoleta o non pubblicata — farebbe
		// approvare all'admin una conseguenza diversa da quella reale.
		$successor = $breaks_chain ? Dealer_Versioning::find_best_successor( $post_id ) : 0;

		if ( $breaks_chain && ! $confirmed ) {
			$plural = ( 1 === $previous_count ) ? 'e' : 'i';
			wp_send_json_success( [
				'needs_confirm' => true,
				'message'       => sprintf(
					'Questo documento è la versione corrente di una catena con %d version%s precedent%s. %s Procedere?',
					$previous_count,
					$plural,
					$plural,
					$successor
						? 'Se lo segni obsoleto la versione precedente tornerà corrente e visibile ai dealer; '
							. 'questo documento resterà nello storico della catena.'
						: 'Nessuna delle versioni precedenti può prenderne il posto (sono già obsolete o non pubblicate): '
							. 'la catena resterà senza alcun documento visibile ai dealer.'
				),
			] );
		}

		$promoted_id = 0;
		if ( $breaks_chain ) {
			// demote() (non detach()): promuove la precedente ma tiene il
			// documento obsoleto nella catena, così lo storico resta leggibile.
			$chain       = Dealer_Versioning::get_chain( $post_id );
			$promoted_id = Dealer_Versioning::demote( $post_id );
			if ( $promoted_id ) {
				foreach ( $chain as $chain_post ) {
					if ( (int) $chain_post->ID !== $post_id ) {
						do_action( 'searchwp\index\post', (int) $chain_post->ID );
					}
				}
			}
		}

		update_post_meta( $post_id, '_doc_status', 'obsoleto' );
		wp_update_post( [ 'ID' => $post_id, 'post_status' => 'draft' ] );

		wp_send_json_success( [
			'message' => $promoted_id
				? 'Documento segnato come obsoleto. La versione precedente è tornata corrente.'
				: 'Documento segnato come obsoleto.',
			'reload'  => (bool) $promoted_id,
		] );
	}

	// ─── AJAX: elimina documento ──────────────────────────────────────────────

	public function ajax_delete_document(): void {
		check_ajax_referer( 'dealer_archive_nonce', 'nonce' );

		// Eliminazione definitiva: cancella il file dal server ed è
		// irreversibile. Resta all'amministratore, anche per chi può caricare.
		// All'area manager basta marcare obsoleto e pubblicare una nuova
		// versione, che è reversibile e lascia traccia.
		if ( ! current_user_can( DEALER_PORTAL_CAP ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
		}

		$post_id = absint( $_POST['post_id'] ?? 0 );
		if ( ! $post_id || get_post_type( $post_id ) !== 'documento_dealer' ) {
			wp_send_json_error( [ 'message' => 'Post non valido.' ] );
		}

		// Sgancia dalla catena PRIMA di eliminare: se era la versione corrente,
		// promuove la precedente. Altrimenti la catena resterebbe senza nessun
		// documento visibile in ricerca.
		//
		// Come in bulk_delete(): l'esito arriva dal valore restituito da
		// detach(), non da una previsione. Una catena le cui altre versioni
		// sono tutte in bozza o obsolete non ha un successore promuovibile, e
		// annunciare "la versione precedente è tornata corrente" sarebbe falso.
		$chain    = Dealer_Versioning::get_chain( $post_id );
		$in_chain = count( $chain ) > 1;
		$promotes = (bool) Dealer_Versioning::detach( $post_id );

		$attachment_id = (int) get_post_meta( $post_id, '_doc_file_id', true );
		if ( $attachment_id ) {
			wp_delete_attachment( $attachment_id, true ); // true = force delete (non va nel cestino)
		}

		wp_delete_post( $post_id, true );

		// Reindicizza i superstiti: la promozione ne cambia la visibilità.
		if ( $in_chain ) {
			foreach ( $chain as $chain_post ) {
				if ( (int) $chain_post->ID !== $post_id ) {
					do_action( 'searchwp\index\post', (int) $chain_post->ID );
				}
			}
		}

		wp_send_json_success( [
			'message' => $promotes
				? 'Documento eliminato. La versione precedente è tornata corrente.'
				: 'Documento eliminato.',
			'reload'  => $in_chain,
		] );
	}

	// ─── User profile: linee prodotto + referente ────────────────────────────

	public function render_user_fields( \WP_User $user ): void {
		if ( ! current_user_can( 'edit_users' ) ) {
			return;
		}

		// ── Area manager: qui NON si configura niente ────────────────────────
		//
		// Il campo "Linee Prodotto Assegnate" qui sotto scrive _dealer_lines,
		// il meta del modello dealer, che per un area manager non viene mai
		// letto: il suo perimetro vive in _am_lines/_am_orgs. Mostrarglielo
		// comunque sarebbe la trappola peggiore possibile — il posto più ovvio
		// dove cercarlo, che accetta la modifica, la salva, non dà errori e non
		// produce alcun effetto. Al suo posto va detto qual è il perimetro
		// reale e dove si imposta.
		if ( Dealer_Identity::is_area_manager( $user ) ) {
			$this->render_area_manager_profile_panel( $user );
			return;
		}

		$dealer_lines = get_user_meta( $user->ID, '_dealer_lines', true );
		if ( ! is_array( $dealer_lines ) ) { $dealer_lines = []; }

		$ref_nome     = get_user_meta( $user->ID, '_referente_nome',     true );
		$ref_email    = get_user_meta( $user->ID, '_referente_email',    true );
		$ref_telefono = get_user_meta( $user->ID, '_referente_telefono', true );

		// Costruisce l'elenco completo "Brand|Linea" da assegnare al dealer.
		$all_lines = [];
		foreach ( self::PRODUCT_LINES as $brand => $lines ) {
			foreach ( $lines as $line ) {
				$all_lines[] = $brand . '|' . $line;
			}
		}
		?>
		<h2>Impostazioni Dealer Portal</h2>
		<?php wp_nonce_field( 'dealer_user_meta_nonce', 'dealer_user_meta_nonce_field' ); ?>
		<table class="form-table">
			<tr>
				<th><label for="dealer_lines">Linee Prodotto Assegnate</label></th>
				<td>
					<select name="dealer_lines[]" id="dealer_lines" multiple style="min-width:320px;min-height:130px;">
						<?php foreach ( $all_lines as $key ) :
							$label = str_replace( '|', ' › ', $key );
							?>
							<option value="<?php echo esc_attr( $key ); ?>"<?php echo in_array( $key, $dealer_lines, true ) ? ' selected' : ''; ?>>
								<?php echo esc_html( $label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<p class="description">Tieni premuto <kbd>Ctrl</kbd> (Windows) o <kbd>Cmd</kbd> (Mac) per selezionare più linee.</p>
				</td>
			</tr>
			<tr>
				<th><label for="referente_nome">Referente – Nome</label></th>
				<td><input type="text" id="referente_nome" name="referente_nome" value="<?php echo esc_attr( $ref_nome ); ?>" class="regular-text"></td>
			</tr>
			<tr>
				<th><label for="referente_email">Referente – Email</label></th>
				<td><input type="email" id="referente_email" name="referente_email" value="<?php echo esc_attr( $ref_email ); ?>" class="regular-text"></td>
			</tr>
			<tr>
				<th><label for="referente_telefono">Referente – Telefono</label></th>
				<td><input type="tel" id="referente_telefono" name="referente_telefono" value="<?php echo esc_attr( $ref_telefono ); ?>" class="regular-text"></td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Pannello mostrato al posto dei campi dealer quando l'utente e' un area
	 * manager: cosa gli risulta assegnato adesso, e il collegamento all'unica
	 * schermata che lo puo' cambiare.
	 *
	 * In sola lettura di proposito: il perimetro si scrive da un solo punto
	 * (Dealer_Identity::set_am_scope(), chiamato da Dealer_Org_Admin), che
	 * valida organizzazioni e linee. Un secondo modulo di modifica qui
	 * significherebbe due strade per lo stesso dato, destinate a divergere.
	 */
	private function render_area_manager_profile_panel( \WP_User $user ): void {
		$lines = Dealer_Identity::get_scope_lines( $user );
		$roots = array_values( array_filter(
			array_map( 'absint', (array) get_user_meta( $user->ID, Dealer_Identity::META_AM_ORGS, true ) ),
			[ 'Dealer_Organization', 'exists' ]
		) );

		$scope_url = class_exists( 'Dealer_Org_Admin' )
			? add_query_arg( 'user', $user->ID, Dealer_Org_Admin::am_page_url() )
			: admin_url( 'admin.php?page=dealer-portal-area-managers' );
		?>
		<?php
		// Stesso titolo della sezione mostrata ai dealer, di proposito: cambiare
		// anche l'intestazione faceva sembrare che la sezione fosse sparita,
		// invece che sostituita da quella giusta per questo ruolo.
		?>
		<h2>Impostazioni Dealer Portal</h2>
		<p class="description" style="max-width:640px;">
			Questo utente è un <strong>Area Manager</strong>: i suoi diritti non si impostano qui.
			Il campo <em>Linee Prodotto Assegnate</em>, che compare sul profilo dei dealer, appartiene
			al modello dealer e per un area manager non verrebbe mai letto — mostrarlo qui significherebbe
			lasciarti configurare qualcosa che non ha alcun effetto. Il suo perimetro è questo:
		</p>
		<table class="form-table">
			<tr>
				<th>Linee di pubblicazione</th>
				<td>
					<?php if ( empty( $lines ) ) : ?>
						<p style="margin:0 0 6px;">
							<strong style="color:#d63638;">Nessuna linea assegnata: non è operativo.</strong>
							Non può pubblicare né aggiornare documenti, e trova la propria area di lavoro vuota.
						</p>
					<?php else : ?>
						<p style="margin:0 0 6px;">
							<strong><?php echo esc_html( (string) count( $lines ) ); ?></strong> linee assegnate:
							<?php echo esc_html( implode( ', ', array_map( static function ( string $line ): string {
								return str_replace( '|', ' › ', $line );
							}, array_slice( $lines, 0, 12 ) ) ) ); ?><?php echo count( $lines ) > 12 ? ' …' : ''; ?>
						</p>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th>Organizzazioni seguite</th>
				<td>
					<p style="margin:0 0 6px;">
						<?php if ( empty( $roots ) ) : ?>
							Nessuna — facoltative: servono solo per seguire dei dealer (persone, log, statistiche).
						<?php else : ?>
							<?php echo esc_html( implode( ', ', array_map( [ 'Dealer_Organization', 'get_name' ], $roots ) ) ); ?>
						<?php endif; ?>
					</p>
				</td>
			</tr>
			<tr>
				<th></th>
				<td>
					<a class="button button-primary" href="<?php echo esc_url( $scope_url ); ?>">Assegna il perimetro</a>
					<p class="description" style="margin-top:8px;">
						Il perimetro di un area manager si imposta solo da lì. I campi
						<em>Linee Prodotto Assegnate</em> del modello dealer non compaiono in questa pagina
						proprio perché, per questo ruolo, non avrebbero alcun effetto.
					</p>
				</td>
			</tr>
		</table>
		<?php
	}

	public function save_user_fields( int $user_id ): void {
		// Verifica nonce.
		if ( ! isset( $_POST['dealer_user_meta_nonce_field'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['dealer_user_meta_nonce_field'] ) ), 'dealer_user_meta_nonce' ) ) {
			return;
		}
		// Verifica capacità.
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}

		$lines = isset( $_POST['dealer_lines'] )
			? array_values( array_intersect(
				array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['dealer_lines'] ) ),
				self::get_valid_lines()
			) )
			: [];

		update_user_meta( $user_id, '_dealer_lines',      $lines );
		update_user_meta( $user_id, '_referente_nome',     sanitize_text_field( wp_unslash( $_POST['referente_nome']     ?? '' ) ) );
		update_user_meta( $user_id, '_referente_email',    sanitize_email(      wp_unslash( $_POST['referente_email']    ?? '' ) ) );
		update_user_meta( $user_id, '_referente_telefono', sanitize_text_field( wp_unslash( $_POST['referente_telefono'] ?? '' ) ) );
	}

	// ─── Static helpers (usati dai template) ─────────────────────────────────

	public static function get_brands(): array {
		return array_keys( self::PRODUCT_LINES );
	}

	public static function get_product_lines(): array {
		return self::PRODUCT_LINES;
	}

	public static function get_doc_types(): array {
		return self::DOC_TYPES;
	}

	public static function get_valid_lines(): array {
		$valid = [];
		foreach ( self::PRODUCT_LINES as $brand => $brand_lines ) {
			foreach ( $brand_lines as $line ) {
				$valid[] = $brand . '|' . $line;
			}
		}
		return $valid;
	}

	/**
	 * Alberatura Brand => linee limitata a ciò che l'utente può pubblicare.
	 *
	 * Per l'amministratore è l'elenco completo; per un area manager solo le
	 * linee del suo perimetro, così il wizard non gli propone nemmeno scelte
	 * che il server rifiuterebbe. Chi non può pubblicare riceve un elenco
	 * vuoto.
	 *
	 * Resta una comodità dell'interfaccia: l'autorizzazione vera avviene in
	 * ajax_save_document() tramite Dealer_Identity::can_publish_to_lines().
	 *
	 * @return array<string, string[]>
	 */
	public static function allowed_product_lines( \WP_User $user ): array {
		if ( user_can( $user, 'manage_options' ) || user_can( $user, DEALER_PORTAL_CAP ) ) {
			return self::PRODUCT_LINES;
		}

		if ( ! Dealer_Identity::is_area_manager( $user ) ) {
			return [];
		}

		$scope = Dealer_Identity::get_scope_lines( $user );
		if ( empty( $scope ) ) {
			return [];
		}

		$allowed = [];
		foreach ( self::PRODUCT_LINES as $brand => $brand_lines ) {
			$kept = [];
			foreach ( $brand_lines as $line ) {
				if ( in_array( $brand . '|' . $line, $scope, true ) ) {
					$kept[] = $line;
				}
			}
			if ( $kept ) {
				$allowed[ $brand ] = $kept;
			}
		}

		return $allowed;
	}
}
