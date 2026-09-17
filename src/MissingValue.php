<?php
/**
 * A required value that is not there.
 *
 * @package   SugarCommerce\Env
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Env;

use RuntimeException;

/**
 * Class MissingValue
 *
 * Its own type so a caller can distinguish "this deployment is misconfigured"
 * from every other RuntimeException — the first is worth a clear page telling
 * somebody which key to set, the rest are not.
 *
 * @since 1.0.0
 */
final class MissingValue extends RuntimeException {

	/**
	 * @since 1.0.0
	 *
	 * @param string $key The name that was missing.
	 */
	public function __construct( public readonly string $key ) {
		parent::__construct( "Missing required configuration: {$key}" );
	}
}
