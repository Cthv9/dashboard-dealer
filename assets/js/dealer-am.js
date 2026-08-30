/**
 * Dealer Portal — Area di lavoro dell'area manager (front-end).
 *
 * Due sole responsabilita':
 *   1. il wizard di caricamento (navigazione, validazione, riepilogo, invio);
 *   2. la marcatura di un documento come obsoleto dall'elenco.
 *
 * Entrambe parlano con gli endpoint AJAX gia' esistenti di Dealer_Admin
 * (dealer_save_document, dealer_get_lines, dealer_mark_obsolete): qui non c'e'
 * nessuna logica di salvataggio ne' di autorizzazione — il server rivaluta
 * capability e perimetro a ogni richiesta, e questo file non puo' in alcun modo
 * ampliare cio' che l'utente ha il diritto di fare.
 *
 * Il resto dell'area (consultazione, filtri, gestione utenti) funziona senza
 * JavaScript: questo script e' un miglioramento, non una dipendenza.
 *
 * Nessun eval(), nessun innerHTML con input utente grezzo: il testo dinamico
 * passa sempre da .text().
 */
/* global dealerAM, jQuery */
( function ( $ ) {
	'use strict';

	var CFG  = ( typeof dealerAM !== 'undefined' ) ? dealerAM : {};
	var I18N = CFG.i18n || {};

	var MAX_BYTES  = 50 * 1024 * 1024;
	var ALLOWED_EXT = [ 'pdf', 'xlsx', 'docx' ];
	var TOTAL_STEPS = 5;

	// ── Wizard: navigazione ───────────────────────────────────────────────

	function showStep( n ) {
		$( '.dealer-am-pane' ).removeClass( 'is-active' );
		$( '.dealer-am-pane[data-pane="' + n + '"]' ).addClass( 'is-active' );

		$( '.dealer-am-step' ).each( function () {
			var step = parseInt( $( this ).attr( 'data-step' ), 10 );
			$( this ).removeClass( 'is-active is-done' );
			if ( step < n ) {
				$( this ).addClass( 'is-done' );
			} else if ( step === n ) {
				$( this ).addClass( 'is-active' );
			}
		} );

		var $wrap = $( '#am-upload-form' );
		if ( $wrap.length && $wrap.offset() ) {
			$( 'html, body' ).animate( { scrollTop: $wrap.offset().top - 40 }, 180 );
		}
	}

	function clearErrors() {
		$( '#am-upload-form .dealer-am-error' ).text( '' ).prop( 'hidden', true );
	}

	function showError( field, message ) {
		$( '#am-upload-form .dealer-am-error[data-error="' + field + '"]' )
			.text( message )
			.prop( 'hidden', false );
	}

	function selectedLines() {
		return $( '#am-doc-lines input[name="doc_lines[]"]:checked' ).map( function () {
			return $( this ).val();
		} ).get();
	}

	function selectedRoles() {
		return $( '#am-upload-form input[name="doc_roles[]"]:checked' ).map( function () {
			return $( this ).val();
		} ).get();
	}

	function validateStep( n ) {
		clearErrors();
		var ok = true;

		if ( n === 1 ) {
			var input = $( '#am-doc-file' ).get( 0 );
			if ( ! input || ! input.files || ! input.files.length ) {
				showError( 'doc_file', I18N.noFile );
				return false;
			}

			var file = input.files[ 0 ];
			var ext  = ( file.name.split( '.' ).pop() || '' ).toLowerCase();

			if ( ALLOWED_EXT.indexOf( ext ) === -1 ) {
				showError( 'doc_file', I18N.badType );
				ok = false;
			}
			if ( file.size > MAX_BYTES ) {
				showError( 'doc_file', I18N.tooBig );
				ok = false;
			}
			if ( ! $( '#am-keep-name' ).prop( 'checked' ) && ! $.trim( $( '#am-custom-name' ).val() || '' ) ) {
				showError( 'doc_custom_name', I18N.noCustomName );
				ok = false;
			}
		}

		if ( n === 2 ) {
			if ( ! $( '#am-doc-brand' ).val() ) {
				showError( 'doc_brand', I18N.noBrand );
				ok = false;
			}
			if ( ! $( '#am-doc-type' ).val() ) {
				showError( 'doc_type', I18N.noType );
				ok = false;
			}
		}

		if ( n === 3 ) {
			if ( ! selectedRoles().length ) {
				showError( 'doc_roles', I18N.noRoles );
				ok = false;
			}
			// Il server rifiuta un documento senza linee (sarebbe visibile a
			// tutta la rete): meglio dirlo qui che farlo scoprire al salvataggio.
			if ( ! selectedLines().length ) {
				showError( 'doc_lines', I18N.noLines );
				ok = false;
			}
		}

		return ok;
	}

	// ── Wizard: file ──────────────────────────────────────────────────────

	function describeFile() {
		var input = $( '#am-doc-file' ).get( 0 );
		if ( ! input || ! input.files || ! input.files.length ) {
			$( '#am-selected' ).text( '' );
			return;
		}
		var file = input.files[ 0 ];
		var mb   = ( file.size / ( 1024 * 1024 ) ).toFixed( 2 );
		$( '#am-selected' ).text( file.name + ' — ' + mb + ' MB' );
	}

	// ── Wizard: brand → linee ─────────────────────────────────────────────

	function fillLines( brand ) {
		var $sel = $( '#am-doc-line' );
		$sel.empty();

		if ( ! brand ) {
			$sel.append( $( '<option>' ).val( '' ).text( I18N.selectBrand ) );
			return;
		}

		var lines = ( CFG.productLines && CFG.productLines[ brand ] ) || null;

		if ( lines ) {
			renderLines( $sel, lines );
			return;
		}

		// Fallback sull'endpoint gia' esistente: restituisce a sua volta solo le
		// linee del perimetro, quindi non e' una scorciatoia per scoprirne altre.
		$.post( CFG.ajaxUrl, {
			action: 'dealer_get_lines',
			nonce:  CFG.nonce,
			brand:  brand
		} ).done( function ( res ) {
			renderLines( $sel, ( res && res.success && res.data ) ? res.data.lines : [] );
		} ).fail( function () {
			renderLines( $sel, [] );
		} );
	}

	function renderLines( $sel, lines ) {
		$sel.empty();
		if ( ! lines || ! lines.length ) {
			$sel.append( $( '<option>' ).val( '' ).text( I18N.noLinesForBrand ) );
			return;
		}
		$sel.append( $( '<option>' ).val( '' ).text( '—' ) );
		$.each( lines, function ( i, line ) {
			$sel.append( $( '<option>' ).val( line ).text( line ) );
		} );
	}

	// ── Wizard: riepilogo ─────────────────────────────────────────────────

	function addSummary( $dl, label, value ) {
		$dl.append( $( '<dt>' ).text( label ) );
		$dl.append( $( '<dd>' ).text( value || I18N.none ) );
	}

	function buildSummary() {
		var $dl = $( '#am-summary' ).empty();

		var input = $( '#am-doc-file' ).get( 0 );
		var name  = ( input && input.files && input.files.length ) ? input.files[ 0 ].name : '';

		if ( ! $( '#am-keep-name' ).prop( 'checked' ) ) {
			var custom = $.trim( $( '#am-custom-name' ).val() || '' );
			if ( custom ) {
				name = custom + '.' + ( name.split( '.' ).pop() || '' ).toLowerCase();
			}
		}

		var $prev     = $( '#am-doc-previous' );
		var prevLabel = '';
		if ( $prev.is( 'select' ) && parseInt( $prev.val(), 10 ) > 0 ) {
			prevLabel = $.trim( $prev.find( 'option:selected' ).text() );
		}

		addSummary( $dl, 'File', name );
		addSummary( $dl, 'Brand', $( '#am-doc-brand' ).val() );
		addSummary( $dl, 'Linea prodotto', $( '#am-doc-line' ).val() );
		addSummary( $dl, 'Tipo', $.trim( $( '#am-doc-type option:selected' ).text() ) );
		addSummary( $dl, 'Anno', $( '#am-doc-year' ).val() );
		addSummary( $dl, 'Versione', $( '#am-doc-version' ).val() );
		addSummary( $dl, 'Sostituisce', prevLabel );
		addSummary( $dl, 'Livelli destinatari', selectedRoles().join( ', ' ) );
		addSummary( $dl, 'Linee abilitate', selectedLines().join( ', ' ).replace( /\|/g, ' › ' ) );
		addSummary( $dl, 'Scadenza', $( '#am-doc-expiry' ).val() );
		addSummary( $dl, 'Parole chiave', $( '#am-doc-keywords' ).val() );
	}

	// ── Wizard: messaggi ──────────────────────────────────────────────────

	function notify( type, message ) {
		$( '#am-upload-msg' )
			.removeClass( 'dealer-am-msg-success dealer-am-msg-error' )
			.addClass( 'success' === type ? 'dealer-am-msg-success' : 'dealer-am-msg-error' )
			.text( message )
			.prop( 'hidden', false );
	}

	// ── Bind ──────────────────────────────────────────────────────────────

	$( function () {

		// ── Navigazione ───────────────────────────────────────────────────
		$( document ).on( 'click', '.am-next', function () {
			var next = parseInt( $( this ).attr( 'data-next' ), 10 );
			if ( ! validateStep( next - 1 ) ) {
				return;
			}
			if ( next === TOTAL_STEPS ) {
				buildSummary();
			}
			showStep( next );
		} );

		$( document ).on( 'click', '.am-back', function () {
			showStep( parseInt( $( this ).attr( 'data-back' ), 10 ) );
		} );

		// ── File ──────────────────────────────────────────────────────────
		$( document ).on( 'change', '#am-doc-file', describeFile );

		$( document ).on( 'click keydown', '#am-drop', function ( e ) {
			if ( 'keydown' === e.type && e.key !== 'Enter' && e.key !== ' ' ) {
				return;
			}
			if ( $( e.target ).is( 'label, input' ) ) {
				return;
			}
			e.preventDefault();
			$( '#am-doc-file' ).trigger( 'click' );
		} );

		$( document ).on( 'dragover dragenter', '#am-drop', function ( e ) {
			e.preventDefault();
			$( this ).addClass( 'is-over' );
		} );

		$( document ).on( 'dragleave drop', '#am-drop', function () {
			$( this ).removeClass( 'is-over' );
		} );

		$( document ).on( 'drop', '#am-drop', function ( e ) {
			e.preventDefault();
			var dt = e.originalEvent && e.originalEvent.dataTransfer;
			if ( dt && dt.files && dt.files.length ) {
				$( '#am-doc-file' ).get( 0 ).files = dt.files;
				describeFile();
			}
		} );

		$( document ).on( 'change', '#am-keep-name', function () {
			$( '#am-rename' ).prop( 'hidden', $( this ).prop( 'checked' ) );
		} );

		// ── Brand ─────────────────────────────────────────────────────────
		$( document ).on( 'change', '#am-doc-brand', function () {
			var brand = $( this ).val();
			fillLines( brand );

			// Comodita': si preselezionano le linee del brand scelto, tutte
			// dentro il perimetro perche' sono le uniche renderizzate.
			$( '#am-doc-lines .dealer-am-brand' ).each( function () {
				var isBrand = ( $( this ).attr( 'data-brand' ) === brand );
				$( this ).find( 'input[type="checkbox"]' ).prop( 'checked', isBrand );
			} );
		} );

		// ── Invio ─────────────────────────────────────────────────────────
		$( document ).on( 'submit', '#am-upload-form', function ( e ) {
			e.preventDefault();

			var step;
			for ( step = 1; step <= 3; step++ ) {
				if ( ! validateStep( step ) ) {
					showStep( step );
					return;
				}
			}

			var $btn = $( '#am-save' );
			$btn.prop( 'disabled', true ).text( I18N.uploading );

			var data = new FormData( this );
			data.append( 'action', 'dealer_save_document' );

			$.ajax( {
				url:         CFG.ajaxUrl,
				type:        'POST',
				data:        data,
				processData: false,
				contentType: false
			} ).done( function ( res ) {
				if ( res && res.success ) {
					notify( 'success', ( res.data && res.data.message ) || I18N.saveSuccess );
					$( '#am-upload-form' ).get( 0 ).reset();
					$( '#am-selected' ).text( '' );
					$( '#am-rename' ).prop( 'hidden', true );
					fillLines( '' );
					showStep( 1 );
				} else {
					notify( 'error', ( res && res.data && res.data.message ) || I18N.saveError );
				}
			} ).fail( function ( xhr ) {
				var message = I18N.saveError;
				if ( xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message ) {
					message = xhr.responseJSON.data.message;
				}
				notify( 'error', message );
			} ).always( function () {
				$btn.prop( 'disabled', false ).text( I18N.saveLabel );
			} );
		} );

		// ── Elenco documenti: marcatura obsoleto ──────────────────────────
		$( document ).on( 'click', '.dealer-am-obsolete', function () {
			var $btn    = $( this );
			var postId  = parseInt( $btn.attr( 'data-doc' ), 10 );
			var $msg    = $( '.dealer-am-error[data-doc-msg="' + postId + '"]' );

			if ( ! postId || ! window.confirm( I18N.confirmObsolete ) ) {
				return;
			}

			send( false );

			function send( confirmChain ) {
				$btn.prop( 'disabled', true ).text( I18N.working );
				$msg.text( '' ).prop( 'hidden', true );

				$.post( CFG.ajaxUrl, {
					action:        'dealer_mark_obsolete',
					nonce:         CFG.archiveNonce,
					post_id:       postId,
					confirm_chain: confirmChain ? 1 : 0
				} ).done( function ( res ) {
					if ( res && res.success && res.data && res.data.needs_confirm ) {
						$btn.prop( 'disabled', false ).text( I18N.obsoleteLabel );
						if ( window.confirm( res.data.message ) ) {
							send( true );
						}
						return;
					}

					if ( res && res.success ) {
						// La riga sparisce dall'elenco: ricaricare e' il modo piu'
						// semplice per restare allineati a cio' che il server ha
						// deciso (una versione precedente puo' essere tornata
						// corrente).
						window.location.reload();
						return;
					}

					$btn.prop( 'disabled', false ).text( I18N.obsoleteLabel );
					$msg.text( ( res && res.data && res.data.message ) || I18N.obsoleteError )
						.prop( 'hidden', false );
				} ).fail( function () {
					$btn.prop( 'disabled', false ).text( I18N.obsoleteLabel );
					$msg.text( I18N.obsoleteError ).prop( 'hidden', false );
				} );
			}
		} );

		// Stato iniziale del wizard, quando presente.
		if ( $( '#am-upload-form' ).length ) {
			$( '#am-rename' ).prop( 'hidden', $( '#am-keep-name' ).prop( 'checked' ) );
			fillLines( $( '#am-doc-brand' ).val() );
		}
	} );

}( jQuery ) );
