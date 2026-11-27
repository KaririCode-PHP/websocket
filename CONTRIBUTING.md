# Contributing

## Gates

```bash
composer install     # from composer.lock: kariricode/exception, http, router and their dependencies
kcode init           # install the toolchain pinned in devkit.php into .kcode/
composer check       # cs-check, phpstan (max), psalm (1), complexity, the suites, mutation
composer security    # audit of the runtime and the toolchain, secret scan
composer sbom        # CycloneDX 1.5 SBOM
```

[KaririCode Devkit](https://github.com/KaririCode-PHP/devkit) (`kcode`) installs the tools and runs this repository's configs (ADR-006). Install 1.2.1 and check it against its published SHA-256. Mutation testing needs pcov or Xdebug; the server tests need a loopback interface, and one of them needs ext-pcntl and ext-posix.

## Code rules (KaririCode Spec V4.3.3)

- **Files.** `declare(strict_types=1)`, one type per file, PSR-4, PER Coding Style.
- **Type docblocks.** Each states the domain role, its preconditions, thread-safety and side effects, a usage example, references and an `@see` link (§17). The conformance suite checks the markers.
- **Prohibited.** Zero-argument `get*()` methods, `set*()` methods (`putAttribute()` stores an attribute), `toArray()`/`fromArray()`, magic methods, static state, `object` parameters beyond the router's `RequestNormalizer` signatures, and error suppression with `@` (use `Server\Silently`). kariricode/router and kariricode/http may be imported only in `src/Integration`.
- **Wire format.** Every RFC 6455 rule a client can break has its own `WsCode` and the close code or HTTP status the RFC names (ADR-001, ADR-005). A new rule gets a test with the bytes that break it.
- **The loop.** Protocol logic belongs in `Session`, which has no socket; `WebSocketServer` only moves bytes (ADR-004). Nothing may block on one client.
- **Complexity.** Cyclomatic ≤ 10, cognitive ≤ 15, nesting ≤ 3, parameters ≤ 4 (§13). `tools/complexity-baseline.json` lists accepted breaches with their reasons; it is empty and may only stay so or shrink.
- **Exceptions.** A failure is a final class in a kariricode/contract family, built by a named constructor with a `WsCode` case (ADR-003). Messages never repeat a value the client controls; identifiers go through `quote()`. A new code takes the next free number in its range.
- **Tests.** Every bug fix ships with a test that fails without it. `tests/Support` has a client that writes handshakes and masked frames byte by byte, and a recording handler.

## Decisions and releases

- **ADRs** go in `docs/adr/` with Status, Date, and the sections Context, Decision, Consequences, Alternatives Considered and References, in that order.
- **Commits** follow Conventional Commits.
- **Releases** follow Semantic Versioning. Tags should be signed (`git tag -s`); releases created from the GitHub UI are not, for now.
