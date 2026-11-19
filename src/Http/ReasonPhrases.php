<?php

/**
 * ReasonPhrases.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Http;

/**
 * HTTP status reason-phrase table + the strict-empty resolve law (v0.57 leg 0.10 M-17, A-12).
 *
 * Canonical source: phlix-hub `src/Http/BodylessResponse.php:114-119`. The
 * reason fallback is a STRICT-EMPTY test — `$reason === null || $reason === ''`
 * — NOT `?:`: the parent's `$reason` is `?string`, and `''` must fall back to
 * the standard phrase exactly as an unset one does, while an explicit `'0'`
 * (a legal non-empty string) must SURVIVE. The server twin
 * (`phlix-server/src/Server/Workerman/BodylessResponse.php:109`) carries the
 * named bug (`$this->reason ?: ...` silently replaces a `'0'` reason with the
 * table phrase); consumer migration to this kernel IS that disclosed fix —
 * strictly a bug fix, client-visible only on a crafted HEAD-only response.
 *
 * The `PHRASES` table is a byte-faithful copy of the vendored
 * `Workerman\Protocols\Http\Response::PHRASES` both hosts read through their
 * parent (`self::PHRASES`) — same map, so both copies were already identical
 * except for the missing fix (A-12: "hub-as-donor makes this a source, not a
 * port"). Keep it in sync with the vendor on workerman upgrades; the class
 * pair itself cannot move (both extend the Workerman response — G-14), only
 * the table + resolver can, and here they are.
 */
final class ReasonPhrases
{
    /**
     * Status code => standard reason phrase (vendor map copy).
     *
     * @var array<int, string>
     */
    public const array PHRASES = [
        100 => 'Continue',
        101 => 'Switching Protocols',
        102 => 'Processing', // WebDAV; RFC 2518
        103 => 'Early Hints', // RFC 8297

        200 => 'OK',
        201 => 'Created',
        202 => 'Accepted',
        203 => 'Non-Authoritative Information', // since HTTP/1.1
        204 => 'No Content',
        205 => 'Reset Content',
        206 => 'Partial Content', // RFC 7233
        207 => 'Multi-Status', // WebDAV; RFC 4918
        208 => 'Already Reported', // WebDAV; RFC 5842
        226 => 'IM Used', // RFC 3229

        300 => 'Multiple Choices',
        301 => 'Moved Permanently',
        302 => 'Found', // Previously "Moved temporarily"
        303 => 'See Other', // since HTTP/1.1
        304 => 'Not Modified', // RFC 7232
        305 => 'Use Proxy', // since HTTP/1.1
        306 => 'Switch Proxy',
        307 => 'Temporary Redirect', // since HTTP/1.1
        308 => 'Permanent Redirect', // RFC 7538

        400 => 'Bad Request',
        401 => 'Unauthorized', // RFC 7235
        402 => 'Payment Required',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        406 => 'Not Acceptable',
        407 => 'Proxy Authentication Required', // RFC 7235
        408 => 'Request Timeout',
        409 => 'Conflict',
        410 => 'Gone',
        411 => 'Length Required',
        412 => 'Precondition Failed', // RFC 7232
        413 => 'Payload Too Large', // RFC 7231
        414 => 'URI Too Long', // RFC 7231
        415 => 'Unsupported Media Type', // RFC 7231
        416 => 'Range Not Satisfiable', // RFC 7233
        417 => 'Expectation Failed',
        418 => 'I\'m a teapot', // RFC 2324, RFC 7168
        421 => 'Misdirected Request', // RFC 7540
        422 => 'Unprocessable Entity', // WebDAV; RFC 4918
        423 => 'Locked', // WebDAV; RFC 4918
        424 => 'Failed Dependency', // WebDAV; RFC 4918
        425 => 'Too Early', // RFC 8470
        426 => 'Upgrade Required',
        428 => 'Precondition Required', // RFC 6585
        429 => 'Too Many Requests', // RFC 6585
        431 => 'Request Header Fields Too Large', // RFC 6585
        451 => 'Unavailable For Legal Reasons', // RFC 7725

        500 => 'Internal Server Error',
        501 => 'Not Implemented',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
        504 => 'Gateway Timeout',
        505 => 'HTTP Version Not Supported',
        506 => 'Variant Also Negotiates', // RFC 2295
        507 => 'Insufficient Storage', // WebDAV; RFC 4918
        508 => 'Loop Detected', // WebDAV; RFC 5842
        510 => 'Not Extended', // RFC 2774
        511 => 'Network Authentication Required', // RFC 6585
    ];

    /**
     * Non-instantiable: static table + resolver only.
     */
    private function __construct()
    {
    }

    /**
     * Resolve the status-line reason phrase — the hub's strict-empty law verbatim.
     *
     * Only null/`''` fall through to the table; any caller string (including
     * `'0'`) is returned unchanged. Unknown status without a custom reason
     * resolves to `''` (both twins' `?? ''` arm).
     *
     * @param string|null $custom caller-supplied reason phrase, or null
     * @param int         $status HTTP status code
     */
    public static function resolve(?string $custom, int $status): string
    {
        return ($custom === null || $custom === '')
            ? (self::PHRASES[$status] ?? '')
            : $custom;
    }
}
