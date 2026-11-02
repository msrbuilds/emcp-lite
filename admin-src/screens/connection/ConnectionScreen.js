import { useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { Notice, PageHeader, Segmented, useQueryState } from '@emcp/ui';
import { McpSetup } from './McpSetup';
import { ServerRail } from './ServerRail';
import { CloudSection } from './CloudSection';
import { ServicesSection } from './ServicesSection';

const CLOUD_FLAGS = [
	'cloud_connected',
	'cloud_error',
	'cloud_disconnected',
	'cloud_gateway',
	'synced',
];

function flagNotice() {
	const q = new URLSearchParams( window.location.search );
	if ( q.get( 'cloud_connected' ) ) {
		return [
			'success',
			__( 'This site is connected to EMCP Cloud.', 'emcp-lite' ),
		];
	}
	if ( q.get( 'cloud_disconnected' ) ) {
		return [
			'info',
			__( 'This site is disconnected from EMCP Cloud.', 'emcp-lite' ),
		];
	}
	if ( q.get( 'cloud_error' ) ) {
		return [
			'error',
			__(
				'Connecting to EMCP Cloud failed. Please try again.',
				'emcp-lite'
			),
		];
	}
	if ( 'disabled' === q.get( 'cloud_gateway' ) ) {
		return [
			'success',
			__( 'Gateway access is off for this site.', 'emcp-lite' ),
		];
	}
	if ( 'reissued' === q.get( 'cloud_gateway' ) ) {
		return [
			'success',
			__( 'Gateway credential re-issued.', 'emcp-lite' ),
		];
	}
	if ( q.get( 'cloud_gateway' ) ) {
		return [
			'error',
			__(
				'The gateway credential could not be issued. Reconnect with gateway access switched on, then try again.',
				'emcp-lite'
			),
		];
	}
	if ( 'push' === q.get( 'synced' ) ) {
		return [
			'success',
			__( 'Settings pushed to the cloud.', 'emcp-lite' ),
		];
	}
	if ( 'pull' === q.get( 'synced' ) ) {
		const applied = parseInt( q.get( 'sync_applied' ) || '0', 10 );
		return [
			'success',
			applied > 0
				? sprintf(
						/* translators: %d: number of settings applied. */
						_n(
							'Settings pulled from the cloud: %d setting applied.',
							'Settings pulled from the cloud: %d settings applied.',
							applied,
							'emcp-lite'
						),
						applied
				  )
				: __( 'Settings pulled from the cloud and applied.', 'emcp-lite' ),
		];
	}
	if ( 'err' === q.get( 'synced' ) ) {
		return [ 'error', syncErrorMessage( q.get( 'sync_error' ) || '' ) ];
	}
	return null;
}

/**
 * What went wrong with a settings push or pull, from the handler's error code.
 *
 * @param {string} code WP_Error code passed back by the admin-post handler.
 * @return {string} Notice text.
 */
export function syncErrorMessage( code ) {
	switch ( code ) {
		case 'nothing_pushed':
			return __(
				'There are no settings in EMCP Cloud to pull yet. Open the site whose settings you want to copy, click "Push settings to cloud" there, then pull here.',
				'emcp-lite'
			);
		case 'nothing_applied':
		case 'invalid_blob':
			return __(
				'The settings stored in EMCP Cloud could not be applied to this site. Push them again from the site you want to copy, then pull here.',
				'emcp-lite'
			);
		case 'not_entitled':
		case 'cloud_http_402':
			return __(
				'Settings sync is part of the paid EMCP Cloud plans. Upgrade your plan, then try again.',
				'emcp-lite'
			);
		case 'not_connected':
		case 'cloud_http_401':
			return __(
				'This site is no longer signed in to EMCP Cloud. Reconnect it, then try again.',
				'emcp-lite'
			);
		default:
			return code
				? sprintf(
						/* translators: %s: error code. */
						__(
							'Settings sync failed (%s). EMCP Cloud may be busy: please try again in a minute.',
							'emcp-lite'
						),
						code
				  )
				: __(
						'Settings sync failed. Please try again in a minute.',
						'emcp-lite'
				  );
	}
}

export function ConnectionScreen( { data } ) {
	const fromCloud = CLOUD_FLAGS.some( ( f ) =>
		new URLSearchParams( window.location.search ).has( f )
	);
	const [ section, setSection ] = useQueryState(
		'section',
		fromCloud ? 'cloud' : 'mcp'
	);
	const [ apps, setApps ] = useState( data.apps );
	// Saved in the rail, read by the wizard: OAuth state and the public URLs.
	const [ live, setLive ] = useState( {
		oauth: data.oauth,
		endpoint: data.endpoint,
		siteUrl: data.siteUrl,
	} );
	const [ notice ] = useState( flagNotice );
	const options = [
		{ value: 'mcp', label: __( 'MCP', 'emcp-lite' ) },
		{ value: 'cloud', label: __( 'Cloud', 'emcp-lite' ) },
		{ value: 'services', label: __( '3rd-party services', 'emcp-lite' ) },
	];
	return (
		<div className="eui-conn">
			<PageHeader
				title={ __( 'Connection', 'emcp-lite' ) }
				description={ __(
					'Connect an AI client to this site in four steps.',
					'emcp-lite'
				) }
				actions={
					<Segmented
						label={ __( 'Section', 'emcp-lite' ) }
						value={ section }
						onChange={ setSection }
						options={ options }
					/>
				}
			/>
			{ notice && <Notice tone={ notice[ 0 ] }>{ notice[ 1 ] }</Notice> }
			{ /* Kept mounted while hidden: the open setup and a one-time password survive a section switch. */ }
			<div className="eui-conn__grid" hidden={ 'mcp' !== section }>
				<div className="eui-conn__main">
					<McpSetup data={ { ...data, ...live, apps } } />
				</div>
				<ServerRail
					data={ data }
					apps={ apps }
					setApps={ setApps }
					onSaved={ ( res ) =>
						setLive( {
							oauth: res.oauth,
							endpoint: res.endpoint || live.endpoint,
							siteUrl: res.siteUrl || live.siteUrl,
						} )
					}
				/>
			</div>
			{ 'cloud' === section && <CloudSection data={ data } /> }
			{ 'services' === section && <ServicesSection data={ data } /> }
		</div>
	);
}
