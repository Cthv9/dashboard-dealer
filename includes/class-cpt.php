<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Dealer_CPT {

	public function __construct() {
		add_action( 'init', [ $this, 'register' ] );
	}

	public function register(): void {
		$labels = [
			'name'               => 'Documenti Dealer',
			'singular_name'      => 'Documento Dealer',
			'add_new_item'       => 'Aggiungi documento',
			'edit_item'          => 'Modifica documento',
			'search_items'       => 'Cerca documenti',
			'not_found'          => 'Nessun documento trovato',
			'not_found_in_trash' => 'Nessun documento nel cestino',
		];

		register_post_type( 'documento_dealer', [
			'labels'          => $labels,
			'public'          => false,   // Non accessibile via URL pubblici
			'show_ui'         => true,    // Visibile nell'admin WP
			'show_in_menu'    => false,   // Nascosto dal menu standard (usiamo menu custom)
			'show_in_rest'    => false,   // Nessun endpoint REST pubblico
			'supports'        => [ 'title', 'author' ],
			'capability_type' => 'post',
			'map_meta_cap'    => true,
		] );
	}
}
