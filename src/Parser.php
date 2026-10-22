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
	 * The byte-order mark some editors put at the start of a UTF-8 file.
	 *
	 * @since 1.1.0
	 *
	 * @var string
	 */
	private const string BOM = "\xEF\xBB\xBF";

	/**
	 * The escapes a double-quoted value understands, and what each becomes.
	 *
	 * Applied in a single pass, so `\\n` is a backslash followed by `n` and
	 * not a backslash followed by a newline.
	 *
	 * @since 1.1.0
	 *
	 * @var array<string, string>
	 */
	private const array ESCAPES = array(
		'\\n'  => "\n",
		'\\r'  => "\r",
		'\\t'  => "\t",
		'\\"'  => '"',
		'\\\\' => '\\',
	);

	/**
	 * Parse the contents of a .env file.
	 *
	 * Nothing is expanded: `$OTHER` and `${OTHER}` are literal text, so a
	 * value can never pull in another secret or the server's environment.
	 *
	 * @since 1.0.0
	 *
	 * @param string $contents Raw file contents.
	 *
	 * @return array<string, string> Keys in file order.
	 */
	public static function parse( #[\SensitiveParameter] string $contents ): array {
		if ( str_starts_with( $contents, self::BOM ) ) {
			$contents = substr( $contents, strlen( self::BOM ) );
		}

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

			$value  = ltrim( $value );
			$quoted = self::opens_quote( $value ) ? self::quoted( $value, $lines, $i ) : null;

			$values[ $key ] = $quoted ?? self::unquoted( $value );
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
		return 1 === preg_match( '/^[A-Za-z_][A-Za-z0-9_]*$/D', $key );
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
	 * Read a quoted value, following it over as many lines as it takes.
	 *
	 * The value ends at the first closing quote that is not escaped, and
	 * anything after it on that line — a comment, usually — is ignored. A
	 * pasted private key is the reason this reads across lines at all.
	 *
	 * A quote that never closes is not a quoted value: it is a value that
	 * happens to begin with a quote, so this returns null and leaves `$i`
	 * alone. Reading to the end of the file instead would swallow every key
	 * after it — a `DEBUG=false` among them — without a word.
	 *
	 * @since 1.1.0
	 *
	 * @param string             $value The value as it appears on its first line.
	 * @param array<int, string> $lines Every line of the file.
	 * @param int                $i     Index of the current line; moved to the
	 *                                  line the value ends on.
	 *
	 * @return string|null The unquoted value, or null when the quote never closes.
	 */
	private static function quoted( #[\SensitiveParameter] string $value, array $lines, int &$i ): ?string {
		$quote = $value[0];
		$body  = substr( $value, 1 );
		$from  = 0;
		$line  = $i;
		$total = count( $lines );

		while ( true ) {
			$end = self::closing( $body, $quote, $from );

			if ( null !== $end ) {
				$i     = $line;
				$value = substr( $body, 0, $end );

				// Escapes only mean anything inside double quotes, which is
				// how every shell behaves and what people expect.
				return '"' === $quote ? strtr( $value, self::ESCAPES ) : $value;
			}

			if ( $line + 1 >= $total ) {
				return null;
			}

			$from  = strlen( $body ) + 1;
			$body .= "\n" . $lines[ ++$line ];
		}
	}

	/**
	 * Where the closing quote is, if there is one.
	 *
	 * Inside double quotes a backslash escapes the next character, so `\"`
	 * does not close the value and `\\"` does. Single quotes have no escapes.
	 *
	 * @since 1.1.0
	 *
	 * @param string $body  The value after its opening quote.
	 * @param string $quote The opening quote character.
	 * @param int    $from  Offset to start looking from.
	 *
	 * @return int|null Offset of the closing quote, or null when there is none.
	 */
	private static function closing( #[\SensitiveParameter] string $body, string $quote, int $from ): ?int {
		$length = strlen( $body );

		for ( $at = $from; $at < $length; $at++ ) {
			if ( '"' === $quote && '\\' === $body[ $at ] ) {
				++$at;
				continue;
			}

			if ( $quote === $body[ $at ] ) {
				return $at;
			}
		}

		return null;
	}

	/**
	 * Strip a trailing comment from an unquoted value.
	 *
	 * A `#` starts a comment only with space before it — otherwise a
	 * password containing one would be truncated.
	 *
	 * @since 1.1.0
	 *
	 * @param string $value Raw value.
	 *
	 * @return string
	 */
	private static function unquoted( #[\SensitiveParameter] string $value ): string {
		$value = preg_replace( '/\s+#.*$/', '', $value ) ?? $value;

		return trim( $value );
	}
}
