<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Ruoli dell'area riservata.
 *
 * I tre ruoli storici (dealer, top dealer, parts center) sono il punto di
 * partenza, non un elenco chiuso: l'amministratore puo' rinominarli, crearne di
 * nuovi e disattivarli dalla schermata "Ruoli e Linee". La mappa vive quindi in
 * un'opzione, con le costanti come seme della prima installazione.
 *
 * Un ruolo DISATTIVATO non viene piu' considerato un ruolo del portale: gli
 * utenti che ce l'hanno restano, ma smettono di essere trattati come dealer da
 * dashboard, ricerca e restrizioni sui documenti. Il ruolo WordPress non viene
 * eliminato — cancellarlo toglierebbe l'accesso a persone reali senza che
 * nessuno lo abbia chiesto.
 */
class Dealer_Roles {

	/** Opzione che contiene la mappa completa. */
	const OPTION = 'dealer_portal_roles';

	/** Seme: i tre ruoli storici. Slug => etichetta. */
	const ROLES = [
		'dealer'      => 'Dealer',
		'top_dealer'  => 'Top Dealer',
		'part_center' => 'Parts Center',
	];

	/** Ruolo dell'area manager: non e' un ruolo dealer e non e' modificabile da qui. */
	const AREA_MANAGER = 'area_manager';

	public function __construct() {
		$this->register();
	}

	// ─── Lettura ──────────────────────────────────────────────────────────────

	/**
	 * Mappa completa dei ruoli dealer, attivi e non.
	 *
	 * @return array<string,array{label:string,active:bool,custom:bool}>
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION );
		if ( ! is_array( $stored ) || empty( $stored ) ) {
			$stored = [];
		}

		$out = [];

		// I ruoli storici ci sono sempre: si possono rinominare o disattivare,
		// non far sparire dall'elenco.
		foreach ( self::ROLES as $slug => $label ) {
			$row = is_array( $stored[ $slug ] ?? null ) ? $stored[ $slug ] : [];
			$out[ $slug ] = [
				'label'  => (string) ( $row['label'] ?? $label ),
				'active' => ! isset( $row['active'] ) || (bool) $row['active'],
				'custom' => false,
			];
		}

		foreach ( $stored as $slug => $row ) {
			$slug = self::sanitize_slug( (string) $slug );
			if ( '' === $slug || isset( $out[ $slug ] ) || self::AREA_MANAGER === $slug ) {
				continue;
			}
			$out[ $slug ] = [
				'label'  => (string) ( $row['label'] ?? $slug ),
				'active' => ! isset( $row['active'] ) || (bool) $row['active'],
				'custom' => true,
			];
		}

		return $out;
	}

	/**
	 * Slug dei ruoli dealer ATTIVI. E' l'unica risposta alla domanda "quali
	 * ruoli contano come dealer": ogni punto del plugin passa di qui invece di
	 * ripetere l'elenco, che era scritto a mano in dodici posti diversi.
	 *
	 * @return string[]
	 */
	public static function dealer_slugs(): array {
		$slugs = [];
		foreach ( self::all() as $slug => $row ) {
			if ( $row['active'] ) {
				$slugs[] = $slug;
			}
		}
		// Nessun ruolo attivo sarebbe una configurazione che spegne il portale
		// senza dirlo: si ricade sul seme.
		return $slugs ?: array_keys( self::ROLES );
	}

	/** Slug dei ruoli del portale: dealer attivi piu' l'area manager. @return string[] */
	public static function portal_slugs(): array {
		return array_merge( self::dealer_slugs(), [ self::AREA_MANAGER ] );
	}

	/** Etichette dei soli ruoli dealer attivi. @return array<string,string> */
	public static function labels(): array {
		$out = [];
		foreach ( self::all() as $slug => $row ) {
			if ( $row['active'] ) {
				$out[ $slug ] = $row['label'];
			}
		}
		return $out ?: self::ROLES;
	}

	public static function label( string $slug ): string {
		$all = self::all();
		if ( isset( $all[ $slug ] ) ) {
			return $all[ $slug ]['label'];
		}
		return self::AREA_MANAGER === $slug ? 'Area Manager' : $slug;
	}

	// ─── Scrittura ────────────────────────────────────────────────────────────

	/**
	 * Salva la mappa dei ruoli e allinea i ruoli WordPress.
	 *
	 * @param array $rows slug => [ 'label' => string, 'active' => bool ]
	 * @return array{saved:int,created:int,errors:string[]}
	 */
	public static function save( array $rows ): array {
		$map     = [];
		$errors  = [];
		$created = 0;

		foreach ( $rows as $slug => $row ) {
			$slug  = self::sanitize_slug( (string) $slug );
			$label = trim( (string) ( $row['label'] ?? '' ) );

			if ( '' === $slug ) {
				continue;
			}
			if ( '' === $label ) {
				$errors[] = sprintf( 'Il ruolo "%s" e\' stato ignorato: manca l\'etichetta.', $slug );
				continue;
			}
			if ( self::is_reserved( $slug ) ) {
				$errors[] = sprintf( 'Lo slug "%s" e\' riservato a WordPress o al plugin e non puo\' essere usato.', $slug );
				continue;
			}

			$map[ $slug ] = [
				'label'  => $label,
				'active' => ! empty( $row['active'] ),
			];

			if ( ! get_role( $slug ) ) {
				$created++;
			}
		}

		update_option( self::OPTION, $map );

		// Riallinea subito i ruoli WordPress: creare la riga senza creare il
		// ruolo lascerebbe l'amministratore convinto di aver fatto una cosa che
		// non e' avvenuta.
		( new self() )->register();

		return [ 'saved' => count( $map ), 'created' => $created, 'errors' => $errors ];
	}

	/** Slug ammesso: minuscole, cifre e underscore. */
	public static function sanitize_slug( string $slug ): string {
		$slug = strtolower( trim( $slug ) );
		$slug = preg_replace( '/[^a-z0-9_]/', '_', $slug );
		$slug = preg_replace( '/_+/', '_', (string) $slug );
		return trim( (string) $slug, '_' );
	}

	/**
	 * Slug che non si possono usare: i ruoli nativi di WordPress (assegnarne le
	 * capability a un dealer sarebbe un'escalation) e quelli del plugin con un
	 * significato proprio.
	 */
	public static function is_reserved( string $slug ): bool {
		$reserved = [
			'administrator', 'editor', 'author', 'contributor', 'subscriber',
			self::AREA_MANAGER,
		];
		return in_array( $slug, $reserved, true );
	}

	// ─── Registrazione in WordPress ───────────────────────────────────────────

	private function register(): void {
		foreach ( self::all() as $slug => $row ) {
			$role = get_role( $slug );

			if ( ! $role ) {
				// Anche i ruoli disattivati vengono creati: disattivare significa
				// "non contarlo piu' come dealer", non "cancellalo".
				add_role( $slug, $row['label'], [ 'read' => true ] );
				continue;
			}

			// add_role() e' idempotente e non aggiorna l'etichetta di un ruolo
			// gia' esistente: va riparata esplicitamente.
			$this->maybe_rename( $slug, $row['label'], $role );
		}

		// Area manager: segue un perimetro di organizzazioni e pubblica sulle
		// proprie linee. Non e' un dealer e non si configura da "Ruoli e Linee".
		if ( ! get_role( self::AREA_MANAGER ) ) {
			add_role( self::AREA_MANAGER, 'Area Manager', [ 'read' => true ] );
		}
	}

	/**
	 * Corregge l'etichetta visibile di un ruolo già esistente, senza toccarne
	 * le capability.
	 *
	 * Non esiste un update_role() in WordPress: il nome visibile vive in
	 * `wp_roles()->role_names`, non nell'oggetto WP_Role. La tecnica standard
	 * è rimuovere e ricreare il ruolo con lo stesso slug, riusando le
	 * capability che aveva — tutto nella stessa richiesta, quindi nessun
	 * utente resta senza le proprie capability nel frattempo.
	 *
	 * Un effetto collaterale va neutralizzato: WP_Roles::remove_role() azzera
	 * l'opzione `default_role` a 'subscriber' se il ruolo rimosso è quello
	 * predefinito del sito.
	 */
	private function maybe_rename( string $slug, string $label, \WP_Role $role ): void {
		$roles   = wp_roles();
		$current = $roles->role_names[ $slug ] ?? '';

		if ( $current === $label ) {
			return;
		}

		$capabilities = $role->capabilities;
		$default_role = get_option( 'default_role' );

		remove_role( $slug );
		add_role( $slug, $label, $capabilities );

		if ( $slug === $default_role && get_option( 'default_role' ) !== $default_role ) {
			update_option( 'default_role', $default_role );
		}
	}
}
