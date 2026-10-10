import { useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import {
	Button,
	Drawer,
	Notice,
	SearchInput,
	Skeleton,
	Table,
	errorMessage,
	request,
	useToast,
} from '@emcp/ui';
import { API } from './api';

/**
 * The workspace's Cloud items of one kind, importable as new inactive drafts.
 *
 * @param {Object}                props            Props.
 * @param {boolean}               props.open       Open.
 * @param {string}                props.kind       widget | block | snippet.
 * @param {Object}                props.cloud      { connected, connectUrl }.
 * @param {() => void}            props.onClose    Close.
 * @param {(res: Object) => void} props.onImported ( res ) after an import.
 */
export function CloudLibraryDrawer( {
	open,
	kind,
	cloud,
	onClose,
	onImported,
} ) {
	const toast = useToast();
	const [ state, setState ] = useState( { loading: false } );
	const [ filter, setFilter ] = useState( '' );
	const [ done, setDone ] = useState( {} );
	const [ busy, setBusy ] = useState( '' );
	const ticket = useRef( 0 );

	const load = ( refresh = false ) => {
		const mine = ++ticket.current;
		setState( { loading: true } );
		return request(
			`${ API }/cloud/library?kind=${ kind }${ refresh ? '&refresh=1' : '' }`
		)
			.then( ( res ) => mine === ticket.current && setState( { res } ) )
			.catch(
				( e ) =>
					mine === ticket.current &&
					setState( {
						res: { error: errorMessage( e ), artifacts: [] },
					} )
			);
	};

	useEffect( () => {
		if ( open && cloud.connected && ! state.res && ! state.loading ) {
			load();
		}
	}, [ open ] ); // eslint-disable-line react-hooks/exhaustive-deps

	const importOne = async ( a ) => {
		setBusy( a.uuid );
		try {
			const res = await request(
				`${ API }/cloud/library/${ encodeURIComponent( a.uuid ) }/import`,
				{ method: 'POST', data: { kind } }
			);
			setDone( ( d ) => ( { ...d, [ a.uuid ]: true } ) );
			onImported( res );
		} catch ( e ) {
			toast.error( errorMessage( e ) );
		} finally {
			setBusy( '' );
		}
	};

	const res = state.res;
	const rows = res
		? ( res.artifacts || [] ).filter( ( a ) =>
				a.title.toLowerCase().includes( filter.toLowerCase() )
			)
		: [];

	let body;
	if ( ! cloud.connected ) {
		body = (
			<Notice tone="info">
				{ __(
					'Connect this site to EMCP Cloud to see items from your other sites.',
					'emcp-lite'
				) }{ ' ' }
				<a href={ cloud.connectUrl }>
					{ __( 'Connect Cloud', 'emcp-lite' ) }
				</a>
			</Notice>
		);
	} else if ( state.loading ) {
		body = <Skeleton lines={ 4 } />;
	} else if ( res && res.error ) {
		body = (
			<Notice
				tone="danger"
				actions={
					<Button size="sm" onClick={ () => load( true ) }>
						{ __( 'Try again', 'emcp-lite' ) }
					</Button>
				}
			>
				{ res.error }
			</Notice>
		);
	} else if ( res ) {
		body = (
			<>
				<SearchInput
					label={ __( 'Search the Cloud library', 'emcp-lite' ) }
					placeholder={ __(
						'Search the Cloud library',
						'emcp-lite'
					) }
					value={ filter }
					debounce={ 250 }
					onChange={ setFilter }
				/>
				<Table
					caption={ __( 'Cloud library', 'emcp-lite' ) }
					rowKey="uuid"
					rows={ rows }
					empty={ __(
						'Nothing in your Cloud library yet. Save one from another connected site and it appears here.',
						'emcp-lite'
					) }
					columns={ [
						{ key: 'title', header: __( 'Title', 'emcp-lite' ) },
						{
							key: 'origin',
							header: __( 'From', 'emcp-lite' ),
							render: ( a ) =>
								a.origin === res.site
									? __( 'This site', 'emcp-lite' )
									: a.originName || a.originUrl,
						},
						{
							key: 'version',
							header: __( 'Version', 'emcp-lite' ),
							mono: true,
						},
						{
							key: 'updated',
							header: __( 'Updated', 'emcp-lite' ),
						},
						{
							key: 'import',
							header: (
								<span className="eui-visually-hidden">
									{ __( 'Import', 'emcp-lite' ) }
								</span>
							),
							align: 'end',
							render: ( a ) => (
								<Button
									size="sm"
									loading={ busy === a.uuid }
									disabled={ !! done[ a.uuid ] }
									onClick={ () => importOne( a ) }
								>
									{ done[ a.uuid ]
										? __( 'Imported', 'emcp-lite' )
										: __( 'Import', 'emcp-lite' ) }
								</Button>
							),
						},
					] }
				/>
			</>
		);
	}

	return (
		<Drawer
			open={ open }
			title={ __( 'Cloud library', 'emcp-lite' ) }
			onClose={ onClose }
			width={ 720 }
		>
			<p className="emcp-sb-lib__intro">
				{ __(
					'Items saved to EMCP Cloud from any of your connected sites. Imports land here as new inactive drafts.',
					'emcp-lite'
				) }
			</p>
			{ body }
		</Drawer>
	);
}
