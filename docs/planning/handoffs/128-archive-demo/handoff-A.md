# Handoff-A: Phase 3 — #128 SD-3 Archive Demonstrator Seeds (up-front plan review)

VERDICT: PASS

**Date:** 2026-07-23
**Branch:** 128-archive-demo
**Brief reviewed:** docs/planning/handoffs/128-archive-demo/brief.md
**Reuse map:** docs/planning/handoffs/128-archive-demo/survey.md §"Reuse & Analogous-Feature Map"
**Wireframe:** N/A (D skipped; no new visual)

## Summary

The plan is a 4-line semantic correction to `step_700_demo_data.php` (397–400) plus
three test-file edits. Every runtime mechanism it depends on (Archive-term tagging in
step_720, `DoGroupExtrasHooks::nodeAccess`, `preprocessGroup` archive class,
`ArchivePinHooks` badge, `DoGroupPinHooks` pin, #143 restore) already ships and is
reused as-is. No new modules, no config/sync, no HelpText edits. Reuse-map discipline
is clean; there is no parallel-path smell. Idempotency, forward-compat with #134, and
the enforcement-path assumption in AC-1c are all correctly identified and defensively
scoped for T-RED to finalize empirically. PASS — T may proceed.

## Findings

| # | Severity | Plan element | Drift dimension | Finding | Suggested fix |
|---|---|---|---|---|---|
| 1 | info | AC-1c enforcement path | pattern consistency | Survey §"Risk/Follow-ups" already flags that `DoGroupExtrasHooks::nodeAccess()` protects the `node/add` route but not necessarily `group/{group}/content/create/{plugin}` (site-wide access runs through `_group_relationship_create_any_entity_access`, which does not fire `hook_node_access`). Decisions.md already codifies the fallback: if no anonymous- or non-Organizer-reachable route is denied, re-scope AC-1c to badge-visibility observability, matching #143's PR-time posture. | No action for A. T-RED must probe both routes and record which one is observably enforced; if neither, invoke the pre-authorized fallback. |
| 2 | warn | Doc consumers of old semantic | naming / cross-cutting | `docs/groups/RUNBOOK.md:2638` ("Archive: Legacy Infrastructure group set to unpublished") and `:2800` ("Legacy Infrastructure archived (status=0)") encode the very conflation #128 corrects. Brief explicitly excludes copy edits. This is downstream documentation drift that belongs to #133 (final honesty sweep). | Not blocking. Note in `handoff-F` PR body / decisions.md so #133 (or a spin-off follow-up) picks up the RUNBOOK reconciliation. Do not edit RUNBOOK inside #128 — that would violate the brief's non-goals. |
| 3 | info | AC-2 pin visibility premise | contract shape | Sprint Planning is pinned in DrupalCon Portland 2026 (default `open` visibility) — reachable anonymously in principle, but the anonymous *surface* on which the pin badge actually renders (stream page vs. node page) is not verified in the plan. Decisions.md correctly marks this as a T-RED empirical check. | No action for A. If T-RED discovers no anonymous surface renders the pin, seed additions (or a stream-link surface) belong in this story — that's still "extend the existing pin mechanism," not a new object. |

## Evaluation against the six requested dimensions

1. **Reuse-map soundness:** Sound. Every requirement maps to an already-shipped mechanism (`step_720` Archive tagging, `DoGroupExtrasHooks::{preprocessGroup, nodeAccess}`, `ArchivePinHooks`, `DoGroupPinHooks`, #143 restore). The only mutations are (a) deleting a redundant `set('status', 0)` and (b) test-assertion corrections. No new modules, no new services, no new routes, no new config. Zero hidden-new-object smell.
2. **Test-plan sufficiency vs. 6 ACs:** Coverage is complete.
   - AC-1a (card + badge on `/all-groups`) → new `demonstrator-seeds.spec.ts` anonymous spec.
   - AC-1b (group page shows archived state) → same new spec, click-through.
   - AC-1c (read-only enforcement observable) → same new spec, plus `group-restore.spec.ts` positive assertion; fallback path pre-approved in decisions.md.
   - AC-2 (anon pin badge + tooltip) → new spec.
   - AC-3 (`grep set("status", 0)` on step_700 returns nothing on any group) → trivially provable by the diff itself; can be a static-check assertion in the new spec or PR body.
   - AC-4 (idempotency) → guaranteed by the existing `loadByProperties` + `if ($existing) continue` guard at step_700:78–79 (unaffected by the deletion).
   - AC-5 (full-seed E2E green) → the two existing spec edits + the new spec cover the touched surfaces.
   - AC-6 (PR-body enumeration) → mechanical, F/O concern.
3. **Idempotency after removing `set('status', 0)`:** Preserved. Group creation at step_700:78–92 short-circuits on `loadByProperties` match, so re-runs never re-create Legacy Infrastructure and never toggle its status. Removing lines 397–400 removes the *only* mutation on re-run; the seed becomes strictly more idempotent, not less.
4. **AC-1c enforcement-path reachability:** Correctly flagged in survey + decisions as a T-RED empirical decision with a pre-authorized fallback (badge-visibility as sole observable). Nothing more for A to do at Phase 3 — the plan does not assume; it defers.
5. **Non-adjacency to #134:** Confirmed. #128 edits step_700:397–400 (interior, between flag-follow-user and RSVP blocks). #134's Security Team seed will append (analogous to #121 which appended at line 432+/Step 790). No merge collision.
6. **Forward-compat / silent consumers of `status=0` on Legacy Infrastructure:** Only two silent consumers exist and both are documentation, not runtime: `RUNBOOK.md:2638` and `:2800`. No runtime code (no view, no service, no test, no config) queries Legacy Infrastructure by `status`. `GroupExtrasBehaviorTest` at lines 34 and 222 tests the `entityPresave` non-admin-creation path (unrelated code path, unaffected). No config/sync consumer. Zero runtime forward-compat risk.

## Notes for O

None required (PASS). One informational: consider filing a small follow-up ticket (or annotating #133) to reconcile `docs/groups/RUNBOOK.md:2635-2800` with the corrected Archive ≠ Unpublished semantic. Explicitly out of scope for #128 per the brief's non-goals.

## Patterns referenced

- `docs/groups/scripts/step_700_demo_data.php:78–92` (idempotency guard), `:397–400` (sanctioned deletion)
- `docs/groups/scripts/step_720_group_types.php:101` (Archive tagging — the semantic that survives)
- `docs/groups/modules/do_group_extras/src/Hook/DoGroupExtrasHooks.php:64` (non-admin presave; bypassed by uid=1), `:80–94` (preprocessGroup), `:99–118` (nodeAccess)
- `docs/groups/config/views.view.all_groups.yml:83–91` (status=1 filter — reason the group appears once unpublish is removed)
- `tests/e2e/group-restore.spec.ts:44–69` (helper simplification target), `:86–92` (comment revisit)
- `docs/planning/handoffs/128-archive-demo/{brief,survey,decisions}.md`
