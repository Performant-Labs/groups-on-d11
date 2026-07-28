# Handoff-A: Phase 3 - #136 W0: Dependency pre-story (up-front plan review)

**Date:** 2026-07-22
**Branch:** 136-w0-composer-deps
**Brief reviewed:** docs/handoffs/136-w0-composer-deps/brief.md   **Reuse map:** docs/handoffs/136-w0-composer-deps/survey.md   **Wireframe:** N/A (no UI surface)
**Verdict:** PASS

## Summary

The plan is a mechanical, additive-only extension of the existing `require` block, following
the same pattern established by the prior `drupal/flag`/`drupal/group` additions (alphabetical,
`sort-packages: true` respected, no new `repositories`/`config` structure). All 5 proposed
constraints are internally consistent with the current manifest (D11 core pin, `drupal/group:
4.0.x-dev`, `minimum-stability: dev` / `prefer-stable: true`), and none of the candidates carry
a `drupal/group` dependency that would conflict. The `drupal/grequest` exclusion is
evidence-based (checked the package's own `composer.json` and Group 4.0.x core's actual module
tree, not assumed) and correctly scoped as an issue-#121 comment rather than a broken package
add. Scope is correctly bounded to `composer.json`/`composer.lock` only, and the declared
pipeline shape (skipping D/U, substituting F's real `composer require` + enable-state assert +
regression suite for a traditional T red/green cycle) is proportionate to a manifest-only
change with no new PHP.

## Findings

Plan is consistent with existing patterns. No `block` or `warn` findings.

Two non-blocking observations for O to note (not drift, not gating this PASS):

| # | Severity | Plan element | Drift dimension | Finding | Suggested fix |
|---|---|---|---|---|---|
| 1 | note | resolver risk | verification | Survey correctly flags that release-history `core_compatibility` metadata is not proof of a clean simultaneous resolve against `drupal/group: 4.0.x-dev` + `minimum-stability: dev`; this is deferred to F's real `composer require` run | none needed — already correctly gated as an F-phase empirical check, not asserted as fact in the plan |
| 2 | note | Phase 7 (A anti-dup gate) | process | Manifest-only diffs have no analogous object to duplicate, so Phase 7 A is effectively a no-op for this story | O should explicitly confirm/skip Phase 7 rather than let it silently drop, for pipeline-journal completeness |

## Notes for O

None — PASS. No amendments required before F proceeds.

## Patterns referenced

- `composer.json` (current `require` block, base commit `c18f417`) — precedent for
  alphabetical-sort, plain-caret constraints (`drupal/flag: ^4.0`) alongside the one
  dev-branch-pinned outlier (`drupal/group: 4.0.x-dev`).
- `docs/handoffs/136-w0-composer-deps/survey.md` — per-package compatibility research and
  Reuse & Analogous-Feature map.
- `docs/handoffs/136-w0-composer-deps/brief.md` — acceptance criteria and declared pipeline
  shape.
- `docs/groups/RUNBOOK.md` (cited in survey, lines 81-82/3200-3201) — pre-existing sanction of
  `drupal/message`/`drupal/message_notify` as Phase 5 optional deps.
