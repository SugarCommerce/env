# Env

Environment configuration for plain PHP: `.env` parsing that handles the cases
people actually write, typed accessors that fail loudly on a missing
requirement, and nothing written to process globals. Zero dependencies.

## Why

Reading a `.env` file looks like four lines of code, and the four-line version
is wrong in ways that do not announce themselves.

**It truncates values at a `#`.** A generated password contains one eventually,
and the symptom is an authentication failure nobody can reproduce.

**It reads one line of a multi-line value.** A private key pasted into a `.env`
file spans lines. The naive parser takes the first, and you get a key that
verifies nothing and an error message about signatures.

**It strips quotes unconditionally**, including from a value that legitimately
begins with one.

**It calls `putenv()`.** That is process-global: anything else running in the
same process can read a secret it was never handed, and on some configurations
those values reach subprocesses and end up in logs.

**It returns `0` for `PORT=eighty`.** A typo becomes a valid-looking number and
the service binds somewhere surprising.

## Usage

```php
use SugarCommerce\Env\Env;

$env = Env::load( __DIR__ . '/.env' );

$env->string( 'TOKEN_SECRET' );              // required — throws if absent
$env->string( 'MAIL_FROM', 'noreply@…' );    // optional
$env->int( 'PORT', 8080 );
$env->bool( 'DEBUG', false );
$env->list( 'ALLOWED_HOSTS' );               // "a.com, b.com" → ['a.com', 'b.com']
$env->map( 'PLAN_PRICES' );                  // "price_a:pro" → ['price_a' => 'pro']
```

### Required values

A missing requirement throws `MissingValue`, which carries the key:

```php
try {
    $secret = $env->string( 'TOKEN_SECRET' );
} catch ( MissingValue $missing ) {
    exit( "Set {$missing->key} in .env" );
}
```

Its own type, so this can be told apart from every other `RuntimeException` —
one deserves a page telling somebody which key to set, the rest do not.

### Checking everything at once

```php
$missing = $env->missing( [ 'STRIPE_SECRET_KEY', 'STORAGE_ACCESS_KEY', 'TOKEN_SECRET' ] );

if ( $missing ) {
    exit( 'Missing: ' . implode( ', ', $missing ) );
}
```

One report beats one key per restart.

## The real environment wins

A value already set by the server is never overwritten by the file. Production
can set secrets properly and ship no `.env` at all, and a stale file left on a
box cannot quietly override what the platform configured.

An empty value counts as absent, so `FOO=` in a file does not shadow a real one
and read as configured-but-blank.

## What the parser handles

```env
# Comments, and blank lines.
export FOO=bar                  # `export` prefix, from copying out of a shell
PORT=8080 # the http port       # trailing comment, only after whitespace
PASSWORD=abc#def                # but not one inside a value
QUOTED="a b c" # rotated monthly # quotes stripped, comment after them ignored
LITERAL='no \n escapes'         # single quotes are literal
ESCAPED="line one\nline two"    # double quotes expand escapes
KEY="-----BEGIN-----
spanning
lines
-----END-----"                  # multi-line values
```

Anything it cannot use — a name with a dash, a line with no `=` — is skipped
rather than guessed at. A quote that never closes is part of the value, not
the start of one that swallows the rest of the file. Nothing is expanded:
`$HOME` and `${OTHER}` are literal text.

## Keeping secrets secret

`var_dump()` and `print_r()` of an `Env` show the key names and never the
values, and the values are marked `#[\SensitiveParameter]` so they stay out of
stack traces. A name starting `HTTP_` is read from the process environment
only: under CGI and FastCGI a request header arrives as an `HTTP_*` variable,
and would otherwise be able to set it.

## Testing

```php
Env::from_array( [ 'API_KEY' => 'sk_test_123' ] );
```

No filesystem, no globals to reset between tests.

## Requirements

PHP 8.5+. No extensions, no dependencies.

## Tests

52 tests covering the parser cases above, precedence, and every accessor.

```bash
composer test
```
