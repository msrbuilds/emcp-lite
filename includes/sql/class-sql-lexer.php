<?php
/**
 * A fail-closed SQL tokenizer for the read-only `query` tool.
 *
 * WHY THIS EXISTS
 * ---------------
 * The previous guard normalized SQL with a hand-written scanner and then ran
 * regexes over the result. That design is only as good as the scanner's parity
 * with MySQL, and it was wrong four separate times: `--` treated as a comment
 * where MySQL requires trailing whitespace, backslash escaping assumed
 * regardless of NO_BACKSLASH_ESCAPES, backticks stripped before lexing so
 * identifier contents were re-lexed as SQL, and double quotes always read as
 * strings even though ANSI_QUOTES makes them identifiers. Three of those leaked
 * real data, and two were introduced by the fix for an earlier one.
 *
 * The failure mode was always the same: the scanner silently mis-read a byte
 * and produced a plausible-looking string, so the policy above it inspected
 * something MySQL would never execute.
 *
 * THE STRUCTURAL CHANGE
 * ---------------------
 * This tokenizer must account for EVERY byte of input. Anything it cannot
 * classify, and any construct it cannot finish (an unterminated string, comment
 * or quoted identifier), is a hard error rather than a best guess. Callers
 * therefore never inspect a "normalized" approximation; they inspect a typed
 * token stream, or they refuse the statement.
 *
 * Two MySQL session modes change how a statement TOKENIZES: ANSI_QUOTES and
 * NO_BACKSLASH_ESCAPES. Both are parameters here rather than assumptions, and
 * EMCP_Tools_SQL_Policy runs every combination. See MODE_FLAGS there.
 *
 * ONE NUANCE, because "always a hard error" would be too strong a claim:
 * an error from THIS class always aborts THIS reading. What the policy above
 * does with that depends on the error. An unterminated quote is mode-dependent,
 * so a statement whose quoting only balances under one mode is a syntax error
 * under the other, and that unbalanced reading is not a statement the server
 * could have executed; the policy skips it, provided some other reading parses
 * and every reading that parses is safe. Every other failure here (unknown
 * byte, bad encoding, executable comment, unterminated comment) is
 * mode-independent and refuses the statement outright. See
 * EMCP_Tools_SQL_Policy::MODE_DEPENDENT_ERRORS.
 *
 * @package EMCP_Tools
 * @since   3.13.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns SQL into a typed token stream, or refuses.
 *
 * @since 3.13.0
 */
class EMCP_Tools_SQL_Lexer {

	/** Token types. */
	const T_WHITESPACE = 'ws';
	const T_COMMENT    = 'comment';
	const T_STRING     = 'string';   // A literal value.
	const T_IDENT      = 'ident';    // Bare or quoted identifier / keyword.
	const T_NUMBER     = 'number';
	const T_PUNCT      = 'punct';    // Operators and separators.
	const T_VARIABLE   = 'variable'; // @user or @@system variables.
	const T_PARAM      = 'param';    // ? placeholder.

	/** Longest operators first so the greedy match is correct. */
	const OPERATORS = array(
		'<=>',
		'>=', '<=', '<>', '!=', '&&', '||', '<<', '>>', ':=',
		'=', '<', '>', '+', '-', '*', '/', '%', '&', '|', '^', '~', '!',
		'(', ')', ',', ';', '.',
	);

	/**
	 * Tokenize $sql under one interpretation of the session mode.
	 *
	 * @since 3.13.0
	 * @param string $sql               Raw SQL.
	 * @param bool   $backslash_escapes False models NO_BACKSLASH_ESCAPES.
	 * @param bool   $ansi_quotes       True models ANSI_QUOTES (double quote = identifier).
	 * @return array[]|WP_Error List of array{t:string,v:string,name:string,quoted:bool}.
	 */
	public static function tokenize( string $sql, bool $backslash_escapes = true, bool $ansi_quotes = false ) {
		// A byte-level scanner is only safe when no multi-byte sequence can carry
		// a byte that also means something in ASCII. That holds for UTF-8, whose
		// continuation bytes are all >= 0x80, and NOT for legacy charsets like
		// GBK or SJIS where a trail byte can be 0x27 (') or 0x5C (\). Refusing
		// non-UTF-8 input closes that entire class rather than modelling it.
		if ( ! self::is_valid_utf8( $sql ) ) {
			return self::err( 'sql_not_utf8', __( 'The query is not valid UTF-8 text.', 'emcp-lite' ) );
		}

		$tokens = array();
		$len    = strlen( $sql );
		$i      = 0;

		while ( $i < $len ) {
			$c   = $sql[ $i ];
			$two = substr( $sql, $i, 2 );

			// --- whitespace ------------------------------------------------
			if ( self::is_space( $c ) ) {
				$start = $i;
				while ( $i < $len && self::is_space( $sql[ $i ] ) ) {
					$i++;
				}
				$tokens[] = self::tok( self::T_WHITESPACE, substr( $sql, $start, $i - $start ) );
				continue;
			}

			// --- line comments ---------------------------------------------
			// MySQL starts one at `--` only when a whitespace or control byte
			// follows. `1--1` is arithmetic: 1 minus -1.
			$dash_comment = ( '--' === $two ) && ( $i + 2 >= $len || self::is_comment_space( $sql[ $i + 2 ] ) );
			if ( '#' === $c || $dash_comment ) {
				$nl       = strpos( $sql, "\n", $i );
				$end      = ( false === $nl ) ? $len : $nl + 1;
				$tokens[] = self::tok( self::T_COMMENT, substr( $sql, $i, $end - $i ) );
				$i        = $end;
				continue;
			}

			// --- block comments --------------------------------------------
			if ( '/*' === $two ) {
				// `/*! ... */` is executed by MySQL and `/*+ ... */` is an
				// optimizer hint. Neither is inert, so neither may be treated as
				// a comment.
				$marker = substr( $sql, $i + 2, 1 );
				if ( '!' === $marker || '+' === $marker ) {
					return self::err( 'executable_comment', __( 'MySQL executable comments and optimizer hints are not allowed.', 'emcp-lite' ) );
				}
				$end = strpos( $sql, '*/', $i + 2 );
				if ( false === $end ) {
					return self::err( 'unterminated_comment', __( 'The query has an unterminated comment.', 'emcp-lite' ) );
				}
				$tokens[] = self::tok( self::T_COMMENT, substr( $sql, $i, $end + 2 - $i ) );
				$i        = $end + 2;
				continue;
			}

			// --- single-quoted string --------------------------------------
			if ( "'" === $c || ( '"' === $c && ! $ansi_quotes ) ) {
				$res = self::read_quoted( $sql, $i, $c, $backslash_escapes );
				if ( is_wp_error( $res ) ) {
					return $res;
				}
				$tokens[] = self::tok( self::T_STRING, $res['raw'], $res['value'], true );
				$i        = $res['next'];
				continue;
			}

			// --- quoted identifier ------------------------------------------
			if ( '`' === $c || ( '"' === $c && $ansi_quotes ) ) {
				// A quoted identifier never honours backslash escapes, in either
				// session mode: only the doubled delimiter escapes itself.
				$res = self::read_quoted( $sql, $i, $c, false );
				if ( is_wp_error( $res ) ) {
					return $res;
				}
				$tokens[] = self::tok( self::T_IDENT, $res['raw'], $res['value'], true );
				$i        = $res['next'];
				continue;
			}

			// --- hex / bit literals written as x'..' or b'..' ----------------
			if ( preg_match( '/^[xXbB]\'/', substr( $sql, $i, 2 ) ) ) {
				$res = self::read_quoted( $sql, $i + 1, "'", false );
				if ( is_wp_error( $res ) ) {
					return $res;
				}
				$tokens[] = self::tok( self::T_STRING, substr( $sql, $i, $res['next'] - $i ), $res['value'], true );
				$i        = $res['next'];
				continue;
			}

			// --- variables ---------------------------------------------------
			if ( '@' === $c ) {
				$start = $i;
				$i++;
				if ( $i < $len && '@' === $sql[ $i ] ) {
					$i++;
				}
				while ( $i < $len && ( self::is_ident_char( $sql[ $i ] ) || '.' === $sql[ $i ] ) ) {
					$i++;
				}
				$tokens[] = self::tok( self::T_VARIABLE, substr( $sql, $start, $i - $start ) );
				continue;
			}

			// --- placeholder --------------------------------------------------
			if ( '?' === $c ) {
				$tokens[] = self::tok( self::T_PARAM, '?' );
				$i++;
				continue;
			}

			// --- numbers -------------------------------------------------------
			// Leading digit only. A leading dot is punctuation followed by digits,
			// which the number branch below still resolves correctly for `.5`.
			if ( self::is_digit( $c ) || ( '.' === $c && $i + 1 < $len && self::is_digit( $sql[ $i + 1 ] ) ) ) {
				$start = $i;
				if ( '0' === $c && $i + 1 < $len && ( 'x' === $sql[ $i + 1 ] || 'X' === $sql[ $i + 1 ] ) ) {
					$i += 2;
					while ( $i < $len && ctype_xdigit( $sql[ $i ] ) ) {
						$i++;
					}
				} else {
					while ( $i < $len && ( self::is_digit( $sql[ $i ] ) || '.' === $sql[ $i ] ) ) {
						$i++;
					}
					// Exponent.
					if ( $i < $len && ( 'e' === $sql[ $i ] || 'E' === $sql[ $i ] ) ) {
						$save = $i;
						$i++;
						if ( $i < $len && ( '+' === $sql[ $i ] || '-' === $sql[ $i ] ) ) {
							$i++;
						}
						if ( $i < $len && self::is_digit( $sql[ $i ] ) ) {
							while ( $i < $len && self::is_digit( $sql[ $i ] ) ) {
								$i++;
							}
						} else {
							$i = $save; // Not an exponent after all.
						}
					}
				}
				$tokens[] = self::tok( self::T_NUMBER, substr( $sql, $start, $i - $start ) );
				continue;
			}

			// --- bare identifiers and keywords -----------------------------------
			if ( self::is_ident_start( $c ) ) {
				$start = $i;
				while ( $i < $len && self::is_ident_char( $sql[ $i ] ) ) {
					$i++;
				}
				$raw      = substr( $sql, $start, $i - $start );
				$tokens[] = self::tok( self::T_IDENT, $raw, $raw, false );
				continue;
			}

			// --- operators and separators ------------------------------------------
			$matched = '';
			foreach ( self::OPERATORS as $op ) {
				if ( 0 === substr_compare( $sql, $op, $i, strlen( $op ) ) ) {
					$matched = $op;
					break;
				}
			}
			if ( '' !== $matched ) {
				$tokens[] = self::tok( self::T_PUNCT, $matched );
				$i       += strlen( $matched );
				continue;
			}

			// --- nothing matched: refuse rather than skip ---------------------------
			// This is the whole point of the rewrite. A byte we cannot classify
			// means our reading of the statement has diverged from the server's,
			// and every past bypass looked exactly like that.
			return self::err(
				'sql_unparsable',
				sprintf(
					/* translators: 1: byte offset, 2: the character. */
					__( 'The query contains something this guard cannot interpret at position %1$d (%2$s), so it was refused.', 'emcp-lite' ),
					$i,
					'"' . $c . '"'
				)
			);
		}

		return $tokens;
	}

	/**
	 * Read a delimited run starting at $i (the opening delimiter).
	 *
	 * @param string $sql               SQL.
	 * @param int    $i                 Offset of the opening delimiter.
	 * @param string $delim             Delimiter character.
	 * @param bool   $backslash_escapes Whether a backslash escapes the next byte.
	 * @return array{raw:string,value:string,next:int}|WP_Error
	 */
	private static function read_quoted( string $sql, int $i, string $delim, bool $backslash_escapes ) {
		$len   = strlen( $sql );
		$start = $i;
		$value = '';
		$i++;
		while ( $i < $len ) {
			$ch = $sql[ $i ];
			if ( $backslash_escapes && '\\' === $ch ) {
				if ( $i + 1 >= $len ) {
					break; // Trailing backslash: falls through to the error below.
				}
				$value .= $sql[ $i + 1 ];
				$i     += 2;
				continue;
			}
			if ( $ch === $delim ) {
				// A doubled delimiter is a literal one.
				if ( $i + 1 < $len && $sql[ $i + 1 ] === $delim ) {
					$value .= $delim;
					$i     += 2;
					continue;
				}
				$i++;
				return array(
					'raw'   => substr( $sql, $start, $i - $start ),
					'value' => $value,
					'next'  => $i,
				);
			}
			$value .= $ch;
			$i++;
		}
		return self::err( 'unterminated_quote', __( 'The query has an unterminated quoted string or identifier.', 'emcp-lite' ) );
	}

	/**
	 * Strict UTF-8 validation.
	 *
	 * @param string $s Input.
	 * @return bool
	 */
	private static function is_valid_utf8( string $s ): bool {
		// preg with /u fails on malformed input; the anchored empty match is the
		// cheapest way to ask "is this well-formed UTF-8".
		return '' === $s || 1 === preg_match( '//u', $s );
	}

	/** @param string $c Byte. @return bool */
	private static function is_space( string $c ): bool {
		return ' ' === $c || "\t" === $c || "\n" === $c || "\r" === $c || "\f" === $c || "\x0B" === $c;
	}

	/**
	 * Bytes MySQL accepts directly after `--` to make it a comment.
	 *
	 * @param string $c Byte.
	 * @return bool
	 */
	private static function is_comment_space( string $c ): bool {
		// Determined empirically against MySQL rather than from prose, by running
		// `SELECT 1--<byte>XYZZY` for every byte 0x00 to 0x7F and recording which
		// ones swallow the tail. Two results shaped this:
		//
		//  - NUL is NOT a comment introducer, even though it is a control
		//    character. Treating it as one hid bytes the server still tried to
		//    execute, which is the exact failure mode this rewrite exists to end.
		//  - MySQL DOES comment on the other C0 controls (0x01-0x08, 0x0E-0x1F).
		//    We deliberately do not follow it there, because being a strict SUBSET
		//    of the server's comment set is the safe direction: we may treat as
		//    code something the server ignores, which at worst causes a refusal,
		//    but we can never ignore something the server runs.
		return self::is_space( $c );
	}

	/** @param string $c Byte. @return bool */
	private static function is_digit( string $c ): bool {
		return $c >= '0' && $c <= '9';
	}

	/** @param string $c Byte. @return bool */
	private static function is_ident_start( string $c ): bool {
		return ( $c >= 'a' && $c <= 'z' ) || ( $c >= 'A' && $c <= 'Z' ) || '_' === $c || '$' === $c || $c >= "\x80";
	}

	/** @param string $c Byte. @return bool */
	private static function is_ident_char( string $c ): bool {
		return self::is_ident_start( $c ) || self::is_digit( $c );
	}

	/**
	 * @param string $type   Token type.
	 * @param string $raw    Raw text.
	 * @param string $name   Decoded value (identifiers and strings).
	 * @param bool   $quoted Was it delimited?
	 * @return array{t:string,v:string,name:string,quoted:bool}
	 */
	private static function tok( string $type, string $raw, string $name = '', bool $quoted = false ): array {
		return array(
			't'      => $type,
			'v'      => $raw,
			'name'   => $name,
			'quoted' => $quoted,
		);
	}

	/**
	 * @param string $code    Error code.
	 * @param string $message Message.
	 * @return WP_Error
	 */
	private static function err( string $code, string $message ): WP_Error {
		return new WP_Error( $code, $message );
	}
}
