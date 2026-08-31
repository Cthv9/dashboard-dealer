<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Dealer_CPT {

	/**
	 * Capability proprie del CPT documenti.
	 *
	 * PERCHÉ NON 'post': con `capability_type => 'post'` i permessi sui
	 * documenti sarebbero quelli generici dei contenuti WordPress. Chiunque
	 * abbia `edit_others_posts` e `delete_others_posts` — cioè qualunque
	 * Editor, un ruolo che su un sito aziendale esiste quasi sempre per il
	 * sito pubblico — potrebbe elencare, modificare ed eliminare i documenti
	 * dealer da `edit.php?post_type=documento_dealer`, note interne comprese.
	 * `show_in_menu => false` nasconde la voce di menu, non blocca l'URL.
	 *
	 * Con capability proprie, l'intero impianto di permessi del plugin
	 * (manage_dealer_portal, perimetro dell'area manager, delega al titolare)
	 * non è più scavalcabile da un ruolo WordPress standard.
	 *
	 * Sono assegnate al SOLO amministratore, deliberatamente: l'area manager
	 * lavora esclusivamente dalle schermate del plugin, che applicano il
	 * controllo di perimetro. L'editor nativo di WordPress non lo applica,
	 * quindi dargli accesso da lì sarebbe una via d'uscita dal perimetro.
	 */
	const SINGULAR_CAP = 'documento_dealer';
	const PLURAL_CAP   = 'documenti_dealer';

	/**
	 * Capability primitive derivate da map_meta_cap. Sono queste a essere
	 * assegnate ai ruoli (le meta cap edit_post/delete_post/read_post vengono
	 * risolte da WordPress a partire da queste).
	 *
	 * @return string[]
	 */
	public static function primitive_caps(): array {
		$plural = self::PLURAL_CAP;

		return [
			'edit_' . $plural,
			'edit_others_' . $plural,
			'edit_private_' . $plural,
			'edit_published_' . $plural,
			'publish_' . $plural,
			'read_private_' . $plural,
			'delete_' . $plural,
			'delete_others_' . $plural,
			'delete_private_' . $plural,
			'delete_published_' . $plural,
		];
	}

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
			// Capability proprie: vedi il commento in testa alla classe.
			'capability_type' => [ self::SINGULAR_CAP, self::PLURAL_CAP ],
			'map_meta_cap'    => true,
		] );
	}
}
