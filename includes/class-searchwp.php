<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Integrazione SearchWP 4.x per il CPT documento_dealer.
 *
 * Se SearchWP non è installato o attivo, tutte le azioni/filtri non vengono
 * registrati (le classi non esistono), quindi il plugin funziona comunque
 * con la ricerca base di WordPress.
 */
class Dealer_SearchWP {

	public function __construct() {
		// Registra il CPT come sorgente SearchWP.
		add_filter( 'searchwp\sources', [ $this, 'register_source' ] );

		// Aggiunge i meta field custom come attributi ricercabili.
		add_filter( 'searchwp\entry\attributes', [ $this, 'add_meta_attributes' ], 10, 2 );
	}

	/**
	 * Aggiunge "documento_dealer" alle sorgenti di SearchWP.
	 */
	public function register_source( array $sources ): array {
		if ( ! class_exists( '\SearchWP\Sources\Post' ) ) {
			return $sources;
		}
		$sources[] = new \SearchWP\Sources\Post( 'documento_dealer' );
		return $sources;
	}

	/**
	 * Espone i campi meta del documento come attributi ricercabili con pesi.
	 * I pesi verranno poi rifiniti dall'admin SearchWP nell'interfaccia grafica,
	 * ma questi valori di default già privilegiano le informazioni più rilevanti.
	 */
	public function add_meta_attributes( array $attributes, $source ): array {
		if ( ! class_exists( '\SearchWP\Attribute' ) ) {
			return $attributes;
		}

		// Verifica che la sorgente sia il nostro CPT.
		if ( ! method_exists( $source, 'get_post_type' ) || $source->get_post_type() !== 'documento_dealer' ) {
			return $attributes;
		}

		$meta_fields = [
			'_doc_keywords'     => 8, // keyword esplicite → peso massimo
			'_doc_brand'        => 7, // brand
			'_doc_product_line' => 6, // linea prodotto
			'_doc_type'         => 5, // tipo documento
			'_doc_year'         => 4, // anno
			'_doc_version'      => 3, // versione
		];

		foreach ( $meta_fields as $key => $weight ) {
			$attributes[] = new \SearchWP\Attribute( [
				'label'   => $key,
				'name'    => $key,
				'default' => $weight,
			] );
		}

		return $attributes;
	}
}
