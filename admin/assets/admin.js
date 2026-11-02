/*
 * Afterware Connect admin behaviour.
 *
 * Everything here is an improvement on something that already works: the card
 * filters are links the server honours, the confirm fields are required inputs
 * the server re-checks, the copy buttons sit next to text that can be selected,
 * and <details> is native. If this file never loads, nothing is lost.
 */
( function () {
	'use strict';

	/**
	 * Filters the overview table in place, so a card click does not cost a
	 * page load. The links still carry ?filter=, for anyone without this.
	 */
	function wireCardFilters() {
		var links = document.querySelectorAll( '[data-afterware-filter]' );
		var rows = document.querySelectorAll( '[data-afterware-filters]' );
		if ( ! links.length || ! rows.length ) {
			return;
		}

		function apply( filter ) {
			Array.prototype.forEach.call( rows, function ( row ) {
				var filters = ( row.getAttribute( 'data-afterware-filters' ) || '' ).split( ' ' );
				row.hidden = '' !== filter && -1 === filters.indexOf( filter );
			} );
			Array.prototype.forEach.call( links, function ( link ) {
				var own = link.getAttribute( 'data-afterware-filter' );
				var active = '' !== own && own === filter;
				link.classList.toggle( 'is-active', active );
				if ( active ) {
					link.setAttribute( 'aria-current', 'true' );
				} else {
					link.removeAttribute( 'aria-current' );
				}
			} );
		}

		Array.prototype.forEach.call( links, function ( link ) {
			link.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				apply( link.getAttribute( 'data-afterware-filter' ) || '' );
				if ( window.history && window.history.replaceState ) {
					window.history.replaceState( {}, '', link.href );
				}
			} );
		} );
	}

	/**
	 * Keeps a switch, revert or disconnect button disabled until the word
	 * next to it has been typed out exactly. The server checks it again.
	 */
	function wireTypedConfirms() {
		var buttons = document.querySelectorAll( '[data-afterware-confirm-for]' );
		Array.prototype.forEach.call( buttons, function ( button ) {
			var field = document.getElementById( button.getAttribute( 'data-afterware-confirm-for' ) );
			if ( ! field || button.disabled ) {
				return;
			}
			var expected = field.getAttribute( 'data-afterware-confirm' ) || '';

			function sync() {
				button.disabled = field.value !== expected;
			}

			field.addEventListener( 'input', sync );
			sync();
		} );
	}

	/**
	 * Adds a copy button next to anything marked for it — but only where the
	 * clipboard API exists. A button that cannot copy is worse than no button,
	 * so PHP renders none and this puts one there when it will work.
	 */
	function wireCopyButtons() {
		if ( ! navigator.clipboard ) {
			return;
		}

		var sources = document.querySelectorAll( '[data-afterware-copy]' );
		Array.prototype.forEach.call( sources, function ( source ) {
			var label = source.getAttribute( 'data-copy-label' ) || 'Copy';
			var done = source.getAttribute( 'data-copy-done' ) || 'Copied';
			var failed = source.getAttribute( 'data-copy-failed' ) || 'Could not copy';

			var button = document.createElement( 'button' );
			button.type = 'button';
			button.className = 'button-link afterware-copy';
			button.textContent = label;
			source.insertAdjacentElement( 'afterend', button );

			var timer = null;

			function say( text ) {
				button.textContent = text;
				window.clearTimeout( timer );
				timer = window.setTimeout( function () {
					button.textContent = label;
				}, 1500 );
			}

			button.addEventListener( 'click', function () {
				navigator.clipboard.writeText( source.textContent.trim() ).then(
					function () {
						say( done );
					}
				).catch( function () {
					say( failed );
				} );
			} );
		} );
	}

	function init() {
		wireCardFilters();
		wireTypedConfirms();
		wireCopyButtons();
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
