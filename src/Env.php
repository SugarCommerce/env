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
	 * @since 1.0.0
	 *
	 * @param array<string, string> $values Key-value pairs.
	 */
	public function __construct( array $values = array() ) {
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
		$values = is_readable( $path )
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
	public static function from_array( array $values ): self {
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
	 * A non-numeric value is the default rather than zero: `PORT=eighty`
	 * silently becoming 0 is worse than it being ignored.
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

		if ( null === $value || ! is_numeric( $value ) ) {
			if ( null === $default ) {
				throw new MissingValue( $key );
			}

			return $default;
		}

		return (int) $value;
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

		return array_values( array_filter( array_map( 'trim', explode( ',', $value ) ), 'strlen' ) );
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
	 * The raw value, preferring the real environment over the file.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key Name.
	 *
	 * @return string|null Null when absent or empty.
	 */
	private function raw( string $key ): ?string {
		$live = getenv( $key );

		if ( false !== $live && '' !== $live ) {
			return $live;
		}

		$value = $this->values[ $key ] ?? '';

		return '' === $value ? null : $value;
	}
}
