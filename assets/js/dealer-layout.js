/**
 * Dealer Portal — larghezza reale della finestra.
 *
 * Le pagine del portale escono dal contenitore del tema per occupare tutta la
 * finestra (vedi il blocco "Wrapper globale" in assets/css/dealer.css). In CSS
 * l'unica misura disponibile sarebbe 100vw, che però comprende la barra di
 * scorrimento verticale: su Windows sono una quindicina di pixel di troppo, e
 * il risultato è una barra di scorrimento orizzontale su ogni pagina.
 *
 * document.documentElement.clientWidth è la stessa larghezza SENZA la barra.
 * Qui viene scritta nella variabile CSS --dp-vw, che il foglio di stile usa al
 * posto di 100vw. Se questo file non venisse caricato il CSS ricade su 100vw:
 * il layout resta corretto, semplicemente un po' più largo del dovuto.
 *
 * Nessuna dipendenza (né jQuery né altro): deve poter girare ovunque.
 */
( function () {
	'use strict';

	var root = document.documentElement;

	function sync() {
		root.style.setProperty( '--dp-vw', root.clientWidth + 'px' );
	}

	sync();

	// Il ridimensionamento della finestra può far comparire o sparire la barra
	// di scorrimento: la misura va rifatta, ma non a ogni singolo evento.
	var pending = null;
	window.addEventListener( 'resize', function () {
		if ( pending ) {
			window.cancelAnimationFrame( pending );
		}
		pending = window.requestAnimationFrame( function () {
			pending = null;
			sync();
		} );
	}, { passive: true } );

	// Il contenuto che cresce dopo il caricamento (risultati di ricerca via
	// AJAX, pannelli che si aprono) può far comparire la barra di scorrimento
	// senza alcun resize della finestra.
	//
	// Lo script è nell'head, quindi <body> non esiste ancora: l'osservatore va
	// agganciato a documento pronto. sync() invece gira subito — serve prima
	// che la pagina venga disegnata, e a quel punto clientWidth è già noto.
	function observeBody() {
		if ( ! ( 'ResizeObserver' in window ) || ! document.body ) {
			return;
		}
		new ResizeObserver( sync ).observe( document.body );
		sync();
	}

	/**
	 * Rete di sicurezza per l'uscita dal contenitore del tema.
	 *
	 * La tecnica funziona con qualunque tema, tranne uno: se un contenitore fra
	 * il wrapper e <body> ritaglia ciò che deborda (overflow diverso da
	 * visible, oppure contain:paint), il wrapper resta largo quanto la finestra
	 * ma se ne vede solo la fetta centrale — e il contenuto risulterebbe
	 * tagliato ai lati. Non è un caso frequente, ma non è verificabile a
	 * priori: il tema non lo conosciamo.
	 *
	 * Invece di sperare, si controlla. Se un antenato ritaglia, il wrapper
	 * riceve la classe dp-no-bleed e torna a stare dentro il contenitore del
	 * tema: più stretto del dovuto, ma integro. Meglio stretti che tagliati.
	 *
	 * <body> è escluso dal controllo: un overflow-x nascosto lì non ritaglia
	 * l'uscita (body è già largo quanto la finestra), anzi è proprio ciò che
	 * molti temi usano per evitare lo scorrimento orizzontale.
	 */
	var WRAPS = '.dealer-dashboard-wrap, .dealer-search-wrap, .dealer-favorites-wrap,'
		+ ' .dealer-team-wrap, .dealer-am-wrap';

	function clipsOverflow( el ) {
		var style = window.getComputedStyle( el );
		if ( 'visible' !== style.overflowX || 'visible' !== style.overflowY ) {
			return true;
		}
		return -1 !== String( style.contain || '' ).indexOf( 'paint' );
	}

	function guardBleed() {
		var wraps = document.querySelectorAll( WRAPS );

		for ( var i = 0; i < wraps.length; i++ ) {
			var node = wraps[ i ].parentElement;

			while ( node && node !== document.body && node !== document.documentElement ) {
				if ( clipsOverflow( node ) ) {
					wraps[ i ].classList.add( 'dp-no-bleed' );
					break;
				}
				node = node.parentElement;
			}
		}
	}

	function ready() {
		observeBody();
		guardBleed();
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', ready, { once: true } );
	} else {
		ready();
	}
} )();
