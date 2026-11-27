<?php

/**
 * Security Secret Redactor Test.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Tests\Security;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Phlix\Shared\Security\SecretRedactor;

/**
 * Unit tests for the canonical SecretRedactor.
 *
 * Carries the full exact-value kernel pin set from the Arr era verbatim
 * (the S-shared-10 promotion moves the home, never the behavior), then pins
 * the new pattern arms — `redactUri()` and `redactBearer()` — including the
 * two shapes the finding named as the bug being closed: a credential-named
 * query value erased WITHOUT the caller holding the secret, and a prose line
 * that must survive the entropy gate untouched.
 *
 * @package Phlix\Shared\Tests\Security
 */
#[CoversClass(SecretRedactor::class)]
class SecretRedactorTest extends TestCase
{
    // ------------------------------------------------------------------
    // Exact-value kernel — the ten Arr-era pins, verbatim behavior.
    // ------------------------------------------------------------------

    public function testRedactReplacesSingleSecret(): void
    {
        $message = 'Connection failed: API key abc123secret was rejected';
        $result = SecretRedactor::redact($message, 'abc123secret');

        $this->assertSame('Connection failed: API key *** was rejected', $result);
    }

    public function testRedactReplacesMultipleSecrets(): void
    {
        $message = 'Failed to connect to http://api.example.com with key secret1 and key secret2';
        $result = SecretRedactor::redact($message, 'secret1', 'secret2');

        $this->assertSame('Failed to connect to http://api.example.com with key *** and key ***', $result);
    }

    public function testRedactHandlesApiKeyInUrl(): void
    {
        $message = 'GET http://localhost:7878/api/v3/movie?apiKey=my-super-secret-key';
        $result = SecretRedactor::redact($message, 'my-super-secret-key');

        $this->assertSame('GET http://localhost:7878/api/v3/movie?apiKey=***', $result);
    }

    public function testRedactHandlesEmptySecrets(): void
    {
        $message = 'Error: something went wrong';
        $result = SecretRedactor::redact($message, '', 'secret');

        $this->assertSame('Error: something went wrong', $result);
    }

    public function testRedactHandlesNoSecrets(): void
    {
        $message = 'Error: connection refused';
        $result = SecretRedactor::redact($message);

        $this->assertSame('Error: connection refused', $result);
    }

    public function testRedactHandlesSecretNotPresent(): void
    {
        $message = 'Error: connection refused';
        $result = SecretRedactor::redact($message, 'nonexistent');

        $this->assertSame('Error: connection refused', $result);
    }

    public function testRedactHandlesCaseSensitivity(): void
    {
        $message = 'API Key: SecretKey and secretkey are different';
        $result = SecretRedactor::redact($message, 'SecretKey');

        $this->assertSame('API Key: *** and secretkey are different', $result);
    }

    /**
     * LW-055 [review shared__srphp Minor #4]: overlapping secrets must be
     * replaced longest-first. Red-first at the parent commit: str_replace
     * applied the secrets in argument order, so the short 'KEY' pass ran
     * before 'SECRETKEY' and stranded the 'SECRET' residue of the longer
     * secret in the message.
     */
    public function testRedactOrdersOverlappingSecretsLongestFirst(): void
    {
        $message = 'Error with key SECRETKEY and token abc123secret for KEY';
        $result = SecretRedactor::redact($message, 'abc123secret', 'KEY', 'SECRETKEY');

        $this->assertSame('Error with key *** and token *** for ***', $result);
    }

    /**
     * LW-055: nested secret — the shorter secret is a strict prefix of the
     * longer one, so argument order alone would shred the longer value.
     */
    public function testRedactLongestWinsWhenSecretsAreNested(): void
    {
        $message = 'url=/feed?apiKey=AAABBBCCC&alt=AAABBB';
        $result = SecretRedactor::redact($message, 'AAABBB', 'AAABBBCCC');

        $this->assertSame('url=/feed?apiKey=***&alt=***', $result);
    }

    /**
     * LW-055: equal-length secrets keep working (PHP 8 sorts are stable —
     * equal lengths preserve the caller's order; either replacement is a
     * single '***' so the outcome is order-independent anyway).
     */
    public function testRedactHandlesEqualLengthSecrets(): void
    {
        $message = 'keys alpha and bravo both leak';
        $result = SecretRedactor::redact($message, 'alpha', 'bravo');

        $this->assertSame('keys *** and *** both leak', $result);
    }

    // ------------------------------------------------------------------
    // redactUri — pattern arms (phlix-shared-010 / SEC R-002).
    // ------------------------------------------------------------------

    /**
     * THE bug being closed: a credential-named query value collapses even
     * though the caller never passes the secret itself.
     */
    public function testRedactUriMasksApiKeyQueryValueWithoutKnowingTheSecret(): void
    {
        $this->assertSame(
            '/api/v3/movie?apikey=***&title=ok',
            SecretRedactor::redactUri('/api/v3/movie?apikey=SECRET42&title=ok')
        );
    }

    public function testRedactUriMasksTokenAndKeyQueryValues(): void
    {
        $this->assertSame(
            '/feed?token=***&key=***&name=ok',
            SecretRedactor::redactUri('/feed?token=abc123def&key=xyz789&name=ok')
        );
    }

    public function testRedactUriMasksPasswordQueryValueCaseInsensitively(): void
    {
        $this->assertSame(
            '/login?user=a&PASSWORD=***',
            SecretRedactor::redactUri('/login?user=a&PASSWORD=hunter2')
        );
    }

    /**
     * An empty query value is not a secret — `?apiKey=` must survive
     * byte-identically (the arm convicts values, not key names).
     */
    public function testRedactUriLeavesEmptyQueryValuesUntouched(): void
    {
        $this->assertSame(
            '/v3?apiKey=&q=1',
            SecretRedactor::redactUri('/v3?apiKey=&q=1')
        );
    }

    /**
     * A secret-free path passes through byte-identically — the property the
     * shared *arr base depends on to keep every constant-path 404/3xx
     * message exactly as it read before the promotion.
     */
    public function testRedactUriPassesPlainPathsThrough(): void
    {
        $this->assertSame('/api/v3/movie', SecretRedactor::redactUri('/api/v3/movie'));
        $this->assertSame(
            'http://localhost:7878/api/v3/movie',
            SecretRedactor::redactUri('http://localhost:7878/api/v3/movie', 'abc123secret')
        );
    }

    public function testRedactUriMasksUserinfoPasswordOnly(): void
    {
        $this->assertSame(
            'http://admin:***@127.0.0.1:8989/api',
            SecretRedactor::redactUri('http://admin:sup3rs3cret@127.0.0.1:8989/api')
        );
    }

    /**
     * Bare `user@host` userinfo carries no secret and must never match.
     */
    public function testRedactUriLeavesUserinfoWithoutPasswordUntouched(): void
    {
        $this->assertSame(
            'http://admin@127.0.0.1:8989/api',
            SecretRedactor::redactUri('http://admin@127.0.0.1:8989/api')
        );
    }

    /**
     * The exact-value kernel still layers on top of the pattern pass.
     */
    public function testRedactUriAppliesExactSecretsToo(): void
    {
        $this->assertSame(
            '/api/v3/movie?filter=***',
            SecretRedactor::redactUri('/api/v3/movie?filter=abc123secret', 'abc123secret')
        );
    }

    public function testRedactUriTakesMultipleSecrets(): void
    {
        $this->assertSame(
            '/x?p=***&q=***',
            SecretRedactor::redactUri('/x?p=aaa1&q=bbb2', 'aaa1', 'bbb2')
        );
    }

    // ------------------------------------------------------------------
    // redactBearer — pattern arm (phlix-shared-010 / SEC R-002).
    // ------------------------------------------------------------------

    public function testRedactBearerMasksBearerToken(): void
    {
        $this->assertSame(
            'Authorization failed: Bearer *** sent',
            SecretRedactor::redactBearer('Authorization failed: Bearer abc123.def456 sent')
        );
    }

    public function testRedactBearerMasksTokenEqualsShape(): void
    {
        $this->assertSame(
            'failed with token=***',
            SecretRedactor::redactBearer('failed with token=abc123=')
        );
    }

    public function testRedactBearerMasksJwtShapeValue(): void
    {
        $this->assertSame(
            'got Bearer *** back',
            SecretRedactor::redactBearer('got Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWI6MTIz.f-oo_bar back')
        );
    }

    public function testRedactBearerMasksBasicWithColonSeparator(): void
    {
        $this->assertSame(
            'header Basic: ***',
            SecretRedactor::redactBearer('header Basic: QVJDzogc2VjcmV0')
        );
    }

    /**
     * The entropy gate: ordinary prose containing the scheme WORDS survives
     * — "bearer of" / "token of" carry pure-letter values and are not
     * credentials. This is the deliberate bias of the pattern arm (known
     * secrets are still erased by the exact-value layer below).
     */
    public function testRedactBearerSparesProseWords(): void
    {
        $prose = 'the bearer of bad news lost token parity';
        $this->assertSame($prose, SecretRedactor::redactBearer($prose));
    }

    /**
     * Documented bias: a pure-letter UNKNOWN value is outside the pattern
     * arm's reach — but with the secret in hand, the kernel pass still hits.
     */
    public function testRedactBearerKernelPassCoversPureLetterSecret(): void
    {
        $message = 'Bearer mysecretvalue was logged';

        $this->assertSame($message, SecretRedactor::redactBearer($message));
        $this->assertSame(
            'Bearer *** was logged',
            SecretRedactor::redactBearer($message, 'mysecretvalue')
        );
    }

    public function testRedactBearerAppliesExactSecretsToo(): void
    {
        $this->assertSame(
            'error: leaked *** here',
            SecretRedactor::redactBearer('error: leaked s3cr3t-key here', 's3cr3t-key')
        );
    }

    /**
     * Multi-secret signature on every arm (SEC R-002: each Arr call-site
     * previously passed one secret; the arms admit the full set).
     */
    public function testRedactBearerTakesMultipleSecrets(): void
    {
        $this->assertSame(
            'key1 *** key2 *** plain kept',
            SecretRedactor::redactBearer('key1 alpha1 key2 bravo1 plain kept', 'alpha1', 'bravo1')
        );
    }
}
