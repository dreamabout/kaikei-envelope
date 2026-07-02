---
project: "kaikei-envelope"
module: "root"
generated_by: "draft:init"
generated_at: "2026-07-02T16:56:25Z"
git:
  branch: "docs-unit-cost-events"
  remote: "origin/docs-unit-cost-events"
  commit: "5f80c542038b3afe2f2908594333072cd8673bf5"
  commit_short: "5f80c54"
  commit_date: "2026-07-02 18:46:36 +0200"
  commit_message: "docs(events): document optional items[].unit_cost on shipped/refunded"
  dirty: true
synced_to_commit: "5f80c542038b3afe2f2908594333072cd8673bf5"
---

# Tech Stack: kaikei-envelope

> Auto-detected from composer.json, phpstan.neon, .php-cs-fixer.dist.php, and CI. Verify accuracy.

## Language & Runtime

| Item | Value |
|------|-------|
| Language | PHP `^8.1` (floor is authoritative — CI trusts the 8.1 job) |
| CI matrix | PHP 8.1, 8.2, 8.3, 8.4 (`.github/workflows/ci.yml`) |
| Required extensions | `ext-json`, `ext-hash`, `ext-bcmath` |
| Package type | `library` (proprietary), PSR-4 `Dreamabout\KaikeiEnvelope\` → `src/` |

## Runtime Dependencies

| Package | Version | Purpose |
|---------|---------|---------|
| `opis/json-schema` | `^2.3` | JSON Schema draft 2020-12 validation (Tier 2). Chosen over justinrainbow for 2020-12 support + structured error paths (decision D1). |

No other runtime dependencies; zero transitive runtime deps.

## Dev / Tooling

| Tool | Version | Role |
|------|---------|------|
| `phpunit/phpunit` | `^10.5` | Tests (`unit` + `schema-lint` suites) |
| `phpstan/phpstan` | `^1.10` | Static analysis **level 8**, `phpVersion` pinned to 80100 |
| `friendsofphp/php-cs-fixer` | `^3.50` | Code style (`.php-cs-fixer.dist.php`) |

## Commands

```bash
composer install
composer test           # phpunit --testsuite=unit
composer test:coverage  # phpunit --coverage-text
composer stan           # phpstan analyse --memory-limit=1G  (level 8)
composer cs:check       # php-cs-fixer --dry-run --diff
composer cs:fix         # php-cs-fixer fix
composer schema:lint    # phpunit --testsuite=schema-lint (schema compile + fixture pass/reject)
composer ci             # cs:check + stan + test
```

## Patterns & Conventions (accepted)

- **`declare(strict_types=1)`** in every source file.
- **`final` classes with promoted `readonly` properties** — DTOs are immutable value objects.
- **`fromArray()` / `toArray()`** as the canonical (de)serialization pair on every payload.
- **Exhaustive `match(EventType)`** for dispatch (Envelope + validator) — omitting an arm is a compile-visible error.
- **JSON Schemas are the structural source of truth**; PHP DTOs hand-mirrored to **v2**, drift caught by `SchemaDtoEquivalenceTest` (decision D3).
- **3-tier validation** (decision D4): hand-PHP envelope tier → opis data tier → hand-PHP bcmath invariant tier. Codes/paths kept at Kaikei parity.
- **Money as exact-2-decimal strings**, compared with `bccomp`/`bcadd` at scale 2.
- **Constant-time signature comparison** (`hash_equals`); signer byte-parity with Dreamshop locked by test (decision D5).
- Tests mirror `src/` tree under `tests/`; fixtures under `tests/fixtures/v{1,2}/{event}/`.

## Architecture Style

Stateless value-object + validation + signing library. No process/entry binary, no framework,
no database, no network transport. Hexagonal-ish: JSON Schema is the port for structure, PHP
owns only cross-field invariants the schema cannot express.
