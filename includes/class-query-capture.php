<?php
/**
 * Records this process's write statements on a plugin's tables while a callable runs, so a write
 * that failed after the plugin committed can be attributed positively (integration checklist item
 * 7). Shared by the Amelia and FluentCRM adapters.
 *
 * The query filter runs before a statement executes and before wpdb::flush(), so an insert stays
 * pending until the next statement's filter fires or the call ends; it completed when last_query
 * is that statement, last_error is empty, rows_affected is 1 and insert_id is above 0. Ids are per
 * table, never compared across tables.
 *
 * @package EMCP_Tools
 * @since   3.19.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** @since 3.19.0 */
final class EMCP_Tools_Query_Capture {

	/**
	 * @param array         $scope    {prefix: string, tables: string[]}: every table under the prefix,
	 *                                plus the named tables, all without $wpdb->prefix. Statements on a
	 *                                named table are also kept in `statements` (UPDATE and DELETE), so
	 *                                the caller can check which object they touch.
	 * @param callable      $fn       The plugin call.
	 * @param callable|null $on_throw Runs once when $fn throws (a ROLLBACK, a level reset).
	 * @return array{result: mixed, error: ?WP_Error, throwable: ?Throwable, inserted: array<string,int[]>, updated: string[], deleted: string[], uncertain: string[], statements: array<string,string[]>}
	 */
	public static function run( array $scope, callable $fn, ?callable $on_throw = null ): array {
		global $wpdb;
		$base   = (string) $wpdb->prefix;
		$prefix = $base . (string) ( $scope['prefix'] ?? '' );
		$named  = array();
		foreach ( (array) ( $scope['tables'] ?? array() ) as $t ) {
			$named[ $base . $t ] = (string) $t;
		}
		$state  = array( 'inserted' => array(), 'updated' => array(), 'deleted' => array(), 'uncertain' => array(), 'statements' => array(), 'pending' => null );
		$settle = static function () use ( &$state, $wpdb ) {
			$p = $state['pending'];
			if ( ! $p ) {
				return;
			}
			$state['pending'] = null;
			if ( $wpdb->last_query === $p['sql'] && '' === (string) $wpdb->last_error && 1 === (int) $wpdb->rows_affected && (int) $wpdb->insert_id > 0 ) {
				$state['inserted'][ $p['table'] ][] = (int) $wpdb->insert_id;
			} else {
				$state['uncertain'][] = $p['table'];
			}
		};
		$classify = static function ( string $full ) use ( $prefix, $base, $named ): ?array {
			if ( isset( $named[ $full ] ) ) {
				return array( $named[ $full ], true );
			}
			if ( '' !== $prefix && $prefix !== $base && 0 === strpos( $full, $prefix ) ) {
				return array( substr( $full, strlen( $prefix ) ), false );
			}
			return null;
		};
		$filter   = static function ( $sql ) use ( &$state, $settle, $classify ) {
			$settle();
			if ( ! is_string( $sql ) ) {
				return $sql;
			}
			$multi = self::multi_table_targets( $sql );
			if ( null !== $multi ) {
				foreach ( $multi['tables'] as $full ) {
					$c = $classify( $full );
					if ( ! $c ) {
						continue;
					}
					$state[ 'UPDATE' === $multi['verb'] ? 'updated' : 'deleted' ][] = $c[0];
					if ( $c[1] ) {
						$state['statements'][ $c[0] ][] = $sql;
					}
				}
				return $sql;
			}
			if ( ! preg_match( '/^\s*(INSERT|REPLACE|UPDATE|DELETE)\s+(?:(?:LOW_PRIORITY|DELAYED|HIGH_PRIORITY|IGNORE|INTO|FROM)\s+)*`?([A-Za-z0-9_]+)`?/i', $sql, $m ) ) {
				return $sql;
			}
			$c = $classify( $m[2] );
			if ( ! $c ) {
				return $sql;
			}
			list( $table, $keep ) = $c;
			$verb                 = strtoupper( $m[1] );
			if ( 'INSERT' === $verb || 'REPLACE' === $verb ) {
				$state['pending'] = array( 'table' => $table, 'sql' => $sql );
			} else {
				$state[ 'UPDATE' === $verb ? 'updated' : 'deleted' ][] = $table;
				if ( $keep ) {
					$state['statements'][ $table ][] = $sql;
				}
			}
			return $sql;
		};
		add_filter( 'query', $filter, PHP_INT_MAX );
		$result    = null;
		$error     = null;
		$throwable = null;
		try {
			$result = $fn();
			if ( is_wp_error( $result ) ) {
				$error = $result;
			}
		} catch ( \Throwable $e ) {
			$throwable = $e;
			if ( $on_throw ) {
				$on_throw( $e );
			}
			$error = new WP_Error( 'capture_throw', $e->getMessage(), array( 'exception' => get_class( $e ) ) );
		} finally {
			$settle();
			remove_filter( 'query', $filter, PHP_INT_MAX );
		}
		return array(
			'result'     => $result,
			'error'      => $error,
			'throwable'  => $throwable,
			'inserted'   => $state['inserted'],
			'updated'    => array_values( array_unique( $state['updated'] ) ),
			'deleted'    => array_values( array_unique( $state['deleted'] ) ),
			'uncertain'  => array_values( array_unique( $state['uncertain'] ) ),
			'statements' => $state['statements'],
		);
	}

	const REF_KEYWORDS = array( 'ON', 'USING', 'INNER', 'LEFT', 'RIGHT', 'CROSS', 'NATURAL', 'OUTER', 'JOIN', 'STRAIGHT_JOIN', 'WHERE', 'SET' );

	/**
	 * The tables a multi-table statement writes (MySQL's `DELETE a[, b] FROM t1 a JOIN t2 b ...` and
	 * `UPDATE t1 a JOIN t2 b ... SET ...`), with the verb; null for a single-table statement. A DELETE
	 * writes the tables its aliases name; an alias the statement does not resolve, and every multi-table
	 * UPDATE (its SET can name any of them), count every table in the statement as written.
	 *
	 * @return array{verb: string, tables: string[]}|null
	 */
	public static function multi_table_targets( string $sql ): ?array {
		$id = '`?([A-Za-z0-9_]+)`?';
		if ( preg_match( '/^\s*DELETE\s+(?:(?:LOW_PRIORITY|QUICK|IGNORE)\s+)*((?!FROM\b)' . $id . '(?:\.\*)?(?:\s*,\s*' . $id . '(?:\.\*)?)*)\s+FROM\s+(.*?)(?:\s+WHERE\b.*)?$/is', $sql, $m ) ) {
			$verb    = 'DELETE';
			$aliases = array_map( static fn( $a ) => trim( preg_replace( '/\.\*$/', '', trim( $a ) ), '` ' ), explode( ',', $m[1] ) );
			$refs    = $m[ count( $m ) - 1 ];
		} elseif ( preg_match( '/^\s*UPDATE\s+(?:(?:LOW_PRIORITY|IGNORE)\s+)*(.*?)\s+SET\b/is', $sql, $m ) && preg_match( '/,|\bJOIN\b/i', $m[1] ) ) {
			$verb    = 'UPDATE';
			$aliases = null;
			$refs    = $m[1];
		} else {
			return null;
		}
		preg_match_all( '/(?:^|,|\bJOIN)\s*' . $id . '(?:\s+(?:AS\s+)?' . $id . ')?/i', ' ' . $refs, $found, PREG_SET_ORDER );
		$map = array();
		foreach ( $found as $f ) {
			if ( in_array( strtoupper( $f[1] ), self::REF_KEYWORDS, true ) ) {
				continue;
			}
			$alias = ( isset( $f[2] ) && '' !== $f[2] && ! in_array( strtoupper( $f[2] ), self::REF_KEYWORDS, true ) ) ? $f[2] : $f[1];
			$map[ $alias ] = $f[1];
			$map[ $f[1] ] = $f[1];
		}
		$all = array_values( array_unique( array_values( $map ) ) );
		if ( null === $aliases ) {
			return array( 'verb' => $verb, 'tables' => $all );
		}
		$tables = array();
		foreach ( $aliases as $a ) {
			if ( ! isset( $map[ $a ] ) ) {
				return array( 'verb' => $verb, 'tables' => $all );
			}
			$tables[] = $map[ $a ];
		}
		return array( 'verb' => $verb, 'tables' => array_values( array_unique( $tables ) ) );
	}

	/**
	 * The conditions of a statement's WHERE clause, read outside string literals: a list of
	 * [column, values] for `col = int`, `col = 'literal'` and `col IN (ints)` joined by AND only.
	 * null for anything else (OR, comparisons, functions, subqueries, a trailing clause, comments,
	 * no WHERE, a repeated column), so an unusual statement is never attributed. Shared by the
	 * FluentCRM and MailPoet adapters.
	 *
	 * @return array<string,array>|null column => values
	 */
	public static function where_conditions( string $sql ): ?array {
		$lits   = array();
		$masked = preg_replace_callback(
			"/'(?:[^'\\\\]|\\\\.|'')*'/s",
			static function ( $m ) use ( &$lits ) {
				$lits[] = stripslashes( str_replace( "''", "'", substr( $m[0], 1, -1 ) ) );
				return '?S' . ( count( $lits ) - 1 );
			},
			$sql
		);
		if ( ! is_string( $masked ) || preg_match( '/[\'"\\\\;#]|--|\/\*/', $masked ) || 1 !== preg_match_all( '/\bWHERE\b/i', $masked ) ) {
			return null;
		}
		$where = trim( (string) preg_replace( '/^.*?\bWHERE\b/is', '', $masked ) );
		$out   = array();
		foreach ( preg_split( '/\s+AND\s+/i', $where ) as $part ) {
			$part = trim( $part );
			if ( preg_match( '/^`?([A-Za-z_]+)`?\s*=\s*(\d+|\?S(\d+))$/', $part, $m ) ) {
				$val = isset( $m[3] ) && '' !== $m[3] ? $lits[ (int) $m[3] ] : (int) $m[2];
				// WPFluent quotes integer bindings (`ID` = '42'): a literal that is exactly a canonical
				// integer is that integer; anything else ('42abc', '042', ' 42') stays a string and is refused.
				if ( is_string( $val ) && 1 === preg_match( '/^[1-9][0-9]{0,18}$/', $val ) ) {
					$val = (int) $val;
				}
				$col = strtolower( $m[1] );
				if ( isset( $out[ $col ] ) ) {
					return null;
				}
				$out[ $col ] = array( $val );
			} elseif ( preg_match( '/^`?([A-Za-z_]+)`?\s+IN\s*\(\s*(\d+(?:\s*,\s*\d+)*)\s*\)$/i', $part, $m ) ) {
				$col = strtolower( $m[1] );
				if ( isset( $out[ $col ] ) ) {
					return null;
				}
				$out[ $col ] = array_map( 'intval', preg_split( '/\s*,\s*/', trim( $m[2] ) ) );
			} else {
				return null;
			}
		}
		return $out;
	}
}
