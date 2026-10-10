import { __ } from '@wordpress/i18n';
import { SearchInput, Segmented, Select } from '@emcp/ui';

const KINDS = [
	{ value: 'all', label: __( 'All', 'emcp-lite' ) },
	{ value: 'content', label: __( 'Content', 'emcp-lite' ) },
	{ value: 'design', label: __( 'Design', 'emcp-lite' ) },
	{ value: 'settings', label: __( 'Settings', 'emcp-lite' ) },
];

const RANGES = [
	{ value: 'all', label: __( 'All time', 'emcp-lite' ) },
	{ value: '24h', label: __( 'Last 24 hours', 'emcp-lite' ) },
	{ value: '7d', label: __( 'Last 7 days', 'emcp-lite' ) },
	{ value: '14d', label: __( 'Last 14 days', 'emcp-lite' ) },
	{ value: '30d', label: __( 'Last 30 days', 'emcp-lite' ) },
];

/**
 * History filters (spec 8.19).
 *
 * @param {Object}     props
 * @param {Object}     props.filters  { search, kind, client, range }.
 * @param {string[]}   props.clients  Clients seen.
 * @param {() => void} props.onChange ( key, value ).
 */
export function Toolbar( { filters, clients, onChange } ) {
	return (
		<div className="emcp-history__toolbar">
			<SearchInput
				label={ __( 'Search changes', 'emcp-lite' ) }
				placeholder={ __( 'Search changes', 'emcp-lite' ) }
				value={ filters.search }
				debounce={ 250 }
				onChange={ ( v ) => onChange( 'search', v ) }
			/>
			<Segmented
				label={ __( 'Kind', 'emcp-lite' ) }
				options={ KINDS }
				value={ filters.kind }
				onChange={ ( v ) => onChange( 'kind', v ) }
			/>
			<Select
				aria-label={ __( 'Client', 'emcp-lite' ) }
				options={ [
					{ value: '', label: __( 'All clients', 'emcp-lite' ) },
					...clients.map( ( c ) => ( { value: c, label: c } ) ),
				] }
				value={ filters.client }
				onChange={ ( v ) => onChange( 'client', v ) }
			/>
			<Select
				aria-label={ __( 'Time range', 'emcp-lite' ) }
				options={ RANGES }
				value={ filters.range }
				onChange={ ( v ) => onChange( 'range', v ) }
			/>
		</div>
	);
}
