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

	public function __construct() {
		add_action( 'admin_menu',             [ $this, 'register_menus' ] );
		add_action( 'admin_enqueue_scripts',  [ $this, 'enqueue_assets' ] );
		add_action( 'wp_ajax_dealer_save_document',    [ $this, 'ajax_save_document' ] );
		add_action( 'wp_ajax_dealer_get_lines',        [ $this, 'ajax_get_lines' ] );
		add_action( 'wp_ajax_dealer_mark_obsolete',    [ $this, 'ajax_mark_obsolete' ] );
		add_action( 'wp_ajax_dealer_delete_document',  [ $this, 'ajax_delete_document' ] );
		add_action( 'show_user_profile',      [ $this, 'render_user_fields' ] );
		add_action( 'edit_user_profile',      [ $this, 'render_user_fields' ] );
		add_action( 'personal_options_update',   [ $this, 'save_user_fields' ] );
		add_action( 'edit_user_profile_update',  [ $this, 'save_user_fields' ] );
	}

	// ─── Menu ────────────────────────────────────────────────────────────────

	public function register_menus(): void {
		add_menu_page(
			'Dealer Portal',
			'Dealer Portal',
			DEALER_PORTAL_CAP,
			'dealer-portal',
			[ $this, 'render_upload' ],
			'dashicons-portfolio',
			30
		);
		add_submenu_page( 'dealer-portal', 'Carica Documento',   'Carica Documento',   DEALER_PORTAL_CAP, 'dealer-portal',          [ $this, 'render_upload' ] );
		add_submenu_page( 'dealer-portal', 'Archivio Documenti', 'Archivio Documenti', DEALER_PORTAL_CAP, 'dealer-portal-archive',  [ $this, 'render_archive' ] );
		add_submenu_page( 'dealer-portal', 'Log Download',       'Log Download',       DEALER_PORTAL_CAP, 'dealer-portal-logs',     [ $this, 'render_logs' ] );
	}

	// ─── Assets ──────────────────────────────────────────────────────────────

	public function enqueue_assets( string $hook ): void {
		$plugin_hooks = [
			'toplevel_page_dealer-portal',
			'dealer-portal_page_dealer-portal-archive',
			'dealer-portal_page_dealer-portal-logs',
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
			'currentYear'  => (int) gmdate( 'Y' ),
			'productLines' => self::PRODUCT_LINES,
			'docTypes'     => self::DOC_TYPES,
			'i18n'         => [
				'confirmDelete'   => 'Eliminare definitivamente questo documento? Il file verrà cancellato dal server.',
				'confirmObsolete' => 'Segnare questo documento come obsoleto? Non sarà più visibile ai dealer.',
				'saveSuccess'     => 'Documento salvato con successo.',
				'saveError'       => 'Errore durante il salvataggio. Controlla i campi e riprova.',
				'uploading'       => 'Caricamento in corso…',
			],
		] );
	}

	// ─── Page renderers ──────────────────────────────────────────────────────

	public function render_upload(): void {
		if ( ! current_user_can( DEALER_PORTAL_CAP ) ) {
			wp_die( esc_html__( 'Accesso non consentito.', 'dealer-portal' ) );
		}
		require DEALER_PORTAL_PATH . 'templates/admin-upload.php';
	}

	public function render_archive(): void {
		if ( ! current_user_can( DEALER_PORTAL_CAP ) ) {
			wp_die( esc_html__( 'Accesso non consentito.', 'dealer-portal' ) );
		}

		// Filtri dalla GET (tutti sanitizzati).
		$filter_brand  = sanitize_text_field( wp_unslash( $_GET['filter_brand']  ?? '' ) );
		$filter_type   = sanitize_key( $_GET['filter_type']   ?? '' );
		$filter_role   = sanitize_key( $_GET['filter_role']   ?? '' );
		$filter_line   = sanitize_text_field( wp_unslash( $_GET['filter_line']   ?? '' ) );
		$filter_status = sanitize_key( $_GET['filter_status'] ?? '' );
		$paged         = max( 1, absint( $_GET['paged'] ?? 1 ) );

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

		$wp_query  = new WP_Query( [
			'post_type'      => 'documento_dealer',
			'post_status'    => $post_status,
			'posts_per_page' => 20,
			'paged'          => $paged,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'meta_query'     => $meta_query,
		] );
		$documents = $wp_query->posts;

		// Filtro per ruolo e linea in PHP (dati serializzati in meta).
		if ( $filter_role ) {
			$documents = array_values( array_filter( $documents, static function ( $post ) use ( $filter_role ) {
				$roles = get_post_meta( $post->ID, '_doc_roles', true );
				return is_array( $roles ) && in_array( $filter_role, $roles, true );
			} ) );
		}
		if ( $filter_line ) {
			$documents = array_values( array_filter( $documents, static function ( $post ) use ( $filter_line ) {
				$lines = get_post_meta( $post->ID, '_doc_lines', true );
				return is_array( $lines ) && in_array( $filter_line, $lines, true );
			} ) );
		}

		$total_pages = (int) $wp_query->max_num_pages;

		require DEALER_PORTAL_PATH . 'templates/admin-archive.php';
	}

	public function render_logs(): void {
		if ( ! current_user_can( DEALER_PORTAL_CAP ) ) {
			wp_die( esc_html__( 'Accesso non consentito.', 'dealer-portal' ) );
		}
		$logs = Dealer_DB::get_all_logs();
		require DEALER_PORTAL_PATH . 'templates/admin-logs.php';
	}

	// ─── AJAX: linee prodotto per brand ──────────────────────────────────────

	public function ajax_get_lines(): void {
		check_ajax_referer( 'dealer_upload_nonce', 'nonce' );

		if ( ! current_user_can( DEALER_PORTAL_CAP ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
		}

		$brand = sanitize_text_field( wp_unslash( $_POST['brand'] ?? '' ) );
		$lines = self::PRODUCT_LINES[ $brand ] ?? [];

		wp_send_json_success( [ 'lines' => array_values( $lines ) ] );
	}

	// ─── AJAX: salva documento ────────────────────────────────────────────────

	public function ajax_save_document(): void {
		check_ajax_referer( 'dealer_upload_nonce', 'nonce' );

		if ( ! current_user_can( DEALER_PORTAL_CAP ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
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

		// Valida linee contro la whitelist delle combinazioni Brand|Linea ammesse.
		$raw_lines = isset( $_POST['doc_lines'] ) ? (array) wp_unslash( $_POST['doc_lines'] ) : [];
		$lines     = array_values( array_intersect(
			array_map( 'sanitize_text_field', $raw_lines ),
			self::get_valid_lines()
		) );

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
			'_doc_filename'           => basename( $uploaded['file'] ),
		];
		foreach ( $meta as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}

		// Trigger reindex SearchWP (no-op se SearchWP non è attivo).
		do_action( 'searchwp\index\post', $post_id );

		wp_send_json_success( [
			'message' => 'Documento salvato con successo.',
			'post_id' => $post_id,
		] );
	}

	// ─── AJAX: segna obsoleto ─────────────────────────────────────────────────

	public function ajax_mark_obsolete(): void {
		check_ajax_referer( 'dealer_archive_nonce', 'nonce' );

		if ( ! current_user_can( DEALER_PORTAL_CAP ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
		}

		$post_id = absint( $_POST['post_id'] ?? 0 );
		if ( ! $post_id || get_post_type( $post_id ) !== 'documento_dealer' ) {
			wp_send_json_error( [ 'message' => 'Post non valido.' ] );
		}

		update_post_meta( $post_id, '_doc_status', 'obsoleto' );
		wp_update_post( [ 'ID' => $post_id, 'post_status' => 'draft' ] );

		wp_send_json_success( [ 'message' => 'Documento segnato come obsoleto.' ] );
	}

	// ─── AJAX: elimina documento ──────────────────────────────────────────────

	public function ajax_delete_document(): void {
		check_ajax_referer( 'dealer_archive_nonce', 'nonce' );

		if ( ! current_user_can( DEALER_PORTAL_CAP ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
		}

		$post_id = absint( $_POST['post_id'] ?? 0 );
		if ( ! $post_id || get_post_type( $post_id ) !== 'documento_dealer' ) {
			wp_send_json_error( [ 'message' => 'Post non valido.' ] );
		}

		$attachment_id = (int) get_post_meta( $post_id, '_doc_file_id', true );
		if ( $attachment_id ) {
			wp_delete_attachment( $attachment_id, true ); // true = force delete (non va nel cestino)
		}

		wp_delete_post( $post_id, true );

		wp_send_json_success( [ 'message' => 'Documento eliminato.' ] );
	}

	// ─── User profile: linee prodotto + referente ────────────────────────────

	public function render_user_fields( \WP_User $user ): void {
		if ( ! current_user_can( 'edit_users' ) ) {
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
}
