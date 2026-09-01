<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Dealer_Search {

	private static array $dealer_roles = [ 'dealer', 'top_dealer', 'part_center' ];

	/** Tetto massimo di documenti recuperati con una sola query (base per i facet). */
	const MAX_RESULTS = 300;

	/** Documenti mostrati per pagina nella griglia. */
	const PER_PAGE = 24;

	/** Gruppi di facet gestiti (ordine = ordine di rendering nella sidebar). */
	const FACET_GROUPS = [ 'brand', 'line', 'type', 'year' ];

	/** User meta con i documenti preferiti del dealer (array di post ID). */
	const FAVORITES_META = '_dealer_favorites';

	/** Tetto ai preferiti memorizzabili: evita che lo user meta cresca all'infinito. */
	const MAX_FAVORITES = 200;

	/** Tetto ai documenti inclusi in un singolo archivio ZIP. */
	const ZIP_MAX_FILES = 50;

	/** Tetto alla dimensione complessiva (non compressa) di un archivio ZIP. */
	const ZIP_MAX_BYTES = 209715200; // 200 MB

	/** Insiemi ammessi per il download aggregato. */
	const ZIP_SCOPES = [ 'results', 'favorites' ];

	/** Archivi ZIP consentiti a un utente nella finestra sotto. */
	const ZIP_RATE_LIMIT_MAX = 10;

	/** Finestra del limite di frequenza sugli archivi ZIP. */
	const ZIP_RATE_LIMIT_WINDOW = 10 * MINUTE_IN_SECONDS;

	/** Evita la doppia registrazione degli handler AJAX. */
	private static bool $ajax_registered = false;

	/** Evita la doppia registrazione degli hook di rendering. */
	private static bool $render_hooks_registered = false;

	// ─── Constructor ─────────────────────────────────────────────────────────

	public function __construct() {
		add_shortcode( 'dealer_search', [ $this, 'render' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		self::register_ajax();
		self::register_render_hooks();
		add_filter( 'query_vars', [ $this, 'add_query_vars' ] );
		add_action( 'template_redirect', [ $this, 'handle_download' ] );
		add_action( 'template_redirect', [ $this, 'handle_favorite_link' ] );
		add_action( 'template_redirect', [ $this, 'handle_zip_download' ] );
	}

	/**
	 * Registrazione degli endpoint AJAX.
	 *
	 * Viene chiamata sia dal costruttore sia in coda al file: il bootstrap del
	 * plugin istanzia Dealer_Search solo quando ! is_admin(), mentre le chiamate
	 * ad admin-ajax.php girano in contesto admin. Registrare qui gli hook rende
	 * gli endpoint raggiungibili in entrambi i casi. Il flag statico e i callback
	 * statici garantiscono che l'hook resti unico.
	 */
	public static function register_ajax(): void {
		if ( self::$ajax_registered ) {
			return;
		}
		self::$ajax_registered = true;

		add_action( 'wp_ajax_dealer_log_download',    [ __CLASS__, 'ajax_log_download' ] );
		add_action( 'wp_ajax_dealer_facet_search',    [ __CLASS__, 'ajax_facet_search' ] );
		add_action( 'wp_ajax_dealer_toggle_favorite', [ __CLASS__, 'ajax_toggle_favorite' ] );

		// Utenti non loggati ricevono 403 senza informazioni sull'esistenza dei documenti.
		add_action( 'wp_ajax_nopriv_dealer_log_download',    [ __CLASS__, 'ajax_deny' ] );
		add_action( 'wp_ajax_nopriv_dealer_facet_search',    [ __CLASS__, 'ajax_deny' ] );
		add_action( 'wp_ajax_nopriv_dealer_toggle_favorite', [ __CLASS__, 'ajax_deny' ] );
	}

	/**
	 * Hook di rendering condivisi. Come register_ajax(), va registrata anche in
	 * contesto admin: la card dei risultati viene ricostruita dentro
	 * ajax_facet_search(), dove Dealer_Search non è istanziata.
	 */
	public static function register_render_hooks(): void {
		if ( self::$render_hooks_registered ) {
			return;
		}
		self::$render_hooks_registered = true;

		add_action( 'dealer_portal_search_card_meta', [ __CLASS__, 'render_card_extras' ], 10, 2 );
	}

	// ─── Assets ──────────────────────────────────────────────────────────────

	/**
	 * Aggancio su wp_enqueue_scripts: caso normale, asset già nell'head.
	 *
	 * Il rilevamento non può basarsi sul solo has_shortcode(): Elementor, Divi,
	 * WPBakery e simili non salvano il contenuto in post_content ma nei
	 * postmeta, e lo shortcode può anche stare in un template part o in un
	 * widget. In quei casi il controllo fallirebbe e la pagina si aprirebbe
	 * senza stile e senza JavaScript, senza alcun errore visibile.
	 */
	public function enqueue_assets(): void {
		global $post;

		$has_shortcode = is_a( $post, 'WP_Post' )
			&& has_shortcode( (string) $post->post_content, 'dealer_search' );

		// Confronto anche con la pagina registrata dal plugin: regge quando il
		// contenuto non è in post_content.
		$is_search_page = is_a( $post, 'WP_Post' )
			&& (int) $post->ID === (int) get_option( 'dealer_portal_search_page_id' );

		if ( ! $has_shortcode && ! $is_search_page ) {
			return;
		}

		self::enqueue_search_assets();
	}

	/**
	 * Carica gli asset della ricerca. Idempotente: wp_enqueue_* ignora una
	 * seconda registrazione dello stesso handle.
	 *
	 * Viene richiamata anche dallo shortcode durante il rendering — è la rete
	 * di sicurezza che rende il caricamento indipendente da come la pagina è
	 * stata costruita: se lo shortcode gira, gli asset partono. WordPress
	 * stampa in footer ciò che viene accodato dopo wp_head.
	 */
	public static function enqueue_search_assets(): void {
		wp_enqueue_style( 'dashicons' );
		wp_enqueue_style( 'dealer-portal-dealer', DEALER_PORTAL_URL . 'assets/css/dealer.css', [], DEALER_PORTAL_VERSION );
		wp_enqueue_script(
			'dealer-portal-search',
			DEALER_PORTAL_URL . 'assets/js/dealer-search.js',
			[ 'jquery' ],
			DEALER_PORTAL_VERSION,
			true
		);
		wp_localize_script( 'dealer-portal-search', 'dealerSearch', [
			'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
			'nonce'     => wp_create_nonce( 'dealer_search_nonce' ),
			'action'    => 'dealer_facet_search',
			'favAction' => 'dealer_toggle_favorite',
			'debounce'  => 350,
			'mobileBp'  => 782,
		] );
	}

	// ─── Shortcode render ────────────────────────────────────────────────────

	public function render( $atts ): string {
		// Rete di sicurezza sugli asset: se lo shortcode gira, la pagina ha
		// bisogno di CSS e JS, qualunque sia il sistema che l'ha costruita.
		// Copre i page builder, dove il rilevamento su post_content fallisce.
		self::enqueue_search_assets();

		if ( ! is_user_logged_in() ) {
			return '<p class="dealer-notice"><a href="' . esc_url( wp_login_url( get_permalink() ) ) . '">Accedi</a> per cercare i documenti.</p>';
		}
		$user = wp_get_current_user();
		if ( ! self::user_is_dealer( $user ) ) {
			return '<p class="dealer-notice">Accesso non autorizzato.</p>';
		}

		// Organizzazione sospesa: senza un messaggio esplicito l'utente
		// vedrebbe soltanto una libreria vuota e non avrebbe modo di capire
		// che si tratta di una sospensione e non di un guasto.
		if ( ! Dealer_Identity::is_active( $user ) ) {
			return '<p class="dealer-notice">L’accesso della tua azienda ai documenti è attualmente sospeso. '
				. 'Contatta il tuo referente commerciale per maggiori informazioni.</p>';
		}

		$user_lines = self::get_user_lines( $user->ID );

		// I filtri arrivano dalla query string: nessuna azione distruttiva, nonce non necessario.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$state = self::parse_state( $_GET, $user_lines );

		$base_url = get_permalink();
		if ( ! $base_url ) {
			$base_url = home_url( '/' );
		}

		$data = self::run_search( $user, $user_lines, $state );

		// HTML dei tre contenitori sostituibili via AJAX: generato sempre lato PHP.
		$facets_html  = self::render_facets( $data['facets'], $state, $user_lines );
		$filters_html = self::render_active_filters( $state, $base_url );
		$results_html = self::render_results( $data, $state, $base_url );

		// Variabili usate dal template principale.
		$search_term  = $state['s'];
		$orderby      = $state['orderby'];
		$sort_options = self::sort_options();
		$total        = $data['total'];
		$count_label  = self::count_label( $data['total'] );
		$paged        = $data['paged'];

		// La dashboard è la home dell'area riservata: questa pagina ne è un
		// ramo e deve poterci tornare senza affidarsi al tasto "indietro".
		$dashboard_url = Dealer_DB::dashboard_url();

		ob_start();
		require DEALER_PORTAL_PATH . 'templates/dealer-search.php';
		return ob_get_clean();
	}

	// ─── Stato dei filtri ────────────────────────────────────────────────────

	/**
	 * Normalizza la richiesta (GET o POST) in uno stato di ricerca validato.
	 * Ogni valore viene confrontato con un vocabolario noto: quello che non
	 * corrisponde viene scartato, così URL e chip restano sempre coerenti.
	 */
	private static function parse_state( array $src, array $user_lines ): array {
		$search_term = sanitize_text_field( wp_unslash( $src['s'] ?? '' ) );

		$brand = self::sanitize_list( $src['brand'] ?? [] );
		$line  = self::sanitize_list( $src['line']  ?? [] );
		$type  = self::sanitize_list( $src['type']  ?? [] );
		$year  = self::sanitize_list( $src['year']  ?? [] );

		// Retrocompatibilità con i vecchi link a filtro singolo (?filter_brand=…).
		if ( empty( $brand ) && ! empty( $src['filter_brand'] ) ) {
			$brand = self::sanitize_list( $src['filter_brand'] );
		}
		if ( empty( $line ) && ! empty( $src['filter_line'] ) ) {
			$line = self::sanitize_list( $src['filter_line'] );
		}
		if ( empty( $type ) && ! empty( $src['filter_type'] ) ) {
			$type = self::sanitize_list( $src['filter_type'] );
		}
		if ( empty( $year ) && ! empty( $src['filter_year'] ) ) {
			$year = self::sanitize_list( $src['filter_year'] );
		}

		// Vocabolari: solo valori esistenti.
		$brand = array_values( array_intersect( $brand, Dealer_Admin::get_brands() ) );
		$type  = array_values( array_intersect( $type, array_keys( Dealer_Admin::get_doc_types() ) ) );
		// Le linee sono limitate a quelle assegnate al dealer: nessun modo di sondare le altre.
		$line  = array_values( array_intersect( $line, $user_lines ) );

		$years = [];
		foreach ( $year as $y ) {
			$y = absint( $y );
			if ( $y >= 1900 && $y <= 2999 && ! in_array( (string) $y, $years, true ) ) {
				$years[] = (string) $y;
			}
		}

		$orderby = sanitize_key( $src['orderby'] ?? '' );
		$allowed = array_keys( self::sort_options() );
		if ( ! in_array( $orderby, $allowed, true ) ) {
			// Con un termine di ricerca ha senso partire dalla rilevanza.
			$orderby = '' !== $search_term ? 'relevance' : 'recent';
		}
		if ( 'relevance' === $orderby && '' === $search_term ) {
			$orderby = 'recent';
		}

		$paged = max( 1, absint( $src['pag'] ?? 1 ) );

		return [
			's'       => $search_term,
			'brand'   => $brand,
			'line'    => $line,
			'type'    => $type,
			'year'    => $years,
			'orderby' => $orderby,
			'paged'   => $paged,
		];
	}

	/** Sanifica una lista di valori testuali (accetta anche uno scalare). */
	private static function sanitize_list( $raw ): array {
		if ( ! is_array( $raw ) ) {
			$raw = [ $raw ];
		}
		$out = [];
		foreach ( $raw as $value ) {
			if ( is_array( $value ) || is_object( $value ) ) {
				continue;
			}
			$value = sanitize_text_field( wp_unslash( (string) $value ) );
			if ( '' !== $value && ! in_array( $value, $out, true ) ) {
				$out[] = $value;
			}
		}
		return $out;
	}

	/**
	 * Opzioni di ordinamento. La lista è sempre la stessa: "Più pertinenti" ha
	 * senso solo con un termine di ricerca e in quel caso viene disabilitata nel
	 * markup (così il <select> non va ricostruito a ogni aggiornamento AJAX).
	 */
	public static function sort_options(): array {
		return [
			'relevance' => 'Più pertinenti',
			'recent'    => 'Più recenti',
			'title'     => 'Titolo A-Z',
			'downloads' => 'Più scaricati',
		];
	}

	// ─── Motore di ricerca a faccette ────────────────────────────────────────

	/**
	 * Esegue la ricerca completa: un solo WP_Query, filtro di accesso, calcolo
	 * dei conteggi in PHP sull'array già filtrato, ordinamento e paginazione.
	 */
	private static function run_search( \WP_User $user, array $user_lines, array $state ): array {
		$items = self::fetch_accessible_items( $user, $user_lines, $state['s'] );

		// Conteggi: per ogni gruppo si applicano i filtri di TUTTI gli altri gruppi,
		// così spuntando una seconda voce dello stesso gruppo si allarga il set (OR interno).
		$facets = [];
		foreach ( self::FACET_GROUPS as $group ) {
			$subset = self::filter_items( $items, $state, $group );
			$counts = self::count_group( $subset, $group, $user_lines );
			// Un valore selezionato resta sempre visibile, anche a conteggio 0: va tolto.
			foreach ( $state[ $group ] as $selected ) {
				if ( ! isset( $counts[ $selected ] ) ) {
					$counts[ $selected ] = 0;
				}
			}
			$facets[ $group ] = self::format_facet( $group, $counts );
		}

		$filtered = self::filter_items( $items, $state, '' );

		// Conteggio download: una sola query sull'intero set solo se serve per l'ordinamento.
		$download_counts = [];
		if ( 'downloads' === $state['orderby'] && ! empty( $filtered ) ) {
			$download_counts = self::download_counts( wp_list_pluck( $filtered, 'id' ) );
		}

		$filtered = self::sort_items( $filtered, $state['orderby'], $download_counts );

		$total       = count( $filtered );
		$total_pages = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		$paged       = min( max( 1, $state['paged'] ), $total_pages );
		$page_items  = array_slice( $filtered, ( $paged - 1 ) * self::PER_PAGE, self::PER_PAGE );

		if ( empty( $download_counts ) && ! empty( $page_items ) ) {
			$download_counts = self::download_counts( wp_list_pluck( $page_items, 'id' ) );
		}

		return [
			'facets'          => $facets,
			'page_items'      => $page_items,
			'total'           => $total,
			'total_pages'     => $total_pages,
			'paged'           => $paged,
			'base_total'      => count( $items ),
			'download_counts' => $download_counts,
		];
	}

	/**
	 * ID di tutti i documenti che corrispondono allo stato dei filtri, senza
	 * paginazione. Usato dal download aggregato: il set viene sempre ricalcolato
	 * lato server a partire dai filtri, non da una lista di ID inviata dal client.
	 *
	 * @return int[]
	 */
	private static function collect_filtered_ids( \WP_User $user, array $user_lines, array $state ): array {
		$items    = self::fetch_accessible_items( $user, $user_lines, $state['s'] );
		$filtered = self::filter_items( $items, $state, '' );
		$filtered = self::sort_items( $filtered, $state['orderby'], [] );

		return array_values( array_map( 'intval', wp_list_pluck( $filtered, 'id' ) ) );
	}

	/**
	 * Recupera il set di base (una sola query) e lo riduce ai soli documenti
	 * effettivamente accessibili al dealer. Tutto il resto — filtri e conteggi —
	 * lavora su questo array: i facet non possono quindi rivelare l'esistenza di
	 * documenti non autorizzati.
	 */
	private static function fetch_accessible_items( \WP_User $user, array $user_lines, string $search_term ): array {
		$meta_query = [
			'relation' => 'AND',
			// Solo documenti attivi.
			[
				'relation' => 'OR',
				[ 'key' => '_doc_status', 'value' => 'obsoleto', 'compare' => '!=' ],
				[ 'key' => '_doc_status', 'compare' => 'NOT EXISTS' ],
			],
			// Escludi documenti con data di scadenza superata.
			[
				'relation' => 'OR',
				[ 'key' => '_doc_expiry', 'compare' => 'NOT EXISTS' ],
				[ 'key' => '_doc_expiry', 'value' => '', 'compare' => '=' ],
				[ 'key' => '_doc_expiry', 'value' => gmdate( 'Y-m-d' ), 'compare' => '>=' ],
			],
		];

		// Solo versioni correnti: lo storico resta scaricabile ma fuori dalla ricerca.
		if ( class_exists( 'Dealer_Versioning' ) ) {
			$meta_query[] = Dealer_Versioning::meta_query_current_only();
		}

		$query_args = [
			'post_type'              => 'documento_dealer',
			'post_status'            => 'publish',
			'posts_per_page'         => self::MAX_RESULTS,
			'meta_query'             => $meta_query,
			'no_found_rows'          => true,
			'ignore_sticky_posts'    => true,
			'update_post_meta_cache' => true,
			'update_post_term_cache' => false,
		];

		// Usa SearchWP per ricerca full-text se disponibile.
		if ( $search_term && class_exists( '\SWP_Query' ) ) {
			$swp = new \SWP_Query( [
				'engine'         => 'default',
				'keywords'       => $search_term,
				'fields'         => 'ids',
				'posts_per_page' => self::MAX_RESULTS,
			] );
			$ids = array_filter( array_map( static function ( $p ) {
				return is_object( $p ) ? (int) $p->ID : (int) $p;
			}, $swp->posts ?? [] ) );

			if ( empty( $ids ) ) {
				return []; // SearchWP non ha trovato nulla.
			}
			$query_args['post__in'] = $ids;
			$query_args['orderby']  = 'post__in';
		} elseif ( $search_term ) {
			$query_args['s'] = $search_term;
			// Nessun orderby esplicito: WordPress ordina per rilevanza.
		} else {
			$query_args['orderby'] = 'date';
			$query_args['order']   = 'DESC';
		}

		$posts = ( new WP_Query( $query_args ) )->posts;

		$items = [];
		foreach ( $posts as $post ) {
			// Controllo accesso doppio: ruolo E linea assegnata al dealer. Mai bypassabile.
			if ( ! self::user_can_access_post( $user, $user_lines, $post->ID ) ) {
				continue;
			}
			$items[] = self::build_item( $post );
		}

		return $items;
	}

	/** Estrae una sola volta i meta che servono a filtri, conteggi e card. */
	private static function build_item( \WP_Post $post ): array {
		$brand = (string) get_post_meta( $post->ID, '_doc_brand', true );
		$line  = (string) get_post_meta( $post->ID, '_doc_product_line', true );

		$line_keys = get_post_meta( $post->ID, '_doc_lines', true );
		$line_keys = is_array( $line_keys ) ? array_values( array_filter( array_map( 'strval', $line_keys ) ) ) : [];
		if ( empty( $line_keys ) && '' !== $brand && '' !== $line ) {
			// Documento senza restrizione di linea: si classifica con brand + linea.
			$line_keys = [ $brand . '|' . $line ];
		}

		$year = absint( get_post_meta( $post->ID, '_doc_year', true ) );

		return [
			'id'        => (int) $post->ID,
			'post'      => $post,
			'title'     => (string) $post->post_title,
			'date'      => (string) $post->post_date_gmt,
			'brand'     => $brand,
			'line'      => $line,
			'line_keys' => $line_keys,
			'type'      => (string) get_post_meta( $post->ID, '_doc_type', true ),
			'year'      => $year ? (string) $year : '',
			'version'   => (string) get_post_meta( $post->ID, '_doc_version', true ),
			'filename'  => (string) get_post_meta( $post->ID, '_doc_filename', true ),
		];
	}

	/** Applica i filtri attivi, saltando eventualmente un gruppo (per i conteggi). */
	private static function filter_items( array $items, array $state, string $skip_group ): array {
		return array_values( array_filter( $items, static function ( $item ) use ( $state, $skip_group ) {
			return self::item_matches( $item, $state, $skip_group );
		} ) );
	}

	private static function item_matches( array $item, array $state, string $skip_group ): bool {
		if ( 'brand' !== $skip_group && ! empty( $state['brand'] ) && ! in_array( $item['brand'], $state['brand'], true ) ) {
			return false;
		}
		if ( 'type' !== $skip_group && ! empty( $state['type'] ) && ! in_array( $item['type'], $state['type'], true ) ) {
			return false;
		}
		if ( 'year' !== $skip_group && ! empty( $state['year'] ) && ! in_array( $item['year'], $state['year'], true ) ) {
			return false;
		}
		if ( 'line' !== $skip_group && ! empty( $state['line'] ) && empty( array_intersect( $state['line'], $item['line_keys'] ) ) ) {
			return false;
		}
		return true;
	}

	/** Conteggio dei valori di un gruppo sull'array già filtrato per accesso. */
	private static function count_group( array $items, string $group, array $user_lines ): array {
		$counts = [];

		foreach ( $items as $item ) {
			if ( 'line' === $group ) {
				foreach ( $item['line_keys'] as $key ) {
					// Solo le linee assegnate al dealer compaiono nella sidebar.
					if ( ! in_array( $key, $user_lines, true ) ) {
						continue;
					}
					$counts[ $key ] = ( $counts[ $key ] ?? 0 ) + 1;
				}
				continue;
			}

			$value = (string) ( $item[ $group ] ?? '' );
			if ( '' === $value ) {
				continue;
			}
			$counts[ $value ] = ( $counts[ $value ] ?? 0 ) + 1;
		}

		return $counts;
	}

	/** Trasforma i conteggi grezzi in voci ordinate ed etichettate per il template. */
	private static function format_facet( string $group, array $counts ): array {
		if ( 'type' === $group ) {
			$order  = array_keys( Dealer_Admin::get_doc_types() );
			$sorted = [];
			foreach ( $order as $key ) {
				if ( isset( $counts[ $key ] ) ) {
					$sorted[ $key ] = $counts[ $key ];
				}
			}
			// Tipi non più in vocabolario ma ancora presenti sui documenti.
			foreach ( $counts as $key => $n ) {
				if ( ! isset( $sorted[ $key ] ) ) {
					$sorted[ $key ] = $n;
				}
			}
			$counts = $sorted;
		} elseif ( 'year' === $group ) {
			krsort( $counts, SORT_NUMERIC );
		} else {
			uksort( $counts, 'strnatcasecmp' );
		}

		$entries = [];
		foreach ( $counts as $value => $count ) {
			$entries[] = [
				'value' => (string) $value,
				'label' => self::facet_label( $group, (string) $value ),
				'count' => (int) $count,
			];
		}
		return $entries;
	}

	/** Etichetta leggibile di un valore di facet. */
	public static function facet_label( string $group, string $value ): string {
		if ( 'type' === $group ) {
			$types = Dealer_Admin::get_doc_types();
			return (string) ( $types[ $value ] ?? $value );
		}
		if ( 'line' === $group ) {
			return str_replace( '|', ' › ', $value );
		}
		return $value;
	}

	/** Nome leggibile di un gruppo di facet. */
	public static function facet_group_label( string $group ): string {
		$labels = [
			'brand' => 'Brand',
			'line'  => 'Linea prodotto',
			'type'  => 'Tipo documento',
			'year'  => 'Anno',
		];
		return $labels[ $group ] ?? $group;
	}

	private static function sort_items( array $items, string $orderby, array $download_counts ): array {
		if ( 'title' === $orderby ) {
			usort( $items, static function ( $a, $b ) {
				return strnatcasecmp( $a['title'], $b['title'] );
			} );
		} elseif ( 'downloads' === $orderby ) {
			usort( $items, static function ( $a, $b ) use ( $download_counts ) {
				$da = $download_counts[ $a['id'] ] ?? 0;
				$db = $download_counts[ $b['id'] ] ?? 0;
				if ( $da === $db ) {
					return strcmp( $b['date'], $a['date'] );
				}
				return $db <=> $da;
			} );
		} elseif ( 'recent' === $orderby ) {
			usort( $items, static function ( $a, $b ) {
				return strcmp( $b['date'], $a['date'] );
			} );
		}
		// 'relevance': si mantiene l'ordine restituito dal motore di ricerca.
		return $items;
	}

	private static function download_counts( array $post_ids ): array {
		if ( empty( $post_ids ) || ! class_exists( 'Dealer_DB' ) || ! method_exists( 'Dealer_DB', 'get_download_counts_for_posts' ) ) {
			return [];
		}
		return Dealer_DB::get_download_counts_for_posts( $post_ids );
	}

	// ─── Rendering dei frammenti (sempre lato PHP, già escapato) ─────────────

	private static function render_facets( array $facets, array $state, array $user_lines ): string {
		ob_start();
		require DEALER_PORTAL_PATH . 'templates/dealer-search-facets.php';
		return (string) ob_get_clean();
	}

	private static function render_active_filters( array $state, string $base_url ): string {
		$chips = [];

		if ( '' !== $state['s'] ) {
			$chips[] = [
				'group' => 's',
				'value' => '',
				'group_label' => 'Testo',
				'label' => $state['s'],
				'url'   => self::build_url( $base_url, $state, [ 's' => null, 'pag' => 1 ] ),
			];
		}

		foreach ( self::FACET_GROUPS as $group ) {
			foreach ( $state[ $group ] as $value ) {
				$next            = $state;
				$next[ $group ]  = array_values( array_diff( $state[ $group ], [ $value ] ) );
				$chips[]         = [
					'group'       => $group,
					'value'       => $value,
					'group_label' => self::facet_group_label( $group ),
					'label'       => self::facet_label( $group, $value ),
					'url'         => self::build_url( $base_url, $next, [ 'pag' => 1 ] ),
				];
			}
		}

		$has_filters = ! empty( $chips );
		$reset_url   = $base_url;

		ob_start();
		require DEALER_PORTAL_PATH . 'templates/dealer-search-filters.php';
		return (string) ob_get_clean();
	}

	private static function render_results( array $data, array $state, string $base_url ): string {
		$items           = $data['page_items'];
		$total           = (int) $data['total'];
		$total_pages     = (int) $data['total_pages'];
		$paged           = (int) $data['paged'];
		$download_counts = $data['download_counts'];
		$library_empty   = ( 0 === (int) $data['base_total'] );
		$has_filters     = self::has_active_filters( $state );
		$search_term     = $state['s'];
		$doc_types       = Dealer_Admin::get_doc_types();
		$reset_url       = $base_url;

		ob_start();
		require DEALER_PORTAL_PATH . 'templates/dealer-search-results.php';
		return (string) ob_get_clean();
	}

	// ─── Extra della card: preferiti e storico versioni ──────────────────────

	/**
	 * Innesto su 'dealer_portal_search_card_meta'. Aggiunge alla card il
	 * pulsante preferiti e, se il documento ha una catena di versioni, il
	 * blocco a scomparsa con lo storico.
	 *
	 * La card viene renderizzata fino a PER_PAGE volte per pagina: qui si evita
	 * ogni query non necessaria (i meta sono già in cache grazie a WP_Query).
	 */
	public static function render_card_extras( int $post_id, array $item = [] ): void {
		if ( ! is_user_logged_in() ) {
			return;
		}
		$user = wp_get_current_user();
		if ( ! self::user_is_dealer( $user ) ) {
			return;
		}

		$user_lines = self::get_user_lines( $user->ID );

		$is_favorite  = self::is_favorite( $user->ID, $post_id );
		$favorite_url = self::get_favorite_url( $post_id, ! $is_favorite );
		$versions     = self::get_accessible_previous_versions( $user, $user_lines, $post_id );

		require DEALER_PORTAL_PATH . 'templates/dealer-search-card-extras.php';
	}

	/**
	 * Versioni precedenti che il dealer può davvero vedere e scaricare.
	 *
	 * Il controllo accessi viene rifatto su ogni versione storica: i meta
	 * _doc_roles/_doc_lines possono differire fra una versione e l'altra, quindi
	 * vedere la corrente non implica poter vedere le precedenti.
	 *
	 * @return array[] voci pronte per il template: id, label, date, url.
	 */
	public static function get_accessible_previous_versions( \WP_User $user, array $user_lines, int $post_id ): array {
		if ( ! class_exists( 'Dealer_Versioning' ) ) {
			return [];
		}

		// Scorciatoie a costo zero (i meta del post sono già in cache):
		// senza _doc_group_id il documento è precedente al versionamento, e una
		// versione corrente in posizione 1 non ha per definizione uno storico.
		if ( ! (int) get_post_meta( $post_id, Dealer_Versioning::META_GROUP, true ) ) {
			return [];
		}
		if ( Dealer_Versioning::is_current( $post_id ) && Dealer_Versioning::get_sequence( $post_id ) <= 1 ) {
			return [];
		}

		// Una sola chiamata: get_previous_versions() interroga il DB, e
		// count_previous_versions() non farebbe altro che rifare la stessa query.
		$previous = Dealer_Versioning::get_previous_versions( $post_id );
		if ( empty( $previous ) ) {
			return [];
		}

		$out = [];
		foreach ( $previous as $prev ) {
			$prev_id = (int) $prev->ID;

			// Stesse difese del download: obsoleti, scaduti e non pubblicati fuori.
			if ( ! self::user_can_download_post( $user, $user_lines, $prev_id ) ) {
				continue;
			}

			$label = (string) get_post_meta( $prev_id, '_doc_version', true );
			if ( '' === $label ) {
				$label = 'Versione ' . Dealer_Versioning::get_sequence( $prev_id );
			}

			$prev_filename = (string) get_post_meta( $prev_id, '_doc_filename', true );

			$out[] = [
				'id'          => $prev_id,
				'label'       => $label,
				'date'        => (string) $prev->post_date,
				'url'         => self::get_download_url( $prev_id ),
				'view_url'    => self::get_view_url( $prev_id ),
				'previewable' => self::is_previewable( $prev_filename ),
			];
		}

		return $out;
	}

	public static function has_active_filters( array $state ): bool {
		if ( '' !== $state['s'] ) {
			return true;
		}
		foreach ( self::FACET_GROUPS as $group ) {
			if ( ! empty( $state[ $group ] ) ) {
				return true;
			}
		}
		return false;
	}

	/** Etichetta del contatore risultati. */
	public static function count_label( int $total ): string {
		if ( 0 === $total ) {
			return 'Nessun documento';
		}
		if ( 1 === $total ) {
			return '1 documento';
		}
		return number_format_i18n( $total ) . ' documenti';
	}

	/**
	 * Ricostruisce l'URL della pagina con lo stato corrente (per i link no-JS:
	 * chip di rimozione, paginazione, reset).
	 */
	public static function build_url( string $base, array $state, array $overrides = [] ): string {
		$args = [];

		if ( '' !== $state['s'] ) {
			$args['s'] = $state['s'];
		}
		foreach ( self::FACET_GROUPS as $group ) {
			if ( ! empty( $state[ $group ] ) ) {
				$args[ $group ] = array_values( $state[ $group ] );
			}
		}
		// 'recent' è il default solo senza termine di ricerca: negli altri casi va esplicitato,
		// altrimenti parse_state ricadrebbe su 'relevance'.
		if ( 'recent' !== $state['orderby'] || '' !== $state['s'] ) {
			$args['orderby'] = $state['orderby'];
		}
		if ( $state['paged'] > 1 ) {
			$args['pag'] = $state['paged'];
		}

		foreach ( $overrides as $key => $value ) {
			if ( null === $value || '' === $value || [] === $value ) {
				unset( $args[ $key ] );
			} else {
				$args[ $key ] = $value;
			}
		}
		if ( isset( $args['pag'] ) && (int) $args['pag'] <= 1 ) {
			unset( $args['pag'] );
		}

		if ( empty( $args ) ) {
			return $base;
		}
		$separator = ( false === strpos( $base, '?' ) ) ? '?' : '&';
		return $base . $separator . http_build_query( $args );
	}

	// ─── Controllo accesso (statico, riusato da Dashboard) ───────────────────

	public static function user_is_dealer( \WP_User $user ): bool {
		foreach ( self::$dealer_roles as $role ) {
			if ( in_array( $role, (array) $user->roles, true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Chi può ricevere un documento: i dealer e gli area manager.
	 *
	 * L'area manager non è un dealer, ma pubblica e aggiorna documenti:
	 * impedirgli di aprirli sarebbe incoerente, non si mantiene un manuale che
	 * non si può leggere. Il suo diritto sul singolo documento resta comunque
	 * deciso da user_can_access_post(), che per lui richiede una linea nel
	 * perimetro — questo metodo apre la porta, non la spalanca.
	 *
	 * Il download resta distinguibile nel registro: Dealer_Identity::
	 * get_access_context() registra con quale titolo è avvenuto.
	 */
	public static function can_receive_documents( \WP_User $user ): bool {
		return self::user_is_dealer( $user ) || Dealer_Identity::is_area_manager( $user );
	}

	/** Linee assegnate al dealer (user meta _dealer_lines), sempre come array di stringhe. */
	/**
	 * Linee dell'utente per la COSTRUZIONE DELL'INTERFACCIA: alimenta il facet
	 * "Linea prodotto" e restringe il filtro richiesto a ciò che l'utente ha.
	 *
	 * Passa dal risolutore di identità, non dal meta storico. Leggendo
	 * `_dealer_lines` direttamente, un utente del modello a organizzazioni —
	 * per esempio un collaboratore invitato dal titolare, che quel meta non lo
	 * ha mai avuto — si ritroverebbe senza alcun filtro per linea. L'accesso
	 * resterebbe comunque corretto (user_can_access_post() risolve per conto
	 * suo), ma il portale gli risulterebbe monco senza un motivo visibile.
	 */
	public static function get_user_lines( int $user_id ): array {
		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			return [];
		}
		return Dealer_Identity::get_effective_lines( $user );
	}

	/**
	 * Regola di accesso doppio:
	 * 1. Il ruolo dell'utente deve essere nella lista _doc_roles del documento.
	 * 2. Se il documento ha _doc_lines impostato, almeno una delle linee dell'utente
	 *    deve corrispondere (formato "Brand|Linea" in entrambi i lati).
	 */
	public static function user_can_access_post( \WP_User $user, array $user_lines, int $post_id ): bool {
		$doc_roles = get_post_meta( $post_id, '_doc_roles', true );
		if ( ! is_array( $doc_roles ) || empty( $doc_roles ) ) {
			return false; // Nessun ruolo impostato = nessuno può vederlo.
		}

		$doc_lines = get_post_meta( $post_id, '_doc_lines', true );
		$doc_lines = is_array( $doc_lines ) ? $doc_lines : [];

		// ── Area manager ──────────────────────────────────────────────────────
		// Legge in sovrapposizione (basta una linea nel perimetro), ma scrive
		// solo in contenimento (vedi Dealer_Identity::can_edit_document()):
		// per seguire un documento condiviso con un'altra area basta esserne
		// competenti in parte, per modificarlo no.
		if ( Dealer_Identity::is_area_manager( $user ) ) {
			if ( empty( $doc_lines ) ) {
				return false; // Documento non ristretto: resta dell'amministratore.
			}
			return ! empty( array_intersect( Dealer_Identity::get_scope_lines( $user ), $doc_lines ) );
		}

		// ── Dealer ────────────────────────────────────────────────────────────
		// Organizzazione sospesa: nessun accesso, nemmeno ai documenti aperti.
		if ( ! Dealer_Identity::is_active( $user ) ) {
			return false;
		}

		// Il livello commerciale viene dall'organizzazione quando esiste, dal
		// ruolo WordPress altrimenti.
		$tier = Dealer_Identity::get_effective_tier( $user );
		if ( ! $tier || ! in_array( $tier, $doc_roles, true ) ) {
			return false; // Livello non autorizzato.
		}

		if ( empty( $doc_lines ) ) {
			return true; // Nessuna restrizione per linea: basta il livello.
		}

		// NOTA: $user_lines e' deliberatamente IGNORATO. Le linee vengono sempre
		// risolte qui, dal risolutore di identita', cosi' un chiamante che passi
		// per distrazione un elenco piu' ampio o non aggiornato non puo'
		// allargare l'accesso. Il parametro resta solo per compatibilita' con i
		// numerosi call site esistenti.
		$effective = Dealer_Identity::get_effective_lines( $user );

		return ! empty( array_intersect( $effective, $doc_lines ) );
	}

	/**
	 * Chi vede l'intero archivio senza restrizioni di perimetro.
	 *
	 * È l'amministratore del portale: tutte le viste admin (archivio, log,
	 * statistiche, export) partono da qui per decidere se applicare o no il
	 * filtro per linee.
	 */
	public static function has_unrestricted_scope( \WP_User $user ): bool {
		return user_can( $user, 'manage_options' ) || user_can( $user, DEALER_PORTAL_CAP );
	}

	/**
	 * Perimetro di visibilità *amministrativa* su un documento: può questo
	 * utente vederlo nell'archivio, nei log e nelle statistiche?
	 *
	 * Stesso criterio di lettura di user_can_access_post() per l'area manager —
	 * sovrapposizione: basta che una linea del documento sia nel perimetro —
	 * ma senza la regola sui ruoli dealer, che qui non c'entra: non stiamo
	 * decidendo se un dealer può scaricare, ma se un gestore è competente sul
	 * documento.
	 *
	 * Un documento senza restrizione di linea è visibile a tutta la rete e
	 * resta quindi di competenza del solo amministratore.
	 *
	 * @param string[]|null $scope Perimetro già risolto, per evitare di
	 *                             ricalcolarlo dentro un ciclo. Facoltativo.
	 */
	public static function user_can_view_document( \WP_User $user, int $post_id, ?array $scope = null ): bool {
		if ( self::has_unrestricted_scope( $user ) ) {
			return true;
		}

		if ( ! Dealer_Identity::is_area_manager( $user ) ) {
			return false;
		}

		$doc_lines = get_post_meta( $post_id, '_doc_lines', true );
		if ( ! is_array( $doc_lines ) || empty( $doc_lines ) ) {
			return false;
		}

		if ( null === $scope ) {
			$scope = Dealer_Identity::get_scope_lines( $user );
		}

		return ! empty( array_intersect( $scope, $doc_lines ) );
	}

	/**
	 * Verifica completa "questo dealer può scaricare questo documento?", con le
	 * stesse regole di handle_download(): tipo di post, stato pubblicato,
	 * documento non obsoleto, accesso per ruolo/linea, scadenza non superata.
	 *
	 * Le versioni superate restano scaricabili (sono lo storico): qui non si
	 * controlla _doc_is_current, esattamente come in handle_download().
	 */
	public static function user_can_download_post( \WP_User $user, array $user_lines, int $post_id ): bool {
		if ( $post_id <= 0 || 'documento_dealer' !== get_post_type( $post_id ) ) {
			return false;
		}
		if ( 'publish' !== get_post_status( $post_id ) ) {
			return false;
		}
		if ( 'obsoleto' === get_post_meta( $post_id, '_doc_status', true ) ) {
			return false;
		}
		if ( ! self::can_receive_documents( $user ) || ! self::user_can_access_post( $user, $user_lines, $post_id ) ) {
			return false;
		}
		if ( self::is_expired_doc( $post_id ) ) {
			return false;
		}
		return true;
	}

	// ─── Preferiti ───────────────────────────────────────────────────────────

	/**
	 * Preferiti del dealer, sempre normalizzati: interi positivi, senza
	 * duplicati, entro il tetto MAX_FAVORITES.
	 *
	 * @return int[]
	 */
	public static function get_favorites( int $user_id ): array {
		$raw = get_user_meta( $user_id, self::FAVORITES_META, true );
		return self::sanitize_favorites( is_array( $raw ) ? $raw : [] );
	}

	/** Normalizzazione applicata a ogni lettura e a ogni scrittura del meta. */
	private static function sanitize_favorites( array $ids ): array {
		$clean = [];
		foreach ( $ids as $id ) {
			if ( is_array( $id ) || is_object( $id ) ) {
				continue;
			}
			$id = absint( $id );
			if ( $id > 0 ) {
				$clean[] = $id;
			}
		}
		$clean = array_values( array_unique( $clean ) );

		return array_slice( $clean, 0, self::MAX_FAVORITES );
	}

	/** Scrive i preferiti (già sanificati) sul user meta. */
	public static function set_favorites( int $user_id, array $ids ): array {
		$clean = self::sanitize_favorites( $ids );
		update_user_meta( $user_id, self::FAVORITES_META, $clean );
		return $clean;
	}

	public static function is_favorite( int $user_id, int $post_id ): bool {
		return in_array( $post_id, self::get_favorites( $user_id ), true );
	}

	/**
	 * Documenti preferiti ancora accessibili al dealer, con pulizia
	 * silenziosa di quelli che non lo sono più.
	 *
	 * Unico punto in cui si risolve "quali preferiti mostrare": usato dalla
	 * dashboard e dal modulo Preferiti, così il criterio non diverge fra i
	 * due. Un preferito il cui accesso è stato revocato — o il cui documento
	 * è stato eliminato — sparisce senza dirlo all'utente e senza mostrarne
	 * il titolo: rivelarlo sarebbe un'informazione che non deve più avere.
	 * Scaduti e obsoleti restano salvati (la situazione può cambiare) ma non
	 * compaiono nell'elenco restituito.
	 *
	 * @return \WP_Post[]
	 */
	public static function get_accessible_favorites( \WP_User $user, array $user_lines, int $limit = 0 ): array {
		$ids = self::get_favorites( $user->ID );
		if ( empty( $ids ) ) {
			return [];
		}

		$docs  = [];
		$keep  = [];
		$dirty = false;

		foreach ( $ids as $post_id ) {
			$post = get_post( $post_id );

			if ( ! $post
				|| 'documento_dealer' !== $post->post_type
				|| 'publish' !== $post->post_status
				|| ! self::user_can_access_post( $user, $user_lines, $post_id ) ) {
				$dirty = true;
				continue;
			}

			$keep[] = $post_id;

			if ( ! self::user_can_download_post( $user, $user_lines, $post_id ) ) {
				continue;
			}

			$docs[] = $post;
		}

		if ( $dirty ) {
			self::set_favorites( $user->ID, $keep );
		}

		return $limit > 0 ? array_slice( $docs, 0, $limit ) : $docs;
	}

	public static function count_favorites( int $user_id ): int {
		return count( self::get_favorites( $user_id ) );
	}

	/**
	 * Aggiunge o rimuove un preferito.
	 *
	 * @return array{favorite:bool,count:int,capped:bool}
	 */
	public static function toggle_favorite( int $user_id, int $post_id, bool $add ): array {
		$current = self::get_favorites( $user_id );

		if ( $add ) {
			if ( ! in_array( $post_id, $current, true ) ) {
				if ( count( $current ) >= self::MAX_FAVORITES ) {
					// Tetto raggiunto: non si scrive nulla, l'utente riceve un avviso.
					return [ 'favorite' => false, 'count' => count( $current ), 'capped' => true ];
				}
				// Il più recente in testa: la dashboard mostra i primi N.
				array_unshift( $current, $post_id );
			}
		} else {
			$current = array_values( array_diff( $current, [ $post_id ] ) );
		}

		$current = self::set_favorites( $user_id, $current );

		return [
			'favorite' => in_array( $post_id, $current, true ),
			'count'    => count( $current ),
			'capped'   => false,
		];
	}

	/** Etichetta del pulsante preferiti nei due stati. */
	public static function favorite_label( bool $is_favorite ): string {
		return $is_favorite ? 'Nei preferiti' : 'Aggiungi ai preferiti';
	}

	/** Etichetta estesa (title / screen reader) del pulsante preferiti. */
	public static function favorite_title( bool $is_favorite ): string {
		return $is_favorite ? 'Rimuovi dai preferiti' : 'Aggiungi ai preferiti';
	}

	/**
	 * Link di fallback senza JavaScript: esegue l'azione indicata e riporta
	 * l'utente alla pagina di partenza. Nonce per documento e per azione.
	 */
	public static function get_favorite_url( int $post_id, bool $add ): string {
		$action = $add ? 'add' : 'remove';

		return add_query_arg( [
			'dealer_favorite' => $post_id,
			'fav'             => $action,
			'nonce'           => wp_create_nonce( 'dealer_favorite_' . $action . '_' . $post_id ),
		], home_url( '/' ) );
	}

	// ─── Download file ───────────────────────────────────────────────────────

	public function add_query_vars( array $vars ): array {
		$vars[] = 'dealer_download';
		$vars[] = 'dealer_favorite';
		$vars[] = 'dealer_zip';
		return $vars;
	}

	public function handle_download(): void {
		$post_id = (int) get_query_var( 'dealer_download' );
		if ( ! $post_id ) {
			return;
		}

		// Autenticazione.
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url() );
			exit;
		}

		$user = wp_get_current_user();

		// Verifica nonce (scade in 12h, impedisce link diretti permanenti).
		$nonce = sanitize_text_field( wp_unslash( $_GET['nonce'] ?? '' ) );
		if ( ! wp_verify_nonce( $nonce, 'dealer_download_' . $post_id ) ) {
			wp_die( esc_html__( 'Link di download scaduto o non valido. Torna alla ricerca e riprova.', 'dealer-portal' ), '', [ 'response' => 403 ] );
		}

		// Verifica tipo post.
		if ( get_post_type( $post_id ) !== 'documento_dealer' ) {
			wp_die( esc_html__( 'Documento non trovato.', 'dealer-portal' ), '', [ 'response' => 404 ] );
		}

		// Blocca documenti non pubblicati (draft, pending, ecc.).
		if ( get_post_status( $post_id ) !== 'publish' ) {
			wp_die( esc_html__( 'Documento non trovato.', 'dealer-portal' ), '', [ 'response' => 404 ] );
		}

		// Blocca documenti segnati come obsoleti.
		if ( get_post_meta( $post_id, '_doc_status', true ) === 'obsoleto' ) {
			wp_die( esc_html__( 'Questo documento è obsoleto e non è più disponibile per il download.', 'dealer-portal' ), '', [ 'response' => 403 ] );
		}

		// Controllo accesso doppio.
		$user_lines = self::get_user_lines( $user->ID );

		if ( ! self::can_receive_documents( $user ) || ! self::user_can_access_post( $user, $user_lines, $post_id ) ) {
			wp_die( esc_html__( 'Non hai i permessi per scaricare questo documento.', 'dealer-portal' ), '', [ 'response' => 403 ] );
		}

		// Verifica che il documento non sia scaduto.
		$expiry = get_post_meta( $post_id, '_doc_expiry', true );
		if ( $expiry && strtotime( $expiry ) < time() ) {
			wp_die(
				esc_html__( 'Questo documento è scaduto e non è più disponibile per il download.', 'dealer-portal' ),
				'',
				[ 'response' => 403 ]
			);
		}

		// Recupera il file.
		$attachment_id = (int) get_post_meta( $post_id, '_doc_file_id', true );
		if ( ! $attachment_id ) {
			wp_die( esc_html__( 'File non trovato.', 'dealer-portal' ), '', [ 'response' => 404 ] );
		}

		$file_path = get_attached_file( $attachment_id );
		if ( ! $file_path || ! file_exists( $file_path ) ) {
			wp_die( esc_html__( 'File non trovato sul server.', 'dealer-portal' ), '', [ 'response' => 404 ] );
		}

		// ── Path traversal prevention ──────────────────────────────────────────
		// Verifica che il file si trovi nella cartella uploads di WP.
		$uploads   = wp_upload_dir();
		$real_file = realpath( $file_path );
		$real_base = realpath( $uploads['basedir'] );

		if ( ! $real_file || ! $real_base || strpos( $real_file, $real_base . DIRECTORY_SEPARATOR ) !== 0 ) {
			wp_die( esc_html__( 'Accesso al file non consentito.', 'dealer-portal' ), '', [ 'response' => 403 ] );
		}

		// Registra il download nel log: vale anche per l'anteprima "Vedi", che
		// trasferisce comunque il contenuto del file al browser — è un accesso
		// al documento allo stesso titolo di un download, non un evento minore.
		Dealer_DB::log_download( $post_id );

		// Serve il file con header di sicurezza.
		$filename  = get_post_meta( $post_id, '_doc_filename', true ) ?: basename( $real_file );
		$mime_type = get_post_mime_type( $attachment_id ) ?: 'application/octet-stream';
		$file_size = filesize( $real_file );

		// "Vedi" (apertura nel browser, invece del download forzato) è concesso
		// solo per i PDF: è l'unico formato, fra quelli ammessi dalla whitelist
		// di caricamento (PDF/XLSX/DOCX), che ogni browser sa aprire da solo —
		// per gli altri, "inline" produrrebbe comunque un download o un errore.
		// Il flag arriva dal client (get_view_url()), ma la decisione resta
		// sempre server-side sul mime type reale del file: un ?inline=1
		// aggiunto a mano su un altro documento non cambia nulla.
		$wants_inline = isset( $_GET['inline'] ) && '1' === $_GET['inline'];
		$disposition  = ( $wants_inline && 'application/pdf' === $mime_type ) ? 'inline' : 'attachment';

		// Svuota tutti i buffer di output prima di inviare il file.
		while ( ob_get_level() ) {
			ob_end_clean();
		}

		header( 'Content-Type: '        . $mime_type );
		header( 'Content-Disposition: ' . $disposition . '; filename="' . rawurlencode( $filename ) . '"' );
		header( 'Content-Length: '      . $file_size );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		readfile( $real_file );
		exit;
	}

	// ─── Preferiti: fallback senza JavaScript ────────────────────────────────

	/**
	 * Gestisce il link ?dealer_favorite=ID&fav=add|remove&nonce=…
	 * Stesse difese dell'endpoint AJAX: nonce, ruolo dealer, tipo di post e
	 * accesso effettivo al documento.
	 */
	public function handle_favorite_link(): void {
		$post_id = (int) get_query_var( 'dealer_favorite' );
		if ( ! $post_id ) {
			return;
		}

		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url() );
			exit;
		}

		$action = sanitize_key( wp_unslash( $_GET['fav'] ?? '' ) );
		if ( ! in_array( $action, [ 'add', 'remove' ], true ) ) {
			wp_die( esc_html__( 'Azione non valida.', 'dealer-portal' ), '', [ 'response' => 400 ] );
		}

		$nonce = sanitize_text_field( wp_unslash( $_GET['nonce'] ?? '' ) );
		if ( ! wp_verify_nonce( $nonce, 'dealer_favorite_' . $action . '_' . $post_id ) ) {
			wp_die( esc_html__( 'Link scaduto o non valido. Torna alla ricerca e riprova.', 'dealer-portal' ), '', [ 'response' => 403 ] );
		}

		$user = wp_get_current_user();
		if ( ! self::user_is_dealer( $user ) ) {
			wp_die( esc_html__( 'Non hai i permessi per questa operazione.', 'dealer-portal' ), '', [ 'response' => 403 ] );
		}

		$user_lines = self::get_user_lines( $user->ID );

		// Aggiungere: solo documenti realmente accessibili. Rimuovere: sempre
		// consentito, così un preferito revocato può comunque essere ripulito.
		if ( 'add' === $action && ! self::user_can_download_post( $user, $user_lines, $post_id ) ) {
			wp_die( esc_html__( 'Documento non disponibile.', 'dealer-portal' ), '', [ 'response' => 403 ] );
		}

		self::toggle_favorite( $user->ID, $post_id, 'add' === $action );

		// Ritorno alla pagina di partenza, validata contro gli host consentiti.
		$referer = wp_get_referer();
		wp_safe_redirect( $referer ? $referer : home_url( '/' ) );
		exit;
	}

	// ─── Download aggregato ZIP ──────────────────────────────────────────────

	/**
	 * URL del download aggregato.
	 *
	 * Nell'URL viaggiano solo l'insieme richiesto e i filtri attivi: la lista
	 * dei documenti viene ricostruita lato server al momento della richiesta,
	 * così il client non può mai imporre quali ID finiscano nell'archivio.
	 */
	public static function get_zip_url( string $scope, array $state = [] ): string {
		if ( ! in_array( $scope, self::ZIP_SCOPES, true ) ) {
			$scope = 'results';
		}

		$args = [
			'dealer_zip' => $scope,
			'nonce'      => wp_create_nonce( 'dealer_zip_' . $scope ),
		];

		if ( 'results' === $scope && ! empty( $state ) ) {
			if ( ! empty( $state['s'] ) ) {
				$args['s'] = $state['s'];
			}
			foreach ( self::FACET_GROUPS as $group ) {
				if ( ! empty( $state[ $group ] ) ) {
					$args[ $group ] = array_values( $state[ $group ] );
				}
			}
		}

		// http_build_query e non add_query_arg: i valori dei facet contengono
		// spazi e il separatore "|" delle linee, che vanno codificati (altrimenti
		// esc_url li rimuoverebbe dall'URL).
		$base      = home_url( '/' );
		$separator = ( false === strpos( $base, '?' ) ) ? '?' : '&';

		return $base . $separator . http_build_query( $args );
	}

	/** Etichetta del bottone/nota per il download aggregato. */
	public static function zip_limit_notice(): string {
		return sprintf(
			'Il download in ZIP è limitato a %d documenti per volta: restringi i filtri.',
			self::ZIP_MAX_FILES
		);
	}

	/**
	 * Handler di ?dealer_zip=results|favorites&nonce=…
	 *
	 * Modellato su handle_download(): nonce, ruolo dealer, controllo accessi su
	 * ogni singolo documento, esclusione di scaduti e obsoleti, verifica
	 * realpath() contro la cartella uploads, tetti di sicurezza, file temporaneo
	 * sempre rimosso e log di ogni documento incluso.
	 */
	public function handle_zip_download(): void {
		$scope = sanitize_key( (string) get_query_var( 'dealer_zip' ) );
		if ( '' === $scope ) {
			return;
		}
		if ( ! in_array( $scope, self::ZIP_SCOPES, true ) ) {
			wp_die( esc_html__( 'Richiesta non valida.', 'dealer-portal' ), '', [ 'response' => 400 ] );
		}

		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url() );
			exit;
		}

		// Nonce prima di qualsiasi elaborazione.
		$nonce = sanitize_text_field( wp_unslash( $_GET['nonce'] ?? '' ) );
		if ( ! wp_verify_nonce( $nonce, 'dealer_zip_' . $scope ) ) {
			wp_die( esc_html__( 'Link di download scaduto o non valido. Torna alla ricerca e riprova.', 'dealer-portal' ), '', [ 'response' => 403 ] );
		}

		$user = wp_get_current_user();
		if ( ! self::user_is_dealer( $user ) ) {
			wp_die( esc_html__( 'Non hai i permessi per scaricare questi documenti.', 'dealer-portal' ), '', [ 'response' => 403 ] );
		}

		// Limite di frequenza. La creazione dell'archivio è di gran lunga
		// l'operazione più costosa del portale — legge fino a 200 MB dal disco,
		// li comprime e scrive un file temporaneo — e la può innescare
		// qualunque utente autenticato con un semplice link. Senza un tetto,
		// bastano poche richieste ripetute per saturare CPU e spazio su disco.
		if ( ! self::zip_rate_limit_ok( $user->ID ) ) {
			wp_die(
				esc_html__( 'Hai richiesto troppi archivi in poco tempo. Attendi qualche minuto e riprova.', 'dealer-portal' ),
				'',
				[ 'response' => 429 ]
			);
		}

		if ( ! class_exists( 'ZipArchive' ) ) {
			wp_die(
				esc_html__( 'Il download in un unico archivio non è disponibile su questo server: manca l\'estensione PHP ZipArchive. Scarica i documenti singolarmente dalla ricerca.', 'dealer-portal' ),
				'',
				[ 'response' => 501 ]
			);
		}

		$user_lines = self::get_user_lines( $user->ID );

		// Il set viene sempre ricalcolato qui, mai ricevuto dal client.
		if ( 'favorites' === $scope ) {
			$ids       = self::get_favorites( $user->ID );
			$zip_label = 'preferiti';
		} else {
			// Il nonce è già stato verificato; parse_state sanifica ogni valore.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$state     = self::parse_state( $_GET, $user_lines );
			$ids       = self::collect_filtered_ids( $user, $user_lines, $state );
			$zip_label = 'documenti';
		}

		$entries = self::collect_zip_entries( $user, $user_lines, $ids );

		if ( empty( $entries ) ) {
			wp_die( esc_html__( 'Nessun documento disponibile per il download.', 'dealer-portal' ), '', [ 'response' => 404 ] );
		}

		if ( count( $entries ) > self::ZIP_MAX_FILES ) {
			wp_die(
				esc_html( sprintf(
					/* translators: 1: numero documenti disponibili, 2: tetto massimo */
					'I documenti disponibili sono %1$d, ma il download in un unico archivio è limitato a %2$d file per volta. Restringi i filtri e riprova.',
					count( $entries ),
					self::ZIP_MAX_FILES
				) ),
				'',
				[ 'response' => 400 ]
			);
		}

		$total_bytes = 0;
		foreach ( $entries as $entry ) {
			$total_bytes += $entry['size'];
		}
		if ( $total_bytes > self::ZIP_MAX_BYTES ) {
			wp_die(
				esc_html( sprintf(
					/* translators: 1: dimensione richiesta, 2: tetto massimo */
					'I documenti selezionati occupano %1$s, oltre il limite di %2$s per un singolo archivio. Restringi i filtri e riprova.',
					size_format( $total_bytes ),
					size_format( self::ZIP_MAX_BYTES )
				) ),
				'',
				[ 'response' => 400 ]
			);
		}

		self::stream_zip( $entries, $zip_label );
	}

	/**
	 * Valida uno per uno gli ID e produce le voci pronte per l'archivio.
	 * Ogni documento ripassa dal controllo accessi e dalla verifica di path
	 * traversal: quello che non supera i controlli viene semplicemente saltato.
	 *
	 * @param int[] $ids
	 * @return array[] voci con id, path, name, size.
	 */
	/**
	 * Consente un numero limitato di archivi per utente in una finestra breve.
	 *
	 * Il contatore sta in un transient per utente: se lo storage dei transient
	 * non fosse disponibile la funzione lascia passare, perché negare un
	 * download legittimo per un problema di cache sarebbe peggio del rischio
	 * che il limite copre.
	 */
	private static function zip_rate_limit_ok( int $user_id ): bool {
		$key   = 'dealer_zip_rl_' . $user_id;
		$count = (int) get_transient( $key );

		if ( $count >= self::ZIP_RATE_LIMIT_MAX ) {
			return false;
		}

		set_transient( $key, $count + 1, self::ZIP_RATE_LIMIT_WINDOW );
		return true;
	}

	private static function collect_zip_entries( \WP_User $user, array $user_lines, array $ids ): array {
		$uploads   = wp_upload_dir();
		$real_base = realpath( $uploads['basedir'] ?? '' );
		if ( ! $real_base ) {
			return [];
		}

		$entries = [];
		$used    = [];

		foreach ( $ids as $raw_id ) {
			$post_id = absint( $raw_id );
			if ( ! $post_id ) {
				continue;
			}

			// Controllo accessi rifatto qui, al momento della creazione dell'archivio.
			if ( ! self::user_can_download_post( $user, $user_lines, $post_id ) ) {
				continue;
			}

			$attachment_id = (int) get_post_meta( $post_id, '_doc_file_id', true );
			if ( ! $attachment_id ) {
				continue;
			}

			$file_path = get_attached_file( $attachment_id );
			if ( ! $file_path || ! file_exists( $file_path ) ) {
				continue;
			}

			// ── Path traversal prevention: stesso controllo di handle_download() ──
			$real_file = realpath( $file_path );
			if ( ! $real_file || strpos( $real_file, $real_base . DIRECTORY_SEPARATOR ) !== 0 ) {
				continue;
			}

			$size = (int) filesize( $real_file );

			$entries[] = [
				'id'   => $post_id,
				'path' => $real_file,
				'name' => self::unique_zip_name( $post_id, $real_file, $used ),
				'size' => $size,
			];
		}

		// Nessun break anticipato: il numero di ID in ingresso è già limitato a
		// monte (MAX_RESULTS per la ricerca, MAX_FAVORITES per i preferiti) e il
		// chiamante deve poter dire all'utente quanti documenti ha davvero.
		return $entries;
	}

	/**
	 * Nome interno all'archivio: sanificato e deduplicato (due documenti
	 * distinti possono avere lo stesso _doc_filename).
	 *
	 * @param array $used Mappa dei nomi già assegnati, aggiornata per riferimento.
	 */
	private static function unique_zip_name( int $post_id, string $real_file, array &$used ): string {
		$name = (string) get_post_meta( $post_id, '_doc_filename', true );
		if ( '' === trim( $name ) ) {
			$name = basename( $real_file );
		}
		$name = sanitize_file_name( $name );
		if ( '' === $name ) {
			$name = 'documento-' . $post_id;
		}

		$ext  = pathinfo( $name, PATHINFO_EXTENSION );
		$stem = pathinfo( $name, PATHINFO_FILENAME );
		if ( '' === $stem ) {
			$stem = 'documento-' . $post_id;
		}
		$suffix = '' !== $ext ? '.' . $ext : '';

		$candidate = $stem . $suffix;
		$n         = 2;
		while ( isset( $used[ strtolower( $candidate ) ] ) ) {
			$candidate = $stem . '-' . $n . $suffix;
			$n++;
		}

		$used[ strtolower( $candidate ) ] = true;

		return $candidate;
	}

	/**
	 * Crea l'archivio in un file temporaneo, lo invia e lo rimuove.
	 * Il temporaneo viene cancellato in ogni caso: sia sul percorso di successo
	 * sia dal register_shutdown_function in caso di errore o fatal.
	 *
	 * @param array[] $entries
	 */
	private static function stream_zip( array $entries, string $zip_label ): void {
		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$archive_name = sprintf( 'dealer-%s-%s.zip', $zip_label, gmdate( 'Y-m-d' ) );

		$tmp = wp_tempnam( $archive_name );
		if ( ! $tmp ) {
			wp_die( esc_html__( 'Impossibile creare l\'archivio sul server. Riprova più tardi.', 'dealer-portal' ), '', [ 'response' => 500 ] );
		}

		// Rete di sicurezza: qualunque cosa accada dopo, il temporaneo sparisce.
		register_shutdown_function( static function () use ( $tmp ): void {
			if ( file_exists( $tmp ) ) {
				wp_delete_file( $tmp );
			}
		} );

		$zip = new ZipArchive();
		if ( true !== $zip->open( $tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			wp_delete_file( $tmp );
			wp_die( esc_html__( 'Impossibile creare l\'archivio sul server. Riprova più tardi.', 'dealer-portal' ), '', [ 'response' => 500 ] );
		}

		$added = [];
		foreach ( $entries as $entry ) {
			if ( $zip->addFile( $entry['path'], $entry['name'] ) ) {
				$added[] = (int) $entry['id'];
			}
		}

		if ( empty( $added ) ) {
			$zip->close();
			wp_delete_file( $tmp );
			wp_die( esc_html__( 'Nessun documento disponibile per il download.', 'dealer-portal' ), '', [ 'response' => 404 ] );
		}

		if ( ! $zip->close() || ! file_exists( $tmp ) ) {
			wp_delete_file( $tmp );
			wp_die( esc_html__( 'Impossibile creare l\'archivio sul server. Riprova più tardi.', 'dealer-portal' ), '', [ 'response' => 500 ] );
		}

		// Il log resta la fonte di verità su chi ha ottenuto cosa: una riga per
		// ogni documento effettivamente inserito nell'archivio.
		foreach ( $added as $post_id ) {
			Dealer_DB::log_download( $post_id );
		}

		$size = (int) filesize( $tmp );

		// Svuota tutti i buffer di output prima di inviare il file.
		while ( ob_get_level() ) {
			ob_end_clean();
		}

		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . rawurlencode( $archive_name ) . '"' );
		header( 'Content-Length: ' . $size );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		readfile( $tmp );

		wp_delete_file( $tmp );
		exit;
	}

	// ─── AJAX ────────────────────────────────────────────────────────────────

	/** Risposta secca per gli utenti non autenticati. */
	public static function ajax_deny(): void {
		wp_send_json_error( [ 'message' => 'Authentication required.' ], 403 );
	}

	public static function ajax_log_download(): void {
		check_ajax_referer( 'dealer_search_nonce', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( [ 'message' => 'Authentication required.' ], 403 );
		}

		// Il log è già scritto in modo affidabile da handle_download() server-side.
		// Questo endpoint AJAX è mantenuto per compatibilità con dealer-search.js.
		wp_send_json_success();
	}

	/**
	 * Ricerca a faccette live.
	 * Restituisce HTML già generato ed escapato lato PHP: il JS si limita a
	 * sostituire l'innerHTML dei contenitori, senza duplicare l'escaping.
	 */
	public static function ajax_facet_search(): void {
		check_ajax_referer( 'dealer_search_nonce', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( [ 'message' => 'Authentication required.' ], 403 );
		}

		$user = wp_get_current_user();
		if ( ! self::user_is_dealer( $user ) ) {
			wp_send_json_error( [ 'message' => 'Forbidden.' ], 403 );
		}

		$user_lines = self::get_user_lines( $user->ID );

		// Il nonce è già stato verificato sopra; parse_state sanifica ogni valore.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$state = self::parse_state( $_POST, $user_lines );

		// URL della pagina di ricerca, usato solo per i link di fallback no-JS.
		$page_url = esc_url_raw( wp_unslash( $_POST['page_url'] ?? '' ) );
		$page_url = wp_validate_redirect( $page_url, home_url( '/' ) );

		$data = self::run_search( $user, $user_lines, $state );

		wp_send_json_success( [
			'results'    => self::render_results( $data, $state, $page_url ),
			'facets'     => self::render_facets( $data['facets'], $state, $user_lines ),
			'filters'    => self::render_active_filters( $state, $page_url ),
			'count'      => (int) $data['total'],
			'countLabel' => self::count_label( (int) $data['total'] ),
			'paged'      => (int) $data['paged'],
			'totalPages' => (int) $data['total_pages'],
		] );
	}

	/**
	 * Aggiunge/rimuove un documento dai preferiti del dealer.
	 * Restituisce solo etichette e URL generati in PHP: il JS aggiorna attributi
	 * e testo del pulsante, non costruisce markup.
	 */
	public static function ajax_toggle_favorite(): void {
		check_ajax_referer( 'dealer_search_nonce', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( [ 'message' => 'Authentication required.' ], 403 );
		}

		$user = wp_get_current_user();
		if ( ! self::user_is_dealer( $user ) ) {
			wp_send_json_error( [ 'message' => 'Forbidden.' ], 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing — nonce verificato sopra.
		$post_id = absint( wp_unslash( $_POST['post_id'] ?? 0 ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing — nonce verificato sopra.
		$action = sanitize_key( wp_unslash( $_POST['fav'] ?? '' ) );

		if ( ! $post_id || ! in_array( $action, [ 'add', 'remove' ], true ) ) {
			wp_send_json_error( [ 'message' => 'Richiesta non valida.' ], 400 );
		}

		$user_lines = self::get_user_lines( $user->ID );

		// Aggiungere richiede un documento esistente, accessibile e non scaduto;
		// rimuovere è sempre concesso (serve a ripulire un preferito revocato).
		if ( 'add' === $action && ! self::user_can_download_post( $user, $user_lines, $post_id ) ) {
			wp_send_json_error( [ 'message' => 'Documento non disponibile.' ], 403 );
		}

		$result = self::toggle_favorite( $user->ID, $post_id, 'add' === $action );

		if ( ! empty( $result['capped'] ) ) {
			wp_send_json_error( [
				'message' => sprintf(
					'Hai raggiunto il limite di %d preferiti: rimuovine qualcuno prima di aggiungerne altri.',
					self::MAX_FAVORITES
				),
			], 400 );
		}

		$is_favorite = (bool) $result['favorite'];

		wp_send_json_success( [
			'favorite' => $is_favorite,
			'count'    => (int) $result['count'],
			'label'    => self::favorite_label( $is_favorite ),
			'title'    => self::favorite_title( $is_favorite ),
			'next'     => $is_favorite ? 'remove' : 'add',
			'href'     => self::get_favorite_url( $post_id, ! $is_favorite ),
		] );
	}

	// ─── Helpers statici (usati dai template) ────────────────────────────────

	public static function get_download_url( int $post_id ): string {
		return add_query_arg( [
			'dealer_download' => $post_id,
			'nonce'           => wp_create_nonce( 'dealer_download_' . $post_id ),
		], home_url( '/' ) );
	}

	/**
	 * URL di anteprima: stesso endpoint e stesso nonce del download (stessa
	 * risorsa, stesso permesso), con il solo flag "inline" che handle_download()
	 * onora unicamente se il file è davvero un PDF.
	 */
	public static function get_view_url( int $post_id ): string {
		return add_query_arg( [
			'dealer_download' => $post_id,
			'inline'          => 1,
			'nonce'           => wp_create_nonce( 'dealer_download_' . $post_id ),
		], home_url( '/' ) );
	}

	/** Il pulsante "Vedi" ha senso solo per i PDF: sono l'unico formato, fra
	 * quelli caricabili, che ogni browser apre da solo senza scaricarlo. */
	public static function is_previewable( string $filename ): bool {
		return 'pdf' === strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
	}

	/**
	 * Markup dei pulsanti di azione su un documento — "Vedi" (solo se
	 * previewable) e "Scarica" (sempre). Unico punto che li genera: gli stessi
	 * due pulsanti compaiono nella griglia di ricerca, nella dashboard, nei
	 * preferiti e nell'area di lavoro dell'area manager, e duplicare il
	 * markup in ognuno significherebbe aggiornarlo in quattro posti diversi
	 * a ogni ritocco (esattamente il tipo di divergenza che questo plugin
	 * cerca sempre di evitare sui punti che decidono un accesso).
	 *
	 * @param int    $post_id
	 * @param string $filename    Nome file originale — decide solo se mostrare
	 *                            "Vedi"; l'idoneità reale è rivalidata server-side.
	 * @param array  $args {
	 *     @type string $class      Classe dimensionale aggiuntiva (es. 'dealer-btn-sm'
	 *                              per le liste compatte della dashboard; '' per la
	 *                              dimensione piena della card di ricerca).
	 *     @type bool   $with_label Testo oltre all'icona (default false: solo icona).
	 * }
	 */
	public static function render_document_actions( int $post_id, string $filename, array $args = [] ): string {
		$args  = array_merge( [ 'class' => '', 'with_label' => false ], $args );
		$extra = '' !== $args['class'] ? ' ' . sanitize_html_class( $args['class'] ) : '';

		$html = '';

		if ( self::is_previewable( $filename ) ) {
			$html .= '<a href="' . esc_url( self::get_view_url( $post_id ) ) . '" class="dealer-btn dealer-btn-view' . $extra . '" target="_blank" rel="noopener" title="Apri nel browser">'
				. '<span class="dashicons dashicons-visibility" aria-hidden="true"></span>'
				. ( $args['with_label'] ? ' Vedi' : '' )
				. '</a>';
		}

		$html .= '<a href="' . esc_url( self::get_download_url( $post_id ) ) . '" class="dealer-btn dealer-btn-download' . $extra . '" title="Scarica">'
			. '<span class="dashicons dashicons-download" aria-hidden="true"></span>'
			. ( $args['with_label'] ? ' Scarica' : '' )
			. '</a>';

		// Avvolto in un contenitore invece di restituire i due <a> come figli
		// diretti: nei contesti con più elementi allo stesso livello (es. il
		// contatore download in .dealer-doc-foot, spaziato con
		// justify-content:space-between) due figli in più romperebbero quella
		// spaziatura. Il contenitore conta come un solo figlio, sempre.
		return '<span class="dealer-doc-actions">' . $html . '</span>';
	}

	public static function get_file_icon_svg( string $filename ): string {
		$ext = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
		switch ( $ext ) {
			case 'pdf':
				return '<svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48" width="44" height="44"><rect width="48" height="48" rx="8" fill="#e53935"/><text x="24" y="30" text-anchor="middle" fill="#fff" font-weight="700" font-size="13" font-family="sans-serif">PDF</text></svg>';
			case 'xlsx':
			case 'xls':
				return '<svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48" width="44" height="44"><rect width="48" height="48" rx="8" fill="#43a047"/><text x="24" y="30" text-anchor="middle" fill="#fff" font-weight="700" font-size="13" font-family="sans-serif">XLS</text></svg>';
			case 'docx':
			case 'doc':
				return '<svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48" width="44" height="44"><rect width="48" height="48" rx="8" fill="#1565c0"/><text x="24" y="30" text-anchor="middle" fill="#fff" font-weight="700" font-size="13" font-family="sans-serif">DOC</text></svg>';
			default:
				return '<svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48" width="44" height="44"><rect width="48" height="48" rx="8" fill="#546e7a"/><text x="24" y="30" text-anchor="middle" fill="#fff" font-weight="700" font-size="12" font-family="sans-serif">FILE</text></svg>';
		}
	}

	public static function is_new_doc( int $post_id ): bool {
		$post = get_post( $post_id );
		if ( ! $post ) { return false; }
		return ( time() - strtotime( $post->post_date_gmt ) ) < ( 30 * DAY_IN_SECONDS );
	}

	public static function is_expiring_doc( int $post_id ): bool {
		$expiry = get_post_meta( $post_id, '_doc_expiry', true );
		if ( ! $expiry ) { return false; }
		$diff = strtotime( $expiry ) - time();
		return $diff > 0 && $diff < ( 30 * DAY_IN_SECONDS );
	}

	public static function is_expired_doc( int $post_id ): bool {
		$expiry = get_post_meta( $post_id, '_doc_expiry', true );
		if ( ! $expiry ) { return false; }
		return strtotime( $expiry ) < time();
	}
}

// Gli endpoint AJAX devono esistere anche in contesto admin (admin-ajax.php),
// dove il bootstrap del plugin non istanzia Dealer_Search. Idempotente.
Dealer_Search::register_ajax();
Dealer_Search::register_render_hooks();
