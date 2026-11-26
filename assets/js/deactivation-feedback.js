/**
 * EMCP Lite: deactivation feedback popup on the Plugins screen.
 *
 * A click on EMCP Lite's Deactivate link opens a dialog. Submit posts to this
 * site's admin-ajax (never to emcptools.com), waits at most 2 seconds, then
 * follows the original link whatever happened. Skip follows it at once. Esc,
 * the close button or a click outside cancels without deactivating.
 */
( function () {
	'use strict';

	var cfg = window.emcpLiteDeactivation;
	if ( ! cfg || ! cfg.plugin ) {
		return;
	}
	var rows = document.querySelectorAll( 'tr[data-plugin]' );
	var link = null;
	for ( var i = 0; i < rows.length; i++ ) {
		if ( rows[ i ].getAttribute( 'data-plugin' ) === cfg.plugin ) {
			link = rows[ i ].querySelector( '.deactivate a' );
			break;
		}
	}
	if ( ! link ) {
		return;
	}

	var s = cfg.strings || {};
	var overlay = null;
	var dialog = null;
	var busy = false;

	function el( tag, cls, text ) {
		var node = document.createElement( tag );
		if ( cls ) {
			node.className = cls;
		}
		if ( text ) {
			node.textContent = text;
		}
		return node;
	}

	function go() {
		window.location.href = link.href;
	}

	function close() {
		if ( busy || ! overlay ) {
			return;
		}
		document.removeEventListener( 'keydown', onKey, true );
		overlay.parentNode.removeChild( overlay );
		overlay = null;
		dialog = null;
		link.focus();
	}

	function focusables() {
		return Array.prototype.filter.call(
			dialog.querySelectorAll( 'button, input, textarea, a[href]' ),
			function ( n ) {
				return ! n.disabled && n.offsetParent !== null;
			}
		);
	}

	function onKey( e ) {
		if ( ! dialog ) {
			return;
		}
		if ( 'Escape' === e.key ) {
			e.preventDefault();
			close();
			return;
		}
		if ( 'Tab' !== e.key ) {
			return;
		}
		var list = focusables();
		if ( ! list.length ) {
			return;
		}
		var first = list[ 0 ];
		var last = list[ list.length - 1 ];
		if ( e.shiftKey && document.activeElement === first ) {
			e.preventDefault();
			last.focus();
		} else if ( ! e.shiftKey && document.activeElement === last ) {
			e.preventDefault();
			first.focus();
		}
	}

	function open() {
		overlay = el( 'div', 'emcp-df' );
		dialog = el( 'div', 'emcp-df__dialog' );
		dialog.setAttribute( 'role', 'dialog' );
		dialog.setAttribute( 'aria-modal', 'true' );
		dialog.setAttribute( 'aria-labelledby', 'emcp-df-title' );

		var x = el( 'button', 'emcp-df__close' );
		x.type = 'button';
		x.setAttribute( 'aria-label', s.close || 'Close' );
		x.textContent = '×';
		x.addEventListener( 'click', close );

		var title = el( 'h2', 'emcp-df__title', s.title );
		title.id = 'emcp-df-title';
		var intro = el( 'p', 'emcp-df__intro', s.intro );

		var form = el( 'form', 'emcp-df__form' );
		var group = el( 'fieldset', 'emcp-df__reasons' );
		group.appendChild( el( 'legend', 'screen-reader-text', s.legend ) );

		var detailWrap = el( 'div', 'emcp-df__detail' );
		var detailLabel = el( 'label', '', s.detail );
		var detail = el( 'input', '' );
		detail.type = 'text';
		detail.maxLength = 200;
		detail.id = 'emcp-df-detail';
		detailLabel.htmlFor = detail.id;
		detailWrap.appendChild( detailLabel );
		detailWrap.appendChild( detail );
		detailWrap.hidden = true;

		var commentWrap = el( 'div', 'emcp-df__comment' );
		var commentLabel = el( 'label', '', s.comment );
		var comment = el( 'textarea', '' );
		comment.maxLength = 1000;
		comment.rows = 3;
		comment.id = 'emcp-df-comment';
		commentLabel.htmlFor = comment.id;
		commentWrap.appendChild( commentLabel );
		commentWrap.appendChild( comment );
		commentWrap.hidden = true;

		var submit = el( 'button', 'button button-primary emcp-df__submit', s.submit );
		submit.type = 'submit';
		submit.disabled = true;

		var reasons = cfg.reasons || {};
		Object.keys( reasons ).forEach( function ( key ) {
			var row = el( 'label', 'emcp-df__reason' );
			var radio = el( 'input', '' );
			radio.type = 'radio';
			radio.name = 'emcp-df-reason';
			radio.value = key;
			radio.addEventListener( 'change', function () {
				submit.disabled = false;
				commentWrap.hidden = false;
				detailWrap.hidden = 'better_plugin' !== key;
			} );
			row.appendChild( radio );
			row.appendChild( document.createTextNode( ' ' + reasons[ key ] ) );
			group.appendChild( row );
		} );

		var sends = el( 'p', 'emcp-df__sends', cfg.linked ? s.sendsId : s.sends );

		var skip = el( 'button', 'button emcp-df__skip', s.skip );
		skip.type = 'button';
		skip.addEventListener( 'click', function () {
			busy = true;
			go();
		} );

		var actions = el( 'div', 'emcp-df__actions' );
		actions.appendChild( sends );
		actions.appendChild( skip );
		actions.appendChild( submit );

		form.appendChild( group );
		form.appendChild( detailWrap );
		form.appendChild( commentWrap );
		form.appendChild( actions );
		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			var picked = form.querySelector( 'input[name="emcp-df-reason"]:checked' );
			if ( ! picked || busy ) {
				return;
			}
			busy = true;
			submit.disabled = true;
			skip.disabled = true;
			var data = new URLSearchParams();
			data.append( 'action', 'emcp_lite_deactivation_feedback' );
			data.append( 'nonce', cfg.nonce );
			data.append( 'reason', picked.value );
			data.append( 'detail', detail.hidden ? '' : detail.value );
			data.append( 'comment', comment.value );
			data.append( 'network', cfg.network || '' );
			var done = false;
			var finish = function () {
				if ( ! done ) {
					done = true;
					go();
				}
			};
			window.setTimeout( finish, 2000 );
			try {
				window
					.fetch( cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: data } )
					.then( finish, finish );
			} catch ( err ) {
				finish();
			}
		} );

		dialog.appendChild( x );
		dialog.appendChild( title );
		dialog.appendChild( intro );
		dialog.appendChild( form );
		overlay.appendChild( dialog );
		overlay.addEventListener( 'mousedown', function ( e ) {
			if ( e.target === overlay ) {
				close();
			}
		} );
		document.body.appendChild( overlay );
		document.addEventListener( 'keydown', onKey, true );
		var firstRadio = group.querySelector( 'input' );
		( firstRadio || x ).focus();
	}

	link.addEventListener( 'click', function ( e ) {
		if ( e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey ) {
			return;
		}
		e.preventDefault();
		open();
	} );
} )();
