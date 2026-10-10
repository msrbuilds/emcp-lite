import { __, sprintf } from '@wordpress/i18n';
import {
	Badge,
	Button,
	Card,
	Icon,
	Notice,
	Toggle,
	useConfirm,
} from '@emcp/ui';
import { useState } from '@wordpress/element';

function PostForm( {
	url,
	action,
	nonce,
	nonceName = '_wpnonce',
	children,
	fields = {},
	confirmText = '',
	confirmLabel = __( 'Pull', 'emcp-lite' ),
} ) {
	const confirm = useConfirm();
	const onSubmit = async ( e ) => {
		if ( ! confirmText ) {
			return;
		}
		e.preventDefault();
		const form = e.currentTarget;
		if (
			await confirm( {
				title: confirmText,
				confirmLabel,
				tone: 'danger',
			} )
		) {
			form.submit();
		}
	};
	return (
		<form
			method="post"
			action={ url }
			className="eui-conn__inline-form"
			onSubmit={ onSubmit }
		>
			<input type="hidden" name="action" value={ action } />
			<input type="hidden" name={ nonceName } value={ nonce } />
			{ Object.entries( fields ).map( ( [ k, v ] ) => (
				<input key={ k } type="hidden" name={ k } value={ v } />
			) ) }
			{ children }
		</form>
	);
}

const defaultNavigate = ( url ) => window.location.assign( url );

/**
 * A site-local date ('2026-09-20') in the viewer's locale.
 *
 * @param {string} ymd Date from the server.
 * @return {string} Formatted date.
 */
function formatDay( ymd ) {
	return new Date( `${ ymd }T00:00:00Z` ).toLocaleDateString( undefined, {
		dateStyle: 'medium',
		timeZone: 'UTC',
	} );
}

/**
 * Who the site is linked to: the account email (redacted by the server),
 * the Cloud host and when it connected.
 *
 * @param {Object} props
 * @param {Object} props.c            data.cloud.
 * @param {string} props.adminPostUrl admin-post.php.
 */
function Account( { c, adminPostUrl } ) {
	const when = c.connectedAt
		? sprintf(
				/* translators: %s: date. */
				__( 'Connected %s', 'emcp-lite' ),
				formatDay( c.connectedAt )
			)
		: '';
	return (
		<div className="eui-conn__account">
			<span className="eui-conn__account-icon" aria-hidden="true">
				<Icon name="cloud" size={ 18 } />
			</span>
			<span className="eui-conn__account-text">
				<strong className="eui-conn__account-name">
					{ c.account || __( 'EMCP Cloud account', 'emcp-lite' ) }
				</strong>
				<span className="eui-conn__muted">
					{ [ c.host || c.baseUrl, when ]
						.filter( Boolean )
						.join( ' · ' ) }
				</span>
				{ ! c.account && (
					// Connections made before the email scope never got an
					// email; connecting again keeps gateway access as it is.
					<PostForm
						url={ adminPostUrl }
						action={ c.connectAction }
						nonce={ c.connectNonce }
						fields={ c.gateway ? { emcp_gateway_optin: '1' } : {} }
					>
						<button
							type="submit"
							className="eui-conn__setting-link"
						>
							{ __(
								'Reconnect to show the account email',
								'emcp-lite'
							) }
						</button>
					</PostForm>
				) }
			</span>
		</div>
	);
}

export function CloudSection( { data, navigate = defaultNavigate } ) {
	const confirm = useConfirm();
	const [ gateway, setGateway ] = useState( false );
	// Set while a gateway change is on its way to admin-post.
	const [ pending, setPending ] = useState( null );
	const c = data.cloud;
	if ( ! c ) {
		return (
			<Notice tone="info">
				{ __(
					'Turn on the EMCP Cloud module to connect this site.',
					'emcp-lite'
				) }
			</Notice>
		);
	}
	const connect = (
		<PostForm
			url={ data.adminPostUrl }
			action={ c.connectAction }
			nonce={ c.connectNonce }
			fields={ gateway ? { emcp_gateway_optin: '1' } : {} }
		>
			<Toggle
				checked={ gateway }
				onChange={ setGateway }
				label={ __(
					'Let EMCP Cloud manage this site through the gateway',
					'emcp-lite'
				) }
			/>
			<Button type="submit" variant="primary">
				{ c.connected
					? __( 'Reconnect', 'emcp-lite' )
					: __( 'Connect to EMCP Cloud', 'emcp-lite' ) }
			</Button>
		</PostForm>
	);
	const disconnect = async () => {
		if (
			await confirm( {
				title: __( 'Disconnect EMCP Cloud?', 'emcp-lite' ),
				message: __(
					'Backups and sync stop until you connect again.',
					'emcp-lite'
				),
				confirmLabel: __( 'Disconnect', 'emcp-lite' ),
				tone: 'danger',
			} )
		) {
			navigate( c.disconnectUrl );
		}
	};
	const gatewayOn = null === pending ? !! c.gateway : pending;
	const toggleGateway = async ( on ) => {
		if ( ! on ) {
			const ok = await confirm( {
				title: __( 'Turn off gateway access?', 'emcp-lite' ),
				message: __(
					'AI clients connected through the EMCP Cloud gateway lose access to this site until you turn it back on.',
					'emcp-lite'
				),
				confirmLabel: __( 'Turn off', 'emcp-lite' ),
				tone: 'danger',
			} );
			if ( ! ok ) {
				return;
			}
		}
		setPending( on );
		navigate( on ? c.reissueUrl : c.gatewayOffUrl );
	};
	return (
		<div className="eui-conn__cloud">
			{ data.management && (
				<Notice tone="info">
					<p>
						{ sprintf(
							/* translators: 1: WordPress login, 2: user ID. */
							__(
								'Managing this site as %1$s (WordPress user ID %2$d).',
								'emcp-lite'
							),
							data.management.login,
							data.management.id
						) }
					</p>
					<p>
						{ __(
							'Reissuing Gateway access authorizes this WordPress account. Existing MCP credentials retain their original execution identity and permissions.',
							'emcp-lite'
						) }
					</p>
					<a href={ data.management.url }>
						{ __( 'Manage local access', 'emcp-lite' ) }
					</a>
				</Notice>
			) }
			{ c.identityConflict && (
				<Notice tone="warning">
					{ __(
						'This installation has a copied Cloud identity or a changed address. Cloud requests are paused to protect the original connection. Connect it as a separate site below.',
						'emcp-lite'
					) }
				</Notice>
			) }
			<Card
				title={ __( 'Cloud account', 'emcp-lite' ) }
				actions={
					c.connected ? (
						<Badge
							kind="status"
							value={ c.healthy ? 'success' : 'warning' }
						>
							{ c.healthy
								? __( 'Connected', 'emcp-lite' )
								: __( 'Reconnect needed', 'emcp-lite' ) }
						</Badge>
					) : null
				}
			>
				{ ! c.connected && (
					<p className="eui-conn__muted">
						{ __(
							'Optional: connect to EMCP Cloud to sync settings. Connecting sends your site URL, name, installation identifier and authorization details to the configured Cloud service (emcptools.com by default). Gateway access is a separate opt-in that sends a revocable credential for your WordPress user. Requests use your WordPress permissions and enabled tools, including write operations, and relay the content requested by your AI client.',
							'emcp-lite'
						) }
					</p>
				) }
				<p className="eui-conn__muted">
					<a href="https://emcptools.com/privacy/" target="_blank" rel="noopener noreferrer">{ __( 'Privacy policy', 'emcp-lite' ) }</a>{ ' · ' }
					<a href="https://emcptools.com/terms-of-service/" target="_blank" rel="noopener noreferrer">{ __( 'Terms of service', 'emcp-lite' ) }</a>
				</p>
				{ c.connected && (
					<div className="eui-conn__account-row">
						<Account c={ c } adminPostUrl={ data.adminPostUrl } />
						<Button size="sm" onClick={ disconnect }>
							{ __( 'Disconnect', 'emcp-lite' ) }
						</Button>
					</div>
				) }
				{ ! c.identityConflict &&
					( ! c.connected || ! c.healthy ) &&
					connect }
				{ c.connected && c.healthy && (
					<div className="eui-conn__setting">
						<div className="eui-conn__setting-text">
							<span className="eui-conn__setting-title">
								{ __( 'Gateway access', 'emcp-lite' ) }
							</span>
							<span className="eui-conn__muted">
								{ __(
									'Enabling Gateway sends a revocable credential for your WordPress user to the configured Cloud service. Requests use your permissions and enabled tools, including write operations, and relay requested site content and personal data. Disable Gateway here to revoke access.',
									'emcp-lite'
								) }
							</span>
							{ gatewayOn && null === pending && (
								<a
									className="eui-conn__setting-link"
									href={ c.reissueUrl }
								>
									{ __(
										'Re-issue credential',
										'emcp-lite'
									) }
								</a>
							) }
						</div>
						<Toggle
							checked={ gatewayOn }
							onChange={ toggleGateway }
							disabled={ null !== pending }
							label={ __( 'Gateway access', 'emcp-lite' ) }
							hideLabel
						/>
					</div>
				) }
			</Card>
			{ c.separateAction && (
				<Card title={ __( 'Cloned or restored site?', 'emcp-lite' ) }>
					<p>
						{ __(
							'Use a separate Cloud identity for a copy of another site. This clears only this installation’s saved Cloud connection. The original site and its Cloud data stay connected. You will connect this copy again and it will count toward your site allowance.',
							'emcp-lite'
						) }
					</p>
					<PostForm
						url={ data.adminPostUrl }
						action={ c.separateAction }
						nonce={ c.separateNonce }
						fields={ { confirm_separate: '1' } }
						confirmText={ __(
							'Give this installation a separate Cloud identity?',
							'emcp-lite'
						) }
						confirmLabel={ __( 'Separate site', 'emcp-lite' ) }
					>
						<Button type="submit">
							{ __( 'Connect as a separate site', 'emcp-lite' ) }
						</Button>
					</PostForm>
				</Card>
			) }
			{ c.connected && c.sync && (
				<Card title={ __( 'Settings sync', 'emcp-lite' ) }>
					{ c.sync.entitled ? (
						<>
							<p className="eui-conn__muted">
								{ __(
									'Copy supported EMCP settings between connected sites, including tool toggles, modules, behaviour preferences and free-text site context. Credential options are excluded by default. Site context is not automatically redacted: do not include passwords or API keys.',
									'emcp-lite'
								) }
							</p>
							<div className="eui-conn__actions">
								<PostForm
									url={ data.adminPostUrl }
									action="emcp_tools_settings_push"
									nonce={ c.sync.nonce }
								>
									<Button type="submit" variant="primary">
										{ __(
											'Push settings to cloud',
											'emcp-lite'
										) }
									</Button>
								</PostForm>
								<PostForm
									url={ data.adminPostUrl }
									action="emcp_tools_settings_pull"
									nonce={ c.sync.nonce }
									confirmText={ __(
										"Pull settings and overwrite this site's settings?",
										'emcp-lite'
									) }
								>
									<Button type="submit">
										{ __(
											'Pull settings from cloud',
											'emcp-lite'
										) }
									</Button>
								</PostForm>
							</div>
						</>
					) : (
						<>
							<p className="eui-conn__muted">
								{ __(
									'Sync your EMCP settings across all your sites. This is a paid EMCP Cloud feature.',
									'emcp-lite'
								) }
							</p>
							<a
								className="eui-btn"
								href={ c.sync.billingUrl }
								target="_blank"
								rel="noopener noreferrer"
							>
								{ __(
									'Upgrade your Cloud plan',
									'emcp-lite'
								) }
							</a>
						</>
					) }
				</Card>
			) }
		</div>
	);
}
