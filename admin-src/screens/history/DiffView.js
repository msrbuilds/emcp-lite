import { __, _n, sprintf } from '@wordpress/i18n';
import { EmptyState } from '@emcp/ui';
import { lineDiff } from './lib';

const REASONS = {
	not_found: __( 'This change no longer exists.', 'emcp-lite' ),
	unsupported: __( 'This kind of change has no preview.', 'emcp-lite' ),
	missing: __( 'The saved snapshot is no longer available.', 'emcp-lite' ),
	too_large: __( 'The content is larger than 256 KB.', 'emcp-lite' ),
	binary: __( 'This file is binary.', 'emcp-lite' ),
	outside: __( 'The file is outside this WordPress install.', 'emcp-lite' ),
};

const MARK = { add: '+', del: '-', same: ' ' };
const SPOKEN = {
	add: __( 'Added:', 'emcp-lite' ),
	del: __( 'Removed:', 'emcp-lite' ),
};

/**
 * Before/after line diff of one change (spec 9.1 Diff).
 *
 * @param {Object} props      Props.
 * @param {Object} props.diff { kind, before, after, reason }.
 */
export function DiffView( { diff } ) {
	if ( 'none' === diff.kind ) {
		return (
			<EmptyState icon="eye" title={ __( 'No preview', 'emcp-lite' ) }>
				{ REASONS[ diff.reason ] || REASONS.unsupported }
			</EmptyState>
		);
	}
	const lines = lineDiff( diff.before, diff.after );
	if ( lines.every( ( l ) => 'skip' === l.type || 'same' === l.type ) ) {
		return (
			<p className="emcp-history__same">
				{ __(
					'The current value matches the saved one.',
					'emcp-lite'
				) }
			</p>
		);
	}
	return (
		<pre
			className="emcp-diff"
			tabIndex={ 0 }
			role="region"
			aria-label={ __( 'Difference', 'emcp-lite' ) }
		>
			{ lines.map( ( l, i ) =>
				'skip' === l.type ? (
					<span key={ i } className="emcp-diff__line is-skip">
						{ sprintf(
							/* translators: %d: number of unchanged lines. */
							_n(
								'%d unchanged line',
								'%d unchanged lines',
								l.count,
								'emcp-lite'
							),
							l.count
						) }
					</span>
				) : (
					<span
						key={ i }
						className={ `emcp-diff__line is-${ l.type }` }
					>
						<span className="emcp-diff__mark" aria-hidden="true">
							{ MARK[ l.type ] }
						</span>
						{ SPOKEN[ l.type ] && (
							<span className="screen-reader-text">
								{ SPOKEN[ l.type ] }
							</span>
						) }
						<span>{ l.text }</span>
					</span>
				)
			) }
		</pre>
	);
}
