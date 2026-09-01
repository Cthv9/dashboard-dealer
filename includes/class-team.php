<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Delega al titolare: gestione dei collaboratori della propria organizzazione.
 *
 * Fino a ieri ogni collaboratore andava creato a mano da un amministratore del
 * portale. Qui il titolare gestisce da solo la propria squadra, ma **entro i
 * diritti che l'azienda gia' possiede**: la delega non e' una scorciatoia verso
 * poteri amministrativi, e' una finestra ristretta su un solo insieme di utenti.
 *
 * Cosa puo' fare il titolare (shortcode [dealer_team]):
 *   - vedere i collaboratori dell'azienda, funzione, linee effettive, ultimo accesso;
 *   - invitarne uno nuovo via email (nessuna password in chiaro: link di reset);
 *   - limitarlo a un sottoinsieme delle linee aziendali;
 *   - disattivarlo quando lascia l'azienda.
 *
 * REGOLE DI CONTENIMENTO — sono il motivo per cui questa classe puo' esistere:
 *
 *  1. Ogni handler ricontrolla Dealer_Identity::is_titolare(): il rendering non
 *     e' un'autorizzazione, e un collaboratore che scopre l'endpoint deve
 *     sbatterci contro comunque.
 *  2. L'ID utente bersaglio arriva dal POST, quindi non prova nulla: prima di
 *     ogni modifica passa da resolve_target(), che verifica l'appartenenza alla
 *     STESSA organizzazione del titolare.
 *  3. Le linee si scrivono solo con Dealer_Identity::set_line_limit(), che
 *     interseca gia' con le linee dell'organizzazione. Qui dentro non si scrive
 *     mai a mano _dealer_line_limit ne' _dealer_lines: sarebbe il modo di
 *     concedere piu' di quanto l'azienda possiede.
 *  4. Un invito produce SEMPRE un collaboratore (FUNCTION_COLLABORATORE
 *     costante nel codice). Se il titolare potesse nominare altri titolari la
 *     delega si propagherebbe fuori controllo: promuovere resta dell'admin.
 *  5. Il ruolo WordPress del nuovo utente deriva dal livello commerciale
 *     dell'organizzazione, filtrato contro una whitelist di tre ruoli dealer.
 *     Nessun percorso puo' produrre un administrator o un area_manager.
 *  6. Disattivare non e' eliminare: si revoca il ruolo e si toglie l'utente
 *     dall'organizzazione, l'account WordPress resta. Cancellarlo distruggerebbe
 *     lo storico dei download, cioe' la tracciabilita' per cui il portale esiste.
 *  7. Organizzazione sospesa: nessuna operazione, nemmeno in lettura operativa.
 *
 * Il criterio non e' MAI manage_options ne' DEALER_PORTAL_CAP — il titolare non
 * le ha e non deve averle. E' is_titolare() piu' l'appartenenza all'organizzazione.
 *
 * Progressive enhancement: form POST classici con pattern PRG (come
 * Dealer_Access_Request), nessun JavaScript necessario.
 */
class Dealer_Team {

	// ─── Costanti ─────────────────────────────────────────────────────────────

	/** Shortcode dell'area riservata al titolare. */
	const SHORTCODE = 'dealer_team';

	/**
	 * Tetto ai membri per organizzazione (titolare compreso).
	 * E' pur sempre un utente non amministratore che genera account WordPress.
	 */
	const MAX_MEMBERS = 15;

	/** Rate limit inviti: massimo per organizzazione nella finestra. */
	const INVITE_RATE_MAX    = 5;
	const INVITE_RATE_WINDOW = HOUR_IN_SECONDS;

	/** Azioni nonce. Quelle sul bersaglio includono l'ID nel contesto. */
	const NONCE_INVITE     = 'dealer_team_invite';
	const NONCE_LINES      = 'dealer_team_lines';
	const NONCE_DEACTIVATE = 'dealer_team_deactivate';

	/** Ruoli dealer assegnabili a un invito. Mai nulla che venga dall'input. */
	const ALLOWED_ROLES = [ 'dealer', 'top_dealer', 'part_center' ];

	/** Tracciabilita' della delega: chi ha invitato e chi ha disattivato. */
	const META_INVITED_BY     = '_dealer_invited_by';
	const META_INVITED_AT     = '_dealer_invited_at';
	const META_DEACTIVATED_BY = '_dealer_deactivated_by';
	const META_DEACTIVATED_AT = '_dealer_deactivated_at';


	// ─── Constructor ──────────────────────────────────────────────────────────

	public function __construct() {
		add_shortcode( self::SHORTCODE, [ $this, 'render' ] );

		// Front-end: gli invii si intercettano prima di qualsiasi output, cosi'
		// ogni operazione si chiude con un redirect (PRG) e un refresh non la
		// ripete.
		add_action( 'template_redirect', [ $this, 'maybe_handle_submission' ] );

		// Accesso rapido dalla dashboard senza toccarne il template: si filtra
		// l'output dello shortcode [dealer_dashboard].
		add_filter( 'do_shortcode_tag', [ $this, 'inject_dashboard_card' ], 10, 2 );
	}

	// ─── Contesto autorizzativo ───────────────────────────────────────────────

	/**
	 * Il titolare corrente e la sua organizzazione, oppure null.
	 *
	 * Unico punto in cui si stabilisce "questa richiesta puo' agire": ogni
	 * handler parte da qui, non dal fatto che la pagina si sia disegnata.
	 *
	 * @param bool $require_active Se true pretende anche che l'organizzazione
	 *                             non sia sospesa (regola: sospesa = nessuna
	 *                             operazione).
	 * @return array{user:\WP_User,org_id:int}|null
	 */
	private function titolare_context( bool $require_active = true ): ?array {
		if ( ! is_user_logged_in() ) {
			return null;
		}

		$user = wp_get_current_user();
		if ( ! $user || ! $user->ID ) {
			return null;
		}

		// is_titolare() implica gia' l'esistenza dell'organizzazione.
		if ( ! Dealer_Identity::is_titolare( $user ) ) {
			return null;
		}

		$org_id = Dealer_Identity::get_org_id( $user );
		if ( ! $org_id ) {
			return null;
		}

		if ( $require_active && ! Dealer_Organization::is_active( $org_id ) ) {
			return null;
		}

		return [ 'user' => $user, 'org_id' => $org_id ];
	}

	/**
	 * Valida l'utente bersaglio di un'operazione.
	 *
	 * Un ID arrivato dal POST non prova nulla: qui si verifica, PRIMA di
	 * qualunque modifica, che sia davvero un collaboratore di questa
	 * organizzazione e non un account che il titolare non deve poter toccare.
	 *
	 * Rifiuta: utenti inesistenti, se stesso, utenti di un'altra
	 * organizzazione (o senza organizzazione), altri titolari, amministratori,
	 * gestori del portale e area manager.
	 */
	private function resolve_target( int $target_id, \WP_User $titolare, int $org_id ): ?\WP_User {
		if ( $target_id <= 0 ) {
			return null;
		}

		$target = get_user_by( 'id', $target_id );
		if ( ! $target instanceof \WP_User ) {
			return null;
		}

		// Il titolare non amministra se stesso da qui.
		if ( (int) $target->ID === (int) $titolare->ID ) {
			return null;
		}

		// Appartenenza: il cuore della regola. get_org_id() ritorna 0 se
		// l'organizzazione non esiste piu', quindi il confronto e' sempre
		// contro un ID reale.
		if ( Dealer_Identity::get_org_id( $target ) !== $org_id ) {
			return null;
		}

		// Un altro titolare non e' gestibile: solo l'amministratore promuove e
		// declassa.
		if ( Dealer_Identity::is_titolare( $target ) ) {
			return null;
		}

		// Account privilegiati: mai, nemmeno se per qualche ragione risultassero
		// assegnati all'organizzazione.
		if ( user_can( $target, 'manage_options' )
			|| user_can( $target, DEALER_PORTAL_CAP )
			|| Dealer_Identity::is_area_manager( $target ) ) {
			return null;
		}

		return $target;
	}

	// ─── Front-end: rendering ─────────────────────────────────────────────────

	/**
	 * Rende l'area di gestione squadra. Il rendering non autorizza nulla: e'
	 * solo la faccia visibile dei controlli che ogni handler rifa' da capo.
	 */
	public function render( $atts ): string {
		$atts = shortcode_atts( [
			'titolo' => 'Gestione collaboratori',
		], is_array( $atts ) ? $atts : [], self::SHORTCODE );

		$title    = (string) $atts['titolo'];
		$feedback = $this->consume_feedback();

		if ( ! is_user_logged_in() ) {
			return $this->notice(
				'Per accedere a quest’area devi prima effettuare l’accesso.',
				wp_login_url( $this->current_url() ),
				'Accedi'
			);
		}

		// Contesto senza pretendere l'organizzazione attiva: cosi' possiamo
		// distinguere "non sei titolare" da "azienda sospesa" e dare al titolare
		// un messaggio comprensibile invece di un muro.
		$context = $this->titolare_context( false );
		if ( null === $context ) {
			// L'amministratore non è mai titolare di un'organizzazione: a
			// differenza della dashboard dealer, qui non c'è un'anteprima
			// sensata da mostrargli (non esiste un'azienda su cui appoggiarla).
			// Un muro generico però non gli direbbe come verificare davvero
			// questa pagina prima di consegnare il plugin: glielo si dice.
			if ( current_user_can( 'manage_options' ) ) {
				return $this->notice(
					'Sei amministratore: questa pagina è riservata al titolare di un\'azienda dealer, '
					. 'e per te non c\'è nulla da mostrare in anteprima perché il tuo account non appartiene '
					. 'a nessuna organizzazione. Per collaudarla, assegna temporaneamente al tuo utente la '
					. 'funzione "titolare" su un\'organizzazione (Dealer Portal → Organizzazioni → Utenti): '
					. 'restando amministratore, potrai comunque tornare in wp-admin in qualsiasi momento.'
				);
			}
			return $this->notice( 'Quest’area è riservata al titolare dell’azienda.' );
		}

		$user   = $context['user'];
		$org_id = $context['org_id'];

		$suspended = ! Dealer_Organization::is_active( $org_id );

		$org_name  = Dealer_Organization::get_name( $org_id );
		$org_tier  = Dealer_Organization::get_tier( $org_id );
		$org_lines = Dealer_Organization::get_effective_lines( $org_id );

		$members       = $this->collect_members( $org_id, $user );
		$members_count = count( $members );
		$can_invite    = ! $suspended && $members_count < self::MAX_MEMBERS;

		$lines_by_brand = self::group_lines( $org_lines );
		$form_action    = $this->current_url();
		$tier_label     = Dealer_Roles::labels()[ $org_tier ] ?? $org_tier;
		$max_members    = self::MAX_MEMBERS;

		ob_start();
		require DEALER_PORTAL_PATH . 'templates/dealer-team.php';
		return (string) ob_get_clean();
	}

	/**
	 * Righe della tabella squadra, gia' normalizzate per il template.
	 * Il titolare compare per primo, poi i collaboratori in ordine alfabetico.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function collect_members( int $org_id, \WP_User $titolare ): array {
		$rows = [];

		foreach ( Dealer_Organization::get_users( $org_id ) as $member ) {
			if ( ! $member instanceof \WP_User ) {
				continue;
			}

			$is_titolare = Dealer_Identity::is_titolare( $member );
			$limit       = get_user_meta( $member->ID, Dealer_Identity::META_LINE_LIMIT, true );
			$limit       = is_array( $limit ) ? array_values( array_filter( $limit ) ) : [];
			$last_login  = (string) get_user_meta( $member->ID, '_dealer_last_login', true );

			// Gestibile = collaboratore di questa azienda, non privilegiato,
			// non se stesso. Stessa regola dell'handler, cosi' l'interfaccia
			// non propone pulsanti che il server rifiuterebbe.
			$manageable = null !== $this->resolve_target( (int) $member->ID, $titolare, $org_id );

			$rows[] = [
				'id'          => (int) $member->ID,
				'name'        => (string) $member->display_name,
				'email'       => (string) $member->user_email,
				'is_titolare' => $is_titolare,
				'function'    => $is_titolare ? 'Titolare' : 'Collaboratore',
				'lines'       => Dealer_Identity::get_effective_lines( $member ),
				'limit'       => $limit,
				'restricted'  => ! empty( $limit ),
				'last_login'  => $last_login,
				'manageable'  => $manageable,
				'invited_at'  => (string) get_user_meta( $member->ID, self::META_INVITED_AT, true ),
			];
		}

		usort( $rows, static function ( array $a, array $b ): int {
			if ( $a['is_titolare'] !== $b['is_titolare'] ) {
				return $a['is_titolare'] ? -1 : 1;
			}
			return strcasecmp( $a['name'], $b['name'] );
		} );

		return $rows;
	}

	// ─── Front-end: gestione invii (PRG) ──────────────────────────────────────

	/**
	 * Intercetta i POST dell'area squadra. Nessun output: si conclude sempre
	 * con un redirect.
	 *
	 * Ordine dei controlli — deliberato: nonce, poi autorizzazione, poi merito.
	 * Il nonce da solo non e' un permesso (un collaboratore ne ottiene uno
	 * valido semplicemente caricando una pagina), quindi is_titolare() viene
	 * ricontrollato qui e non si fida di nulla che arrivi dal client.
	 */
	public function maybe_handle_submission(): void {
		if ( empty( $_POST['dt_action'] ) ) {
			return;
		}

		$action = sanitize_key( self::post_string( 'dt_action' ) );
		if ( ! in_array( $action, [ 'invite', 'set_lines', 'deactivate' ], true ) ) {
			return;
		}

		$redirect = $this->current_url();

		// Autorizzazione: titolare + organizzazione esistente + non sospesa.
		$context = $this->titolare_context( true );
		if ( null === $context ) {
			// Messaggio unico: non diciamo a un collaboratore se l'endpoint
			// esiste, se l'azienda e' sospesa o se gli manca la funzione.
			$this->redirect_with_feedback( $redirect, 'error', 'Operazione non consentita.' );
		}

		$user   = $context['user'];
		$org_id = $context['org_id'];

		switch ( $action ) {
			case 'invite':
				$this->handle_invite( $user, $org_id, $redirect );
				break;
			case 'set_lines':
				$this->handle_set_lines( $user, $org_id, $redirect );
				break;
			case 'deactivate':
				$this->handle_deactivate( $user, $org_id, $redirect );
				break;
		}
	}

	// ─── Azione: invito ───────────────────────────────────────────────────────

	/**
	 * Crea un collaboratore e gli manda il link per impostare la password.
	 * Nessuna password in chiaro viene generata, mostrata o spedita.
	 */
	private function handle_invite( \WP_User $titolare, int $org_id, string $redirect ): void {
		// Ricontrollo difensivo: nessun handler si fida di essere stato
		// raggiunto solo dal dispatcher. Se domani qualcuno lo richiamasse da
		// un altro punto, il controllo e' comunque qui.
		if ( ! Dealer_Identity::is_titolare( $titolare )
			|| Dealer_Identity::get_org_id( $titolare ) !== $org_id
			|| ! Dealer_Organization::is_active( $org_id ) ) {
			$this->redirect_with_feedback( $redirect, 'error', 'Operazione non consentita.' );
		}

		if ( ! wp_verify_nonce( self::post_string( 'dt_nonce' ), self::NONCE_INVITE ) ) {
			$this->redirect_with_feedback( $redirect, 'error', 'La sessione del modulo è scaduta. Ricarica la pagina e riprova.' );
		}

		// Tetto ai membri: il titolare non e' un canale illimitato di account.
		if ( count( Dealer_Organization::get_users( $org_id ) ) >= self::MAX_MEMBERS ) {
			$this->redirect_with_feedback(
				$redirect,
				'error',
				sprintf( 'Hai raggiunto il numero massimo di %d membri per l’azienda. Per aggiungerne altri contatta il tuo referente.', self::MAX_MEMBERS )
			);
		}

		// Rate limit: un invito e' un'email e un account, entrambi generati da
		// un utente non amministratore.
		if ( $this->invite_rate_exceeded( $org_id ) ) {
			$this->redirect_with_feedback(
				$redirect,
				'error',
				'Hai inviato diversi inviti nell’ultima ora. Riprova più tardi.'
			);
		}

		$email = sanitize_email( self::post_string( 'dt_email' ) );
		$name  = sanitize_text_field( self::post_string( 'dt_name' ) );

		if ( '' === $email || ! is_email( $email ) ) {
			$this->redirect_with_feedback( $redirect, 'error', 'Indirizzo email non valido.' );
		}
		if ( '' === trim( $name ) ) {
			$this->redirect_with_feedback( $redirect, 'error', 'Indica il nome del collaboratore.' );
		}

		// Account gia' esistente: non lo tocchiamo. Modificare ruolo o
		// organizzazione di un utente gia' a sistema (potenzialmente di
		// un'altra azienda, o un amministratore) sarebbe il buco piu' grosso
		// di tutto il modulo.
		if ( email_exists( $email ) ) {
			$this->redirect_with_feedback(
				$redirect,
				'error',
				'Esiste già un account con questa email. Per collegarlo alla tua azienda scrivi al tuo referente.'
			);
		}

		// Ruolo: deriva dal livello commerciale dell'AZIENDA, non dall'input, e
		// passa comunque da una whitelist di tre ruoli dealer.
		$role = Dealer_Organization::get_tier( $org_id );
		if ( ! in_array( $role, Dealer_Roles::dealer_slugs(), true ) ) {
			$role = 'dealer';
		}

		$user_id = wp_insert_user( [
			'user_login'   => $this->generate_login( $email ),
			'user_email'   => $email,
			// Password casuale mai comunicata: si imposta dal link di reset.
			'user_pass'    => wp_generate_password( 32, true, true ),
			'display_name' => $name,
			'nickname'     => $name,
			'first_name'   => $name,
			'role'         => $role,
		] );

		if ( is_wp_error( $user_id ) || ! $user_id ) {
			$this->redirect_with_feedback( $redirect, 'error', 'Non è stato possibile creare l’utente. Riprova più tardi.' );
		}
		$user_id = (int) $user_id;

		// Sempre collaboratore: la costante e' scritta qui, non arriva dal POST.
		// Un titolare che potesse nominare titolari renderebbe la delega
		// irreversibile dal punto di vista dell'amministratore.
		Dealer_Identity::set_org( $user_id, $org_id, Dealer_Identity::FUNCTION_COLLABORATORE );

		// Restrizione iniziale facoltativa: set_line_limit() interseca gia' con
		// le linee dell'organizzazione, quindi non c'e' modo di concedere di
		// piu' di quanto l'azienda abbia.
		Dealer_Identity::set_line_limit( $user_id, $this->posted_lines() );

		update_user_meta( $user_id, self::META_INVITED_BY, (int) $titolare->ID );
		update_user_meta( $user_id, self::META_INVITED_AT, current_time( 'mysql' ) );
		update_user_meta( $user_id, '_referente_nome',  $name );
		update_user_meta( $user_id, '_referente_email', $email );

		$this->invite_rate_bump( $org_id );

		$sent = $this->send_password_setup_email( $user_id, $org_id, $titolare );

		/**
		 * Un titolare ha aggiunto un collaboratore alla propria organizzazione.
		 *
		 * @param int $user_id     Nuovo utente.
		 * @param int $org_id      Organizzazione.
		 * @param int $titolare_id Titolare che ha invitato.
		 */
		do_action( 'dealer_team_member_invited', $user_id, $org_id, (int) $titolare->ID );

		$this->redirect_with_feedback(
			$redirect,
			'success',
			$sent
				? sprintf( 'Invito inviato a %s: riceverà un’email per impostare la password.', $email )
				: sprintf( 'Collaboratore creato, ma l’email a %s non è partita. Contatta il tuo referente.', $email )
		);
	}

	// ─── Azione: limitazione delle linee ──────────────────────────────────────

	/**
	 * Restringe un collaboratore a un sottoinsieme delle linee aziendali.
	 * Nessuna casella selezionata = nessuna restrizione (vede tutte le linee
	 * dell'azienda), che e' esattamente la semantica di get_effective_lines().
	 */
	private function handle_set_lines( \WP_User $titolare, int $org_id, string $redirect ): void {
		// Ricontrollo difensivo: nessun handler si fida di essere stato
		// raggiunto solo dal dispatcher. Se domani qualcuno lo richiamasse da
		// un altro punto, il controllo e' comunque qui.
		if ( ! Dealer_Identity::is_titolare( $titolare )
			|| Dealer_Identity::get_org_id( $titolare ) !== $org_id
			|| ! Dealer_Organization::is_active( $org_id ) ) {
			$this->redirect_with_feedback( $redirect, 'error', 'Operazione non consentita.' );
		}

		$target_id = absint( self::post_string( 'dt_user' ) );

		if ( ! wp_verify_nonce( self::post_string( 'dt_nonce' ), self::NONCE_LINES . '_' . $target_id ) ) {
			$this->redirect_with_feedback( $redirect, 'error', 'La sessione del modulo è scaduta. Ricarica la pagina e riprova.' );
		}

		$target = $this->resolve_target( $target_id, $titolare, $org_id );
		if ( null === $target ) {
			$this->redirect_with_feedback( $redirect, 'error', 'Collaboratore non valido.' );
		}

		// Unico canale di scrittura ammesso: filtra da solo contro le linee
		// dell'organizzazione, quindi una linea non posseduta dall'azienda
		// sparisce anche se arriva dal POST.
		Dealer_Identity::set_line_limit( (int) $target->ID, $this->posted_lines() );

		$effective = Dealer_Identity::get_effective_lines( $target );

		do_action( 'dealer_team_member_limited', (int) $target->ID, $org_id, (int) $titolare->ID );

		$this->redirect_with_feedback(
			$redirect,
			'success',
			sprintf(
				'Linee aggiornate per %s: %d linee accessibili.',
				$target->display_name,
				count( $effective )
			)
		);
	}

	// ─── Azione: disattivazione ───────────────────────────────────────────────

	/**
	 * Disattiva un collaboratore che ha lasciato l'azienda.
	 *
	 * NON cancella l'account WordPress: il registro dei download e' legato allo
	 * user ID e la tracciabilita' e' il motivo per cui questo portale esiste.
	 * Si toglie l'utente dall'organizzazione e gli si revoca il ruolo dealer,
	 * che e' il cancello vero (Dealer_Search::user_is_dealer()): senza ruolo
	 * dealer non passa ne' dalla ricerca ne' dal download, indipendentemente
	 * da qualunque meta residuo.
	 */
	private function handle_deactivate( \WP_User $titolare, int $org_id, string $redirect ): void {
		// Ricontrollo difensivo: nessun handler si fida di essere stato
		// raggiunto solo dal dispatcher. Se domani qualcuno lo richiamasse da
		// un altro punto, il controllo e' comunque qui.
		if ( ! Dealer_Identity::is_titolare( $titolare )
			|| Dealer_Identity::get_org_id( $titolare ) !== $org_id
			|| ! Dealer_Organization::is_active( $org_id ) ) {
			$this->redirect_with_feedback( $redirect, 'error', 'Operazione non consentita.' );
		}

		$target_id = absint( self::post_string( 'dt_user' ) );

		if ( ! wp_verify_nonce( self::post_string( 'dt_nonce' ), self::NONCE_DEACTIVATE . '_' . $target_id ) ) {
			$this->redirect_with_feedback( $redirect, 'error', 'La sessione del modulo è scaduta. Ricarica la pagina e riprova.' );
		}

		$target = $this->resolve_target( $target_id, $titolare, $org_id );
		if ( null === $target ) {
			$this->redirect_with_feedback( $redirect, 'error', 'Collaboratore non valido.' );
		}

		$name = (string) $target->display_name;

		// 1. Fuori dall'organizzazione: cadono con essa linee e funzione.
		delete_user_meta( $target->ID, Dealer_Identity::META_ORG );
		delete_user_meta( $target->ID, Dealer_Identity::META_FUNCTION );
		delete_user_meta( $target->ID, Dealer_Identity::META_LINE_LIMIT );

		// 2. Revoca dei soli ruoli dealer: l'account resta, con i suoi log.
		//    Rimuoviamo un ruolo alla volta invece di azzerare tutto, per non
		//    toccare eventuali ruoli non nostri presenti sull'utente.
		foreach ( Dealer_Roles::dealer_slugs() as $role ) {
			if ( in_array( $role, (array) $target->roles, true ) ) {
				$target->remove_role( $role );
			}
		}

		// 3. Traccia della disattivazione, per l'amministratore.
		update_user_meta( $target->ID, self::META_DEACTIVATED_BY, (int) $titolare->ID );
		update_user_meta( $target->ID, self::META_DEACTIVATED_AT, current_time( 'mysql' ) );

		/**
		 * Collaboratore disattivato dal titolare. L'account WordPress esiste
		 * ancora: chi si aggancia qui non dia per scontato il contrario.
		 */
		do_action( 'dealer_team_member_deactivated', (int) $target->ID, $org_id, (int) $titolare->ID );

		$this->redirect_with_feedback(
			$redirect,
			'success',
			sprintf( '%s non ha più accesso all’area riservata. Lo storico dei suoi download resta consultabile.', $name )
		);
	}

	// ─── Rate limit inviti ────────────────────────────────────────────────────

	private function invite_rate_key( int $org_id ): string {
		return 'dt_inv_rl_' . $org_id;
	}

	private function invite_rate_exceeded( int $org_id ): bool {
		return (int) get_transient( $this->invite_rate_key( $org_id ) ) >= self::INVITE_RATE_MAX;
	}

	private function invite_rate_bump( int $org_id ): void {
		$key   = $this->invite_rate_key( $org_id );
		$count = (int) get_transient( $key );
		// Finestra scorrevole: ogni invito accettato rinnova la TTL.
		set_transient( $key, $count + 1, self::INVITE_RATE_WINDOW );
	}

	// ─── Email di invito ──────────────────────────────────────────────────────

	/**
	 * Link per impostare la password con il meccanismo nativo (chiave di reset),
	 * stesso pattern di Dealer_Access_Request. Nessuna password in chiaro.
	 */
	private function send_password_setup_email( int $user_id, int $org_id, \WP_User $titolare ): bool {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return false;
		}

		$key = get_password_reset_key( $user );
		if ( is_wp_error( $key ) ) {
			// Fallback sul meccanismo nativo di WordPress (anch'esso a chiave
			// di reset, mai una password in chiaro).
			wp_new_user_notification( $user_id, null, 'user' );
			return true;
		}

		$reset_url = network_site_url(
			'wp-login.php?action=rp&key=' . rawurlencode( $key ) . '&login=' . rawurlencode( $user->user_login ),
			'login'
		);

		$site_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$dashboard = Dealer_DB::dashboard_url();
		$org_name  = Dealer_Organization::get_name( $org_id );
		$lines     = Dealer_Identity::get_effective_lines( $user );
		$line_list = $lines ? implode( "\n  - ", array_map( [ __CLASS__, 'line_label' ], $lines ) ) : '—';

		$subject = sprintf( '[%s] Sei stato aggiunto all’area riservata', $site_name );

		$body  = 'Ciao ' . $user->display_name . ",\n\n";
		$body .= $titolare->display_name . ' ti ha aggiunto all’area riservata di ' . $site_name;
		$body .= '' !== $org_name ? ' per conto di ' . $org_name . ".\n\n" : ".\n\n";
		$body .= 'Nome utente: ' . $user->user_login . "\n\n";
		$body .= "Imposta la tua password da questo link (valido per un tempo limitato):\n";
		$body .= $reset_url . "\n\n";
		$body .= "Dopo aver impostato la password potrai accedere qui:\n" . $dashboard . "\n\n";
		$body .= "Linee prodotto abilitate:\n  - " . $line_list . "\n\n";
		$body .= "Se non ti aspettavi questa email, ignorala.\n";

		if ( wp_mail( $user->user_email, $subject, $body ) ) {
			return true;
		}

		wp_new_user_notification( $user_id, null, 'user' );
		return false;
	}

	/** Username univoco derivato dall'email, come in Dealer_Access_Request. */
	private function generate_login( string $email ): string {
		$parts = explode( '@', $email );
		$base  = sanitize_user( $parts[0], true );
		$base  = trim( preg_replace( '/[^A-Za-z0-9._\-]/', '', $base ) ?? '' );
		if ( '' === $base ) {
			$base = 'dealer';
		}
		$base  = substr( $base, 0, 40 );
		$login = $base;
		$i     = 1;
		while ( username_exists( $login ) && $i < 1000 ) {
			$login = $base . $i;
			$i++;
		}
		return $login;
	}

	// ─── PRG: feedback via transient monouso ──────────────────────────────────

	/**
	 * Salva il feedback in un transient e redirige: in query string finisce solo
	 * un token opaco, mai un'email o un ID.
	 */
	private function redirect_with_feedback( string $url, string $status, string $message ): void {
		$token = md5( wp_generate_password( 32, true, true ) . microtime( true ) );
		set_transient( 'dt_fb_' . $token, [
			'status'  => ( 'success' === $status ) ? 'success' : 'error',
			'message' => $message,
		], 10 * MINUTE_IN_SECONDS );

		wp_safe_redirect( add_query_arg( 'dt_ref', $token, $url ) . '#dealer-team' );
		exit;
	}

	/** Legge e cancella il feedback (monouso). */
	private function consume_feedback(): array {
		$token = sanitize_key( self::get_string( 'dt_ref' ) );
		if ( '' === $token ) {
			return [];
		}
		$feedback = get_transient( 'dt_fb_' . $token );
		delete_transient( 'dt_fb_' . $token );

		return is_array( $feedback ) ? $feedback : [];
	}

	/** URL corrente senza il token gia' consumato. */
	private function current_url(): string {
		$permalink = is_singular() ? get_permalink() : '';

		if ( ! $permalink ) {
			$scheme = is_ssl() ? 'https://' : 'http://';
			$host   = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
			$uri    = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
			$permalink = '' !== $host ? esc_url_raw( $scheme . $host . $uri ) : home_url( '/' );
		}

		return remove_query_arg( 'dt_ref', $permalink );
	}

	// ─── Integrazione dashboard ───────────────────────────────────────────────

	/**
	 * Accesso rapido all'area squadra dalla dashboard dealer, SOLO per il
	 * titolare.
	 *
	 * Il template della dashboard non e' di questo modulo e non va modificato:
	 * ci si aggancia al filtro core `do_shortcode_tag`, che permette di
	 * accodare markup all'output di [dealer_dashboard] senza toccarne una riga.
	 */
	public function inject_dashboard_card( $output, $tag ) {
		if ( 'dealer_dashboard' !== $tag ) {
			return $output;
		}

		$context = $this->titolare_context( true );
		if ( null === $context ) {
			return $output;
		}

		$url = self::team_page_url();
		if ( '' === $url ) {
			return $output;
		}

		$members = count( Dealer_Organization::get_users( $context['org_id'] ) );

		$card  = '<div class="dealer-team-quicklink">';
		$card .= '<div class="dealer-team-quicklink-body">';
		$card .= '<span class="dealer-team-quicklink-title">Gestione collaboratori</span>';
		$card .= '<span class="dealer-team-quicklink-text">Invita, limita alle linee di competenza o disattiva i collaboratori della tua azienda. '
			. esc_html( sprintf( '%d membri attivi.', $members ) ) . '</span>';
		$card .= '</div>';
		$card .= '<a class="dealer-team-quicklink-btn" href="' . esc_url( $url ) . '">Gestisci la squadra</a>';
		$card .= '</div>';
		$card .= '<style>'
			. '.dealer-team-quicklink{display:flex;flex-wrap:wrap;gap:16px;align-items:center;justify-content:space-between;'
			. 'margin:24px 0;padding:18px 22px;background:#fff;border:1px solid #dce5ea;border-left:4px solid #1e6fa8;'
			. 'border-radius:8px;box-shadow:0 4px 14px rgba(0,0,0,.07);}'
			. '.dealer-team-quicklink-body{display:flex;flex-direction:column;gap:4px;min-width:240px;flex:1;}'
			. '.dealer-team-quicklink-title{font-weight:700;color:#0a1628;font-size:1.02rem;}'
			. '.dealer-team-quicklink-text{color:#52616b;font-size:.9rem;}'
			. '.dealer-team-quicklink-btn{display:inline-block;padding:10px 18px;background:#1e6fa8;color:#fff;'
			. 'text-decoration:none;border-radius:6px;font-weight:600;font-size:.92rem;}'
			. '.dealer-team-quicklink-btn:hover{background:#155c91;color:#fff;}'
			. '</style>';

		return $output . $card;
	}

	/**
	 * URL della pagina che ospita [dealer_team].
	 *
	 * Si cerca la pagina che contiene lo shortcode e si tiene il risultato in
	 * cache; un filtro permette di forzarla quando l'installazione non segue la
	 * convenzione. Stringa vuota se non esiste: in quel caso la dashboard non
	 * mostra nessun collegamento rotto.
	 */
	public static function team_page_url(): string {
		$forced = (string) apply_filters( 'dealer_team_page_url', '' );
		if ( '' !== $forced ) {
			return $forced;
		}

		$cached = get_transient( 'dealer_team_page_url' );
		if ( false !== $cached ) {
			return (string) $cached;
		}

		$url   = '';
		$pages = get_posts( [
			'post_type'      => 'page',
			'post_status'    => 'publish',
			'numberposts'    => 20,
			's'              => '[' . self::SHORTCODE,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		] );

		foreach ( $pages as $page_id ) {
			$page = get_post( $page_id );
			if ( $page && has_shortcode( (string) $page->post_content, self::SHORTCODE ) ) {
				$url = (string) get_permalink( $page_id );
				break;
			}
		}

		set_transient( 'dealer_team_page_url', $url, HOUR_IN_SECONDS );
		return $url;
	}

	// ─── Utility ──────────────────────────────────────────────────────────────

	/** Etichetta leggibile di una linea "Brand|Linea". */
	public static function line_label( string $line ): string {
		return str_replace( '|', ' › ', $line );
	}

	/**
	 * Linee "Brand|Linea" raggruppate per marchio, per le checkbox.
	 *
	 * @param string[] $lines
	 * @return array<string,string[]>
	 */
	public static function group_lines( array $lines ): array {
		$grouped = [];
		foreach ( $lines as $line ) {
			$parts = explode( '|', (string) $line, 2 );
			if ( 2 !== count( $parts ) ) {
				continue;
			}
			$grouped[ $parts[0] ][] = $parts[1];
		}
		ksort( $grouped );
		return $grouped;
	}

	/**
	 * Linee arrivate dal POST, solo sanificate.
	 *
	 * NON e' un'autorizzazione: il filtro contro le linee dell'organizzazione lo
	 * fa Dealer_Identity::set_line_limit(), unico punto di verita'. Qui si
	 * normalizza soltanto la forma dei dati.
	 *
	 * @return string[]
	 */
	private function posted_lines(): array {
		if ( ! isset( $_POST['dt_lines'] ) || ! is_array( $_POST['dt_lines'] ) ) {
			return [];
		}
		$raw = (array) wp_unslash( $_POST['dt_lines'] );
		return array_values( array_filter( array_map( 'sanitize_text_field', $raw ) ) );
	}

	private static function post_string( string $key ): string {
		return isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] )
			? (string) wp_unslash( $_POST[ $key ] )
			: '';
	}

	private static function get_string( string $key ): string {
		return isset( $_GET[ $key ] ) && is_scalar( $_GET[ $key ] )
			? (string) wp_unslash( $_GET[ $key ] )
			: '';
	}

	/** Avviso semplice, con eventuale collegamento. */
	private function notice( string $message, string $url = '', string $label = '' ): string {
		$html = '<div class="dealer-team-notice">' . esc_html( $message );
		if ( '' !== $url && '' !== $label ) {
			$html .= ' <a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
		}
		$html .= '</div>';
		$html .= '<style>.dealer-team-notice{margin:24px 0;padding:16px 20px;background:#f4f6f8;border:1px solid #dce5ea;'
			. 'border-left:4px solid #1e6fa8;border-radius:8px;color:#1a2535;}</style>';

		return $html;
	}

	/** Data leggibile, con trattino se assente. */
	public static function format_datetime( string $mysql_date ): string {
		if ( '' === trim( $mysql_date ) ) {
			return '—';
		}
		$ts = strtotime( $mysql_date );
		return $ts ? gmdate( 'd/m/Y \a\l\l\e H:i', $ts ) : '—';
	}
}
