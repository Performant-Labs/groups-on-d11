# Handoff-A: Phase 3 RE-REVIEW — #120 SC-1 Persona Switcher (post-amendments)

**Date:** 2026-07-22
**Branch:** 120-persona-switcher
**Diff reviewed:** `brief-amendments.md` + `decisions.md` (O Phase-3 amendments entry) vs. prior `handoff-A-plan.md` findings.
**Verdict:** PASS

## Summary
All 3 blockers and all 6 warnings from `handoff-A-plan.md` are resolved by the 8 amendments recorded in `brief-amendments.md`. The plan is now consistent with existing `do_showcase` / `group` / `do_chrome` patterns. T may author RED tests.

## Findings — resolution table

| # | Prior severity | Prior finding | Amendment | Status | Notes |
|---|---|---|---|---|---|
| 1 | block | Groups-Moderate enforcement site missed the GROUP role | A1 | RESOLVED | `group.role.community_group-groups_moderate.yml` added to files-touched; `admin: true` → `admin: false`; enumerates `administer members` + `edit group` + archive perm. Sanity: `RestoreGroupAccess.php:50` checks `$group->hasPermission('edit group', $account) OR $account->hasPermission('administer group')` — so **`edit group` alone satisfies restore**; no separate archive-only group perm exists. F's "inspect archive perm" task will confirm and can drop the third bullet if redundant. Non-blocking. |
| 2 | block | New `PersonaRegistry` duplicates `ShowcaseCatalog::personas()` | A2 | RESOLVED | `PersonaRegistry` deleted; `ShowcaseCatalog::personas()` extended with `uname` + `tooltip_key`; helper `personaSpec(id)` added. Sanity: existing entries have `{id, name, description}` — `uname`/`tooltip_key` do NOT collide. `PersonaSwitcher` consumes via DI. |
| 3 | block | masquerade enabled but mechanism bypassed | A3 | RESOLVED | `drupal/masquerade` removed from composer + `core.extension.yml`; no `masquerade.settings.yml`. Bespoke `PersonaSwitchController` + route-level `PersonaAccessCheck`. AC's "D11 masquerade compat" replaced with rationale recording. Coherent with wireframe (dropdown-driven full logout+login). uid-1 guard + allowlist enforcement moved into `PersonaAccessCheck` (see A4). |
| 4 | warn (spec upgrade) | Access check must be a route-level seam, not an in-controller `if` | A4 | RESOLVED | `PersonaAccessCheck` service tagged `access_check` with `applies_to: [_persona_access]`; wired via `requirements: { _persona_access: TRUE }` on `do_showcase.persona_switch`. HTTP-method discipline: `methods: [GET, POST]`, controller branches `anonymous`(GET) vs others (POST-only, 405 on GET). All three security concerns (uid-1 always denied, allowlist enforced, method discipline) are on the plan. |
| 5 | warn | `pageTop()` should not swallow the banner | A5 | RESOLVED | Sibling `#[Hook('page_top')] personaBanner()` method; ribbon `pageTop()` untouched. Disjoint keys (`do_showcase_ribbon` + `do_showcase_persona_banner`). |
| 7 | warn | Cache contexts on widget + banner | A6 | RESOLVED | Both render arrays declare `#cache[contexts] => [user]`; asserted by T. |
| 8 | warn | Seed should use `STATUS_PENDING` const | A8 | RESOLVED | Seed imports `GroupMembershipManager` and writes via the const. |
| 10 | warn | Route naming + HTTP-method discipline | A4 + naming block | RESOLVED | Route id `do_showcase.persona_switch` at `/persona-switch/{persona}`; POST for state-change, GET only for `persona=anonymous`. |
| 11 | warn | HelpText per-option strings ≤ 140 chars | A7 | RESOLVED | Four drafts included; all ≤ 135 chars. Copy fits `title=` attribute cleanly. |
| 6 | warn (contingent) | `masquerade.settings.yml` correctness | A3 | RESOLVED | Dep dropped; file not created. Moot. |
| 9 | warn (informational) | Ribbon/banner slot disjointness | (implicit in A5) | RESOLVED | Keys are disjoint; T asserts both-present-when-persona-active, ribbon-only-when-anonymous. |

## Notes for O / T
- Minor non-blocking observation for F, not a re-block: the "archive perm from `do_group_extras`" bullet in Amendment 1 will collapse to just `edit group` on inspection — `RestoreGroupAccess::access` uses only `edit group` (group) or `administer group` (user). No separate archive-only permission ships. F may safely drop that third bullet with a one-line decision journal entry; if F prefers to keep it explicit, adding a redundant perm is harmless. This does not block T.

## Patterns referenced (re-review)
- `docs/planning/handoffs/120-persona-switcher/brief-amendments.md` (all 8 amendments)
- `docs/groups/config/group.role.community_group-groups_moderate.yml` (current admin:true, permissions:{})
- `docs/groups/modules/do_showcase/src/ShowcaseCatalog.php` lines 97-120 (extension target)
- `docs/groups/modules/do_group_extras/src/Controller/RestoreGroupAccess.php:50` (archive-perm sanity)

VERDICT: PASS — T may author RED tests.
