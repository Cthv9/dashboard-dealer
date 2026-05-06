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
				if ( res.success && res.data.lines ) {
					buildLineSelects( brand, res.data.lines );
				}
			} );
		}
	}

	function buildLineSelects( brand, lines ) {
		var $sel2 = $( '#doc_product_line' );
		var $sel3 = $( '#doc_lines' );
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
			var labels = { dealer: 'Dealer', top_dealer: 'Top Dealer', part_center: 'Part Center' };
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
						.html( '<p><strong>' + escapeHtml( dealerAdmin.i18n.saveSuccess ) + '</strong> ID: ' + parseInt( res.data.post_id, 10 ) + '</p>' )
						.show();
					// Reset form
					$( '#dealer-upload-form' ).get( 0 ).reset();
					showStep( 1 );
					$( '#dealer-selected-file' ).hide();
					$( '#rename-section' ).hide();
					$( '#filename-preview' ).text( '' );
					updateFilenamePreview();
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
		// Obsoleto
		$( document ).on( 'click', '.dealer-obsolete-btn', function () {
			var $btn    = $( this );
			var postId  = parseInt( $btn.data( 'post' ), 10 );
			if ( ! confirm( dealerAdmin.i18n.confirmObsolete ) ) { return; }

			$.post( dealerAdmin.ajaxUrl, {
				action:  'dealer_mark_obsolete',
				nonce:   dealerAdmin.archiveNonce,
				post_id: postId
			}, function ( res ) {
				if ( res.success ) {
					$( '#doc-row-' + postId ).find( 'td:first-child strong' )
						.after( ' <span class="dealer-badge-obsoleto">Obsoleto</span>' );
					$btn.remove();
				} else {
					alert( res.data.message );
				}
			} );
		} );

		// Elimina
		$( document ).on( 'click', '.dealer-delete-btn', function () {
			var $btn   = $( this );
			var postId = parseInt( $btn.data( 'post' ), 10 );
			if ( ! confirm( dealerAdmin.i18n.confirmDelete ) ) { return; }

			$.post( dealerAdmin.ajaxUrl, {
				action:  'dealer_delete_document',
				nonce:   dealerAdmin.archiveNonce,
				post_id: postId
			}, function ( res ) {
				if ( res.success ) {
					$( '#doc-row-' + postId ).fadeOut( 300, function () {
						$( this ).remove();
					} );
					$( '#log-row-' + postId ).remove();
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

		// Brand change → aggiorna linee prodotto
		$( '#doc_brand' ).on( 'change', function () {
			fetchProductLines( $( this ).val() );
		} );

		// Submit
		$( '#dealer-upload-form' ).on( 'submit', handleSubmit );

		// Archive actions (pagina archivio)
		handleArchiveActions();

		// Drag & Drop
		initDropArea();
	} );

} )( jQuery );
