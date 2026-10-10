<?php
/**
 * Database safety guard: validates read-only SQL for the `query` tool,
 * validates/protects table names for structured writes, captures before-image
 * snapshots, and audits. is_read_only_sql() is the safety boundary for the
 * flexible read path.
 *
 * @package EMCP_Tools
 * @since   3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @since 3.0.0
 */
class EMCP_Tools_Database_Guard {

	const MAX_ROWS         = 1000;
	/** Server-side statement timeout for read-only queries, in seconds. */
	const MAX_QUERY_SECONDS = 10;
	const BEFORE_IMAGE_CAP = 500;

	/**
	 * Legacy text normalizer.
	 *
	 * Superseded in 3.13.0 by EMCP_Tools_SQL_Lexer. Nothing in the security path
	 * calls this any more: the guard inspects a typed token stream now, because a
	 * normalizer that quietly mis-reads a byte still produces a plausible-looking
	 * string, and that is precisely how four separate bypasses happened. Kept so
	 * a third-party caller does not fatal, and deliberately not used for policy.
	 *
	 * @deprecated 3.13.0 Use EMCP_Tools_SQL_Lexer::tokenize().
	 * @param string $sql               SQL.
	 * @param bool   $backslash_escapes Mode flag.
	 * @param bool   $keep_identifiers  Emit identifier names rather than blanking them.
	 * @param bool   $ansi_quotes       Mode flag.
	 * @return string Empty string when the statement cannot be tokenized.
	 */
	public static function normalize_sql( string $sql, bool $backslash_escapes = true, bool $keep_identifiers = false, bool $ansi_quotes = false ): string {
		$tokens = EMCP_Tools_SQL_Lexer::tokenize( $sql, $backslash_escapes, $ansi_quotes );
		if ( is_wp_error( $tokens ) ) {
			return '';
		}
		$out = '';
		foreach ( $tokens as $t ) {
			switch ( $t['t'] ) {
				case EMCP_Tools_SQL_Lexer::T_COMMENT:
					$out .= ' ';
					break;
				case EMCP_Tools_SQL_Lexer::T_STRING:
					$out .= "''";
					break;
				case EMCP_Tools_SQL_Lexer::T_IDENT:
					$out .= $t['quoted'] ? ( $keep_identifiers ? ' ' . $t['name'] . ' ' : '``' ) : $t['v'];
					break;
				default:
					$out .= $t['v'];
			}
		}
		return $out;
	}

	/**
	 * Every reading of $sql the server could take.
	 *
	 * @deprecated 3.13.0 Use EMCP_Tools_SQL_Policy::analyze().
	 * @param string $sql              Raw SQL.
	 * @param bool   $keep_identifiers Emit identifier names.
	 * @return string[]
	 */
	public static function normalize_variants( string $sql, bool $keep_identifiers = false ): array {
		$out = array();
		foreach ( EMCP_Tools_SQL_Policy::MODE_FLAGS as $flags ) {
			$out[] = self::normalize_sql( $sql, $flags[0], $keep_identifiers, $flags[1] );
		}
		return array_values( array_unique( $out ) );
	}

	/** Schemas that are never a legitimate target of this tool. */
	const SYSTEM_SCHEMAS = EMCP_Tools_SQL_Policy::SYSTEM_SCHEMAS;

	/**
	 * Does $sql reference a server system schema?
	 *
	 * @param string $sql Raw SQL.
	 * @return bool True also when the statement cannot be parsed (fail closed).
	 */
	public static function references_system_schema( string $sql ): bool {
		return EMCP_Tools_SQL_Policy::references_system_schema( $sql );
	}

	/**
	 * Apply a server-side statement timeout for the next query, and return a
	 * callable that restores the previous session value.
	 *
	 * MySQL 5.7.8+ uses `max_execution_time` (milliseconds, SELECT only);
	 * MariaDB 10.1.1+ uses `max_statement_time` (seconds, as a double). We try
	 * the matching one and simply do nothing on a server that has neither, so
	 * this can never break a query on an unsupported database.
	 *
	 * @param object $wpdb WordPress database handle.
	 * @return callable Restores the prior session setting.
	 */
	public static function apply_statement_timeout( $wpdb ): callable {
		$noop = static function () {};
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'query' ) || ! method_exists( $wpdb, 'get_var' ) ) {
			return $noop;
		}
		$seconds = (int) apply_filters( 'emcp_tools_db_query_max_seconds', self::MAX_QUERY_SECONDS );
		$seconds = max( 1, $seconds );
		$suppress = method_exists( $wpdb, 'suppress_errors' ) ? $wpdb->suppress_errors( true ) : null;
		$restore  = $noop;

		// MariaDB first: it also exposes max_statement_time, and asking for the
		// MySQL-only variable there just returns null.
		$maria = $wpdb->get_var( "SELECT @@SESSION.max_statement_time" );
		if ( null !== $maria ) {
			$prev = (float) $maria;
			$wpdb->query( $wpdb->prepare( 'SET SESSION max_statement_time = %f', (float) $seconds ) );
			$restore = static function () use ( $wpdb, $prev ) {
				$wpdb->query( $wpdb->prepare( 'SET SESSION max_statement_time = %f', $prev ) );
			};
		} else {
			$mysql = $wpdb->get_var( "SELECT @@SESSION.max_execution_time" );
			if ( null !== $mysql ) {
				$prev = (int) $mysql;
				$wpdb->query( $wpdb->prepare( 'SET SESSION max_execution_time = %d', $seconds * 1000 ) );
				$restore = static function () use ( $wpdb, $prev ) {
					$wpdb->query( $wpdb->prepare( 'SET SESSION max_execution_time = %d', $prev ) );
				};
			}
		}
		if ( null !== $suppress && method_exists( $wpdb, 'suppress_errors' ) ) {
			$wpdb->suppress_errors( $suppress );
		}
		return $restore;
	}

	/**
	 * Is $sql a row-returning statement we may append a bound to?
	 *
	 * @param string $sql Raw SQL.
	 * @return bool
	 */
	public static function can_append_limit( string $sql ): bool {
		$a = EMCP_Tools_SQL_Policy::analyze( $sql );
		if ( is_wp_error( $a ) ) {
			return false;
		}
		return in_array( $a['first'], array( 'select', 'with', 'table', 'values' ), true );
	}

	/**
	 * Row count of a TOP-LEVEL trailing LIMIT, or null when there is none.
	 *
	 * Depth-aware via the token stream, so a LIMIT inside a subquery does not
	 * count as bounding the outer result.
	 *
	 * @param string $sql Raw SQL.
	 * @return int|null
	 */
	public static function trailing_limit( string $sql ): ?int {
		$a = EMCP_Tools_SQL_Policy::analyze( $sql );
		return is_wp_error( $a ) ? null : $a['limit'];
	}

	/**
	 * Return $sql guaranteed to fetch at most $max rows, or a WP_Error.
	 *
	 * An oversized caller LIMIT is REFUSED rather than rewritten. Editing raw SQL
	 * around literals is the very class of trick this guard exists to stop.
	 *
	 * @param string $sql Raw SQL.
	 * @param int    $max Maximum rows.
	 * @return string|\WP_Error
	 */
	public static function bound_sql( string $sql, int $max ) {
		$a = EMCP_Tools_SQL_Policy::analyze( $sql );
		if ( is_wp_error( $a ) ) {
			return $a;
		}
		$trimmed = rtrim( trim( $sql ), "; \t\r\n" );
		if ( null !== $a['limit'] ) {
			if ( $a['limit'] > $max ) {
				return new \WP_Error(
					'limit_too_large',
					sprintf(
						/* translators: 1: requested LIMIT, 2: maximum allowed. */
						__( 'The query asks for %1$d rows, above the %2$d row cap. Lower the LIMIT.', 'emcp-lite' ),
						$a['limit'],
						$max
					)
				);
			}
			return $trimmed;
		}
		if ( ! in_array( $a['first'], array( 'select', 'with', 'table', 'values' ), true ) ) {
			return $trimmed; // SHOW / DESCRIBE / EXPLAIN: inherently small.
		}
		// Newline first: a trailing line comment would otherwise swallow the clause.
		return $trimmed . "\n LIMIT " . (int) $max;
	}

	/**
	 * Validate that $sql is a single read-only statement. Pure (no DB).
	 *
	 * @param string $sql
	 * @return true|\WP_Error
	 */
	public static function is_read_only_sql( string $sql ) {
		return EMCP_Tools_SQL_Policy::analyze( $sql ) instanceof \WP_Error
			? EMCP_Tools_SQL_Policy::analyze( $sql )
			: true;
	}

	/**
	 * Resolve a table name against the live table list (table names cannot be
	 * parameterized). Returns the exact real name, or WP_Error.
	 *
	 * @param string $table
	 * @return string|\WP_Error
	 */
	public static function valid_table( string $table ) {
		global $wpdb;
		$table = trim( $table );
		if ( '' === $table ) {
			return new \WP_Error( 'unknown_table', __( 'A table name is required.', 'emcp-lite' ) );
		}
		$tables = (array) $wpdb->get_col( 'SHOW TABLES' );
		foreach ( $tables as $t ) {
			if ( strtolower( (string) $t ) === strtolower( $table ) ) {
				return (string) $t;
			}
		}
		return new \WP_Error( 'unknown_table', __( 'Unknown table.', 'emcp-lite' ) );
	}

	/**
	 * Pure: is $table in the protected list (case-insensitive)?
	 *
	 * @param string   $table
	 * @param string[] $protected
	 * @return bool
	 */
	public static function table_is_protected( string $table, array $protected ): bool {
		$t = strtolower( $table );
		foreach ( $protected as $p ) {
			if ( strtolower( (string) $p ) === $t ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether writes to $table are refused (users/usermeta by default).
	 *
	 * @param string $table
	 * @return bool
	 */
	public static function is_protected( string $table ): bool {
		global $wpdb;
		$protected = apply_filters( 'emcp_tools_db_protected_tables', array( $wpdb->users, $wpdb->usermeta ) );
		return self::table_is_protected( $table, (array) $protected );
	}

	/**
	 * Pure: does a read-only $sql reference any of $tables as a real identifier?
	 *
	 * Comments and string literals are stripped (so `'wp_users'` in a literal is
	 * not a reference), backticks are removed (so `` `wp_users` `` still matches),
	 * and each table is matched on word boundaries (so `wp_users_backup` does not
	 * match `wp_users`). This is the read-path counterpart to is_protected() —
	 * the `query` tool refuses any read that touches the protected user tables.
	 *
	 * @param string   $sql
	 * @param string[] $tables Real table names (e.g. {$wpdb->users}).
	 * @return bool
	 */
	public static function query_touches_tables( string $sql, array $tables ): bool {
		return EMCP_Tools_SQL_Policy::touches_tables( $sql, $tables );
	}

	/**
	 * Whether a read-only $sql touches a protected table (users/usermeta by
	 * default; filter via emcp_tools_db_protected_tables).
	 *
	 * @param string $sql
	 * @return bool
	 */
	public static function query_touches_protected( string $sql ): bool {
		global $wpdb;
		$protected = apply_filters( 'emcp_tools_db_protected_tables', array( $wpdb->users, $wpdb->usermeta ) );
		return self::query_touches_tables( $sql, (array) $protected );
	}

	/**
	 * Capture the rows an equality-AND WHERE will affect, before update/delete.
	 *
	 * @param string $table A validated real table name.
	 * @param array  $where col => value (equality AND).
	 * @return array
	 */


	/**
	 * Deprecated: DB writes are now recorded in the unified change ledger
	 * (EMCP_Tools_Change_Log) via EMCP_Tools_Change_Recorder, which is the single
	 * audit + rollback source. Kept as a no-op for backward compatibility.
	 *
	 * @deprecated 3.10.0
	 */
	public static function log(): void {}
}
