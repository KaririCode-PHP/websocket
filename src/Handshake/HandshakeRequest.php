<?php

declare(strict_types=1);

namespace KaririCode\WebSocket\Handshake;

/**
 * The opening handshake a client sent, once HandshakeNegotiator parsed
 * it: the request target (path and query) and the header fields, with
 * lower-case names and every value in arrival order. The application
 * reads it from the connection, for example a token in the query or a
 * cookie, to decide who the client is.
 *
 * ARFA 1.3: P1 (immutable). Spec V4.3.3: final readonly Value Object.
 *
 * Preconditions: built by HandshakeNegotiator::parseRequest(), which
 * checked the target and every field against the HTTP grammar.
 *
 * Thread-safety: immutable; safe to share.
 *
 * Side effects: none.
 *
 * Example:
 *
 *     $request = $connection->request();
 *     parse_str($request->query(), $query);
 *     $cookie = $request->header('cookie');   // "session=…" or null
 *
 * References: RFC 6455 §4.1, §4.2.1; RFC 9112 §3.2 (origin-form); RFC
 * 9110 §5.3 (combined field values); ADR-005.
 *
 * @see https://www.rfc-editor.org/rfc/rfc6455#section-4.2.1
 */
final readonly class HandshakeRequest
{
    /**
     * @param array<string, non-empty-list<string>> $headers lower-case field name => values
     */
    public function __construct(
        public string $target,
        public array $headers,
    ) {}

    /** The target without its query. */
    public function path(): string
    {
        $query = strpos($this->target, '?');

        return $query === false ? $this->target : substr($this->target, 0, $query);
    }

    /** The query after "?", or "". */
    public function query(): string
    {
        $query = strpos($this->target, '?');

        return $query === false ? '' : substr($this->target, $query + 1);
    }

    /** The values of a field joined with ", " (RFC 9110 §5.3), or null when absent. */
    public function header(string $name): ?string
    {
        $values = $this->headers[strtolower($name)] ?? null;

        return $values === null ? null : implode(', ', $values);
    }
}
