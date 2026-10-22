<?php
/**
 * @package SugarCommerce\Env\Tests
 */

declare( strict_types=1 );

namespace SugarCommerce\Env\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCommerce\Env\{Env, MissingValue, Parser};

/**
 * Class EnvTest
 */
final class EnvTest extends TestCase {

	protected function tearDown(): void {
		foreach ( array( 'TEST_LIVE', 'TEST_EMPTY' ) as $key ) {
			putenv( $key );
		}

		parent::tearDown();
	}

	/* ── parsing ─────────────────────────────────────────────────────── */

	public function test_it_reads_simple_pairs(): void {
		$values = Parser::parse( "FOO=bar\nBAZ=qux" );

		$this->assertSame( array( 'FOO' => 'bar', 'BAZ' => 'qux' ), $values );
	}

	public function test_it_ignores_comments_and_blank_lines(): void {
		$values = Parser::parse( "# a comment\n\nFOO=bar\n\n# another" );

		$this->assertSame( array( 'FOO' => 'bar' ), $values );
	}

	/**
	 * `export FOO=bar` is how a line gets copied out of a shell.
	 */
	public function test_it_accepts_an_export_prefix(): void {
		$this->assertSame( array( 'FOO' => 'bar' ), Parser::parse( 'export FOO=bar' ) );
	}

	public function test_it_strips_quotes(): void {
		$values = Parser::parse( "SINGLE='a b'\nDOUBLE=\"c d\"" );

		$this->assertSame( 'a b', $values['SINGLE'] );
		$this->assertSame( 'c d', $values['DOUBLE'] );
	}

	/**
	 * A trailing comment is a comment; a `#` inside a value is not. Getting
	 * this wrong truncates a password at the first hash.
	 */
	public function test_it_only_strips_a_comment_after_whitespace(): void {
		$values = Parser::parse( "PORT=8080 # the http port\nPASS=abc#def" );

		$this->assertSame( '8080', $values['PORT'] );
		$this->assertSame( 'abc#def', $values['PASS'] );
	}

	public function test_a_hash_inside_quotes_is_kept(): void {
		$this->assertSame( 'a # b', Parser::parse( 'X="a # b"' )['X'] );
	}

	/**
	 * A private key pasted into a .env file spans lines. A line-at-a-time
	 * parser takes the first one and produces a key that verifies nothing.
	 */
	public function test_it_reads_a_multi_line_value(): void {
		$contents = "KEY=\"-----BEGIN-----\nline two\nline three\n-----END-----\"\nAFTER=yes";
		$values   = Parser::parse( $contents );

		$this->assertStringContainsString( 'line two', $values['KEY'] );
		$this->assertStringContainsString( '-----END-----', $values['KEY'] );
		$this->assertSame( 'yes', $values['AFTER'], 'The parser lost its place after a multi-line value.' );
	}

	public function test_it_expands_escapes_in_double_quotes_only(): void {
		$values = Parser::parse( "A=\"one\\ntwo\"\nB='one\\ntwo'" );

		$this->assertSame( "one\ntwo", $values['A'] );
		$this->assertSame( 'one\ntwo', $values['B'] );
	}

	/**
	 * A comment after a quoted value must end the value there. It used to
	 * leave the quote looking unclosed, so every key after it was swallowed
	 * into the secret — `DEBUG=false` silently gone.
	 */
	public function test_a_comment_after_a_quoted_value_does_not_swallow_the_file(): void {
		$values = Parser::parse( "SECRET=\"a b\" # rotated monthly\nDEBUG=false\nNAME='c' # note\nLAST=1" );

		$this->assertSame( array( 'SECRET' => 'a b', 'DEBUG' => 'false', 'NAME' => 'c', 'LAST' => '1' ), $values );
	}

	/**
	 * A quote that never closes is the first character of the value, not
	 * the start of one that runs to the end of the file.
	 */
	public function test_an_unclosed_quote_is_a_literal_and_does_not_swallow_the_file(): void {
		$values = Parser::parse( "PASS=\"abc\nDEBUG=false" );

		$this->assertSame( array( 'PASS' => '"abc', 'DEBUG' => 'false' ), $values );
	}

	/**
	 * `\\"` is an escaped backslash and then the closing quote.
	 */
	public function test_an_escaped_backslash_before_the_closing_quote_closes_it(): void {
		$values = Parser::parse( "PATH_ROOT=\"C:\\\\\"\nNEXT=1" );

		$this->assertSame( array( 'PATH_ROOT' => 'C:\\', 'NEXT' => '1' ), $values );
	}

	/**
	 * Escapes are expanded in one pass: `\\n` is a backslash and an `n`.
	 */
	public function test_an_escaped_backslash_is_not_read_as_a_newline(): void {
		$this->assertSame( 'C:\\new', Parser::parse( 'P="C:\\\\new"' )['P'] );
	}

	public function test_an_escaped_quote_does_not_close_the_value(): void {
		$this->assertSame( 'say "hi"', Parser::parse( 'Q="say \\"hi\\""' )['Q'] );
	}

	/**
	 * Nothing is expanded, so a value cannot pull in another secret.
	 */
	public function test_variables_are_not_expanded(): void {
		$this->assertSame( '${HOME}/$USER', Parser::parse( 'X="${HOME}/$USER"' )['X'] );
	}

	public function test_a_byte_order_mark_does_not_lose_the_first_key(): void {
		$this->assertSame( array( 'FIRST' => '1' ), Parser::parse( "\xEF\xBB\xBFFIRST=1" ) );
	}

	#[DataProvider( 'malformed_lines' )]
	public function test_it_skips_what_it_cannot_use( string $line ): void {
		$this->assertSame( array(), Parser::parse( $line ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function malformed_lines(): array {
		return array(
			'no equals'      => array( 'JUST_A_WORD' ),
			'leading digit'  => array( '1FOO=bar' ),
			'has a dash'     => array( 'FOO-BAR=baz' ),
			'has a space'    => array( 'FOO BAR=baz' ),
			'empty'          => array( '' ),
			'newline in key' => array( "FOO\n=bar" ),
		);
	}

	/* ── precedence ──────────────────────────────────────────────────── */

	/**
	 * A stale file on a box must not override what the platform configured.
	 */
	public function test_the_real_environment_beats_the_file(): void {
		putenv( 'TEST_LIVE=from-environment' );

		$env = Env::from_array( array( 'TEST_LIVE' => 'from-file' ) );

		$this->assertSame( 'from-environment', $env->string( 'TEST_LIVE' ) );
	}

	/**
	 * An empty variable is not a value — otherwise `FOO=` in a file would
	 * shadow a real one and read as configured-but-blank.
	 */
	public function test_an_empty_value_counts_as_absent(): void {
		$env = Env::from_array( array( 'TEST_EMPTY' => '' ) );

		$this->assertFalse( $env->has( 'TEST_EMPTY' ) );
		$this->assertSame( 'fallback', $env->string( 'TEST_EMPTY', 'fallback' ) );
	}

	/**
	 * Nothing is written to putenv(): a process-global secret is readable by
	 * anything else sharing the process.
	 */
	public function test_loading_does_not_pollute_the_process(): void {
		Env::from_array( array( 'TEST_NOT_EXPORTED' => 'secret' ) );

		$this->assertFalse( getenv( 'TEST_NOT_EXPORTED' ) );
		$this->assertArrayNotHasKey( 'TEST_NOT_EXPORTED', $_ENV );
	}

	public function test_a_missing_file_is_not_an_error(): void {
		$env = Env::load( '/nowhere/at/all/.env' );

		$this->assertSame( 'default', $env->string( 'ANYTHING', 'default' ) );
	}

	/* ── typed access ────────────────────────────────────────────────── */

	public function test_a_required_value_throws_by_name(): void {
		try {
			Env::from_array( array() )->string( 'TOKEN_SECRET' );
			$this->fail( 'A missing required value did not throw.' );
		} catch ( MissingValue $thrown ) {
			$this->assertSame( 'TOKEN_SECRET', $thrown->key );
			$this->assertStringContainsString( 'TOKEN_SECRET', $thrown->getMessage() );
		}
	}

	public function test_integers(): void {
		$env = Env::from_array( array( 'PORT' => '8080', 'BAD' => 'eighty' ) );

		$this->assertSame( 8080, $env->int( 'PORT' ) );
		$this->assertSame( 3000, $env->int( 'MISSING', 3000 ) );
	}

	/**
	 * `PORT=eighty` silently becoming 0 is worse than being ignored.
	 */
	public function test_a_non_numeric_integer_is_the_default_not_zero(): void {
		$this->assertSame( 3000, Env::from_array( array( 'PORT' => 'eighty' ) )->int( 'PORT', 3000 ) );
	}

	/**
	 * Only whole numbers: a guess is worse than the default.
	 */
	#[DataProvider( 'not_whole_numbers' )]
	public function test_anything_but_a_whole_number_is_the_default( string $written ): void {
		$this->assertSame( 3000, Env::from_array( array( 'PORT' => $written ) )->int( 'PORT', 3000 ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function not_whole_numbers(): array {
		return array(
			'fraction'    => array( '2.9' ),
			'exponent'    => array( '1e3' ),
			'hex'         => array( '0x1F' ),
			'overflowing' => array( '99999999999999999999' ),
			'trailing'    => array( "80\n" . 'x' ),
		);
	}

	public function test_signed_and_zero_padded_integers(): void {
		$env = Env::from_array( array( 'A' => '-5', 'B' => '+7', 'C' => '0080', 'D' => '0' ) );

		$this->assertSame( -5, $env->int( 'A' ) );
		$this->assertSame( 7, $env->int( 'B' ) );
		$this->assertSame( 80, $env->int( 'C' ) );
		$this->assertSame( 0, $env->int( 'D' ) );
	}

	/**
	 * A debug dump of the instance must not print every secret it holds.
	 */
	public function test_a_dump_shows_names_but_never_values(): void {
		$env = Env::from_array( array( 'TOKEN_SECRET' => 'sk_live_do_not_print' ) );

		$dump = print_r( $env, true );

		$this->assertStringContainsString( 'TOKEN_SECRET', $dump );
		$this->assertStringNotContainsString( 'sk_live_do_not_print', $dump );
	}

	/**
	 * Secrets handed to the constructor must not appear in a stack trace.
	 */
	public function test_values_are_hidden_from_stack_traces(): void {
		$parameters = ( new \ReflectionMethod( Env::class, 'from_array' ) )->getParameters();

		$this->assertNotEmpty( $parameters[0]->getAttributes( \SensitiveParameter::class ) );
	}

	/**
	 * An `HTTP_*` name is read from the process environment only — under
	 * FastCGI a request header would otherwise set it (httpoxy).
	 */
	public function test_an_http_prefixed_key_still_reads_the_process_environment(): void {
		putenv( 'HTTP_TEST_PROXY=from-process' );

		try {
			$this->assertSame( 'from-process', Env::from_array( array() )->string( 'HTTP_TEST_PROXY' ) );
		} finally {
			putenv( 'HTTP_TEST_PROXY' );
		}
	}

	#[DataProvider( 'booleans' )]
	public function test_booleans( string $written, bool $expected ): void {
		$this->assertSame( $expected, Env::from_array( array( 'FLAG' => $written ) )->bool( 'FLAG' ) );
	}

	/**
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public static function booleans(): array {
		return array(
			'true'  => array( 'true', true ),
			'TRUE'  => array( 'TRUE', true ),
			'1'     => array( '1', true ),
			'yes'   => array( 'yes', true ),
			'on'    => array( 'on', true ),
			'false' => array( 'false', false ),
			'0'     => array( '0', false ),
			'no'    => array( 'no', false ),
			'off'   => array( 'off', false ),
		);
	}

	/**
	 * A typo must not read as false and quietly turn a safety feature off.
	 */
	public function test_an_unrecognised_boolean_keeps_the_default(): void {
		$env = Env::from_array( array( 'FLAG' => 'maybe' ) );

		$this->assertTrue( $env->bool( 'FLAG', true ) );
		$this->assertFalse( $env->bool( 'FLAG', false ) );
	}

	public function test_lists(): void {
		$env = Env::from_array( array( 'HOSTS' => 'a.com, b.com ,, c.com,' ) );

		$this->assertSame( array( 'a.com', 'b.com', 'c.com' ), $env->list( 'HOSTS' ) );
		$this->assertSame( array( 'fallback' ), $env->list( 'MISSING', array( 'fallback' ) ) );
	}

	public function test_maps(): void {
		$env = Env::from_array( array( 'PLANS' => 'price_a:starter, price_b:pro' ) );

		$this->assertSame( array( 'price_a' => 'starter', 'price_b' => 'pro' ), $env->map( 'PLANS' ) );
	}

	public function test_a_malformed_map_entry_is_skipped(): void {
		$env = Env::from_array( array( 'PLANS' => 'price_a:starter,nonsense,price_b:pro' ) );

		$this->assertSame( array( 'price_a' => 'starter', 'price_b' => 'pro' ), $env->map( 'PLANS' ) );
	}

	/**
	 * Reporting everything wrong at once beats one key per restart.
	 */
	public function test_missing_reports_every_absent_key_in_order(): void {
		$env = Env::from_array( array( 'A' => '1', 'C' => '3' ) );

		$this->assertSame( array( 'B', 'D' ), $env->missing( array( 'A', 'B', 'C', 'D' ) ) );
		$this->assertSame( array(), $env->missing( array( 'A', 'C' ) ) );
	}

	/* ── the whole thing ─────────────────────────────────────────────── */

	public function test_it_reads_a_real_file(): void {
		$path = sys_get_temp_dir() . '/env-test-' . bin2hex( random_bytes( 4 ) );

		file_put_contents(
			$path,
			"# credentials\nAPI_KEY=\"sk_test_123\"\nPORT=9000 # http\nDEBUG=yes\nHOSTS=a.com,b.com\n"
		);

		$env = Env::load( $path );

		unlink( $path );

		$this->assertSame( 'sk_test_123', $env->string( 'API_KEY' ) );
		$this->assertSame( 9000, $env->int( 'PORT' ) );
		$this->assertTrue( $env->bool( 'DEBUG' ) );
		$this->assertSame( array( 'a.com', 'b.com' ), $env->list( 'HOSTS' ) );
	}
}
