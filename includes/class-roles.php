<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Dealer_Roles {

	/** Slug => etichetta visibile. Unico punto in cui i due sono associati. */
	const ROLES = [
		'dealer'      => 'Dealer',
		'top_dealer'  => 'Top Dealer',
		'part_center' => 'Parts Center',
	];

	public function __construct() {
		$this->register();
	}

	private function register(): void {
		foreach ( self::ROLES as $slug => $label ) {
			$role = get_role( $slug );

			if ( ! $role ) {
				add_role( $slug, $label, [ 'read' => true ] );
				continue;
			}

			// add_role() è idempotente: se il ruolo esiste già non ne aggiorna
			// l'etichetta. Il nome visibile resta quello salvato la prima
			// volta che il ruolo è stato creato — su un sito dove il plugin è
			// già stato attivato, correggere qui la stringa non basta a farla
			// comparire da nessuna parte. Va riparato esplicitamente.
			$this->maybe_rename( $slug, $label, $role );
		}

		// Area manager: segue un perimetro di organizzazioni e pubblica sulle
		// proprie linee. Non e' un dealer e non ha accesso a wp-admin oltre
		// alle pagine del plugin per cui ha capability esplicite.
		if ( ! get_role( 'area_manager' ) ) {
			add_role( 'area_manager', 'Area Manager', [ 'read' => true ] );
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
	 * predefinito del sito. Se il webmaster ha impostato 'dealer' come ruolo
	 * predefinito, una semplice correzione di etichetta glielo cancellerebbe
	 * in silenzio, e da lì in poi ogni nuovo iscritto sarebbe un subscriber.
	 * Il valore va quindi salvato prima e ripristinato dopo.
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

		// Ripristino solo se era davvero questo il ruolo predefinito: in tutti
		// gli altri casi remove_role() non ha toccato nulla e riscrivere
		// l'opzione sarebbe una modifica gratuita alle impostazioni del sito.
		if ( $slug === $default_role && get_option( 'default_role' ) !== $default_role ) {
			update_option( 'default_role', $default_role );
		}
	}
}
