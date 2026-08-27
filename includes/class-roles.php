<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Dealer_Roles {

	public function __construct() {
		$this->register();
	}

	private function register(): void {
		// add_role() è idempotente: non fa nulla se il ruolo esiste già.
		if ( ! get_role( 'dealer' ) ) {
			add_role( 'dealer', 'Dealer', [ 'read' => true ] );
		}
		if ( ! get_role( 'top_dealer' ) ) {
			add_role( 'top_dealer', 'Top Dealer', [ 'read' => true ] );
		}
		if ( ! get_role( 'part_center' ) ) {
			add_role( 'part_center', 'Part Center', [ 'read' => true ] );
		}

		// Area manager: segue un perimetro di organizzazioni e pubblica sulle
		// proprie linee. Non e' un dealer e non ha accesso a wp-admin oltre
		// alle pagine del plugin per cui ha capability esplicite.
		if ( ! get_role( 'area_manager' ) ) {
			add_role( 'area_manager', 'Area Manager', [ 'read' => true ] );
		}
	}
}
