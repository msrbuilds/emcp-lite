import { useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	Button,
	Card,
	Drawer,
	EmptyState,
	IconButton,
	Notice,
	PageHeader,
	Pagination,
	SearchInput,
	Table,
	Toggle,
	errorMessage,
	request,
	useConfirm,
	useQueryState,
	useToast,
} from '@emcp/ui';
import { RedirectForm } from './RedirectForm';

const API = '/emcp-tools/v1/admin/redirects';

/**
 * Redirects screen (spec 8.21).
 *
 * @param {Object} props      Props.
 * @param {Object} props.data Boot payload (EMCP_Tools_Admin_Redirects_Data).
 */
export function RedirectsScreen( { data } ) {
	const [ state, setState ] = useState( data );
	const [ search, setSearch ] = useQueryState( 'search', '' );
	const [ , setPage ] = useQueryState( 'paged', '1' );
	const [ busy, setBusy ] = useState( '' );
	const [ editing, setEditing ] = useState( null );
	const [ warning, setWarning ] = useState( '' );
	const [ form, setForm ] = useState( { key: 0, source: '', focus: false } );
	const toast = useToast();
	const confirm = useConfirm();
	// Bumped on every list load: an older response is dropped.
	const generation = useRef( 0 );

	const view = { search, page: state.page };

	const load = async ( next ) => {
		const mine = ++generation.current;
		const params = new URLSearchParams();
		if ( next.search ) {
			params.set( 'search', next.search );
		}
		if ( next.page > 1 ) {
			params.set( 'page', String( next.page ) );
		}
		const qs = params.toString();
		try {
			const r = await request( qs ? `${ API }?${ qs }` : API );
			if ( mine === generation.current ) {
				setState( r );
			}
		} catch ( e ) {
			toast.error( errorMessage( e ) );
		}
	};

	const write = async ( id, path, method, body ) => {
		setBusy( id );
		const mine = ++generation.current;
		try {
			const r = await request( path, {
				method,
				data: { ...body, search: view.search, page: view.page },
			} );
			if ( mine === generation.current ) {
				setState( r.list );
			}
			return r.result;
		} catch ( e ) {
			toast.error( errorMessage( e ) );
			return null;
		} finally {
			setBusy( '' );
		}
	};

	const create = async ( body ) => {
		const result = await write( 'create', API, 'POST', body );
		if ( result ) {
			setWarning( result.warning || '' );
			setForm( ( f ) => ( {
				key: f.key + 1,
				source: '',
				focus: false,
			} ) );
			toast.success( __( 'Redirect added.', 'emcp-lite' ) );
		}
	};

	const save = async ( body ) => {
		const result = await write(
			`edit:${ editing.id }`,
			`${ API }/${ editing.id }`,
			'POST',
			body
		);
		if ( result ) {
			setEditing( null );
			toast.success( __( 'Redirect saved.', 'emcp-lite' ) );
		}
	};

	const toggle = ( r ) =>
		write( `toggle:${ r.id }`, `${ API }/${ r.id }`, 'POST', {
			enabled: ! r.enabled,
		} );

	const remove = async ( r ) => {
		const ok = await confirm( {
			title: __( 'Delete this redirect?', 'emcp-lite' ),
			message: sprintf(
				/* translators: %s: source path. */
				__(
					'%s will stop redirecting. You can undo this from History.',
					'emcp-lite'
				),
				r.source
			),
			confirmLabel: __( 'Delete', 'emcp-lite' ),
			tone: 'danger',
		} );
		if (
			ok &&
			( await write( `delete:${ r.id }`, `${ API }/${ r.id }`, 'DELETE', {
				confirm: true,
			} ) )
		) {
			toast.success( __( 'Redirect deleted.', 'emcp-lite' ) );
		}
	};

	const accept = async ( s ) => {
		if ( ! s.suggestedTarget ) {
			// Nowhere obvious to send it: hand the path to the Add form.
			setForm( ( f ) => ( {
				key: f.key + 1,
				source: s.oldPath,
				focus: true,
			} ) );
			return;
		}
		const result = await write(
			`accept:${ s.key }`,
			`${ API }/suggestions/${ s.key }/accept`,
			'POST',
			{
				targetPostId: s.suggestedTarget.postId,
				code: 301,
				ignoreQuery: true,
			}
		);
		if ( result ) {
			setWarning( result.warning || '' );
			toast.success( __( 'Redirect added.', 'emcp-lite' ) );
		}
	};

	const dismiss = ( s ) =>
		write(
			`dismiss:${ s.key }`,
			`${ API }/suggestions/${ s.key }/dismiss`,
			'POST',
			{}
		);

	const goTo = ( p ) => {
		setPage( String( p ) );
		load( { search, page: p } );
	};

	const columns = [
		{
			key: 'source',
			header: __( 'From', 'emcp-lite' ),
			render: ( r ) => (
				<code className="emcp-redirects__path">{ r.source }</code>
			),
		},
		{
			key: 'target',
			header: __( 'To', 'emcp-lite' ),
			render: ( r ) =>
				r.targetPostId ? (
					r.targetTitle || r.targetUrl
				) : (
					<code className="emcp-redirects__path">{ r.target }</code>
				),
		},
		{ key: 'code', header: __( 'Type', 'emcp-lite' ) },
		{ key: 'hits', header: __( 'Hits', 'emcp-lite' ), align: 'end' },
		{
			key: 'enabled',
			header: __( 'Enabled', 'emcp-lite' ),
			render: ( r ) => (
				<Toggle
					checked={ r.enabled }
					disabled={ busy === `toggle:${ r.id }` }
					label={ sprintf(
						/* translators: %s: source path. */
						__( 'Enabled: %s', 'emcp-lite' ),
						r.source
					) }
					hideLabel
					onChange={ () => toggle( r ) }
				/>
			),
		},
		{
			key: 'actions',
			header: (
				<span className="eui-visually-hidden">
					{ __( 'Actions', 'emcp-lite' ) }
				</span>
			),
			align: 'end',
			render: ( r ) => (
				<span className="emcp-redirects__actions">
					<IconButton
						icon="pencil"
						label={ sprintf(
							/* translators: %s: source path. */
							__( 'Edit redirect: %s', 'emcp-lite' ),
							r.source
						) }
						onClick={ () => setEditing( r ) }
					/>
					<IconButton
						icon="trash-2"
						label={ sprintf(
							/* translators: %s: source path. */
							__( 'Delete redirect: %s', 'emcp-lite' ),
							r.source
						) }
						disabled={ busy === `delete:${ r.id }` }
						onClick={ () => remove( r ) }
					/>
				</span>
			),
		},
	];

	return (
		<div className="emcp-redirects">
			<PageHeader
				title={ __( 'Redirects', 'emcp-lite' ) }
				description={ __(
					'Send old URLs to new ones with a 301 or 302. Every change can be undone from History.',
					'emcp-lite'
				) }
			/>
			{ state.suggestions.length > 0 && (
				<Card title={ __( 'Suggested redirects', 'emcp-lite' ) }>
					<ul className="emcp-redirects__suggestions">
						{ state.suggestions.map( ( s ) => (
							<li key={ s.key }>
								<code className="emcp-redirects__path">
									{ s.oldPath }
								</code>
								<span className="emcp-redirects__reason">
									{ s.suggestedTarget
										? sprintf(
												/* translators: %s: page title. */
												__(
													'Renamed. Suggested target: %s',
													'emcp-lite'
												),
												s.suggestedTarget.title
											)
										: __(
												'Deleted. Choose where it should go.',
												'emcp-lite'
											) }
								</span>
								<span className="emcp-redirects__actions">
									<Button
										size="sm"
										variant="primary"
										loading={ busy === `accept:${ s.key }` }
										aria-label={ sprintf(
											/* translators: %s: old path. */
											__(
												'Create redirect for %s',
												'emcp-lite'
											),
											s.oldPath
										) }
										onClick={ () => accept( s ) }
									>
										{ __(
											'Create redirect',
											'emcp-lite'
										) }
									</Button>
									<Button
										size="sm"
										loading={
											busy === `dismiss:${ s.key }`
										}
										aria-label={ sprintf(
											/* translators: %s: old path. */
											__(
												'Dismiss suggestion for %s',
												'emcp-lite'
											),
											s.oldPath
										) }
										onClick={ () => dismiss( s ) }
									>
										{ __( 'Dismiss', 'emcp-lite' ) }
									</Button>
								</span>
							</li>
						) ) }
					</ul>
				</Card>
			) }
			<Card title={ __( 'Add redirect', 'emcp-lite' ) }>
				<RedirectForm
					key={ form.key }
					label={ __( 'Add redirect', 'emcp-lite' ) }
					initial={ { source: form.source } }
					focusTarget={ form.focus }
					submitLabel={ __( 'Add redirect', 'emcp-lite' ) }
					busy={ 'create' === busy }
					onSubmit={ create }
				/>
				{ warning && (
					<Notice tone="warning" onDismiss={ () => setWarning( '' ) }>
						{ warning }
					</Notice>
				) }
			</Card>
			<Card
				title={ sprintf(
					/* translators: %d: number of redirects. */
					__( 'Active redirects (%d)', 'emcp-lite' ),
					state.total
				) }
				actions={
					<SearchInput
						label={ __( 'Search redirects', 'emcp-lite' ) }
						placeholder={ __( 'Search redirects', 'emcp-lite' ) }
						value={ search }
						debounce={ 250 }
						onChange={ ( v ) => {
							setSearch( v );
							setPage( '1' );
							load( { search: v, page: 1 } );
						} }
					/>
				}
			>
				<Table
					caption={ __( 'Redirects', 'emcp-lite' ) }
					columns={ columns }
					rows={ state.redirects }
					empty={
						<EmptyState
							icon="shuffle"
							title={
								search
									? __(
											'No redirects match this search.',
											'emcp-lite'
										)
									: __( 'No redirects yet', 'emcp-lite' )
							}
						>
							{ search
								? null
								: __(
										'Add one above, or accept a suggestion when a page is renamed or deleted.',
										'emcp-lite'
									) }
						</EmptyState>
					}
				/>
				<Pagination
					page={ state.page }
					totalPages={ state.pages }
					label={ __( 'Redirect pages', 'emcp-lite' ) }
					onChange={ goTo }
				/>
			</Card>
			<Drawer
				open={ !! editing }
				title={ __( 'Edit redirect', 'emcp-lite' ) }
				onClose={ () => setEditing( null ) }
			>
				{ editing && (
					<RedirectForm
						key={ editing.id }
						label={ __( 'Edit redirect', 'emcp-lite' ) }
						initial={ {
							source: editing.source,
							target: editing.targetPostId ? '' : editing.target,
							targetPostId: editing.targetPostId,
							targetLabel: editing.targetTitle,
							code: editing.code,
							ignoreQuery: editing.ignoreQuery,
						} }
						stacked
						submitLabel={ __( 'Save', 'emcp-lite' ) }
						busy={ busy === `edit:${ editing.id }` }
						onSubmit={ save }
					/>
				) }
			</Drawer>
		</div>
	);
}
