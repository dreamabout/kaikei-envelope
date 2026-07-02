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

# Workflow: kaikei-envelope

> Defaults inferred from repo conventions (Conventional Commits in git log, CI gates,
> fixture-driven tests). Adjust to match your actual team process.

## Testing

- **TDD preference:** flexible-leaning-strict. This is a contract library where correctness
  is the product — new event types / enum members / invariants should land with fixtures
  (`valid.json` + `invalid_*.json` for v1 and v2) and validator tests alongside the code.
- **Test gates before merge:** `composer ci` must pass (cs:check + phpstan L8 + phpunit),
  plus `composer schema:lint` when schemas change.
- Every schema change must keep `SchemaDtoEquivalenceTest` (DTO ↔ v2 drift) green.

## Commits

- **Convention:** Conventional Commits (`feat:`, `docs:`, `feat(order-fee):`, etc.) — matches git history.
- Keep commits scoped; reference the event type / contract area in the scope where useful.
- Co-authored PRs land via GitHub PR (see recent `Merge pull request` history).

## Branching

- Feature branches off `main`; PRs target `main` (CI runs on push to `main` + PRs).
- Draft tracks create a branch per track (`/draft:new-track`).

## Review

- PR review before merge to `main`.
- For contract changes, confirm the semver impact (optional field = MINOR; required change /
  event rename = MAJOR) and bump `Version::PACKAGE_VERSION` + CHANGELOG (CI asserts they match).

## Validation settings

- Auto-run `composer ci` locally before pushing.
- Blocking: PHPStan level 8 and cs-fixer are hard gates in CI.
