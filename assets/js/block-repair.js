/**
 * EMCP auto-repair for AI-inserted static-save blocks.
 *
 * Blocks from packs with a static JS save() (e.g. Kadence) are flagged
 * "Attempt recovery" by the editor when their markup was written server-side,
 * because stored HTML can never byte-match the JS save() output. This script
 * runs the exact same operation as the "Attempt Block Recovery" button —
 * replace the invalid block with createBlock( name, attributes, innerBlocks ),
 * regenerating markup via the block's OWN save() — automatically, for every
 * invalid block in the covered namespaces, then posts one notice asking for a
 * single Update click.
 *
 * Enqueued only on posts the MCP add path flagged (postmeta), localized with
 * `emcpBlockRepair.namespaces` (e.g. ["kadence/"]).
 *
 * @package EMCP_Tools
 * @since   3.12.2
 */
( function ( wp ) {
	'use strict';

	var config = window.emcpBlockRepair || {};
	var namespaces = Array.isArray( config.namespaces ) ? config.namespaces : [];
	var done = false;

	if ( ! namespaces.length || ! wp || ! wp.data || ! wp.blocks ) {
		return;
	}

	function inNamespaces( name ) {
		for ( var i = 0; i < namespaces.length; i++ ) {
			if ( name && name.indexOf( namespaces[ i ] ) === 0 ) {
				return true;
			}
		}
		return false;
	}

	/** Deep-clone a block into a fresh, valid one (what "Attempt Block Recovery" does).
	 * `className` is dropped: on AI-inserted markup, Gutenberg's className support
	 * can extract a block's OWN field-selector class from the root element (seen
	 * live with kadence/infobox's `kt-blocks-info-box-title`), and a recovery that
	 * keeps it re-renders the class on the root, where the field selector matches
	 * it and swallows the block's content on every parse. These flagged blocks were
	 * machine-inserted, so no human-authored className is lost. */
	function recoverBlock( block ) {
		var attributes = {};
		Object.keys( block.attributes || {} ).forEach( function ( key ) {
			if ( 'className' !== key ) {
				attributes[ key ] = block.attributes[ key ];
			}
		} );
		return wp.blocks.createBlock(
			block.name,
			attributes,
			( block.innerBlocks || [] ).map( recoverBlock )
		);
	}

	/** Collect invalid, in-scope blocks depth-first. A block we replace wholesale
	 * regenerates its whole subtree, so we don't descend into collected ones. */
	function collectInvalid( blocks, out ) {
		( blocks || [] ).forEach( function ( block ) {
			if ( false === block.isValid && inNamespaces( block.name ) ) {
				out.push( block );
			} else if ( block.innerBlocks && block.innerBlocks.length ) {
				collectInvalid( block.innerBlocks, out );
			}
		} );
		return out;
	}

	var totalRepaired = 0;

	function repairPass() {
		var select = wp.data.select( 'core/block-editor' );
		var dispatch = wp.data.dispatch( 'core/block-editor' );
		var invalid = collectInvalid( select.getBlocks(), [] );

		invalid.forEach( function ( block ) {
			dispatch.replaceBlock( block.clientId, recoverBlock( block ) );
		} );

		totalRepaired += invalid.length;
		return invalid.length;
	}

	function notify() {
		var __ = wp.i18n.__;
		var sprintf = wp.i18n.sprintf;
		wp.data.dispatch( 'core/notices' ).createNotice(
			'success',
			sprintf(
				/* translators: %d: number of repaired blocks */
				__( 'EMCP repaired %d AI-inserted block(s) with the editor’s own recovery. Click Update to keep the repaired version.', 'emcp-tools' ),
				totalRepaired
			),
			{ id: 'emcp-block-repair', isDismissible: true }
		);
	}

	/**
	 * Repair passes must CONVERGE, not run once: some packs' container blocks
	 * (Kadence rowlayout) re-manipulate their inner blocks right after mount,
	 * which can resurrect invalid children AFTER a first-pass repair (observed
	 * live: repaired columns re-invalidated by the row's own init effect). So
	 * scan repeatedly on a short interval until a pass finds nothing to repair
	 * (settled) or the pass budget runs out, then post one aggregate notice.
	 */
	function repairUntilSettled() {
		var passes = 0;
		var settledScans = 0;
		var loop = setInterval( function () {
			passes++;
			var repaired = repairPass();
			if ( 0 === repaired ) {
				settledScans++;
			} else {
				settledScans = 0;
			}
			// Two consecutive clean scans = the editor settled; stop.
			if ( settledScans >= 2 || passes > 20 ) {
				clearInterval( loop );
				if ( totalRepaired && ! done ) {
					done = true;
					notify();
				}
			}
		}, 600 );
	}

	wp.domReady( function () {
		// The block list populates asynchronously after domReady; start once the
		// editor holds blocks. A flagged post always has content, so a non-empty
		// block list is the ready signal; gives up quietly after ~10s.
		var tries = 0;
		var timer = setInterval( function () {
			tries++;
			var blocks = wp.data.select( 'core/block-editor' ).getBlocks();
			if ( blocks && blocks.length ) {
				clearInterval( timer );
				repairUntilSettled();
			} else if ( tries > 100 ) {
				clearInterval( timer );
			}
		}, 100 );
	} );
} )( window.wp );
