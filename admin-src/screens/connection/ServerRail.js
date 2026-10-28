import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	Badge,
	Button,
	Card,
	CopyField,
	EmptyState,
	Field,
	Icon,
	TextInput,
	Toggle,
	errorMessage,
	request,
	useConfirm,
	useSettingsForm,
	useToast,
} from '@emcp/ui';

const API = '/emcp-tools/v1/admin/connection';

export function ServerRail( { data, apps, setApps, onSaved = () => {} } ) {
	const toast = useToast();
	const confirm = useConfirm();
	const [ status, setStatus ] = useState( data.status );
	const [ oauth, setOauth ] = useState( data.oauth );
	const [ open, setOpen ] = useState( true );
	const form = useSettingsForm( data.advanced, async ( diff ) => {
		try {
			const res = await request( API + '/advanced', {
				method: 'POST',
				data: diff,
			} );
			setStatus( res.status );
			setOauth( res.oauth );
			onSaved( res );
			if ( res.ignored?.length ) {
				toast.error(
					__(
						'OAuth sign-in needs HTTPS on this site.',
						'emcp-lite'
					)
				);
			} else {
				toast.success(
					__(
						'Settings saved. Reconnect your client to see the change.',
						'emcp-lite'
					)
				);
			}
			return res.advanced;
		} catch ( e ) {
			toast.error( errorMessage( e ) );
			throw e;
		}
	} );
	const good =
		status.abilitiesApi &&
		status.serverEnabled &&
		'none' !== status.adapter;
	const copy = status.adapterCopy;
	const adapterLabel = {
		plugin: __( 'Active', 'emcp-lite' ),
		external: __( 'External', 'emcp-lite' ),
		none: __( 'Not active', 'emcp-lite' ),
	}[ status.adapter ];
	const rows = [
		[
			__( 'EMCP Lite', 'emcp-lite' ),
			__( 'Active', 'emcp-lite' ),
			true,
		],
		[
			__( 'MCP Adapter', 'emcp-lite' ),
			copy && ! copy.ours && copy.plugin
				? sprintf(
						/* translators: 1: Active or External, 2: plugin folder, 3: its adapter version. */
						__( '%1$s, with %2$s %3$s loaded first', 'emcp-lite' ),
						adapterLabel,
						copy.plugin,
						copy.version || '?'
					)
				: adapterLabel,
			'none' !== status.adapter,
		],
		[
			__( 'MCP Server', 'emcp-lite' ),
			status.serverEnabled
				? __( 'Enabled', 'emcp-lite' )
				: __( 'Disabled', 'emcp-lite' ),
			status.serverEnabled,
		],
		[
			__( 'Tools enabled', 'emcp-lite' ),
			`${ status.toolsEnabled } / ${ status.toolsTotal }`,
			status.toolsEnabled > 0,
		],
	];

	const removeApp = async ( app, revokeOnly ) => {
		const ok = await confirm( {
			title: revokeOnly
				? /* translators: %s: app name. */
					sprintf( __( 'Sign %s out?', 'emcp-lite' ), app.name )
				: /* translators: %s: app name. */
					sprintf( __( 'Remove %s?', 'emcp-lite' ), app.name ),
			message: revokeOnly
				? __(
						'Its tokens stop working; it can sign in again.',
						'emcp-lite'
					)
				: __(
						'The app and its tokens are deleted. It must register again to connect.',
						'emcp-lite'
					),
			confirmLabel: revokeOnly
				? __( 'Sign out', 'emcp-lite' )
				: __( 'Remove', 'emcp-lite' ),
			tone: 'danger',
		} );
		if ( ! ok ) {
			return;
		}
		try {
			const path =
				API +
				'/oauth-clients/' +
				encodeURIComponent( app.id ) +
				( revokeOnly ? '/revoke' : '' );
			const res = await request( path, {
				method: revokeOnly ? 'POST' : 'DELETE',
			} );
			setApps( res.apps );
		} catch ( e ) {
			toast.error( errorMessage( e ) );
		}
	};

	return (
		<div className="eui-conn__rail">
			<Card>
				<section aria-labelledby="eui-conn-status">
					<div className="eui-conn__rail-head">
						<h2
							id="eui-conn-status"
							className="eui-conn__rail-title"
						>
							{ __( 'Server status', 'emcp-lite' ) }
						</h2>
						<Badge
							kind="status"
							value={ good ? 'success' : 'warning' }
						>
							{ good
								? __( 'All good', 'emcp-lite' )
								: __( 'Needs attention', 'emcp-lite' ) }
						</Badge>
					</div>
					<ul className="eui-conn__status">
						{ rows.map( ( [ label, value, ok ] ) => (
							<li key={ label }>
								<span
									className={
										ok
											? 'eui-conn__dot is-ok'
											: 'eui-conn__dot'
									}
									aria-hidden="true"
								/>
								{ label }
								<strong>{ value }</strong>
							</li>
						) ) }
					</ul>
					<CopyField
						value={ data.endpoint }
						label={ __( 'MCP endpoint', 'emcp-lite' ) }
					/>
				</section>
			</Card>
			<Card>
				<section aria-labelledby="eui-conn-apps">
					<h2 id="eui-conn-apps" className="eui-conn__rail-title">
						{ __( 'Connected apps', 'emcp-lite' ) }
					</h2>
					{ ! apps.length && (
						<EmptyState
							icon="plug"
							title={ __( 'No apps yet', 'emcp-lite' ) }
						>
							{ __(
								'They show up here as soon as they register.',
								'emcp-lite'
							) }
						</EmptyState>
					) }
					<ul className="eui-conn__apps">
						{ apps.map( ( a ) => (
							<li key={ a.id }>
								<div>
									<strong>{ a.name }</strong>
									<span className="eui-conn__muted">
										{
											{
												connected: __(
													'Connected',
													'emcp-lite'
												),
												signed_out: __(
													'Signed out',
													'emcp-lite'
												),
												registered: __(
													'Never signed in',
													'emcp-lite'
												),
											}[ a.state ]
										}
										{ a.user ? ' · ' + a.user : '' }
									</span>
								</div>
								<div className="eui-conn__app-actions">
									{ 'connected' === a.state && (
										<Button
											size="sm"
											onClick={ () =>
												removeApp( a, true )
											}
											aria-label={ sprintf(
												/* translators: %s: app. */ __(
													'Sign out %s',
													'emcp-lite'
												),
												a.name
											) }
										>
											{ __( 'Sign out', 'emcp-lite' ) }
										</Button>
									) }
									<Button
										size="sm"
										variant="ghost"
										onClick={ () => removeApp( a, false ) }
										aria-label={ sprintf(
											/* translators: %s: app. */ __(
												'Remove %s',
												'emcp-lite'
											),
											a.name
										) }
									>
										<Icon name="trash-2" />
									</Button>
								</div>
							</li>
						) ) }
					</ul>
				</section>
			</Card>
			<Card>
				<section aria-labelledby="eui-conn-adv">
					<button
						type="button"
						className="eui-conn__rail-toggle"
						aria-expanded={ open }
						onClick={ () => setOpen( ! open ) }
					>
						<span
							id="eui-conn-adv"
							className="eui-conn__rail-title"
						>
							{ __( 'Advanced settings', 'emcp-lite' ) }
						</span>
						<Icon
							name={ open ? 'chevron-down' : 'chevron-right' }
						/>
					</button>
					{ open && (
						<div className="eui-conn__adv">
							<Toggle
								checked={ form.values.server_enabled }
								onChange={ ( v ) =>
									form.setValue( 'server_enabled', v )
								}
								label={ __( 'Abilities API', 'emcp-lite' ) }
							/>
							<p className="eui-conn__muted">
								{ __(
									'Expose EMCP tools to AI agents on this site.',
									'emcp-lite'
								) }
							</p>
							<Toggle
								checked={ form.values.oauth_enabled }
								onChange={ ( v ) =>
									form.setValue( 'oauth_enabled', v )
								}
								label={ __( 'OAuth sign-in', 'emcp-lite' ) }
								disabled={ ! oauth.available }
							/>
							<p className="eui-conn__muted">
								{ oauth.available
									? __(
											'Clients sign in instead of pasting a password.',
											'emcp-lite'
										)
									: __(
											'Needs HTTPS on this site.',
											'emcp-lite'
										) }
							</p>
							<Toggle
								checked={ form.values.strict_schemas }
								onChange={ ( v ) =>
									form.setValue( 'strict_schemas', v )
								}
								label={ __(
									'OpenAI-strict schemas',
									'emcp-lite'
								) }
							/>
							<p className="eui-conn__muted">
								{ __(
									'Only for strict function-calling clients (for example CrewAI). Keep off for Claude and Gemini.',
									'emcp-lite'
								) }
							</p>
							<Field
								label={ __(
									'Server URL override',
									'emcp-lite'
								) }
							>
								{ ( a11y ) => (
									<TextInput
										{ ...a11y }
										value={ form.values.public_base_url }
										placeholder={ sprintf(
											/* translators: %s: URL. */ __(
												'Auto-detected: %s',
												'emcp-lite'
											),
											data.detectedBaseUrl
										) }
										onChange={ ( e ) =>
											form.setValue(
												'public_base_url',
												e.target.value
											)
										}
									/>
								) }
							</Field>
							<div className="eui-conn__adv-foot">
								<p className="eui-conn__warn">
									<Icon name="triangle-alert" />{ ' ' }
									{ __(
										'Agents can create, edit and delete content.',
										'emcp-lite'
									) }
								</p>
								<Button
									variant="primary"
									onClick={ form.submit }
									loading={ form.saving }
									disabled={ ! form.dirty || form.saving }
								>
									{ __( 'Save', 'emcp-lite' ) }
								</Button>
							</div>
						</div>
					) }
				</section>
			</Card>
		</div>
	);
}
