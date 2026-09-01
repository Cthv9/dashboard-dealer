<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Interfaccia amministrativa delle organizzazioni.
 *
 * Il modello (Dealer_Organization + Dealer_Identity) sposta i diritti
 * dall'utente all'azienda. Questa classe e' l'unico punto in cui un
 * amministratore puo' costruire, correggere e fondere quella struttura.
 *
 * Il criterio che guida tutta l'interfaccia: l'ereditarieta' e' un'INTERSEZIONE
 * e quindi puo' sorprendere. Una sede che "ha" una linea non la vede se la madre
 * non ce l'ha. Ogni schermata mostra percio' non solo cosa e' stato dichiarato,
 * ma cosa ne risulta davvero — e segnala le dichiarazioni che restano lettera
 * morta, invece di lasciare l'amministratore a chiedersi perche' un dealer non
 * trova un documento.
 *
 * Tutte le operazioni distruttive (fusione, eliminazione) passano da POST con
 * nonce e conferma esplicita: un link GET puo' essere innescato dal prefetch del
 * browser o da un antivirus che apre le URL di una email.
 *
 * Capability: DEALER_PORTAL_CAP_ORGS, verificata su OGNI handler e non solo sul
 * rendering della pagina.
 */
class Dealer_Org_Admin {

	/** Slug del sottomenu sotto 'dealer-portal'. */
	const MENU_SLUG = 'dealer-portal-orgs';

	/**
	 * Slug del sottomenu "Area Manager".
	 *
	 * Voce di menu propria e non una vista dentro Organizzazioni: assegnare il
	 * perimetro a un area manager e' un compito a se', il primo che si fa dopo
	 * aver creato l'utente, e chi lo cerca non ha motivo di andarlo a cercare
	 * sotto "Organizzazioni" — tanto piu' che le organizzazioni, per un area
	 * manager, sono la meta' facoltativa del perimetro.
	 */
	const AM_MENU_SLUG = 'dealer-portal-area-managers';

	/** Radici mostrate per pagina nell'albero (i sottoalberi seguono la radice). */
	const ROOTS_PER_PAGE = 20;

	/** Tetto di organizzazioni caricate in una sola schermata. */
	const MAX_ORGS = 500;

	/** Tetto di organizzazioni fondibili in una sola operazione. */
	const MAX_MERGE_SOURCES = 20;

	/** Tetto di utenti spostabili da una singola fusione. */
	const MAX_MERGE_USERS = 300;

	/** Utenti mostrati nella tendina "aggiungi utente". */
	const MAX_CANDIDATES = 100;

	/** Utenti per pagina nella scheda utenti di un'organizzazione. */
	const MEMBERS_PER_PAGE = 25;

	/** Prefisso del transient che trasporta il rapporto di migrazione. */
	const REPORT_TRANSIENT = 'dealer_org_migration_report_';

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'register_menu' ], 20 );

		// Ogni azione ha il suo handler admin_post: mai un GET distruttivo.
		add_action( 'admin_post_dealer_org_save',        [ $this, 'handle_save' ] );
		add_action( 'admin_post_dealer_org_status',      [ $this, 'handle_status' ] );
		add_action( 'admin_post_dealer_org_delete',      [ $this, 'handle_delete' ] );
		add_action( 'admin_post_dealer_org_migrate',     [ $this, 'handle_migrate' ] );
		add_action( 'admin_post_dealer_org_merge',       [ $this, 'handle_merge' ] );
		add_action( 'admin_post_dealer_org_user_assign', [ $this, 'handle_user_assign' ] );
		add_action( 'admin_post_dealer_org_user_update', [ $this, 'handle_user_update' ] );
		add_action( 'admin_post_dealer_org_user_remove', [ $this, 'handle_user_remove' ] );
		add_action( 'admin_post_dealer_org_am_scope',    [ $this, 'handle_am_scope' ] );
	}

	// ─── Menu ─────────────────────────────────────────────────────────────────

	public function register_menu(): void {
		add_submenu_page(
			'dealer-portal',
			'Organizzazioni',
			'Organizzazioni',
			DEALER_PORTAL_CAP_ORGS,
			self::MENU_SLUG,
			[ $this, 'render_page' ]
		);

		add_submenu_page(
			'dealer-portal',
			'Area Manager',
			'Area Manager',
			DEALER_PORTAL_CAP_ORGS,
			self::AM_MENU_SLUG,
			[ $this, 'render_am_page' ]
		);
	}

	/** URL della schermata Area Manager. */
	public static function am_page_url(): string {
		return admin_url( 'admin.php?page=' . self::AM_MENU_SLUG );
	}

	/**
	 * Router della schermata Area Manager: elenco, oppure assegnazione del
	 * perimetro quando l'URL indica un utente.
	 */
	public function render_am_page(): void {
		$this->require_cap();

		if ( absint( $_GET['user'] ?? 0 ) ) {
			$this->render_area_manager_edit();
			return;
		}

		$this->render_area_managers();
	}

	// ─── Router delle viste ───────────────────────────────────────────────────

	public function render_page(): void {
		$this->require_cap();

		$view = sanitize_key( (string) ( $_GET['view'] ?? '' ) );

		switch ( $view ) {
			case 'edit':
				$this->render_edit();
				break;
			case 'users':
				$this->render_users();
				break;
			case 'merge':
				$this->render_merge();
				break;
			default:
				$this->render_list();
		}
	}

	// ─── Vista: elenco e albero ───────────────────────────────────────────────

	private function render_list(): void {
		$orgs  = Dealer_Organization::get_all( self::MAX_ORGS );
		$tree  = self::build_tree( $orgs );
		$roots = $tree['roots'];

		$paged       = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$total_roots = count( $roots );
		$total_pages = (int) max( 1, ceil( $total_roots / self::ROOTS_PER_PAGE ) );
		$paged       = min( $paged, $total_pages );
		$page_roots  = array_slice( $roots, ( $paged - 1 ) * self::ROOTS_PER_PAGE, self::ROOTS_PER_PAGE );

		// Albero appiattito con la profondita': l'indentazione rende leggibile
		// la gerarchia senza bisogno di JS.
		$flat = [];
		$seen = [];
		self::flatten( $tree['children'], $page_roots, 0, $flat, $seen );

		$user_counts = self::get_user_counts_by_org();

		// Riga pronta per il template: linee proprie, ereditate, effettive,
		// linee dichiarate ma senza effetto, stato reale.
		$rows = [];
		foreach ( $flat as $node ) {
			$org_id = $node['id'];
			$rows[] = [
				'id'        => $org_id,
				'depth'     => $node['depth'],
				'name'      => Dealer_Organization::get_name( $org_id ),
				'tier'      => Dealer_Organization::get_tier( $org_id ),
				'own_tier'  => (string) get_post_meta( $org_id, Dealer_Organization::META_TIER, true ),
				'users'     => (int) ( $user_counts[ $org_id ] ?? 0 ),
				'lines'     => self::line_breakdown( $org_id ),
				'status'    => self::status_info( $org_id ),
				'impact'    => self::impact_from_map( $tree['children'], $org_id, $user_counts ),
				'children'  => count( $tree['children'][ $org_id ] ?? [] ),
			];
		}

		$totals = [
			'orgs'       => count( $orgs ),
			'truncated'  => count( $orgs ) >= self::MAX_ORGS,
			'suspended'  => 0,
			'users'      => 0,
			'unmigrated' => Dealer_Organization::count_unmigrated_users(),
		];
		foreach ( $orgs as $org ) {
			if ( Dealer_Organization::STATUS_SUSPENDED === get_post_meta( $org->ID, Dealer_Organization::META_STATUS, true ) ) {
				$totals['suspended']++;
			}
			// Solo organizzazioni esistenti: un meta rimasto appeso a un'org
			// cancellata non deve gonfiare il totale.
			$totals['users'] += (int) ( $user_counts[ (int) $org->ID ] ?? 0 );
		}

		$notice      = self::notice_from_query();
		$report      = self::pull_migration_report();
		$base_url    = self::page_url();
		$post_url    = admin_url( 'admin-post.php' );
		$tier_labels = self::tier_labels();

		require DEALER_PORTAL_PATH . 'templates/admin-organizations.php';
	}

	// ─── Vista: creazione / modifica ──────────────────────────────────────────

	private function render_edit(): void {
		$org_id = absint( $_GET['org'] ?? 0 );
		if ( $org_id && ! Dealer_Organization::exists( $org_id ) ) {
			$org_id = 0;
		}

		$is_new = ( 0 === $org_id );

		$org = [
			'id'        => $org_id,
			'name'      => $is_new ? '' : Dealer_Organization::get_name( $org_id ),
			'vat'       => $is_new ? '' : (string) get_post_meta( $org_id, Dealer_Organization::META_VAT, true ),
			'tier'      => $is_new ? 'dealer' : ( (string) get_post_meta( $org_id, Dealer_Organization::META_TIER, true ) ?: 'dealer' ),
			'parent'    => $is_new ? 0 : Dealer_Organization::get_parent_id( $org_id ),
			'status'    => $is_new ? Dealer_Organization::STATUS_ACTIVE : ( Dealer_Organization::STATUS_SUSPENDED === get_post_meta( $org_id, Dealer_Organization::META_STATUS, true ) ? Dealer_Organization::STATUS_SUSPENDED : Dealer_Organization::STATUS_ACTIVE ),
			'ref_nome'  => $is_new ? '' : (string) get_post_meta( $org_id, Dealer_Organization::META_REFERENTE_NOME, true ),
			'ref_email' => $is_new ? '' : (string) get_post_meta( $org_id, Dealer_Organization::META_REFERENTE_EMAIL, true ),
			'ref_tel'   => $is_new ? '' : (string) get_post_meta( $org_id, Dealer_Organization::META_REFERENTE_TEL, true ),
			'own_lines' => $is_new ? [] : Dealer_Organization::get_own_lines( $org_id ),
		];

		// Candidate madri: tutte tranne se stessa e il proprio sottoalbero,
		// che genererebbero il ciclo rifiutato da set_parent().
		$excluded  = $is_new ? [] : Dealer_Organization::get_subtree( $org_id );
		$all_orgs  = Dealer_Organization::get_all( self::MAX_ORGS );
		$parents   = [];
		foreach ( $all_orgs as $candidate ) {
			if ( in_array( (int) $candidate->ID, $excluded, true ) ) {
				continue;
			}
			$parents[] = [
				'id'   => (int) $candidate->ID,
				'name' => (string) $candidate->post_title,
				'path' => self::org_path( (int) $candidate->ID ),
			];
		}

		$breakdown   = $is_new ? null : self::line_breakdown( $org_id );
		$children    = $is_new ? [] : Dealer_Organization::get_children( $org_id );
		$user_counts = self::get_user_counts_by_org();
		$impact      = $is_new ? [ 'orgs' => 0, 'users' => 0 ] : self::suspend_impact( $org_id, $user_counts );
		$delete_info = $is_new ? null : self::delete_preview( $org_id, $user_counts );

		// Mappa linee effettive delle candidate madri: serve al calcolo live
		// dell'effetto risultante. Le linee viaggiano come indici per non
		// spedire 130 stringhe per ogni organizzazione.
		$valid_lines = Dealer_Admin::get_valid_lines();
		$line_index  = array_flip( $valid_lines );
		$parent_map  = [];
		foreach ( $parents as $candidate ) {
			$eff = Dealer_Organization::get_effective_lines( $candidate['id'] );
			$idx = [];
			foreach ( $eff as $line ) {
				if ( isset( $line_index[ $line ] ) ) {
					$idx[] = (int) $line_index[ $line ];
				}
			}
			$parent_map[ $candidate['id'] ] = $idx;
		}

		$lines_by_brand = Dealer_Admin::get_product_lines();
		$tier_labels    = self::tier_labels();
		$notice         = self::notice_from_query();
		$base_url       = self::page_url();
		$post_url       = admin_url( 'admin-post.php' );

		require DEALER_PORTAL_PATH . 'templates/admin-org-edit.php';
	}

	// ─── Vista: utenti dell'organizzazione ────────────────────────────────────

	private function render_users(): void {
		$org_id = absint( $_GET['org'] ?? 0 );
		if ( ! Dealer_Organization::exists( $org_id ) ) {
			// Stesso motivo spiegato in render_error(): qui la pagina è già
			// cominciata, un redirect non partirebbe e l'exit lascerebbe a
			// schermo mezza schermata di amministrazione.
			$this->render_error(
				'Organizzazione non trovata.',
				self::page_url(),
				'Torna all\'elenco delle organizzazioni'
			);
			return;
		}

		$org_name  = Dealer_Organization::get_name( $org_id );
		$org_lines = Dealer_Organization::get_effective_lines( $org_id );
		$breakdown = self::line_breakdown( $org_id );
		$status    = self::status_info( $org_id );

		$all_members = Dealer_Organization::get_users( $org_id );
		usort( $all_members, static function ( \WP_User $a, \WP_User $b ): int {
			return strcasecmp( (string) $a->display_name, (string) $b->display_name );
		} );

		// Ogni riga porta con se' un selettore di linee: oltre qualche decina di
		// utenti la pagina diventerebbe ingestibile, quindi si impagina.
		$total_members = count( $all_members );
		$total_pages   = (int) max( 1, ceil( $total_members / self::MEMBERS_PER_PAGE ) );
		$paged         = min( max( 1, absint( $_GET['mpaged'] ?? 1 ) ), $total_pages );
		$page_members  = array_slice( $all_members, ( $paged - 1 ) * self::MEMBERS_PER_PAGE, self::MEMBERS_PER_PAGE );

		$members = [];
		foreach ( $page_members as $user ) {
			$members[] = self::describe_member( $user, $org_lines );
		}

		// Candidati: utenti dealer senza organizzazione. La ricerca evita di
		// stampare una tendina con migliaia di voci.
		$search     = sanitize_text_field( wp_unslash( (string) ( $_GET['us'] ?? '' ) ) );
		$query_args = [
			'role__in' => Dealer_Identity::DEALER_ROLES,
			'number'   => self::MAX_CANDIDATES,
			'orderby'  => 'display_name',
			'order'    => 'ASC',
		];
		if ( '' !== $search ) {
			$query_args['search']         = '*' . $search . '*';
			$query_args['search_columns'] = [ 'user_login', 'user_email', 'display_name' ];
		}
		$candidates = [];
		foreach ( get_users( $query_args ) as $user ) {
			if ( Dealer_Identity::has_org( $user ) ) {
				continue;
			}
			$candidates[] = [
				'id'    => (int) $user->ID,
				'name'  => (string) $user->display_name,
				'login' => (string) $user->user_login,
				'email' => (string) $user->user_email,
			];
		}

		$lines_by_brand = Dealer_Admin::get_product_lines();
		$function_labels = self::function_labels();
		$notice         = self::notice_from_query();
		$base_url       = self::page_url();
		$post_url       = admin_url( 'admin-post.php' );

		require DEALER_PORTAL_PATH . 'templates/admin-org-users.php';
	}

	// ─── Vista: perimetro degli area manager ──────────────────────────────────
	//
	// Un area manager non appartiene a un'organizzazione (segue delle
	// organizzazioni): il suo perimetro (_am_orgs, _am_lines) e' un asse
	// separato da quello dei dealer e non ha mai avuto, fino ad ora, un punto
	// dove assegnarlo — solo dove leggerlo (Dealer_Identity) e dove
	// applicarlo (Dealer_Area_Manager). Un area manager creato dall'admin si
	// trovava quindi bloccato per sempre su "perimetro non configurato",
	// perche' nessuna schermata scriveva mai quel meta.

	/** Elenco degli area manager con un riepilogo del loro perimetro. */
	private function render_area_managers(): void {
		$users = get_users( [
			'role'    => Dealer_Identity::ROLE_AREA_MANAGER,
			'number'  => self::MAX_ORGS,
			'orderby' => 'display_name',
			'order'   => 'ASC',
		] );

		$rows = [];
		foreach ( $users as $user ) {
			$root_ids = array_values( array_filter(
				array_map( 'absint', (array) get_user_meta( $user->ID, Dealer_Identity::META_AM_ORGS, true ) ),
				[ 'Dealer_Organization', 'exists' ]
			) );
			$root_names = array_map( [ 'Dealer_Organization', 'get_name' ], $root_ids );
			$line_count = count( Dealer_Identity::get_scope_lines( $user ) );

			$rows[] = [
				'id'         => (int) $user->ID,
				'name'       => (string) $user->display_name,
				'email'      => (string) $user->user_email,
				'root_names' => $root_names,
				'root_count' => count( $root_ids ),
				'line_count' => $line_count,
				// "Operativo" dipende SOLO dalle linee: can_publish_to_lines()
				// rifiuta un perimetro di linee vuoto, quindi senza linee un
				// area manager non puo' pubblicare nulla, per quante
				// organizzazioni gli si assegnino. Le organizzazioni sono l'asse
				// della supervisione (persone, log, statistiche): utili, ma non
				// necessarie per lavorare.
				'operational' => ( $line_count > 0 ),
			];
		}

		$blocked  = count( array_filter( $rows, static function ( array $row ): bool {
			return ! $row['operational'];
		} ) );
		$notice   = self::notice_from_query();
		$base_url = self::am_page_url();
		$new_user_url = admin_url( 'user-new.php' );

		require DEALER_PORTAL_PATH . 'templates/admin-org-area-managers.php';
	}

	/** Assegnazione del perimetro (organizzazioni radice + linee) a un area manager. */
	private function render_area_manager_edit(): void {
		$user_id = absint( $_GET['user'] ?? 0 );
		$user    = $user_id ? get_user_by( 'id', $user_id ) : null;

		if ( ! $user || ! Dealer_Identity::is_area_manager( $user ) ) {
			$this->render_error(
				'Utente non trovato o non ha il ruolo Area Manager.',
				self::am_page_url(),
				'Torna all\'elenco degli area manager'
			);
			return;
		}

		// Si propongono le radici: seguirne una include gia' tutto il suo
		// sottoalbero (Dealer_Identity::get_scope_orgs()), quindi elencare
		// anche le figlie confonderebbe senza aggiungere nulla.
		$roots = [];
		$shown = [];
		foreach ( Dealer_Organization::get_all( self::MAX_ORGS ) as $org ) {
			$org_id = (int) $org->ID;
			if ( Dealer_Organization::get_parent_id( $org_id ) ) {
				continue;
			}
			$roots[] = [
				'id'       => $org_id,
				'name'     => (string) $org->post_title,
				'children' => count( Dealer_Organization::get_subtree( $org_id ) ) - 1,
				'orphan'   => false,
				'path'     => '',
			];
			$shown[ $org_id ] = true;
		}

		$selected_orgs = array_values( array_filter(
			array_map( 'absint', (array) get_user_meta( $user_id, Dealer_Identity::META_AM_ORGS, true ) ),
			[ 'Dealer_Organization', 'exists' ]
		) );

		// Un'organizzazione gia' assegnata che fra le radici non compare — nel
		// frattempo e' diventata figlia di un'altra, oppure sta oltre il tetto
		// di MAX_ORGS — deve avere comunque la sua casella. Il form riscrive
		// _am_orgs per intero: senza casella non verrebbe rispedita e sparirebbe
		// dal perimetro al primo salvataggio, in silenzio e con un messaggio di
		// conferma. Mostrarla la rende visibile, conservabile e rimovibile
		// deliberatamente.
		foreach ( $selected_orgs as $selected_id ) {
			if ( isset( $shown[ $selected_id ] ) ) {
				continue;
			}
			$roots[] = [
				'id'       => $selected_id,
				'name'     => Dealer_Organization::get_name( $selected_id ),
				'children' => max( 0, count( Dealer_Organization::get_subtree( $selected_id ) ) - 1 ),
				'orphan'   => true,
				'path'     => self::org_path( $selected_id ),
			];
			$shown[ $selected_id ] = true;
		}
		$selected_lines = array_map( 'sanitize_text_field', (array) get_user_meta( $user_id, Dealer_Identity::META_AM_LINES, true ) );

		$lines_by_brand = Dealer_Admin::get_product_lines();
		$notice         = self::notice_from_query();
		$base_url       = self::am_page_url();
		$orgs_url       = self::page_url();
		$post_url       = admin_url( 'admin-post.php' );

		require DEALER_PORTAL_PATH . 'templates/admin-org-area-manager-edit.php';
	}

	public function handle_am_scope(): void {
		check_admin_referer( 'dealer_org_am_scope' );
		$this->require_cap();

		$user_id = absint( $_POST['user_id'] ?? 0 );
		$user    = $user_id ? get_user_by( 'id', $user_id ) : null;

		// Gli handler admin_post girano prima di qualunque output: qui il
		// redirect funziona (a differenza dei render, vedi render_error()).
		if ( ! $user || ! Dealer_Identity::is_area_manager( $user ) ) {
			$this->redirect_am( 'err_am_user' );
		}

		$org_ids = self::posted_ids( 'am_orgs' );
		$lines   = self::posted_lines( 'am_lines' );

		Dealer_Identity::set_am_scope( $user_id, $org_ids, $lines );

		// Senza linee l'area manager resta inoperativo: lo si dice subito,
		// invece di lasciare che se ne accorga lui trovando l'area vuota.
		$this->redirect_am( empty( $lines ) ? 'am_scope_no_lines' : 'am_scope_saved', [ 'user' => $user_id ] );
	}

	/** Come redirect(), ma verso la schermata Area Manager. */
	private function redirect_am( string $code, array $args = [] ): void {
		$args['page']    = self::AM_MENU_SLUG;
		$args['org_msg'] = $code;

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	// ─── Vista: fusione ───────────────────────────────────────────────────────

	private function render_merge(): void {
		$orgs        = Dealer_Organization::get_all( self::MAX_ORGS );
		$user_counts = self::get_user_counts_by_org();

		$choices = [];
		foreach ( $orgs as $org ) {
			$id        = (int) $org->ID;
			$choices[] = [
				'id'    => $id,
				'name'  => (string) $org->post_title,
				'path'  => self::org_path( $id ),
				'users' => (int) ( $user_counts[ $id ] ?? 0 ),
				'lines' => count( Dealer_Organization::get_effective_lines( $id ) ),
			];
		}

		// Anteprima: e' una lettura, ma arriva comunque in POST con nonce
		// perche' la stessa schermata prosegue con l'esecuzione.
		$preview = null;
		if ( isset( $_POST['dealer_org_merge_preview'] ) ) {
			check_admin_referer( 'dealer_org_merge_preview' );
			$this->require_cap();

			$sources = self::posted_ids( 'sources' );
			$dest    = absint( $_POST['destination'] ?? 0 );
			$preview = self::merge_preview( $sources, $dest );
		}

		$notice   = self::notice_from_query();
		$base_url = self::page_url();
		$post_url = admin_url( 'admin-post.php' );

		require DEALER_PORTAL_PATH . 'templates/admin-org-merge.php';
	}

	// ─── Handler: salvataggio organizzazione ──────────────────────────────────

	public function handle_save(): void {
		check_admin_referer( 'dealer_org_save' );
		$this->require_cap();

		$org_id = absint( $_POST['org_id'] ?? 0 );
		$name   = sanitize_text_field( wp_unslash( (string) ( $_POST['org_name'] ?? '' ) ) );

		if ( '' === trim( $name ) ) {
			$this->redirect( 'err_name', $org_id ? [ 'view' => 'edit', 'org' => $org_id ] : [ 'view' => 'edit' ] );
		}

		// Whitelist: livello contro TIERS, linee contro get_valid_lines().
		$tier = sanitize_key( (string) ( $_POST['org_tier'] ?? '' ) );
		if ( ! in_array( $tier, Dealer_Organization::TIERS, true ) ) {
			$this->redirect( 'err_tier', $org_id ? [ 'view' => 'edit', 'org' => $org_id ] : [ 'view' => 'edit' ] );
		}

		$lines  = self::posted_lines( 'org_lines' );
		$parent = absint( $_POST['org_parent'] ?? 0 );
		$status = ( Dealer_Organization::STATUS_SUSPENDED === ( $_POST['org_status'] ?? '' ) )
			? Dealer_Organization::STATUS_SUSPENDED
			: Dealer_Organization::STATUS_ACTIVE;

		if ( $parent && ! Dealer_Organization::exists( $parent ) ) {
			$this->redirect( 'err_parent', [ 'view' => 'edit', 'org' => $org_id ] );
		}

		if ( $org_id ) {
			if ( ! Dealer_Organization::exists( $org_id ) ) {
				$this->redirect( 'err_org' );
			}

			// set_parent() rifiuta i cicli: l'errore va mostrato, non ingoiato.
			// La verifica precede ogni scrittura, cosi' un tentativo rifiutato
			// non lascia dietro di se' un salvataggio a meta'.
			$current_parent = Dealer_Organization::get_parent_id( $org_id );
			if ( $parent !== $current_parent && ! Dealer_Organization::set_parent( $org_id, $parent ) ) {
				$this->redirect( 'err_cycle', [ 'view' => 'edit', 'org' => $org_id ] );
			}

			wp_update_post( [ 'ID' => $org_id, 'post_title' => $name ] );
		} else {
			$org_id = Dealer_Organization::create( $name, [ 'tier' => $tier, 'parent' => $parent ] );
			if ( ! $org_id ) {
				$this->redirect( 'err_create', [ 'view' => 'edit' ] );
			}
		}

		Dealer_Organization::set_tier( $org_id, $tier );
		Dealer_Organization::set_lines( $org_id, $lines );
		Dealer_Organization::set_status( $org_id, $status );

		update_post_meta( $org_id, Dealer_Organization::META_VAT, sanitize_text_field( wp_unslash( (string) ( $_POST['org_vat'] ?? '' ) ) ) );
		update_post_meta( $org_id, Dealer_Organization::META_REFERENTE_NOME, sanitize_text_field( wp_unslash( (string) ( $_POST['org_ref_nome'] ?? '' ) ) ) );
		update_post_meta( $org_id, Dealer_Organization::META_REFERENTE_EMAIL, sanitize_email( wp_unslash( (string) ( $_POST['org_ref_email'] ?? '' ) ) ) );
		update_post_meta( $org_id, Dealer_Organization::META_REFERENTE_TEL, sanitize_text_field( wp_unslash( (string) ( $_POST['org_ref_tel'] ?? '' ) ) ) );

		// Se le linee dichiarate non sopravvivono all'intersezione con la madre
		// l'amministratore deve saperlo subito: e' la sorpresa piu' frequente.
		$breakdown = self::line_breakdown( $org_id );
		$code      = ! empty( $breakdown['dead'] ) ? 'saved_dead_lines' : 'saved';

		$this->redirect( $code, [ 'view' => 'edit', 'org' => $org_id ] );
	}

	// ─── Handler: sospensione / riattivazione ─────────────────────────────────

	public function handle_status(): void {
		check_admin_referer( 'dealer_org_status' );
		$this->require_cap();

		$org_id = absint( $_POST['org_id'] ?? 0 );
		if ( ! Dealer_Organization::exists( $org_id ) ) {
			$this->redirect( 'err_org' );
		}

		$status = ( Dealer_Organization::STATUS_SUSPENDED === ( $_POST['status'] ?? '' ) )
			? Dealer_Organization::STATUS_SUSPENDED
			: Dealer_Organization::STATUS_ACTIVE;

		Dealer_Organization::set_status( $org_id, $status );

		$this->redirect( Dealer_Organization::STATUS_SUSPENDED === $status ? 'suspended' : 'reactivated' );
	}

	// ─── Handler: eliminazione ────────────────────────────────────────────────

	public function handle_delete(): void {
		check_admin_referer( 'dealer_org_delete' );
		$this->require_cap();

		$org_id = absint( $_POST['org_id'] ?? 0 );
		if ( ! Dealer_Organization::exists( $org_id ) ) {
			$this->redirect( 'err_org' );
		}

		if ( empty( $_POST['confirm'] ) ) {
			$this->redirect( 'err_confirm', [ 'view' => 'edit', 'org' => $org_id ] );
		}

		// Con utenti dentro non si elimina: prima vanno spostati o rimossi.
		// Eliminare qui lascerebbe utenti che puntano a un'organizzazione
		// inesistente, cioe' senza diritti e senza spiegazione.
		if ( ! empty( Dealer_Organization::get_users( $org_id ) ) ) {
			$this->redirect( 'err_delete_users', [ 'view' => 'edit', 'org' => $org_id ] );
		}

		// Figlie: vanno riagganciate esplicitamente, mai lasciate orfane.
		$children = Dealer_Organization::get_children( $org_id );
		if ( $children ) {
			$mode = sanitize_key( (string) ( $_POST['children_action'] ?? '' ) );
			if ( ! in_array( $mode, [ 'reparent', 'promote' ], true ) ) {
				$this->redirect( 'err_delete_children', [ 'view' => 'edit', 'org' => $org_id ] );
			}

			$new_parent = ( 'reparent' === $mode ) ? Dealer_Organization::get_parent_id( $org_id ) : 0;
			foreach ( $children as $child ) {
				Dealer_Organization::set_parent( $child, $new_parent );
			}
		}

		wp_delete_post( $org_id, true );

		$this->redirect( 'deleted' );
	}

	// ─── Handler: migrazione ──────────────────────────────────────────────────

	public function handle_migrate(): void {
		check_admin_referer( 'dealer_org_migrate' );
		$this->require_cap();

		$report = Dealer_Organization::migrate_legacy_users();

		// Il rapporto puo' essere lungo: viaggia in un transient per utente,
		// non in query string.
		set_transient( self::REPORT_TRANSIENT . get_current_user_id(), $report, 10 * MINUTE_IN_SECONDS );

		$this->redirect( 'migrated' );
	}

	// ─── Handler: fusione ─────────────────────────────────────────────────────

	public function handle_merge(): void {
		check_admin_referer( 'dealer_org_merge' );
		$this->require_cap();

		if ( empty( $_POST['confirm'] ) ) {
			$this->redirect( 'err_confirm', [ 'view' => 'merge' ] );
		}

		$sources = self::posted_ids( 'sources' );
		$dest    = absint( $_POST['destination'] ?? 0 );
		$preview = self::merge_preview( $sources, $dest );

		if ( ! empty( $preview['fatal'] ) ) {
			$this->redirect( 'err_merge', [ 'view' => 'merge' ] );
		}

		$moved   = 0;
		$deleted = 0;

		foreach ( $preview['sources'] as $source ) {
			if ( ! empty( $source['blocked'] ) ) {
				continue; // Gia' segnalata nell'anteprima.
			}

			$source_id = (int) $source['id'];

			// Gli utenti passano alla destinazione conservando la funzione. La
			// restrizione individuale NON viene toccata: e' sempre intersecata
			// con le linee dell'organizzazione, quindi non puo' ampliare nulla
			// e resta valida se un giorno la destinazione riottiene la linea.
			foreach ( Dealer_Organization::get_users( $source_id ) as $user ) {
				Dealer_Identity::set_org( (int) $user->ID, $dest, Dealer_Identity::get_function( $user ) );
				$moved++;
			}

			// Le figlie della sorgente si riagganciano alla destinazione: senza
			// questo passaggio l'eliminazione le lascerebbe orfane.
			foreach ( Dealer_Organization::get_children( $source_id ) as $child ) {
				Dealer_Organization::set_parent( $child, $dest );
			}

			// Si elimina solo cio' che e' rimasto davvero vuoto.
			if ( empty( Dealer_Organization::get_users( $source_id ) ) && empty( Dealer_Organization::get_children( $source_id ) ) ) {
				wp_delete_post( $source_id, true );
				$deleted++;
			}
		}

		$this->redirect( 'merged', [ 'view' => 'edit', 'org' => $dest, 'moved' => $moved, 'gone' => $deleted ] );
	}

	// ─── Handler: assegnazione utente ─────────────────────────────────────────

	public function handle_user_assign(): void {
		check_admin_referer( 'dealer_org_user_assign' );
		$this->require_cap();

		$org_id  = absint( $_POST['org_id'] ?? 0 );
		$user_id = absint( $_POST['user_id'] ?? 0 );

		if ( ! Dealer_Organization::exists( $org_id ) ) {
			$this->redirect( 'err_org' );
		}

		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			$this->redirect( 'err_user', [ 'view' => 'users', 'org' => $org_id ] );
		}

		$function = sanitize_key( (string) ( $_POST['function'] ?? '' ) );
		if ( ! in_array( $function, Dealer_Identity::FUNCTIONS, true ) ) {
			$this->redirect( 'err_function', [ 'view' => 'users', 'org' => $org_id ] );
		}

		if ( ! Dealer_Identity::set_org( $user_id, $org_id, $function ) ) {
			$this->redirect( 'err_user', [ 'view' => 'users', 'org' => $org_id ] );
		}

		// Cambiando azienda la vecchia restrizione individuale non ha piu'
		// senso: si riparte da "nessuna restrizione", cioe' tutte le linee
		// dell'organizzazione.
		delete_user_meta( $user_id, Dealer_Identity::META_LINE_LIMIT );

		$this->redirect( 'user_assigned', [ 'view' => 'users', 'org' => $org_id ] );
	}

	public function handle_user_update(): void {
		check_admin_referer( 'dealer_org_user_update' );
		$this->require_cap();

		$org_id  = absint( $_POST['org_id'] ?? 0 );
		$user_id = absint( $_POST['user_id'] ?? 0 );

		if ( ! Dealer_Organization::exists( $org_id ) ) {
			$this->redirect( 'err_org' );
		}

		$user = get_user_by( 'id', $user_id );
		if ( ! $user || Dealer_Identity::get_org_id( $user ) !== $org_id ) {
			$this->redirect( 'err_user', [ 'view' => 'users', 'org' => $org_id ] );
		}

		$function = sanitize_key( (string) ( $_POST['function'] ?? '' ) );
		if ( ! in_array( $function, Dealer_Identity::FUNCTIONS, true ) ) {
			$this->redirect( 'err_function', [ 'view' => 'users', 'org' => $org_id ] );
		}
		Dealer_Identity::set_org( $user_id, $org_id, $function );

		$mode = sanitize_key( (string) ( $_POST['limit_mode'] ?? 'none' ) );
		if ( 'subset' === $mode ) {
			$lines = self::posted_lines( 'limit_lines' );

			// Un sottoinsieme vuoto verrebbe letto come "nessuna restrizione":
			// l'ambiguita' va risolta chiedendo, non indovinando.
			if ( empty( $lines ) ) {
				$this->redirect( 'err_limit_empty', [ 'view' => 'users', 'org' => $org_id ] );
			}

			// set_line_limit() filtra gia' contro le linee dell'organizzazione.
			Dealer_Identity::set_line_limit( $user_id, $lines );
		} else {
			delete_user_meta( $user_id, Dealer_Identity::META_LINE_LIMIT );
		}

		$this->redirect( 'user_updated', [ 'view' => 'users', 'org' => $org_id ] );
	}

	public function handle_user_remove(): void {
		check_admin_referer( 'dealer_org_user_remove' );
		$this->require_cap();

		$org_id  = absint( $_POST['org_id'] ?? 0 );
		$user_id = absint( $_POST['user_id'] ?? 0 );

		if ( ! Dealer_Organization::exists( $org_id ) ) {
			$this->redirect( 'err_org' );
		}

		$user = get_user_by( 'id', $user_id );
		if ( ! $user || Dealer_Identity::get_org_id( $user ) !== $org_id ) {
			$this->redirect( 'err_user', [ 'view' => 'users', 'org' => $org_id ] );
		}

		self::remove_user_from_org( $user_id );

		$this->redirect( 'user_removed', [ 'view' => 'users', 'org' => $org_id ] );
	}

	/**
	 * Stacca un utente dall'organizzazione.
	 *
	 * Helper assente dal modello (Dealer_Identity ha solo set_org): sta qui per
	 * non toccare file di altri. Rimuove anche la restrizione individuale, che
	 * senza organizzazione non ha alcun significato; i meta storici restano e
	 * l'utente ricade sul vecchio modello, esattamente come un non migrato.
	 */
	public static function remove_user_from_org( int $user_id ): void {
		delete_user_meta( $user_id, Dealer_Identity::META_ORG );
		delete_user_meta( $user_id, Dealer_Identity::META_FUNCTION );
		delete_user_meta( $user_id, Dealer_Identity::META_LINE_LIMIT );
	}

	// ─── Calcolo: linee proprie / ereditate / effettive ───────────────────────

	/**
	 * Scompone i diritti di un'organizzazione nelle tre grandezze che servono a
	 * capire cosa vede davvero:
	 *
	 *   own       — quanto e' stato dichiarato su di lei
	 *   inherited — le linee effettive della madre (il tetto invalicabile)
	 *   effective — il risultato della regola di ereditarieta'
	 *   dead      — linee dichiarate che l'intersezione annulla
	 *
	 * @return array{own:string[],inherited:string[],effective:string[],dead:string[],mode:string,parent:int}
	 */
	public static function line_breakdown( int $org_id ): array {
		$own       = Dealer_Organization::get_own_lines( $org_id );
		$parent    = Dealer_Organization::get_parent_id( $org_id );
		$inherited = $parent ? Dealer_Organization::get_effective_lines( $parent ) : [];
		$effective = Dealer_Organization::get_effective_lines( $org_id );

		if ( ! $parent ) {
			$mode = 'root';        // Radice: vale quanto dichiarato.
		} elseif ( empty( $own ) ) {
			$mode = 'inherited';   // Nessuna linea propria: come la madre.
		} else {
			$mode = 'intersect';   // Intersezione fra proprie e madre.
		}

		return [
			'own'       => $own,
			'inherited' => $inherited,
			'effective' => $effective,
			'dead'      => ( $parent && $own ) ? array_values( array_diff( $own, $inherited ) ) : [],
			'mode'      => $mode,
			'parent'    => $parent,
		];
	}

	/** Etichetta sintetica della provenienza delle linee effettive. */
	public static function mode_label( string $mode ): string {
		switch ( $mode ) {
			case 'inherited':
				return 'ereditate dalla madre';
			case 'intersect':
				return 'proprie ∩ madre';
			default:
				return 'proprie (radice)';
		}
	}

	// ─── Calcolo: stato ───────────────────────────────────────────────────────

	/**
	 * Stato reale: la sospensione di un antenato pesa quanto la propria, ma va
	 * distinta, altrimenti l'amministratore cerca invano l'interruttore sulla
	 * sede sbagliata.
	 */
	public static function status_info( int $org_id ): array {
		$own_suspended = ( Dealer_Organization::STATUS_SUSPENDED === get_post_meta( $org_id, Dealer_Organization::META_STATUS, true ) );
		$active        = Dealer_Organization::is_active( $org_id );

		$blocker = 0;
		if ( ! $active && ! $own_suspended ) {
			foreach ( Dealer_Organization::get_ancestors( $org_id ) as $ancestor ) {
				if ( Dealer_Organization::STATUS_SUSPENDED === get_post_meta( $ancestor, Dealer_Organization::META_STATUS, true ) ) {
					$blocker = $ancestor;
					break;
				}
			}
		}

		return [
			'active'        => $active,
			'own_suspended' => $own_suspended,
			'blocker'       => $blocker,
			'blocker_name'  => $blocker ? Dealer_Organization::get_name( $blocker ) : '',
		];
	}

	/**
	 * Quante organizzazioni e quanti utenti tocca una sospensione: la
	 * propagazione alle discendenti va dichiarata prima di premere il pulsante.
	 *
	 * @param array<int,int> $user_counts Mappa org_id => utenti (per evitare N query).
	 */
	public static function suspend_impact( int $org_id, array $user_counts = [] ): array {
		if ( empty( $user_counts ) ) {
			$user_counts = self::get_user_counts_by_org();
		}

		$subtree = Dealer_Organization::get_subtree( $org_id );
		$users   = 0;
		foreach ( $subtree as $id ) {
			$users += (int) ( $user_counts[ $id ] ?? 0 );
		}

		return [
			'orgs'  => count( $subtree ),
			'users' => $users,
		];
	}

	/**
	 * Come suspend_impact(), ma sulla mappa dell'albero gia' caricata.
	 *
	 * @param array<int,int> $user_counts
	 */
	public static function impact_from_map( array $children, int $org_id, array $user_counts ): array {
		$subtree = self::subtree_from_map( $children, $org_id );
		$users   = 0;
		foreach ( $subtree as $id ) {
			$users += (int) ( $user_counts[ $id ] ?? 0 );
		}

		return [ 'orgs' => count( $subtree ), 'users' => $users ];
	}

	// ─── Calcolo: eliminazione ────────────────────────────────────────────────

	/**
	 * Cosa comporta eliminare un'organizzazione. Le figlie riagganciate possono
	 * cambiare diritti: staccandole da un antenato l'intersezione cade e le loro
	 * linee proprie tornano tutte valide — un ampliamento silenzioso che va
	 * mostrato prima.
	 */
	public static function delete_preview( int $org_id, array $user_counts = [] ): array {
		if ( empty( $user_counts ) ) {
			$user_counts = self::get_user_counts_by_org();
		}

		$parent   = Dealer_Organization::get_parent_id( $org_id );
		$children = [];

		foreach ( Dealer_Organization::get_children( $org_id ) as $child ) {
			$now = Dealer_Organization::get_effective_lines( $child );

			// Simulazione: dopo il riaggancio il tetto e' quello del nuovo
			// antenato (la madre della cancellata) oppure nessuno.
			$children[] = [
				'id'            => $child,
				'name'          => Dealer_Organization::get_name( $child ),
				'now'           => count( $now ),
				'after_parent'  => count( self::simulate_effective( $child, $parent ) ),
				'after_root'    => count( self::simulate_effective( $child, 0 ) ),
				'users'         => (int) ( $user_counts[ $child ] ?? 0 ),
			];
		}

		return [
			'users'        => (int) ( $user_counts[ $org_id ] ?? 0 ),
			'children'     => $children,
			'parent'       => $parent,
			'parent_name'  => $parent ? Dealer_Organization::get_name( $parent ) : '',
		];
	}

	/**
	 * Linee effettive che un'organizzazione avrebbe con una madre diversa.
	 * Pura simulazione: non scrive nulla.
	 *
	 * @return string[]
	 */
	public static function simulate_effective( int $org_id, int $hypothetical_parent ): array {
		$own = Dealer_Organization::get_own_lines( $org_id );

		if ( ! $hypothetical_parent || ! Dealer_Organization::exists( $hypothetical_parent ) ) {
			return $own;
		}

		$inherited = Dealer_Organization::get_effective_lines( $hypothetical_parent );

		return empty( $own )
			? $inherited
			: array_values( array_intersect( $own, $inherited ) );
	}

	// ─── Calcolo: fusione ─────────────────────────────────────────────────────

	/**
	 * Anteprima completa di una fusione. Non scrive nulla: serve a mettere
	 * davanti all'amministratore chi si sposta e, soprattutto, chi ci perde.
	 *
	 * @param int[] $source_ids
	 */
	public static function merge_preview( array $source_ids, int $dest_id ): array {
		$out = [
			'destination'   => 0,
			'dest_name'     => '',
			'dest_lines'    => [],
			'sources'       => [],
			'total_users'   => 0,
			'total_losers'  => 0,
			'fatal'         => '',
		];

		if ( ! Dealer_Organization::exists( $dest_id ) ) {
			$out['fatal'] = 'Destinazione non valida: scegli un\'organizzazione esistente.';
			return $out;
		}

		$source_ids = array_values( array_unique( array_filter( array_map( 'absint', $source_ids ) ) ) );
		$source_ids = array_values( array_diff( $source_ids, [ $dest_id ] ) );

		if ( empty( $source_ids ) ) {
			$out['fatal'] = 'Seleziona almeno un\'organizzazione da fondere, diversa dalla destinazione.';
			return $out;
		}
		if ( count( $source_ids ) > self::MAX_MERGE_SOURCES ) {
			$out['fatal'] = sprintf( 'Troppe organizzazioni selezionate: il massimo per una singola fusione e\' %d.', self::MAX_MERGE_SOURCES );
			return $out;
		}

		$dest_lines          = Dealer_Organization::get_effective_lines( $dest_id );
		$out['destination']  = $dest_id;
		$out['dest_name']    = Dealer_Organization::get_name( $dest_id );
		$out['dest_lines']   = $dest_lines;
		$dest_ancestors      = Dealer_Organization::get_ancestors( $dest_id );

		foreach ( $source_ids as $source_id ) {
			$entry = [
				'id'       => $source_id,
				'name'     => Dealer_Organization::get_name( $source_id ),
				'blocked'  => '',
				'users'    => [],
				'children' => [],
			];

			if ( ! Dealer_Organization::exists( $source_id ) ) {
				$entry['blocked'] = 'Organizzazione inesistente.';
				$out['sources'][] = $entry;
				continue;
			}

			// Fondere una madre dentro una propria discendente creerebbe un
			// ciclo al momento di riagganciarne le figlie: si blocca prima.
			if ( in_array( $source_id, $dest_ancestors, true ) ) {
				$entry['blocked'] = 'E\' un antenato della destinazione: sposta prima la gerarchia, altrimenti il riaggancio delle figlie creerebbe un ciclo.';
				$out['sources'][] = $entry;
				continue;
			}

			foreach ( Dealer_Organization::get_users( $source_id ) as $user ) {
				$before = Dealer_Identity::get_effective_lines( $user );

				// Dopo la fusione la restrizione individuale resta, ma viene
				// intersecata con le linee della destinazione.
				$limit = get_user_meta( $user->ID, Dealer_Identity::META_LINE_LIMIT, true );
				$after = ( is_array( $limit ) && ! empty( $limit ) )
					? array_values( array_intersect( $dest_lines, $limit ) )
					: $dest_lines;

				// La destinazione sospesa azzera comunque tutto.
				if ( ! Dealer_Organization::is_active( $dest_id ) ) {
					$after = [];
				}

				$lost = array_values( array_diff( $before, $after ) );
				if ( $lost ) {
					$out['total_losers']++;
				}

				$entry['users'][] = [
					'id'     => (int) $user->ID,
					'name'   => (string) $user->display_name,
					'email'  => (string) $user->user_email,
					'before' => count( $before ),
					'after'  => count( $after ),
					'lost'   => $lost,
					'gained' => array_values( array_diff( $after, $before ) ),
					'empty'  => empty( $after ),
				];
				$out['total_users']++;
			}

			foreach ( Dealer_Organization::get_children( $source_id ) as $child ) {
				$entry['children'][] = [
					'id'    => $child,
					'name'  => Dealer_Organization::get_name( $child ),
					'now'   => count( Dealer_Organization::get_effective_lines( $child ) ),
					'after' => count( self::simulate_effective( $child, $dest_id ) ),
				];
			}

			$out['sources'][] = $entry;
		}

		if ( $out['total_users'] > self::MAX_MERGE_USERS ) {
			$out['fatal'] = sprintf(
				'La fusione sposterebbe %d utenti: oltre il tetto di %d per singola operazione. Procedi per gruppi piu\' piccoli.',
				$out['total_users'],
				self::MAX_MERGE_USERS
			);
		}

		return $out;
	}

	// ─── Calcolo: utenti ──────────────────────────────────────────────────────

	/**
	 * Scheda di un membro: quanto gli e' concesso dall'azienda, la sua eventuale
	 * restrizione e cosa ne risulta davvero.
	 */
	public static function describe_member( \WP_User $user, array $org_lines ): array {
		$limit = get_user_meta( $user->ID, Dealer_Identity::META_LINE_LIMIT, true );
		$limit = is_array( $limit ) ? array_values( array_filter( $limit ) ) : [];

		return [
			'id'         => (int) $user->ID,
			'name'       => (string) $user->display_name,
			'login'      => (string) $user->user_login,
			'email'      => (string) $user->user_email,
			'function'   => Dealer_Identity::get_function( $user ),
			'limit'      => $limit,
			'has_limit'  => ! empty( $limit ),
			// Linee fuori dalle possibilita' dell'azienda: restano nel meta ma
			// non producono nulla. Vanno mostrate, non nascoste.
			'limit_dead' => array_values( array_diff( $limit, $org_lines ) ),
			'effective'  => Dealer_Identity::get_effective_lines( $user ),
			'active'     => Dealer_Identity::is_active( $user ),
		];
	}

	/**
	 * Utenti per organizzazione in una sola query: con qualche centinaio di
	 * organizzazioni un get_users() per riga renderebbe la pagina inservibile.
	 *
	 * @return array<int,int> org_id => numero utenti
	 */
	public static function get_user_counts_by_org(): array {
		global $wpdb;

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT meta_value AS org_id, COUNT(*) AS total
			 FROM {$wpdb->usermeta}
			 WHERE meta_key = %s
			 GROUP BY meta_value",
			Dealer_Identity::META_ORG
		) );

		$counts = [];
		foreach ( (array) $rows as $row ) {
			$org_id = absint( $row->org_id );
			if ( $org_id ) {
				$counts[ $org_id ] = (int) $row->total;
			}
		}

		return $counts;
	}

	// ─── Albero ───────────────────────────────────────────────────────────────

	/**
	 * Indicizza le organizzazioni per madre. Un post_parent che punta fuori
	 * dall'insieme caricato viene trattato come radice: meglio una riga fuori
	 * posto che una riga invisibile.
	 *
	 * @param \WP_Post[] $orgs
	 */
	public static function build_tree( array $orgs ): array {
		$known    = [];
		$children = [];
		$roots    = [];

		foreach ( $orgs as $org ) {
			$known[ (int) $org->ID ] = true;
		}

		foreach ( $orgs as $org ) {
			$id     = (int) $org->ID;
			$parent = (int) $org->post_parent;

			if ( $parent && isset( $known[ $parent ] ) && $parent !== $id ) {
				$children[ $parent ][] = $id;
			} else {
				$roots[] = $id;
			}
		}

		return [ 'children' => $children, 'roots' => $roots ];
	}

	/**
	 * Appiattisce l'albero conservando la profondita'. Il registro $seen e il
	 * tetto MAX_DEPTH proteggono da un post_parent corrotto in ciclo.
	 */
	public static function flatten( array $children, array $ids, int $depth, array &$out, array &$seen ): void {
		if ( $depth > Dealer_Organization::MAX_DEPTH ) {
			return;
		}

		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( isset( $seen[ $id ] ) ) {
				continue;
			}
			$seen[ $id ] = true;
			$out[]       = [ 'id' => $id, 'depth' => $depth ];

			if ( ! empty( $children[ $id ] ) ) {
				self::flatten( $children, $children[ $id ], $depth + 1, $out, $seen );
			}
		}
	}

	/**
	 * Sottoalbero calcolato sulla mappa gia' in memoria: nell'elenco serve
	 * l'impatto di una sospensione riga per riga, e interrogare il database per
	 * ciascuna riga renderebbe la pagina lenta senza aggiungere nulla.
	 *
	 * @return int[]
	 */
	public static function subtree_from_map( array $children, int $org_id, int $depth = 0 ): array {
		if ( $depth >= Dealer_Organization::MAX_DEPTH ) {
			return [];
		}

		$ids = [ $org_id ];
		foreach ( $children[ $org_id ] ?? [] as $child ) {
			$ids = array_merge( $ids, self::subtree_from_map( $children, (int) $child, $depth + 1 ) );
		}

		return array_values( array_unique( $ids ) );
	}

	/** Percorso leggibile "Gruppo › Concessionaria › Filiale". */
	public static function org_path( int $org_id ): string {
		$parts = [ Dealer_Organization::get_name( $org_id ) ];
		foreach ( Dealer_Organization::get_ancestors( $org_id ) as $ancestor ) {
			array_unshift( $parts, Dealer_Organization::get_name( $ancestor ) );
		}
		return implode( ' › ', $parts );
	}

	// ─── Etichette ────────────────────────────────────────────────────────────

	public static function tier_labels(): array {
		return [
			'dealer'      => 'Dealer',
			'top_dealer'  => 'Top Dealer',
			'part_center' => 'Parts Center',
		];
	}

	public static function tier_label( string $tier ): string {
		$labels = self::tier_labels();
		return $labels[ $tier ] ?? $tier;
	}

	public static function function_labels(): array {
		return [
			Dealer_Identity::FUNCTION_TITOLARE     => 'Titolare',
			Dealer_Identity::FUNCTION_COLLABORATORE => 'Collaboratore',
		];
	}

	/**
	 * Confronto fra linee effettive prima e dopo un'operazione.
	 * Un aumento e' un ampliamento silenzioso di diritti, una diminuzione e' una
	 * perdita di accesso: entrambi vanno segnalati, non solo il caso "peggiore".
	 */
	public static function delta_class( int $now, int $after ): string {
		return ( $now === $after ) ? '' : 'dorg-dead';
	}

	public static function delta_note( int $now, int $after ): string {
		if ( $after > $now ) {
			return sprintf( '+%d — ampliamento', $after - $now );
		}
		if ( $after < $now ) {
			return sprintf( '−%d — restrizione', $now - $after );
		}
		return 'invariato';
	}

	/** Etichetta "Brand › Linea" a partire dal formato interno "Brand|Linea". */
	public static function line_label( string $line ): string {
		$parts = explode( '|', $line, 2 );
		return isset( $parts[1] ) ? $parts[0] . ' › ' . $parts[1] : $line;
	}

	// ─── Utility ──────────────────────────────────────────────────────────────

	/** URL base della pagina. */
	public static function page_url(): string {
		return admin_url( 'admin.php?page=' . self::MENU_SLUG );
	}

	private function require_cap(): void {
		if ( ! current_user_can( DEALER_PORTAL_CAP_ORGS ) ) {
			wp_die( esc_html__( 'Accesso non consentito.', 'dealer-portal' ), '', [ 'response' => 403 ] );
		}
	}

	/**
	 * Errore mostrato DENTRO la schermata, non con un redirect.
	 *
	 * Il callback di una pagina di amministrazione viene invocato dopo che
	 * admin-header.php ha gia' stampato: li' wp_safe_redirect() non
	 * reindirizza niente (header gia' inviati) e l'exit che lo accompagna
	 * lascia a schermo mezza pagina di amministrazione troncata. Un errore
	 * incontrato mentre si sta gia' disegnando va rappresentato, non navigato
	 * — i redirect restano dove funzionano, cioe' negli handler admin_post,
	 * che girano prima di qualunque output.
	 */
	private function render_error( string $message, string $back_url, string $back_label ): void {
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline">Organizzazioni</h1>
			<hr class="wp-header-end">
			<div class="notice notice-error"><p><?php echo esc_html( $message ); ?></p></div>
			<p><a class="button" href="<?php echo esc_url( $back_url ); ?>"><?php echo esc_html( $back_label ); ?></a></p>
		</div>
		<?php
	}

	/** Linee dal POST, ripulite e filtrate contro la whitelist Brand|Linea. */
	private static function posted_lines( string $field ): array {
		$raw = isset( $_POST[ $field ] ) ? (array) wp_unslash( $_POST[ $field ] ) : [];
		$raw = array_map( 'sanitize_text_field', array_map( 'strval', $raw ) );

		return array_values( array_unique( array_intersect( $raw, Dealer_Admin::get_valid_lines() ) ) );
	}

	/** ID dal POST, sempre interi positivi. @return int[] */
	private static function posted_ids( string $field ): array {
		$raw = isset( $_POST[ $field ] ) ? (array) wp_unslash( $_POST[ $field ] ) : [];
		return array_values( array_unique( array_filter( array_map( 'absint', $raw ) ) ) );
	}

	/** Redirect verso la pagina con un codice di esito. */
	private function redirect( string $code, array $args = [] ): void {
		$args['page']    = self::MENU_SLUG;
		$args['org_msg'] = $code;

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/** Traduce il codice di esito in un avviso admin. */
	public static function notice_from_query(): array {
		$code = sanitize_key( (string) ( $_GET['org_msg'] ?? '' ) );
		if ( '' === $code ) {
			return [];
		}

		$moved = absint( $_GET['moved'] ?? 0 );
		$gone  = absint( $_GET['gone'] ?? 0 );

		$map = [
			'saved'              => [ 'success', 'Organizzazione salvata.' ],
			'saved_dead_lines'   => [ 'warning', 'Organizzazione salvata, ma alcune linee selezionate non sono concesse alla madre: l\'intersezione le annulla e restano senza effetto. Vedi il riquadro "Effetto risultante".' ],
			'deleted'            => [ 'success', 'Organizzazione eliminata.' ],
			'suspended'          => [ 'success', 'Organizzazione sospesa. La sospensione vale anche per tutte le sue discendenti.' ],
			'reactivated'        => [ 'success', 'Organizzazione riattivata. Le discendenti tornano attive se non sono sospese a loro volta.' ],
			'migrated'           => [ 'success', 'Migrazione eseguita: vedi il rapporto qui sotto.' ],
			'merged'             => [ 'success', sprintf( 'Fusione completata: %d utenti spostati, %d organizzazioni eliminate.', $moved, $gone ) ],
			'user_assigned'      => [ 'success', 'Utente assegnato all\'organizzazione.' ],
			'user_updated'       => [ 'success', 'Utente aggiornato.' ],
			'user_removed'       => [ 'success', 'Utente rimosso dall\'organizzazione. Torna al modello storico (meta _dealer_lines).' ],
			'am_scope_saved'     => [ 'success', 'Perimetro salvato. Se l\'area manager e\' collegato, lo vede al prossimo caricamento della sua area di lavoro.' ],
			'am_scope_no_lines'  => [ 'warning', 'Perimetro salvato, ma senza nessuna linea prodotto: cosi\' l\'area manager non puo\' pubblicare ne\' aggiornare alcun documento. Assegnagli almeno una linea per renderlo operativo.' ],
			'err_am_user'        => [ 'error',   'Utente non trovato o non ha il ruolo Area Manager.' ],
			'err_org'            => [ 'error',   'Organizzazione non trovata.' ],
			'err_name'           => [ 'error',   'Il nome dell\'organizzazione e\' obbligatorio.' ],
			'err_tier'           => [ 'error',   'Livello commerciale non valido.' ],
			'err_parent'         => [ 'error',   'Organizzazione madre non valida.' ],
			'err_cycle'          => [ 'error',   'Impossibile impostare quella madre: l\'organizzazione scelta e\' una sua discendente e si creerebbe un ciclo nella gerarchia. Sposta prima la discendente.' ],
			'err_create'         => [ 'error',   'Creazione non riuscita.' ],
			'err_confirm'        => [ 'error',   'Operazione annullata: manca la conferma esplicita.' ],
			'err_delete_users'   => [ 'error',   'Non si elimina un\'organizzazione che ha ancora utenti: spostali in un\'altra organizzazione (o rimuovili) e riprova.' ],
			'err_delete_children'=> [ 'error',   'L\'organizzazione ha delle figlie: scegli dove riagganciarle prima di eliminarla.' ],
			'err_merge'          => [ 'error',   'Fusione non eseguita: l\'anteprima riporta un errore bloccante.' ],
			'err_user'           => [ 'error',   'Utente non valido o non appartenente a questa organizzazione.' ],
			'err_function'       => [ 'error',   'Funzione non valida: ammesse solo titolare e collaboratore.' ],
			'err_limit_empty'    => [ 'error',   'Restrizione individuale vuota: seleziona almeno una linea, oppure scegli "nessuna restrizione".' ],
		];

		if ( ! isset( $map[ $code ] ) ) {
			return [];
		}

		return [ 'type' => $map[ $code ][0], 'message' => $map[ $code ][1] ];
	}

	/** Recupera (e consuma) il rapporto dell'ultima migrazione. */
	private static function pull_migration_report(): array {
		$key    = self::REPORT_TRANSIENT . get_current_user_id();
		$report = get_transient( $key );

		if ( ! is_array( $report ) ) {
			return [];
		}

		delete_transient( $key );

		return $report;
	}
}
