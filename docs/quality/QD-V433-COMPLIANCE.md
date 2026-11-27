# QD-V433: kariricode/websocket 4.0.0 — compliance with Spec V4.3.3

**Status:** Measured · **Date:** 2026-11-27 · PHP 8.4.28, PHPUnit 11.5.56, PHPStan 2.3.7, Psalm 6.19.1, PHP-CS-Fixer 3.95.27, Infection 0.35.5 (all pinned in `devkit.php`, ADR-006)

Reproduce with `composer check` and `composer security`; each number below
comes from that run. Runtime: kariricode/contract 2.12.1, kariricode/exception
2.0.2, kariricode/http 4.0.0, kariricode/router 4.1.0, psr/clock 1.0.0,
psr/http-factory 1.1.0, psr/http-message 2.0, psr/http-server-handler 1.0.2,
psr/http-server-middleware 1.0.2. The suites also pass with
`composer update --prefer-lowest` (kariricode/contract 2.11.0).

## 1. Gates (§29.1)

| Gate | Command | Result |
|---|---|---|
| Style | `composer cs-check` (PER-CS 2.0) | pass: 0 files to fix |
| Static analysis | `composer phpstan` (level max; src, tests, tools) | pass: 0 errors |
| Static analysis | `composer psalm` (errorLevel 1) | pass: 0 errors |
| Complexity | `composer complexity` | pass: 179 methods within budget, 0 accepted breaches; cognitive complexity 0 errors |
| Unit | `composer test-unit` | 257 tests, 1225 assertions, all pass |
| Conformance | `composer test-conformance` | 20 tests, 630 assertions, all pass |
| Coverage | PHPUnit with pcov | lines 99.40% (500 of 503), methods 98.16% |
| Mutation | `composer mutation` (MSI and covered MSI ≥ 80) | pass: MSI 98.59%, covered MSI 98.59% (852 mutants: 822 killed, 17 time-outs, 1 syntax error, 12 escaped) |
| Security | `composer security` | `composer audit --locked`: 0 advisories (runtime and toolchain); secret scan: clean |
| SBOM | `composer sbom` | CycloneDX 1.5, 9 runtime components |

The 3 uncovered lines are failures plain TCP on loopback does not produce:
an accept that finds no connection after `stream_select()` reported one, a
readable socket that returns no bytes before end of file, and a write that
fails while the socket is not also readable (a reset is read first).

The 12 escaped mutants are equivalent or unobservable through sockets:

- 5 change the listening socket's context (backlog 128 to 127 or 129, no
  backlog, no options, TCP_NODELAY off); none changes what a client sees.
- 2 change the accept timeout from 0; accept runs only after
  `stream_select()` reported a pending connection.
- 2 remove an `fclose()` (the listening socket in `stop()`, a client in
  `drop()`); PHP closes the resource when its last reference goes at the end
  of the call or round.
- 1 drops the float cast of `hrtime(true)`; dividing by `1e9` gives a float
  either way.
- 1 masks the 7-bit length with `126` instead of `0x7F` in the control-frame
  check; 126 and 127 both stay above 125 and smaller values do not change.
- 1 negates the guard for a readable socket that returns no bytes before end
  of file, which plain TCP does not produce (see the uncovered lines).

## 2. Rules (§29.2), checked by `tests/Conformance`

| Rule | Result |
|---|---|
| One type per file, strict types, namespace | 30 of 30 types |
| Classes final | 24 of 24 classes |
| Exception families (§8.4) | 6 of 6 classes, one family each, on kariricode/exception bases; named constructors only |
| Codes (registry 1800–1899, prefix WS) | 40 codes, unique, in the documented ranges, one factory each; the row in CODE-BLOCKS.md of kariricode/exception |
| No echo of input (E-047) | fixed messages; strings through `quote()`; refused handshakes get fixed responses |
| Zero-argument getters, setters, magic methods (§18) | 0 |
| Static or writable public state (§18) | 0 |
| Untyped constants | 0 |
| Error suppression, eval, debug output | 0 (`Server\Silently` scopes an error handler instead of `@`) |
| Docblocks C1–C5 (§17) | 30 of 30 types |
| ADRs with the canonical sections | 6 of 6 |
| Imports (§31) | kariricode/router and kariricode/http only in `src/Integration` |

## 3. Accepted deviations

| # | Item | Reason |
|---|---|---|
| 1 | `object` parameters of `WebSocketNormalizer::canNormalize()` and `normalize()` | the signatures of kariricode/router's `RequestNormalizer` (its ADR-002) |
| 2 | Soft budget of ten public methods exceeded by `Connection` (12) and `WebSocketConnection` (17), `FrameException` (15) and `HandshakeException` (12) | the connection contract a handler uses, plus the outbox the server drains; one named constructor per code |

## 4. Open items

1. The release tag is created by the GitHub release form and is not signed
   (§32); signing needs the maintainer's key.
2. TLS is not terminated in the loop; a reverse proxy or the application's
   own loop with `Integration\TlsContext` does it (ADR-004).
3. permessage-deflate (RFC 7692) is not implemented (ADR-001).
4. `stream_select()` limits one process to descriptors below `FD_SETSIZE`;
   an event-loop driver for `Session` is future work (ADR-004).
