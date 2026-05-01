/**
 * Dealer Portal — Dealer Search Enhancement
 * Progressive enhancement: la ricerca funziona SENZA JavaScript (form GET).
 * Questo script aggiunge: auto-submit su cambio filtri e scroll ai risultati.
 *
 * Non usa eval() né innerHTML con input utente.
 */
/* global dealerSearch, jQuery */
( function ( $ ) {
	'use strict';

	var submitTimer = null;

	function scheduleSubmit() {
		clearTimeout( submitTimer );
		submitTimer = setTimeout( function () {
			$( '.dealer-search-form' ).submit();
		}, 350 );
	}

	function scrollToResults() {
		var $results = $( '#dealer-results' );
		if ( $results.length && $results.children().length ) {
			$( 'html, body' ).animate( {
				scrollTop: $results.offset().top - 80
			}, 300 );
		}
	}

	$( document ).ready( function () {
		// Auto-submit al cambio dei filtri select (con debounce).
		$( '.dealer-filters-row select' ).on( 'change', function () {
			scheduleSubmit();
		} );

		// Scroll automatico ai risultati se la pagina è stata caricata con una ricerca.
		if ( window.location.search.indexOf( 's=' ) !== -1 || window.location.search.indexOf( 'filter_' ) !== -1 ) {
			scrollToResults();
		}

		// Registra download via AJAX in background (il download avviene già via redirect server-side,
		// questo è solo per tracciamento aggiuntivo da JS).
		$( document ).on( 'click', '.dealer-btn-download', function () {
			var postId = parseInt( $( this ).data( 'post' ), 10 );
			if ( postId && dealerSearch && dealerSearch.nonce ) {
				$.post( dealerSearch.ajaxUrl, {
					action:  'dealer_log_download',
					nonce:   dealerSearch.nonce,
					post_id: postId
				} );
				// Non intercettiamo il link: il browser segue l'href normalmente.
			}
		} );
	} );

} )( jQuery );
