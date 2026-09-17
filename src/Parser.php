<?php
/**
 * Turning a .env file into key-value pairs.
 *
 * @package   SugarCommerce\Env
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Env;

/**
 * Class Parser
 *
 * Small on purpose: this reads a file written by a person, not a format with
 * a specification. The cases it handles are the ones people actually write,
 * and the ones that go wrong silently if it does not:
 *
 * **Quoted values.** `SECRET="a b c"` is one value, not a value and a comment.
 * Stripping quotes unconditionally, as a naive trim does, also strips them
 * from a password that legitimately begins with one.
 *
 * **Comments after a value.** `PORT=8080 # the http port` is 8080, not
 * "8080 # the http port" — but only outside quotes, or a `#` in a password
 * truncates it.
 *
 * **Multi-line values.** A private key pasted into a .env file spans lines.
 * A line-at-a-time parser reads the first line and silently produces a key
 * that cannot verify anything.
 *
 * @since 1.0.0
 */
final class Parser {

	/**
	 * Parse the contents of a .env file.
	 *
	 * @since 1.0.0
	 *
	 * @param string $contents Raw file contents.
	 *
	 * @return array<string, string> Keys in file order.
	 */
	public static function parse( string $contents ): array {
		$values = array();
		$lines  = preg_split( '/\R/', $contents ) ?: array();
		$total  = count( $lines );

		for ( $i = 0; $i < $total; $i++ ) {
			$line = trim( $lines[ $i ] );

			if ( '' === $line || str_starts_with( $line, '#' ) || ! str_contains( $line, '=' ) ) {
				continue;
			}

			[ $key, $value ] = explode( '=', $line, 2 );

			$key = trim( $key );

			// `export FOO=bar` is how people copy a line out of a shell.
			if ( str_starts_with( $key, 'export ' ) ) {
				$key = trim( substr( $key, 7 ) );
			}

			if ( ! self::is_valid_key( $key ) ) {
				continue;
			}

			$value = ltrim( $value );

			// A value opening with a quote may continue over several lines —
			// a pasted private key is the reason this exists.
			if ( self::opens_quote( $value ) ) {
				$quote = $value[0];

				while ( ! self::closes_quote( $value, $quote ) && $i + 1 < $total ) {
					$value .= "\n" . $lines[ ++$i ];
				}
			}

			$values[ $key ] = self::clean( $value );
		}

		return $values;
	}

	/**
	 * Is this a usable environment variable name?
	 *
	 * @since 1.0.0
	 *
	 * @param string $key Candidate name.
	 *
	 * @return bool
	 */
	private static function is_valid_key( string $key ): bool {
		return 1 === preg_match( '/^[A-Za-z_][A-Za-z0-9_]*$/', $key );
	}

	/**
	 * Does this value begin with a quote?
	 *
	 * @since 1.0.0
	 *
	 * @param string $value Raw value.
	 *
	 * @return bool
	 */
	private static function opens_quote( string $value ): bool {
		return '' !== $value && ( '"' === $value[0] || "'" === $value[0] );
	}

	/**
	 * Does this value close the quote it opened with?
	 *
	 * @since 1.0.0
	 *
	 * @param string $value Raw value, possibly spanning lines.
	 * @param string $quote The opening quote character.
	 *
	 * @return bool
	 */
	private static function closes_quote( string $value, string $quote ): bool {
		if ( strlen( $value ) < 2 ) {
			return false;
		}

		// An escaped quote does not close the value.
		return str_ends_with( $value, $quote ) && ! str_ends_with( $value, '\\' . $quote );
	}

	/**
	 * Strip quotes and trailing comments, and expand escapes.
	 *
	 * @since 1.0.0
	 *
	 * @param string $value Raw value.
	 *
	 * @return string
	 */
	private static function clean( string $value ): string {
		if ( self::opens_quote( $value ) ) {
			$quote = $value[0];

			if ( self::closes_quote( $value, $quote ) ) {
				$value = substr( $value, 1, -1 );
			} else {
				$value = substr( $value, 1 );
			}

			// Escapes only mean anything inside double quotes, which is how
			// every shell behaves and what people expect.
			if ( '"' === $quote ) {
				$value = str_replace(
					array( '\\n', '\\r', '\\t', '\\"', '\\\\' ),
					array( "\n", "\r", "\t", '"', '\\' ),
					$value
				);
			}

			return $value;
		}

		// Unquoted: a `#` starts a comment, but only with space before it —
		// otherwise a password containing one would be truncated.
		$value = preg_replace( '/\s+#.*$/', '', $value ) ?? $value;

		return trim( $value );
	}
}
