# Implementation decisions

## D1 — JSON schema validator: `opis/json-schema`

**Track task:** T1.5

**Decision:** pick `opis/json-schema: ^2.3` over
`justinrainbow/json-schema: ^6.0`.

**Reasoning:**

| Axis | opis/json-schema 2.x | justinrainbow/json-schema 6.x |
|---|---|---|
| JSON Schema drafts | draft-06, draft-07, **draft 2019-09, draft 2020-12** | draft-04, draft-06, draft-07 |
| Error reporting | structured `ValidationError` with JSON pointer paths | mostly stringly-typed messages |
| PHP version | requires PHP 8.1+ (perfect match for the package's `^8.1` floor) | requires PHP 7.2+ (works but doesn't take advantage of modern PHP) |
| Maintenance | active (2026 releases) | active but less frequent |
| Bundle size | ~250 KB | ~150 KB |
| Match for `FieldError` DTO | direct -- the `ValidationError::keywordArgs()` + `dataPointer()` map cleanly to `FieldError(path, code, message)` | requires more glue code to extract structured paths |

The JSON-pointer-based path output from opis is exactly the
shape `FieldError::$path` carries (`"payload.order_id"`),
which makes the validator's translation layer trivial. We're
writing the v1 schemas to draft 2020-12 because that's the
current standard + opis supports it natively.

The slightly larger bundle size is acceptable given the
package itself is ~1-2 KLOC. Both libraries have zero
transitive runtime dependencies, so there's no install-tree
risk to either side.

**Implication for the track:** the validator code (Phase 4)
maps every `opis\JsonSchema\ValidationError` into a
`FieldError` with the JSON pointer as the `$path`. The
`FieldError::$code` is derived from the schema keyword that
failed (e.g. `"required"`, `"type"`, `"format"`,
`"pattern"`). Human-readable messages come from a per-code
translation table inside `PayloadValidator` -- not from
opis's default messages.

## D2 — Schemas target JSON Schema draft 2020-12

**Track task:** T3.1+

**Decision:** all five schema files declare
`"$schema": "https://json-schema.org/draft/2020-12/schema"`.

**Reasoning:** current standard; opis supports it natively;
no need to back-port to older drafts.

## D3 — DTOs are hand-mirrored, not code-generated

**Track task:** spec section "Technical Approach"

**Decision:** PHP DTOs are written by hand to mirror the JSON
schemas. The SchemaDtoEquivalenceTest catches drift at CI
time.

**Reasoning:** auto-generation introduces a build step + a
generator tool that isn't worth maintaining for four event
types. Hand-mirrored DTOs are also cleaner to read + can
carry PHP-side method semantics that pure-data generation
can't (e.g. helper accessors for joined fields). The
equivalence test makes hand-mirroring safe.
