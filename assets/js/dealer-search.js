/**
 * Dealer Portal — Ricerca a faccette.
 *
 * PROGRESSIVE ENHANCEMENT: la ricerca funziona SENZA JavaScript (form GET
 * classico con i pulsanti "Cerca" / "Applica filtri", chip e paginazione come
 * link reali). Questo script è solo un miglioramento: intercetta il submit e
 * aggiorna via AJAX i tre contenitori. NON rimuovere il fallback.
 *
 * L'HTML di risultati, facet e chip arriva già generato ed escapato dal PHP:
 * qui ci si limita a sostituire innerHTML. Nessun markup viene costruito in JS,
 * nessun uso di eval().
 */
/* global dealerSearch, jQuery */
( function ( $ ) {
	'use strict';

	// Marca il documento: il CSS nasconde i controlli riservati al no-JS.
	document.documentElement.className += ' dealer-js';

	var CFG = ( typeof dealerSearch !== 'undefined' ) ? dealerSearch : {};
	var DEBOUNCE = parseInt( CFG.debounce, 10 ) || 350;
	var MOBILE_BP = parseInt( CFG.mobileBp, 10 ) || 782;

	var $wrap, $form, $facets, $filters, $results, $count, $paged, $orderby;
	var typingTimer = null;
	var currentXhr = null;
	var requestSeq = 0;
	var sortTouched = false;
	var wasMobile = null;

	function hasText() {
		return $.trim( $( '#dealer-s' ).val() || '' ) !== '';
	}

	/**
	 * "Più pertinenti" ha senso solo con un termine di ricerca. Il <select> non
	 * viene mai ricostruito (perderebbe il focus): si abilita/disabilita l'opzione.
	 */
	function syncSortOptions() {
		var $relevance = $orderby.find( 'option[value="relevance"]' );
		if ( ! $relevance.length ) {
			return;
		}
		var text = hasText();
		$relevance.prop( 'disabled', ! text );

		if ( ! text && $orderby.val() === 'relevance' ) {
			$orderby.val( 'recent' );
		} else if ( text && ! sortTouched && $orderby.val() === 'recent' ) {
			// L'utente non ha scelto un ordinamento: con un testo attivo vince la pertinenza.
			$orderby.val( 'relevance' );
		}
	}

	// ── Serializzazione dello stato ──────────────────────────────────────────

	/** Coppie nome/valore del form, senza i campi vuoti o ridondanti. */
	function collectPairs() {
		var pairs = [];
		var text = hasText();

		$.each( $form.serializeArray(), function ( i, field ) {
			if ( field.value === '' ) {
				return;
			}
			if ( field.name === 'pag' && parseInt( field.value, 10 ) <= 1 ) {
				return;
			}
			// 'recent' è il default solo senza termine di ricerca: con un testo
			// attivo va inviato, altrimenti il PHP ricadrebbe su 'relevance'.
			if ( field.name === 'orderby' && field.value === 'recent' && ! text ) {
				return;
			}
			pairs.push( field );
		} );
		return pairs;
	}

	function queryString() {
		return $.param( collectPairs() );
	}

	/** Mantiene l'URL allineato ai filtri: condivisibile e resistente al refresh. */
	function syncUrl() {
		if ( ! window.history || ! window.history.replaceState ) {
			return;
		}
		var qs = queryString();
		window.history.replaceState( {}, '', window.location.pathname + ( qs ? '?' + qs : '' ) );
	}

	// ── Richiesta AJAX ───────────────────────────────────────────────────────

	function setBusy( busy ) {
		$results.attr( 'aria-busy', busy ? 'true' : 'false' );
		$wrap.toggleClass( 'is-loading', !! busy );
	}

	/**
	 * @param {boolean} resetPage true quando cambia un filtro: si torna a pagina 1.
	 */
	function refresh( resetPage ) {
		if ( ! CFG.ajaxUrl || ! CFG.nonce ) {
			return; // Configurazione assente: resta il submit classico.
		}
		if ( resetPage ) {
			$paged.val( 1 );
		}

		var data = collectPairs();
		data.push( { name: 'action', value: CFG.action || 'dealer_facet_search' } );
		data.push( { name: 'nonce', value: CFG.nonce } );
		data.push( { name: 'page_url', value: $form.attr( 'action' ) || window.location.href } );

		var seq = ++requestSeq;
		if ( currentXhr ) {
			currentXhr.abort();
		}
		setBusy( true );

		currentXhr = $.post( CFG.ajaxUrl, $.param( data ) )
			.done( function ( response ) {
				if ( seq !== requestSeq ) {
					return; // Risposta sorpassata da una più recente.
				}
				if ( ! response || ! response.success || ! response.data ) {
					return;
				}
				var d = response.data;
				$facets.html( d.facets );
				$filters.html( d.filters );
				$results.html( d.results );
				$count.text( d.countLabel );
				if ( d.paged ) {
					$paged.val( d.paged );
				}
				syncUrl();
			} )
			.fail( function ( xhr, status ) {
				if ( status === 'abort' || seq !== requestSeq ) {
					return;
				}
				// In caso di errore si lascia il contenuto attuale: nessun dato perso.
			} )
			.always( function () {
				if ( seq === requestSeq ) {
					currentXhr = null;
					setBusy( false );
				}
			} );
	}

	function scrollToResults() {
		if ( ! $results.length ) {
			return;
		}
		var top = $results.offset().top - 90;
		if ( window.pageYOffset > top ) {
			$( 'html, body' ).animate( { scrollTop: top }, 250 );
		}
	}

	// ── Sidebar collassabile su mobile ───────────────────────────────────────

	function setFacetsCollapsed( collapsed ) {
		$( '#dealer-facets-panel' ).toggleClass( 'is-collapsed', collapsed );
		$( '#dealer-facets-toggle' )
			.attr( 'aria-expanded', collapsed ? 'false' : 'true' )
			.find( '.dealer-facets-toggle-label' ).text( collapsed ? 'Mostra' : 'Nascondi' );
	}

	/**
	 * Sotto i 782px la sidebar parte chiusa. Si interviene solo quando si
	 * attraversa il breakpoint, per non richiudere un pannello aperto a mano.
	 */
	function applyResponsiveFacets() {
		var isMobile = window.innerWidth <= MOBILE_BP;
		if ( isMobile === wasMobile ) {
			return;
		}
		wasMobile = isMobile;
		setFacetsCollapsed( isMobile );
	}

	// ── Bootstrap ────────────────────────────────────────────────────────────

	$( document ).ready( function () {
		$wrap    = $( '.dealer-search-wrap' );
		$form    = $( '#dealer-search-form' );
		$facets  = $( '#dealer-facets' );
		$filters = $( '#dealer-active-filters' );
		$results = $( '#dealer-results' );
		$count   = $( '#dealer-result-count' );
		$paged   = $( '#dealer-paged' );
		$orderby = $( '#dealer-orderby' );

		if ( ! $form.length || ! $results.length ) {
			return;
		}

		applyResponsiveFacets();

		// Un ordinamento diverso dal default (arrivato da un link condiviso) è
		// una scelta esplicita: non va sovrascritto dall'auto-switch.
		sortTouched = $orderby.val() !== ( hasText() ? 'relevance' : 'recent' );

		$( window ).on( 'resize', function () {
			clearTimeout( applyResponsiveFacets.t );
			applyResponsiveFacets.t = setTimeout( applyResponsiveFacets, 200 );
		} );

		// Submit intercettato: con JS si aggiorna via AJAX.
		$form.on( 'submit', function ( e ) {
			e.preventDefault();
			clearTimeout( typingTimer );
			syncSortOptions();
			refresh( true );
		} );

		// Checkbox dei facet (delegato: la sidebar viene rigenerata a ogni richiesta).
		$wrap.on( 'change', '.dealer-facet-check', function () {
			refresh( true );
		} );

		// Ordinamento.
		$orderby.on( 'change', function () {
			sortTouched = true;
			refresh( true );
		} );

		// Campo di testo con debounce.
		$( '#dealer-s' ).on( 'input search', function () {
			clearTimeout( typingTimer );
			typingTimer = setTimeout( function () {
				syncSortOptions();
				refresh( true );
			}, DEBOUNCE );
		} );

		// Chip "×": toglie la spunta corrispondente (o svuota il testo) e ricarica.
		$wrap.on( 'click', '.dealer-chip', function ( e ) {
			e.preventDefault();
			// attr() e non data(): niente conversione automatica dei valori numerici.
			var group = $( this ).attr( 'data-chip-group' );
			var value = $( this ).attr( 'data-chip-value' ) || '';

			if ( group === 's' ) {
				$( '#dealer-s' ).val( '' );
			} else {
				$facets.find( 'input[name="' + group + '[]"]' ).each( function () {
					if ( this.value === value ) {
						this.checked = false;
					}
				} );
			}
			clearTimeout( typingTimer );
			syncSortOptions();
			refresh( true );
		} );

		// "Azzera tutto".
		$wrap.on( 'click', '[data-chip-reset]', function ( e ) {
			e.preventDefault();
			$( '#dealer-s' ).val( '' );
			$facets.find( 'input.dealer-facet-check' ).prop( 'checked', false );
			clearTimeout( typingTimer );
			syncSortOptions();
			refresh( true );
		} );

		// Paginazione.
		$wrap.on( 'click', '.dealer-pagination a[data-page]', function ( e ) {
			e.preventDefault();
			$paged.val( parseInt( $( this ).attr( 'data-page' ), 10 ) || 1 );
			refresh( false );
			scrollToResults();
		} );

		// Toggle sidebar su mobile.
		$( '#dealer-facets-toggle' ).on( 'click', function () {
			var $panel = $( '#dealer-facets-panel' );
			var collapsed = $panel.toggleClass( 'is-collapsed' ).hasClass( 'is-collapsed' );
			$( this )
				.attr( 'aria-expanded', collapsed ? 'false' : 'true' )
				.find( '.dealer-facets-toggle-label' ).text( collapsed ? 'Mostra' : 'Nascondi' );
		} );

		// Registra il download via AJAX in background: il file viene comunque
		// servito (e loggato) server-side dal link, che non viene intercettato.
		$wrap.on( 'click', '.dealer-btn-download, .dealer-btn-download-version', function () {
			var postId = parseInt( $( this ).data( 'post' ), 10 );
			if ( postId && CFG.ajaxUrl && CFG.nonce ) {
				$.post( CFG.ajaxUrl, {
					action:  'dealer_log_download',
					nonce:   CFG.nonce,
					post_id: postId
				} );
			}
		} );

		// ── Preferiti ────────────────────────────────────────────────────────
		// Senza JS la stella è un normale link con nonce che esegue l'azione e
		// riporta indietro. Con JS si aggiorna in posto: il PHP restituisce solo
		// etichette e URL già pronti, qui non si costruisce markup.
		$wrap.on( 'click', '.dealer-fav-toggle', function ( e ) {
			var $btn = $( this );

			if ( ! CFG.ajaxUrl || ! CFG.nonce ) {
				return; // Configurazione assente: resta il link classico.
			}
			e.preventDefault();

			if ( $btn.data( 'busy' ) ) {
				return;
			}
			var postId = parseInt( $btn.attr( 'data-fav-post' ), 10 );
			var next   = $btn.attr( 'data-fav-next' ) === 'remove' ? 'remove' : 'add';
			if ( ! postId ) {
				return;
			}

			$btn.data( 'busy', true ).addClass( 'is-busy' );

			$.post( CFG.ajaxUrl, {
				action:  CFG.favAction || 'dealer_toggle_favorite',
				nonce:   CFG.nonce,
				post_id: postId,
				fav:     next
			} )
				.done( function ( response ) {
					if ( ! response || ! response.success || ! response.data ) {
						return;
					}
					var d = response.data;
					$btn
						.toggleClass( 'is-active', !! d.favorite )
						.attr( 'aria-pressed', d.favorite ? 'true' : 'false' )
						.attr( 'data-fav-next', d.next )
						.attr( 'title', d.title )
						.attr( 'href', d.href );
					$btn.find( '.dealer-fav-label' ).text( d.label );
				} )
				.fail( function ( xhr ) {
					// Tetto raggiunto o permesso negato: si avvisa senza toccare lo stato.
					var msg = xhr && xhr.responseJSON && xhr.responseJSON.data
						? xhr.responseJSON.data.message
						: '';
					if ( msg ) {
						window.alert( msg );
					}
				} )
				.always( function () {
					$btn.data( 'busy', false ).removeClass( 'is-busy' );
				} );
		} );
	} );

} )( jQuery );
