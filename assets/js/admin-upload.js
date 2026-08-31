/**
 * Dealer Portal — Admin Upload Wizard
 * Gestisce: navigazione step, live preview nome file, AJAX linee prodotto,
 * costruzione riepilogo, invio form via AJAX.
 *
 * Non usa eval(), innerHTML con input utente grezzo, o costruzione URL manuale.
 */
/* global dealerAdmin, jQuery */
( function ( $ ) {
	'use strict';

	var currentStep = 1;
	var totalSteps  = 5;

	// ── Navigazione step ──────────────────────────────────────────────────

	function showStep( n ) {
		$( '.dealer-step' ).removeClass( 'active completed' ).attr( 'aria-current', 'false' );
		$( '.dealer-panel' ).removeClass( 'active' );

		for ( var i = 1; i < n; i++ ) {
			$( '.dealer-step[data-step="' + i + '"]' ).addClass( 'completed' );
		}
		$( '.dealer-step[data-step="' + n + '"]' ).addClass( 'active' ).attr( 'aria-current', 'step' );
		$( '.dealer-panel[data-panel="' + n + '"]' ).addClass( 'active' );

		currentStep = n;
		$( 'html, body' ).animate( { scrollTop: $( '.dealer-steps-bar' ).offset().top - 60 }, 200 );
	}

	// ── Validazione per step ──────────────────────────────────────────────

	function validateStep( n ) {
		var ok = true;
		clearErrors();

		if ( n === 1 ) {
			var file = $( '#doc_file' ).get( 0 );
			if ( ! file || ! file.files || ! file.files.length ) {
				showError( '#doc_file', 'Seleziona un file prima di continuare.' );
				ok = false;
			}
			if ( ! $( '#keep_original_name' ).is( ':checked' ) ) {
				var customName = $.trim( $( '#doc_custom_name' ).val() );
				if ( ! customName ) {
					showError( '#doc_custom_name', 'Inserisci il nome del file oppure attiva "Mantieni nome originale".' );
					ok = false;
				}
			}
		}

		if ( n === 2 ) {
			if ( ! $( '#doc_brand' ).val() ) {
				showError( '#doc_brand', 'Seleziona un brand.' );
				ok = false;
			}
			if ( ! $( '#doc_type' ).val() ) {
				showError( '#doc_type', 'Seleziona il tipo di documento.' );
				ok = false;
			}
		}

		if ( n === 3 ) {
			if ( ! $( 'input[name="doc_roles[]"]:checked' ).length ) {
				showError( '#doc_roles_group', 'Seleziona almeno un ruolo.' );
				ok = false;
			}
		}

		return ok;
	}

	function showError( selector, msg ) {
		var $el = $( selector );
		$el.addClass( 'dealer-field-error' );
		var $err = $( '<p class="dealer-error-msg" role="alert">' + escapeHtml( msg ) + '</p>' );
		$el.closest( 'td, .dealer-fieldset, .dealer-upload-area, div' ).first().append( $err );
	}

	function clearErrors() {
		$( '.dealer-field-error' ).removeClass( 'dealer-field-error' );
		$( '.dealer-error-msg' ).remove();
	}

	// ── Escape HTML (sicurezza: non inserire input utente grezzo nel DOM) ─

	function escapeHtml( str ) {
		return String( str )
			.replace( /&/g,  '&amp;' )
			.replace( /</g,  '&lt;' )
			.replace( />/g,  '&gt;' )
			.replace( /"/g,  '&quot;' )
			.replace( /'/g,  '&#039;' );
	}

	// ── Toggle sezione rename ─────────────────────────────────────────────

	function toggleRenameSection() {
		if ( $( '#keep_original_name' ).is( ':checked' ) ) {
			$( '#rename-section' ).slideUp( 150 );
			updateFilenamePreview();
		} else {
			$( '#rename-section' ).slideDown( 150 );
		}
	}

	function updateFilenamePreview() {
		var $preview = $( '#filename-preview' );
		if ( $( '#keep_original_name' ).is( ':checked' ) ) {
			var file = $( '#doc_file' ).get( 0 );
			if ( file && file.files && file.files.length ) {
				$preview.text( 'Nome file: ' + file.files[ 0 ].name );
			} else {
				$preview.text( '' );
			}
			return;
		}
		var customName = $.trim( $( '#doc_custom_name' ).val() );
		var file       = $( '#doc_file' ).get( 0 );
		var ext        = '';
		if ( file && file.files && file.files.length ) {
			var parts = file.files[ 0 ].name.split( '.' );
			if ( parts.length > 1 ) { ext = '.' + parts[ parts.length - 1 ].toLowerCase(); }
		}
		if ( customName ) {
			// Mostra nome sanitizzato (stesso comportamento del server).
			var sanitized = customName.replace( /[^a-zA-Z0-9._\-]/g, '_' ).replace( /^_+|_+$/g, '' );
			$preview.text( 'Nome risultante: ' + sanitized + ext );
		} else {
			$preview.text( ext ? 'Estensione rilevata: ' + ext : '' );
		}
	}

	// ── Perimetro: brand selezionabili ────────────────────────────────────
	// dealerAdmin.productLines contiene solo ciò che l'utente può pubblicare
	// (per l'amministratore è l'elenco completo, quindi qui non cambia nulla).
	// Il markup del select è renderizzato con tutti i brand: le voci fuori
	// perimetro vanno tolte, altrimenti l'utente sceglie un brand che poi non
	// offre nessuna linea. Resta comodità dell'interfaccia: il rifiuto vero
	// arriva comunque dal server.

	function pruneBrandsToScope() {
		var $brand = $( '#doc_brand' );
		if ( ! $brand.length ) { return; }

		var allowed = dealerAdmin.productLines || {};
		var kept    = 0;

		$brand.find( 'option' ).each( function () {
			var $opt = $( this );
			var val  = $opt.val();

			if ( ! val ) { return; } // Placeholder "— Seleziona brand —".

			if ( Object.prototype.hasOwnProperty.call( allowed, val ) ) {
				kept++;
			} else {
				$opt.remove();
			}
		} );

		if ( ! kept ) {
			$brand.empty().append(
				$( '<option>' ).val( '' ).text( dealerAdmin.i18n.noBrands )
			);
		}
	}

	// ── AJAX: carica linee prodotto ───────────────────────────────────────

	function fetchProductLines( brand ) {
		if ( ! brand ) {
			$( '#doc_product_line' ).html( '<option value="">— Seleziona brand prima —</option>' );
			$( '#doc_lines' ).html( '<option value="">— Seleziona brand prima —</option>' );
			return;
		}

		// Usa i dati già presenti in dealerAdmin.productLines (nessuna chiamata AJAX
		// aggiuntiva se i dati sono già disponibili lato client).
		var lines = ( dealerAdmin.productLines && dealerAdmin.productLines[ brand ] ) || [];

		if ( lines.length ) {
			buildLineSelects( brand, lines );
		} else {
			// Fallback AJAX per brand non presenti nel JSON inline.
			$.post( dealerAdmin.ajaxUrl, {
				action: 'dealer_get_lines',
				nonce:  dealerAdmin.nonce,
				brand:  brand
			}, function ( res ) {
				// Anche l'endpoint risponde già filtrato sul perimetro: se non
				// torna nessuna linea i due select vanno svuotati, altrimenti
				// resterebbero visibili quelli del brand scelto prima.
				buildLineSelects( brand, ( res && res.success && res.data.lines ) || [] );
			} ).fail( function () {
				buildLineSelects( brand, [] );
			} );
		}
	}

	function buildLineSelects( brand, lines ) {
		var $sel2 = $( '#doc_product_line' );
		var $sel3 = $( '#doc_lines' );

		if ( ! lines || ! lines.length ) {
			$sel2.empty().append( $( '<option>' ).val( '' ).text( dealerAdmin.i18n.noLines ) );
			$sel3.empty();
			return;
		}

		$sel2.empty().append( '<option value="">— Seleziona linea —</option>' );
		$sel3.empty();

		$.each( lines, function ( i, line ) {
			// Step 2: valore = nome linea semplice (.val() imposta la proprietà DOM, non l'HTML)
			$sel2.append(
				$( '<option>' ).val( line ).text( line )
			);
			// Step 3: valore = "Brand|Linea" per matching con _dealer_lines
			var key = brand + '|' + line;
			$sel3.append(
				$( '<option>' ).val( key ).text( brand + ' › ' + line )
			);
		} );
	}

	// ── Step 2: selettore "Questo documento sostituisce…" ────────────────
	// Elenco potenzialmente lungo: filtro testuale lato client, senza librerie.

	var prevVersionOptions = [];

	function initPreviousVersionPicker() {
		var $sel = $( '#doc_previous_version' );
		if ( ! $sel.length || ! $sel.is( 'select' ) ) { return; }

		// Cache delle opzioni renderizzate dal server (già escapate lato PHP).
		$sel.find( 'option' ).each( function () {
			var $opt = $( this );
			prevVersionOptions.push( {
				value:  $opt.val(),
				text:   $.trim( $opt.text() ),
				search: String( $opt.attr( 'data-search' ) || '' ).toLowerCase()
			} );
		} );

		$( '#doc_previous_version_search' ).on( 'input search', function () {
			filterPreviousVersions( $( this ).val() );
		} );

		$sel.on( 'change', updatePreviousVersionHint );
		updatePreviousVersionHint();
	}

	function filterPreviousVersions( term ) {
		var $sel = $( '#doc_previous_version' );
		if ( ! $sel.length || ! prevVersionOptions.length ) { return; }

		var selected = $sel.val();
		var terms    = $.trim( String( term || '' ) ).toLowerCase().split( /\s+/ );
		var matches  = 0;

		$sel.empty();

		$.each( prevVersionOptions, function ( i, opt ) {
			var keep = true;

			// La voce "Nessuno" e l'eventuale selezione corrente restano sempre visibili.
			if ( opt.value !== '0' && opt.value !== selected ) {
				$.each( terms, function ( j, t ) {
					if ( t && opt.search.indexOf( t ) === -1 ) {
						keep = false;
						return false;
					}
				} );
			}

			if ( ! keep ) { return; }
			if ( opt.value !== '0' ) { matches++; }

			$sel.append(
				$( '<option>' ).val( opt.value ).attr( 'data-search', opt.search ).text( opt.text )
			);
		} );

		$sel.val( selected );
		if ( ! $sel.val() ) { $sel.val( '0' ); }

		var $count = $( '#doc-previous-version-count' );
		if ( $count.length ) {
			$count.text( matches
				? ( matches + ' ' + dealerAdmin.i18n.docsAvailable )
				: dealerAdmin.i18n.noMatch );
		}

		updatePreviousVersionHint();
	}

	function updatePreviousVersionHint() {
		var $hint = $( '#doc-previous-version-hint' );
		if ( ! $hint.length ) { return; }
		var label = getPreviousVersionLabel();
		$hint.text( label === dealerAdmin.i18n.noPrevious
			? label
			: dealerAdmin.i18n.selectedPrefix + ' ' + label );
	}

	/** Etichetta leggibile della scelta corrente (usata anche nel riepilogo). */
	function getPreviousVersionLabel() {
		var $sel = $( '#doc_previous_version' );
		if ( ! $sel.length || ! $sel.is( 'select' ) ) {
			return dealerAdmin.i18n.noPrevious;
		}
		var val = $sel.val();
		if ( ! val || val === '0' ) {
			return dealerAdmin.i18n.noPrevious;
		}
		return $.trim( $sel.find( 'option:selected' ).text() );
	}

	function resetPreviousVersionPicker() {
		var $sel = $( '#doc_previous_version' );
		if ( ! $sel.length || ! $sel.is( 'select' ) ) { return; }
		$( '#doc_previous_version_search' ).val( '' );
		filterPreviousVersions( '' );
		$sel.val( '0' );
		updatePreviousVersionHint();
	}

	// ── Costruisce il riepilogo Step 5 ───────────────────────────────────

	function buildSummary() {
		var file     = $( '#doc_file' ).get( 0 );
		var filename = file && file.files && file.files.length ? file.files[ 0 ].name : '—';
		var keepOrig = $( '#keep_original_name' ).is( ':checked' );
		var custName = $.trim( $( '#doc_custom_name' ).val() );

		var brand    = $( '#doc_brand option:selected' ).text()       || '—';
		var line     = $( '#doc_product_line option:selected' ).text() || '—';
		var typeTxt  = $( '#doc_type option:selected' ).text()        || '—';
		var year     = $( '#doc_year' ).val()                         || '—';
		var version  = $.trim( $( '#doc_version' ).val() )            || '—';
		var expiry   = $( '#doc_expiry' ).val()                       || 'Nessuna';
		var keywords = $.trim( $( '#doc_keywords' ).val() )           || '—';

		var roles = [];
		$( 'input[name="doc_roles[]"]:checked' ).each( function () {
			var labels = { dealer: 'Dealer', top_dealer: 'Top Dealer', part_center: 'Parts Center' };
			roles.push( labels[ $( this ).val() ] || $( this ).val() );
		} );

		var lines = [];
		$( '#doc_lines option:selected' ).each( function () {
			lines.push( $( this ).text() );
		} );

		var finalName = keepOrig
			? filename
			: ( custName ? custName + '.' + ( filename.split( '.' ).pop() || '' ) : filename );

		var rows = [
			[ 'File',           escapeHtml( finalName ) ],
			[ 'Brand',          escapeHtml( brand ) ],
			[ 'Linea prodotto', escapeHtml( line ) ],
			[ 'Tipo',           escapeHtml( typeTxt ) ],
			[ 'Anno',           escapeHtml( String( year ) ) ],
			[ 'Versione',       escapeHtml( version ) ],
			[ 'Sostituisce',    escapeHtml( getPreviousVersionLabel() ) ],
			[ 'Ruoli',          escapeHtml( roles.join( ', ' ) || '—' ) ],
			[ 'Linee accesso',  escapeHtml( lines.join( ', ' ) || 'Tutte' ) ],
			[ 'Scadenza',       escapeHtml( expiry ) ],
			[ 'Keywords',       escapeHtml( keywords ) ],
		];

		var html = '<dl>';
		$.each( rows, function ( i, row ) {
			html += '<dt>' + row[ 0 ] + '</dt><dd>' + row[ 1 ] + '</dd>';
		} );
		html += '</dl>';

		$( '#dealer-summary' ).html( html );
	}

	// ── Invio form via AJAX ───────────────────────────────────────────────

	function handleSubmit( e ) {
		e.preventDefault();

		var $btn  = $( '#dealer-save-btn' );
		var $note = $( '#dealer-upload-notice' );

		$btn.prop( 'disabled', true ).text( dealerAdmin.i18n.uploading );
		$note.hide().removeClass( 'notice-success notice-error' );

		var data = new FormData( $( '#dealer-upload-form' ).get( 0 ) );
		data.append( 'action', 'dealer_save_document' );

		$.ajax( {
			url:         dealerAdmin.ajaxUrl,
			type:        'POST',
			data:        data,
			processData: false,
			contentType: false,
			success: function ( res ) {
				$btn.prop( 'disabled', false ).text( 'Salva Documento' );
				if ( res.success ) {
					$note
						.addClass( 'notice-success' )
						.html( '<p><strong>' + escapeHtml( res.data.message || dealerAdmin.i18n.saveSuccess ) + '</strong> ID: ' + parseInt( res.data.post_id, 10 ) + ' — v' + parseInt( res.data.version_seq, 10 ) + '</p>' )
						.show();
					// Reset form
					$( '#dealer-upload-form' ).get( 0 ).reset();
					showStep( 1 );
					$( '#dealer-selected-file' ).hide();
					$( '#rename-section' ).hide();
					$( '#filename-preview' ).text( '' );
					updateFilenamePreview();
					resetPreviousVersionPicker();
				} else {
					$note
						.addClass( 'notice-error' )
						.html( '<p>' + escapeHtml( res.data.message || dealerAdmin.i18n.saveError ) + '</p>' )
						.show();
				}
			},
			error: function () {
				$btn.prop( 'disabled', false ).text( 'Salva Documento' );
				$note
					.addClass( 'notice-error' )
					.html( '<p>' + escapeHtml( dealerAdmin.i18n.saveError ) + '</p>' )
					.show();
			}
		} );
	}

	// ── Archive: AJAX mark obsolete / delete ─────────────────────────────

	function handleArchiveActions() {
		// Obsoleto — può richiedere una seconda conferma se rompe una catena di versioni.
		function markObsolete( postId, $btn, confirmChain ) {
			$.post( dealerAdmin.ajaxUrl, {
				action:        'dealer_mark_obsolete',
				nonce:         dealerAdmin.archiveNonce,
				post_id:       postId,
				confirm_chain: confirmChain ? 1 : 0
			}, function ( res ) {
				if ( ! res.success ) {
					alert( res.data.message );
					return;
				}
				if ( res.data.needs_confirm ) {
					if ( confirm( res.data.message ) ) {
						markObsolete( postId, $btn, true );
					}
					return;
				}
				if ( res.data.reload ) {
					// La catena è cambiata: ricarica per mostrare la nuova versione corrente.
					window.location.reload();
					return;
				}
				// La cella del titolo è individuata per classe: la colonna delle
				// checkbox bulk non è più la prima cella <td> della riga.
				$( '#doc-row-' + postId ).find( 'td.dealer-doc-title strong' )
					.after( ' <span class="dealer-badge-obsoleto">Obsoleto</span>' );
				$btn.remove();
			} );
		}

		$( document ).on( 'click', '.dealer-obsolete-btn', function () {
			var $btn   = $( this );
			var postId = parseInt( $btn.data( 'post' ), 10 );
			if ( ! confirm( dealerAdmin.i18n.confirmObsolete ) ) { return; }
			markObsolete( postId, $btn, false );
		} );

		// Elimina — solo amministratore. Il pulsante non viene nemmeno reso per
		// gli altri: questa è una seconda rete, quella vera è lato server in
		// ajax_delete_document().
		$( document ).on( 'click', '.dealer-delete-btn', function () {
			if ( ! dealerAdmin.canDelete ) { return; }
			var $btn   = $( this );
			var postId = parseInt( $btn.data( 'post' ), 10 );
			if ( ! confirm( dealerAdmin.i18n.confirmDelete ) ) { return; }

			$.post( dealerAdmin.ajaxUrl, {
				action:  'dealer_delete_document',
				nonce:   dealerAdmin.archiveNonce,
				post_id: postId
			}, function ( res ) {
				if ( res.success ) {
					if ( res.data.reload ) {
						// La versione precedente è tornata corrente: ricarica l'archivio.
						window.location.reload();
						return;
					}
					$( '#doc-row-' + postId ).fadeOut( 300, function () {
						$( this ).remove();
						updateBulkCount();
					} );
					$( '#log-row-' + postId ).remove();
					$( '#chain-row-' + postId ).remove();
				} else {
					alert( res.data.message );
				}
			} );
		} );

		// Toggle log download
		$( document ).on( 'click', '.dealer-toggle-log-btn', function () {
			var postId  = parseInt( $( this ).data( 'post' ), 10 );
			var $logRow = $( '#log-row-' + postId );
			var expanded = $( this ).attr( 'aria-expanded' ) === 'true';
			$logRow.toggle( ! expanded );
			$( this ).attr( 'aria-expanded', String( ! expanded ) );
		} );

		// Toggle storico versioni (stesso pattern della riga log)
		$( document ).on( 'click', '.dealer-toggle-chain-btn', function () {
			var postId    = parseInt( $( this ).data( 'post' ), 10 );
			var $chainRow = $( '#chain-row-' + postId );
			var expanded  = $( this ).attr( 'aria-expanded' ) === 'true';
			$chainRow.toggle( ! expanded );
			$( this ).attr( 'aria-expanded', String( ! expanded ) );
		} );
	}

	// ── Archive: operazioni di gruppo ────────────────────────────────────
	// La conferma è una sola, a monte, per l'intera selezione: il flusso a due
	// fasi di "segna obsoleto" singolo non ha senso documento per documento.

	function intOr0( value ) {
		var n = parseInt( value, 10 );
		return isNaN( n ) ? 0 : n;
	}

	function getSelectedIds() {
		var ids = [];
		$( '.dealer-bulk-cb:checked' ).each( function () {
			var id = intOr0( $( this ).val() );
			if ( id > 0 ) { ids.push( id ); }
		} );
		return ids;
	}

	function updateBulkCount() {
		var $count = $( '#dealer-bulk-count' );
		if ( ! $count.length ) { return; }

		var selected = getSelectedIds().length;
		var total    = $( '.dealer-bulk-cb' ).length;

		if ( selected === 0 ) {
			$count.removeClass( 'has-selection' ).text( 'Nessun documento selezionato' );
		} else if ( selected === 1 ) {
			$count.addClass( 'has-selection' ).text( '1 documento selezionato' );
		} else {
			$count.addClass( 'has-selection' ).text( selected + ' documenti selezionati' );
		}

		$( '#dealer-cb-select-all' ).prop( 'checked', total > 0 && selected === total );
	}

	function bulkConfirmText( bulkAction, count ) {
		var tpl = ( bulkAction === 'delete' )
			? dealerAdmin.i18n.bulkConfirmDel
			: dealerAdmin.i18n.bulkConfirmObs;
		return String( tpl ).replace( '%d', count );
	}

	/**
	 * Ricarica l'archivio conservando i filtri attivi e passando il riepilogo
	 * come parametri numerici: la notice viene renderizzata lato server con il
	 * markup WordPress nativo.
	 */
	function bulkRedirect( data ) {
		var base  = window.location.href.split( '#' )[ 0 ].split( '?' );
		var path  = base[ 0 ];
		var query = base[ 1 ] || '';
		var kept  = [];

		if ( query ) {
			$.each( query.split( '&' ), function ( i, pair ) {
				if ( ! pair ) { return; }
				var key = pair.split( '=' )[ 0 ];
				// I riepiloghi precedenti e la pagina corrente non vanno conservati.
				if ( key.indexOf( 'bulk_' ) === 0 || key === 'paged' ) { return; }
				kept.push( pair );
			} );
		}

		kept.push( 'bulk_done=1' );
		kept.push( 'bulk_op=' + encodeURIComponent( String( data.op || '' ) ) );
		kept.push( 'bulk_ok=' + intOr0( data.processed ) );
		kept.push( 'bulk_invalid=' + intOr0( data.invalid ) );
		kept.push( 'bulk_already=' + intOr0( data.already ) );
		kept.push( 'bulk_nosucc=' + intOr0( data.no_succ ) );
		kept.push( 'bulk_failed=' + intOr0( data.failed ) );
		kept.push( 'bulk_promo=' + intOr0( data.promoted ) );

		window.location.href = path + '?' + kept.join( '&' );
	}

	function handleBulkActions() {
		if ( ! $( '#dealer-bulk-apply' ).length ) { return; }

		// Seleziona / deseleziona tutto.
		$( document ).on( 'change', '#dealer-cb-select-all', function () {
			var checked = $( this ).is( ':checked' );
			$( '.dealer-bulk-cb' ).prop( 'checked', checked )
				.each( function () {
					$( this ).closest( 'tr' ).toggleClass( 'dealer-row-selected', checked );
				} );
			updateBulkCount();
		} );

		// Singola riga.
		$( document ).on( 'change', '.dealer-bulk-cb', function () {
			$( this ).closest( 'tr' ).toggleClass( 'dealer-row-selected', $( this ).is( ':checked' ) );
			updateBulkCount();
		} );

		// Applica.
		$( document ).on( 'click', '#dealer-bulk-apply', function () {
			var $btn       = $( this );
			var bulkAction = $( '#dealer-bulk-action' ).val();

			if ( ! bulkAction ) {
				alert( dealerAdmin.i18n.bulkNoAction );
				return;
			}

			var ids = getSelectedIds();
			if ( ! ids.length ) {
				alert( dealerAdmin.i18n.bulkNoSelection );
				return;
			}

			if ( ! confirm( bulkConfirmText( bulkAction, ids.length ) ) ) { return; }

			$btn.prop( 'disabled', true ).text( dealerAdmin.i18n.bulkWorking );

			$.post( dealerAdmin.ajaxUrl, {
				action:      'dealer_bulk_action',
				nonce:       dealerAdmin.bulkNonce,
				bulk_action: bulkAction,
				post_ids:    ids
			}, function ( res ) {
				if ( ! res || ! res.success ) {
					$btn.prop( 'disabled', false ).text( dealerAdmin.i18n.bulkApply );
					alert( ( res && res.data && res.data.message ) || dealerAdmin.i18n.bulkError );
					return;
				}
				bulkRedirect( res.data );
			} ).fail( function () {
				$btn.prop( 'disabled', false ).text( dealerAdmin.i18n.bulkApply );
				alert( dealerAdmin.i18n.bulkError );
			} );
		} );

		updateBulkCount();
	}

	// ── Drag & Drop ───────────────────────────────────────────────────────

	function initDropArea() {
		var $area = $( '#dealer-drop-area' );
		if ( ! $area.length ) { return; }

		$area.on( 'dragover dragenter', function ( e ) {
			e.preventDefault();
			$( this ).addClass( 'drag-over' );
		} ).on( 'dragleave drop', function ( e ) {
			e.preventDefault();
			$( this ).removeClass( 'drag-over' );
		} ).on( 'drop', function ( e ) {
			var dt    = e.originalEvent.dataTransfer;
			var files = dt && dt.files;
			if ( files && files.length ) {
				$( '#doc_file' ).get( 0 ).files = files; // Assegna i file
				showSelectedFile( files[ 0 ] );
			}
		} ).on( 'click keydown', function ( e ) {
			if ( e.type === 'click' || e.key === 'Enter' || e.key === ' ' ) {
				$( '#doc_file' ).trigger( 'click' );
			}
		} );
	}

	function showSelectedFile( file ) {
		var icons = { pdf: '🔴', xlsx: '🟢', xls: '🟢', docx: '🔵', doc: '🔵' };
		var ext   = file.name.split( '.' ).pop().toLowerCase();
		var icon  = icons[ ext ] || '📄';
		var size  = ( file.size / 1024 / 1024 ).toFixed( 2 );
		$( '#dealer-selected-file' )
			.html( icon + ' <strong>' + escapeHtml( file.name ) + '</strong> (' + escapeHtml( size ) + ' MB)' )
			.show();
		updateFilenamePreview();
	}

	// ── Init ─────────────────────────────────────────────────────────────

	$( document ).ready( function () {

		// Pulsanti "Avanti"
		$( document ).on( 'click', '.dealer-next-btn', function () {
			var next = parseInt( $( this ).data( 'next' ), 10 );
			if ( next === totalSteps ) {
				buildSummary();
			}
			if ( validateStep( currentStep ) ) {
				showStep( next );
			}
		} );

		// Pulsanti "Indietro"
		$( document ).on( 'click', '.dealer-back-btn', function () {
			showStep( parseInt( $( this ).data( 'back' ), 10 ) );
		} );

		// File input change
		$( '#doc_file' ).on( 'change', function () {
			if ( this.files && this.files.length ) {
				showSelectedFile( this.files[ 0 ] );
			}
		} );

		// Toggle nome originale
		$( '#keep_original_name' ).on( 'change', toggleRenameSection );

		// Live preview nome file
		$( '#doc_custom_name, #doc_file' ).on( 'input change', updateFilenamePreview );

		// Brand fuori perimetro: rimossi prima di qualunque interazione.
		pruneBrandsToScope();

		// Brand change → aggiorna linee prodotto
		$( '#doc_brand' ).on( 'change', function () {
			fetchProductLines( $( this ).val() );
		} );

		// Submit
		$( '#dealer-upload-form' ).on( 'submit', handleSubmit );

		// Selettore versione precedente (Step 2)
		initPreviousVersionPicker();

		// Archive actions (pagina archivio)
		handleArchiveActions();

		// Archive: operazioni di gruppo (pagina archivio)
		handleBulkActions();

		// Drag & Drop
		initDropArea();
	} );

} )( jQuery );
