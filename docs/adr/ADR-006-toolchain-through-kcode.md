# ADR-006 — Quality Gates through KaririCode Devkit, with the Root Configs

**Status:** Accepted
**Date:** 2026-11-27

## Context

The reviewed 4.0 declared PHPUnit and PHPStan in `require-dev`, had no
other gate and no CI. KaririCode Spec V4.3.3 §29.1 asks for style, PHPStan
at level max, Psalm at level 1, complexity budgets, tests, a mutation score
of at least 80 and a security check; §32 asks for a committed lock, an SBOM
and CI actions pinned by commit SHA. The ecosystem installs its toolchain
with KaririCode Devkit, whose kcode 1.2.1 runs a component's committed
configs when `devkit.php` sets `'root_configs' => true`.

## Decision

- No `require-dev`. `devkit.php` pins PHPUnit 11.5.56, PHPStan 2.3.7 with
  tomasvotruba/cognitive-complexity 1.2.0, Psalm 6.19.1, PHP-CS-Fixer
  3.95.27 and Infection 0.35.5; kcode installs them into `.kcode/`.
- The configs live at the root (`phpstan.neon`, `psalm.xml`,
  `phpunit.xml.dist`, `infection.json5`, `.php-cs-fixer.dist.php`) and point
  into `.kcode/vendor/`. `tools/complexity.php` checks the §13 budgets,
  `tools/secret-scan.php` the tracked files, `tools/sbom.php` writes a
  CycloneDX 1.5 SBOM.
- `composer check` runs every gate in CI order; `composer security` audits
  the runtime lock and the toolchain lock.
- `composer.lock` is committed with the runtime only.
- CI installs kcode by version and SHA-256, runs the gates on PHP 8.4 and the
  suites on PHP 8.5 with the lowest dependencies the constraints allow.
- The server tests use real loopback sockets and drive the loop with
  `tick()`; one runs `start()` against a client in a child process, and one
  stops the loop from a signal handler (it needs ext-pcntl and ext-posix
  and is skipped without them).

## Consequences

- The same gates as kariricode/router, exception, sse and http, with the
  same commands.
- `composer install` alone does not bring the tools; contributors install
  kcode once (README, CONTRIBUTING).
- The suite needs a loopback interface and runs in about a second.

## Alternatives Considered

- **Tools in `require-dev`.** Rejected: their dependencies would enter the
  component's lock and audit surface, and versions would drift between
  components.
- **kcode's generated configs.** Rejected: the committed configs are what the
  Spec and the conformance tests read.
- **Mocked sockets for the server tests.** Rejected: partial writes, resets
  and the select timeout are properties of real sockets; `Session` already
  covers the protocol without them.

## References

- KaririCode Spec V4.3.3 §13, §29.1, §32.
- kariricode/router ADR-008, kariricode/sse ADR-004 and kariricode/http
  ADR-004 (the same move).
- https://cyclonedx.org/specification/overview/
