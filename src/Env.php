<?php
/**
 * Environment configuration.
 *
 * @package   SugarCommerce\Env
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Env;

/**
 * Class Env
 *
 * Reads a .env file and hands back typed values.
 *
 * Two decisions worth knowing about.
 *
 * **The real environment always wins.** A value already set by the server is
 * never overwritten by the file, so a deployment can set secrets properly and
 * ship no .env at all — and a stale file left on a box cannot quietly
 * override what the platform configured.
 *
 * **Nothing is written to `putenv()` or `$_ENV`.** Values are held on the
 * instance. `putenv()` is process-global, which means anything else running in
 * the same process can read a secret it was never given, and on some setups
 * those values reach subprocesses and get logged. Keeping them here costs
 * nothing and leaks nothing.
 *
 * @since 1.0.0
 */
final class Env {

	/**
	 * Values from the file, and the ones already in the environment.
	 *
	 * @var array<string, string>
	 */
	private array $values;

	/**
	 * Hold a set of values; the real environment still wins over them.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, string> $values Key-value pairs.
	 */
	public function __construct( #[\SensitiveParameter] array $values = array() ) {
		$this->values = $values;
	}

	/**
	 * Load a .env file.
	 *
	 * A missing file is not an error: production sets values through the
	 * server and has no file to read.
	 *
	 * @since 1.0.0
	 *
	 * @param string $path Path to the .env file.
	 *
	 * @return self
	 */
	public static function load( string $path ): self {
		$values = is_file( $path ) && is_readable( $path )
			? Parser::parse( (string) file_get_contents( $path ) )
			: array();

		return new self( $values );
	}

	/**
	 * Build from an array, without touching the filesystem.
	 *
	 * For tests, and for anything that gets its configuration from elsewhere.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, string> $values Key-value pairs.
	 *
	 * @return self
	 */
	public static function from_array( #[\SensitiveParameter] array $values ): self {
		return new self( $values );
	}

	/**
	 * A string.
	 *
	 * @since 1.0.0
	 *
	 * @param string      $key     Name.
	 * @param string|null $default Returned when absent. Null makes it required.
	 *
	 * @return string
	 *
	 * @throws MissingValue When required and absent.
	 */
	public function string( string $key, ?string $default = null ): string {
		$value = $this->raw( $key );

		if ( null !== $value ) {
			return $value;
		}

		if ( null === $default ) {
			throw new MissingValue( $key );
		}

		return $default;
	}

	/**
	 * An integer.
	 *
	 * Only whole numbers count. Anything else is the default rather than a
	 * guess: `PORT=eighty` silently becoming 0, `LIMIT=2.9` becoming 2,
	 * `LIMIT=1e3` becoming 1000 or a number too big for an int saturating
	 * to PHP_INT_MAX are all worse than the value being ignored.
	 *
	 * @since 1.0.0
	 *
	 * @param string   $key     Name.
	 * @param int|null $default Returned when absent. Null makes it required.
	 *
	 * @return int
	 *
	 * @throws MissingValue When required and absent.
	 */
	public function int( string $key, ?int $default = null ): int {
		$value = $this->raw( $key );

		$int = null === $value ? null : self::whole_number( $value );

		if ( null === $int ) {
			if ( null === $default ) {
				throw new MissingValue( $key );
			}

			return $default;
		}

		return $int;
	}

	/**
	 * A boolean.
	 *
	 * Accepts what people write in a config file: true/false, 1/0, yes/no,
	 * on/off. Anything else is the default, because a typo should not silently
	 * read as false and turn a safety feature off.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key     Name.
	 * @param bool   $default Returned when absent or unrecognised.
	 *
	 * @return bool
	 */
	public function bool( string $key, bool $default = false ): bool {
		$value = $this->raw( $key );

		if ( null === $value ) {
			return $default;
		}

		return match ( strtolower( trim( $value ) ) ) {
			'true', '1', 'yes', 'on'   => true,
			'false', '0', 'no', 'off'  => false,
			default                    => $default,
		};
	}

	/**
	 * A comma-separated list.
	 *
	 * Empty entries are dropped, so a trailing comma is harmless.
	 *
	 * @since 1.0.0
	 *
	 * @param string             $key     Name.
	 * @param array<int, string> $default Returned when absent.
	 *
	 * @return array<int, string>
	 */
	public function list( string $key, array $default = array() ): array {
		$value = $this->raw( $key );

		if ( null === $value ) {
			return $default;
		}

		return array_values(
			array_filter(
				array_map( trim( ... ), explode( ',', $value ) ),
				static fn( string $entry ): bool => '' !== $entry
			)
		);
	}

	/**
	 * A map, written `key:value,key:value`.
	 *
	 * For the small lookup tables that belong with credentials rather than in
	 * a database — which price maps to which plan, which host routes where.
	 *
	 * @since 1.0.0
	 *
	 * @param string                $key     Name.
	 * @param array<string, string> $default Returned when absent.
	 *
	 * @return array<string, string>
	 */
	public function map( string $key, array $default = array() ): array {
		$value = $this->raw( $key );

		if ( null === $value ) {
			return $default;
		}

		$map = array();

		foreach ( explode( ',', $value ) as $pair ) {
			if ( ! str_contains( $pair, ':' ) ) {
				continue;
			}

			[ $name, $mapped ] = explode( ':', $pair, 2 );
			$name              = trim( $name );

			if ( '' !== $name ) {
				$map[ $name ] = trim( $mapped );
			}
		}

		return $map ?: $default;
	}

	/**
	 * Is this key set to anything at all?
	 *
	 * @since 1.0.0
	 *
	 * @param string $key Name.
	 *
	 * @return bool
	 */
	public function has( string $key ): bool {
		return null !== $this->raw( $key );
	}

	/**
	 * Which of these keys are missing.
	 *
	 * For a startup check that reports everything wrong at once rather than
	 * one key per restart.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, string> $keys Names that must be present.
	 *
	 * @return array<int, string> The absent ones, in the order given.
	 */
	public function missing( array $keys ): array {
		return array_values( array_filter( $keys, fn( string $key ): bool => ! $this->has( $key ) ) );
	}

	/**
	 * What var_dump() and print_r() show: the names, never the values.
	 *
	 * The instance holds every secret the application has, and a debug dump
	 * of an object that holds it is exactly how one ends up in a log.
	 *
	 * @since 1.1.0
	 *
	 * @return array{keys: list<string>}
	 */
	public function __debugInfo(): array {
		return array( 'keys' => array_keys( $this->values ) );
	}

	/**
	 * The raw value, preferring the real environment over the file.
	 *
	 * A name starting `HTTP_` is read from the process environment only.
	 * Under CGI and FastCGI every request header arrives as an `HTTP_*`
	 * variable, so `Proxy: evil.example` in a request would otherwise become
	 * the value of `HTTP_PROXY` — the "httpoxy" attack.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key Name.
	 *
	 * @return string|null Null when absent or empty.
	 */
	private function raw( string $key ): ?string {
		$live = getenv( $key, str_starts_with( strtoupper( $key ), 'HTTP_' ) );

		if ( false !== $live && '' !== $live ) {
			return $live;
		}

		$value = $this->values[ $key ] ?? '';

		return '' === $value ? null : $value;
	}

	/**
	 * Read a whole number, or nothing.
	 *
	 * @since 1.1.0
	 *
	 * @param string $value Raw value.
	 *
	 * @return int|null Null when it is not a whole number that fits in an int.
	 */
	private static function whole_number( string $value ): ?int {
		if ( 1 !== preg_match( '/^([+-]?)0*([0-9]+)$/D', trim( $value ), $parts ) ) {
			return null;
		}

		$int = filter_var( $parts[1] . $parts[2], FILTER_VALIDATE_INT );

		return false === $int ? null : $int;
	}
}
