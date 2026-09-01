<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Organizzazione dealer: l'azienda (o il gruppo) che detiene realmente i diritti.
 *
 * Prima di questo modello le linee prodotto stavano sul singolo utente, quindi
 * cinque collaboratori della stessa azienda andavano configurati cinque volte e
 * potevano divergere fra loro senza che nessuno se ne accorgesse. Qui i diritti
 * stanno in un posto solo: l'organizzazione.
 *
 * Gerarchia (via post_parent): gruppo → concessionaria → filiale.
 *
 * REGOLA FONDANTE — l'ereditarietà restringe, mai amplia:
 *
 *     effective( org ) = own_lines ∩ effective( parent )
 *
 * con due casi limite espliciti:
 *   - organizzazione radice        → effective = own_lines (niente da ereditare;
 *                                    vuoto significa quindi "nessuna linea")
 *   - figlia con own_lines vuoto   → effective = effective( parent ) ("come la madre")
 *
 * Da qui discende la proprieta' di sicurezza che ci interessa: nessuna
 * organizzazione puo' ottenere una linea che i suoi antenati non hanno. Una
 * revoca sul gruppo si propaga istantaneamente a tutte le sedi, senza pulizie
 * manuali e senza profili dimenticati indietro.
 */
class Dealer_Organization {

	/** CPT privato che rappresenta l'azienda o il gruppo. */
	const CPT = 'dealer_org';

	/** Livello commerciale dell'organizzazione (era il ruolo WP dell'utente). */
	const META_TIER = '_org_tier';

	/** Linee concesse a questa organizzazione, formato "Brand|Linea". */
	const META_LINES = '_org_lines';

	/** 'active' oppure 'suspended'. La sospensione blocca tutti i suoi utenti. */
	const META_STATUS = '_org_status';

	const META_VAT              = '_org_vat';
	const META_REFERENTE_NOME   = '_org_referente_nome';
	const META_REFERENTE_EMAIL  = '_org_referente_email';
	const META_REFERENTE_TEL    = '_org_referente_telefono';

	const STATUS_ACTIVE    = 'active';
	const STATUS_SUSPENDED = 'suspended';

	/** Livelli commerciali ammessi. Mai accettare altro da input esterno. */
	const TIERS = [ 'dealer', 'top_dealer', 'part_center' ];

	/**
	 * Profondita' massima della catena di antenati.
	 * Un post_parent corrotto potrebbe formare un ciclo: senza questo tetto la
	 * risoluzione dei permessi andrebbe in loop infinito.
	 */
	const MAX_DEPTH = 10;

	public function __construct() {
		if ( did_action( 'init' ) ) {
			$this->register_cpt();
		} else {
			add_action( 'init', [ $this, 'register_cpt' ] );
		}
	}

	/**
	 * Mappa ogni capability di un post type su un'unica capability del plugin.
	 *
	 * Serve ai CPT interni (organizzazioni, richieste di accesso) che non
	 * devono in nessun caso essere governati dai permessi generici dei post:
	 * quelli darebbero accesso a chiunque abbia edit_others_posts, cioè a
	 * qualunque Editor del sito.
	 *
	 * @param string $cap Capability del plugin a cui mappare tutto.
	 * @return array<string,string>
	 */
	public static function post_type_capabilities( string $cap ): array {
		return [
			'edit_post'              => $cap,
			'read_post'              => $cap,
			'delete_post'            => $cap,
			'edit_posts'             => $cap,
			'edit_others_posts'      => $cap,
			'edit_private_posts'     => $cap,
			'edit_published_posts'   => $cap,
			'publish_posts'          => $cap,
			'read_private_posts'     => $cap,
			'delete_posts'           => $cap,
			'delete_others_posts'    => $cap,
			'delete_private_posts'   => $cap,
			'delete_published_posts' => $cap,
			'create_posts'           => $cap,
		];
	}

	// ─── CPT ──────────────────────────────────────────────────────────────────

	public function register_cpt(): void {
		if ( post_type_exists( self::CPT ) ) {
			return;
		}

		register_post_type( self::CPT, [
			'labels'              => [
				'name'          => 'Organizzazioni',
				'singular_name' => 'Organizzazione',
			],
			'public'              => false,   // Nessun URL pubblico
			'publicly_queryable'  => false,
			'exclude_from_search' => true,
			'show_ui'             => false,   // Gestita solo dalle nostre pagine
			'show_in_menu'        => false,
			'show_in_nav_menus'   => false,
			'show_in_rest'        => false,   // Nessun endpoint REST
			'has_archive'         => false,
			'rewrite'             => false,
			'query_var'           => false,
			'hierarchical'        => true,    // Abilita post_parent
			'supports'            => [ 'title', 'page-attributes' ],
			// Permessi mappati sulla capability del plugin invece che su
			// quelle generiche dei post: senza questo un Editor, che ha
			// edit_others_posts, potrebbe manipolare le organizzazioni —
			// cioè i diritti di accesso di tutta la rete.
			'map_meta_cap'        => true,
			'capabilities'        => self::post_type_capabilities( DEALER_PORTAL_CAP_ORGS ),
		] );
	}

	// ─── Lettura di base ──────────────────────────────────────────────────────

	public static function exists( int $org_id ): bool {
		return $org_id > 0 && get_post_type( $org_id ) === self::CPT;
	}

	public static function get_name( int $org_id ): string {
		$post = self::exists( $org_id ) ? get_post( $org_id ) : null;
		return $post ? (string) $post->post_title : '';
	}

	public static function get_parent_id( int $org_id ): int {
		if ( ! self::exists( $org_id ) ) {
			return 0;
		}
		$parent = (int) wp_get_post_parent_id( $org_id );
		return self::exists( $parent ) ? $parent : 0;
	}

	/** Linee dichiarate su questa organizzazione, senza ereditarieta'. */
	public static function get_own_lines( int $org_id ): array {
		$lines = get_post_meta( $org_id, self::META_LINES, true );
		return is_array( $lines ) ? array_values( array_unique( array_filter( $lines ) ) ) : [];
	}

	/**
	 * Catena degli antenati, dal genitore diretto verso la radice.
	 * Il tetto MAX_DEPTH protegge da cicli in post_parent.
	 *
	 * @return int[]
	 */
	public static function get_ancestors( int $org_id ): array {
		$ancestors = [];
		$seen      = [ $org_id => true ];
		$current   = self::get_parent_id( $org_id );
		$depth     = 0;

		while ( $current && $depth < self::MAX_DEPTH ) {
			if ( isset( $seen[ $current ] ) ) {
				break; // Ciclo: ci fermiamo invece di girare all'infinito.
			}
			$seen[ $current ] = true;
			$ancestors[]      = $current;
			$current          = self::get_parent_id( $current );
			$depth++;
		}

		return $ancestors;
	}

	// ─── Risoluzione dei diritti ──────────────────────────────────────────────

	/**
	 * Linee effettive dell'organizzazione, applicando l'ereditarieta'.
	 * Vedi la regola fondante nel docblock della classe.
	 *
	 * @param int[] $seen ID gia' attraversati in questa risalita: uso interno.
	 * @return string[] formato "Brand|Linea"
	 */
	public static function get_effective_lines( int $org_id, array $seen = [] ): array {
		if ( ! self::exists( $org_id ) ) {
			return [];
		}

		$own    = self::get_own_lines( $org_id );
		$parent = self::get_parent_id( $org_id );

		// Radice: non c'e' nulla da cui ereditare, quindi vale quanto dichiarato.
		if ( ! $parent ) {
			return $own;
		}

		// Stesso tetto di get_ancestors(): post_parent e' un campo scrivibile e
		// un ciclo (A madre di B, B madre di A) manderebbe la ricorsione in
		// stack overflow, cioe' in schermata bianca su ogni pagina del portale.
		// Interrompere restituisce le linee proprie: restringe, non allarga —
		// coerente con la regola dell'intersezione.
		$seen[ $org_id ] = true;
		if ( isset( $seen[ $parent ] ) || count( $seen ) >= self::MAX_DEPTH ) {
			return $own;
		}

		$inherited = self::get_effective_lines( $parent, $seen );

		// Figlia senza linee proprie: vale esattamente quanto la madre.
		if ( empty( $own ) ) {
			return $inherited;
		}

		// Figlia con linee proprie: intersezione. Non puo' in nessun caso
		// ottenere una linea che la madre non ha.
		return array_values( array_intersect( $own, $inherited ) );
	}

	/**
	 * Livello commerciale effettivo: quello dichiarato, altrimenti ereditato
	 * dalla madre, altrimenti il livello base.
	 *
	 * @param int[] $seen ID gia' attraversati in questa risalita: uso interno.
	 *                    Vedi get_effective_lines() per il perche' del tetto.
	 */
	public static function get_tier( int $org_id, array $seen = [] ): string {
		if ( ! self::exists( $org_id ) ) {
			return 'dealer';
		}

		$tier = (string) get_post_meta( $org_id, self::META_TIER, true );
		if ( in_array( $tier, Dealer_Roles::dealer_slugs(), true ) ) {
			return $tier;
		}

		$parent          = self::get_parent_id( $org_id );
		$seen[ $org_id ] = true;

		// Ciclo o gerarchia troppo profonda: si ripiega sul livello base, il
		// meno privilegiato. Un errore di configurazione non deve poter
		// promuovere nessuno.
		if ( $parent && ! isset( $seen[ $parent ] ) && count( $seen ) < self::MAX_DEPTH ) {
			return self::get_tier( $parent, $seen );
		}

		return 'dealer';
	}

	/**
	 * Un'organizzazione e' attiva solo se lo sono anche tutti i suoi antenati:
	 * sospendere un gruppo deve sospendere ogni sede che ne dipende.
	 */
	public static function is_active( int $org_id ): bool {
		if ( ! self::exists( $org_id ) ) {
			return false;
		}

		if ( self::STATUS_SUSPENDED === get_post_meta( $org_id, self::META_STATUS, true ) ) {
			return false;
		}

		foreach ( self::get_ancestors( $org_id ) as $ancestor ) {
			if ( self::STATUS_SUSPENDED === get_post_meta( $ancestor, self::META_STATUS, true ) ) {
				return false;
			}
		}

		return true;
	}

	// ─── Navigazione dell'albero ──────────────────────────────────────────────

	/** Figlie diretette di un'organizzazione. @return int[] */
	public static function get_children( int $org_id ): array {
		$children = get_posts( [
			'post_type'      => self::CPT,
			'post_status'    => 'publish',
			'post_parent'    => $org_id,
			'numberposts'    => 200,
			'orderby'        => 'title',
			'order'          => 'ASC',
			'fields'         => 'ids',
		] );
		return array_map( 'intval', (array) $children );
	}

	/**
	 * Sottoalbero completo, organizzazione inclusa. Usato dal perimetro
	 * dell'area manager: seguire un gruppo significa seguirne le sedi.
	 *
	 * @return int[]
	 */
	public static function get_subtree( int $org_id, int $depth = 0 ): array {
		if ( ! self::exists( $org_id ) || $depth >= self::MAX_DEPTH ) {
			return [];
		}

		$ids = [ $org_id ];
		foreach ( self::get_children( $org_id ) as $child ) {
			$ids = array_merge( $ids, self::get_subtree( $child, $depth + 1 ) );
		}

		return array_values( array_unique( $ids ) );
	}

	/** Tutte le organizzazioni, ordinate per titolo. @return \WP_Post[] */
	public static function get_all( int $limit = 500 ): array {
		return get_posts( [
			'post_type'      => self::CPT,
			'post_status'    => 'publish',
			'numberposts'    => $limit,
			'orderby'        => 'title',
			'order'          => 'ASC',
		] );
	}

	/** Utenti appartenenti all'organizzazione. @return \WP_User[] */
	public static function get_users( int $org_id ): array {
		if ( ! self::exists( $org_id ) ) {
			return [];
		}
		return get_users( [
			'meta_key'   => Dealer_Identity::META_ORG,
			'meta_value' => $org_id,
			'number'     => 500,
		] );
	}

	// ─── Scrittura ────────────────────────────────────────────────────────────

	/**
	 * Crea un'organizzazione. Livello e linee sono sempre validati contro le
	 * whitelist: nessun valore arbitrario entra nel modello dei permessi.
	 *
	 * @return int ID creato, 0 in caso di errore.
	 */
	public static function create( string $name, array $args = [] ): int {
		$name = sanitize_text_field( $name );
		if ( '' === trim( $name ) ) {
			return 0;
		}

		$parent = isset( $args['parent'] ) ? absint( $args['parent'] ) : 0;
		if ( $parent && ! self::exists( $parent ) ) {
			$parent = 0;
		}

		$org_id = wp_insert_post( [
			'post_title'  => $name,
			'post_type'   => self::CPT,
			'post_status' => 'publish',
			'post_parent' => $parent,
		], true );

		if ( is_wp_error( $org_id ) ) {
			return 0;
		}

		$org_id = (int) $org_id;

		self::set_tier( $org_id, (string) ( $args['tier'] ?? 'dealer' ) );
		self::set_lines( $org_id, (array) ( $args['lines'] ?? [] ) );
		update_post_meta( $org_id, self::META_STATUS, self::STATUS_ACTIVE );

		return $org_id;
	}

	public static function set_tier( int $org_id, string $tier ): void {
		if ( ! self::exists( $org_id ) ) {
			return;
		}
		if ( ! in_array( $tier, Dealer_Roles::dealer_slugs(), true ) ) {
			$tier = 'dealer';
		}
		update_post_meta( $org_id, self::META_TIER, $tier );
	}

	/**
	 * Le linee sono sempre filtrate contro la whitelist delle combinazioni
	 * Brand|Linea realmente esistenti.
	 */
	public static function set_lines( int $org_id, array $lines ): void {
		if ( ! self::exists( $org_id ) ) {
			return;
		}

		$valid = class_exists( 'Dealer_Admin' ) ? Dealer_Admin::get_valid_lines() : [];
		$clean = array_values( array_intersect(
			array_map( 'sanitize_text_field', $lines ),
			$valid
		) );

		update_post_meta( $org_id, self::META_LINES, $clean );
	}

	public static function set_status( int $org_id, string $status ): void {
		if ( ! self::exists( $org_id ) ) {
			return;
		}
		$status = ( self::STATUS_SUSPENDED === $status ) ? self::STATUS_SUSPENDED : self::STATUS_ACTIVE;
		update_post_meta( $org_id, self::META_STATUS, $status );
	}

	/**
	 * Imposta la madre impedendo i cicli: un'organizzazione non puo' diventare
	 * figlia di una propria discendente.
	 *
	 * @return bool false se l'operazione creerebbe un ciclo.
	 */
	// ─── Migrazione dal modello per-utente ────────────────────────────────────

	/**
	 * Porta gli utenti del vecchio modello dentro il nuovo, senza cambiare cosa
	 * vedono. Ogni utente dealer non ancora migrato diventa titolare di una
	 * propria organizzazione che eredita esattamente le sue linee e il suo
	 * livello: il giorno dell'aggiornamento nessuno vede ne' piu' ne' meno di
	 * prima. Sara' poi l'amministratore a fondere le organizzazioni che nella
	 * realta' sono la stessa azienda.
	 *
	 * Idempotente: gli utenti gia' assegnati vengono saltati, quindi puo' essere
	 * rieseguita senza duplicare nulla.
	 *
	 * I meta storici NON vengono cancellati: restano come rete di sicurezza e
	 * come possibilita' di confronto se qualcosa non tornasse.
	 *
	 * @return array{created:int,skipped:int,failed:int,details:array}
	 */
	public static function migrate_legacy_users(): array {
		$report = [ 'created' => 0, 'skipped' => 0, 'failed' => 0, 'details' => [] ];

		$users = get_users( [
			'role__in' => Dealer_Roles::dealer_slugs(),
			'number'   => 1000,
		] );

		foreach ( $users as $user ) {
			// Gia' migrato: non tocchiamo nulla.
			if ( Dealer_Organization::exists( (int) get_user_meta( $user->ID, Dealer_Identity::META_ORG, true ) ) ) {
				$report['skipped']++;
				continue;
			}

			// Nome dell'organizzazione: la ragione sociale raccolta dalla
			// richiesta di accesso se c'e', altrimenti il nome dell'utente.
			$name = (string) get_user_meta( $user->ID, '_dar_company', true );
			if ( '' === trim( $name ) ) {
				$name = (string) $user->display_name;
			}
			if ( '' === trim( $name ) ) {
				$name = (string) $user->user_login;
			}

			// Livello: il primo ruolo dealer trovato, come faceva il vecchio modello.
			$tier = 'dealer';
			foreach ( Dealer_Roles::dealer_slugs() as $role ) {
				if ( in_array( $role, (array) $user->roles, true ) ) {
					$tier = $role;
					break;
				}
			}

			$legacy_lines = get_user_meta( $user->ID, Dealer_Identity::META_LEGACY_LINES, true );
			$legacy_lines = is_array( $legacy_lines ) ? $legacy_lines : [];

			$org_id = self::create( $name, [ 'tier' => $tier, 'lines' => $legacy_lines ] );

			if ( ! $org_id ) {
				$report['failed']++;
				$report['details'][] = sprintf( 'Utente #%d (%s): creazione organizzazione fallita.', $user->ID, $user->user_login );
				continue;
			}

			// Il referente commerciale e i dati fiscali appartengono all'azienda.
			update_post_meta( $org_id, self::META_VAT,             (string) get_user_meta( $user->ID, '_dar_vat', true ) );
			update_post_meta( $org_id, self::META_REFERENTE_NOME,  (string) get_user_meta( $user->ID, '_referente_nome', true ) );
			update_post_meta( $org_id, self::META_REFERENTE_EMAIL, (string) get_user_meta( $user->ID, '_referente_email', true ) );
			update_post_meta( $org_id, self::META_REFERENTE_TEL,   (string) get_user_meta( $user->ID, '_referente_telefono', true ) );

			// L'utente migrato e' titolare della propria organizzazione.
			Dealer_Identity::set_org( $user->ID, $org_id, Dealer_Identity::FUNCTION_TITOLARE );

			$report['created']++;
			$report['details'][] = sprintf(
				'Utente #%d (%s) -> organizzazione #%d "%s" [%s, %d linee].',
				$user->ID,
				$user->user_login,
				$org_id,
				$name,
				$tier,
				count( $legacy_lines )
			);
		}

		return $report;
	}

	/** Quanti utenti dealer non hanno ancora un'organizzazione. */
	public static function count_unmigrated_users(): int {
		$users   = get_users( [ 'role__in' => Dealer_Roles::dealer_slugs(), 'number' => 1000 ] );
		$pending = 0;

		foreach ( $users as $user ) {
			if ( ! Dealer_Organization::exists( (int) get_user_meta( $user->ID, Dealer_Identity::META_ORG, true ) ) ) {
				$pending++;
			}
		}

		return $pending;
	}

	public static function set_parent( int $org_id, int $parent_id ): bool {
		if ( ! self::exists( $org_id ) ) {
			return false;
		}

		if ( $parent_id ) {
			if ( ! self::exists( $parent_id ) || $parent_id === $org_id ) {
				return false;
			}
			if ( in_array( $parent_id, self::get_subtree( $org_id ), true ) ) {
				return false; // Sarebbe un ciclo.
			}
		}

		wp_update_post( [ 'ID' => $org_id, 'post_parent' => $parent_id ] );
		return true;
	}
}
