# Handoff-S: Phase 9 — #132 SD-5 Showcase help

**Date:** 2026-07-23
**Branch:** 132-showcase-help
**Issue:** #132
**Verdict: PASS**

## Preconditions
- A precondition: PASS (Phase 3 A returned PASS with two soft findings folded into brief-amendments.md — both honored in F's diff).
- T precondition: zero blocking issues (handoff-T-green: Unit 21/21 real-executed GREEN; Functional/E2E env-blocked → closed by U live).
- Visual-diff-tool precondition: N/A — this story ships copy + ⓘ triggers on existing surfaces, no new layout or component. U ran full Playwright 30/30 + manual DOM/hover/keyboard against the live seeded site.

## Acceptance-criteria verification

| Criterion | Status | Evidence |
|---|---|---|
| Each device's help renders for its persona | PASS | U verified banner across Elena/Maria/Groups-Moderate; anonymous has no banner; switch-back removes it. Tour ⓘ triggers render for all 7 catalog entries + map ⓘ. |
| No copy duplicates an SC-F1 switcher tooltip | PASS (audit note below) | Grepped `showcase.switcher.*` — 1 hit (`.directory.layout`). Thematic overlap with `showcase_help.directory-presentation` (both cover list/cards), copy is not verbatim; roles distinct (per-switcher-instance vs. tour-page orientation). Documented inline in HelpText.php diff. |
| Append-only, no stale "not yet enforced" phrasing | PASS | `git show cce8d7f --stat` — 3 files, 110 insertions / 3 deletions (the 3 removals are the trailing `];` line reflow, not key edits). All existing keys intact; T's regression run (HelpTextTest 11/11 GREEN) confirms. |
| Existing suite green | PASS | U: 30/30 E2E (6 new + 24 regression). T: 21/21 Unit. Zero console errors. |
| Playwright covers banner + join-flow msg + map | PASS with scope trade-off (below) | `showcase-help.spec.ts` 6/6. Join-flow covered via `showcase_help.membership-models` copy surfaced on tour page rather than a `do_group_membership` tooltip (see audit note). |
| WCAG 2.2 AA | PASS | All 9 ⓘ: `tabindex="0"`, non-empty `aria-label`, visible focus outline, `.do-showcase-info` color `rgb(43,53,59)` reuses #120/#122 baseline. |
| Delivery per epic | PASS | Branch + local `npx playwright test` GREEN before PR. |

## Scope guardrails
- No new routes, no new fields, no `VariantSwitcher` edits, no `do_group_membership` edits — VERIFIED (F touched exactly 3 files: `HelpText.php`, `DoShowcaseHooks.php`, `ShowcaseController.php`).
- HelpText append-only — VERIFIED (append-only block below `persona.moderator`).
- Namespace `showcase_help.*` disjoint from `showcase.*` / `persona.*` / `visibility.*` / `group_type.*` / `page.*` — VERIFIED.

## PR-body audit note (drop into PR description)

> **Scope trade-off — join-flow copy delivery.** Issue #132 asked for a Playwright assertion on
> "one join-flow message." The scope guardrail forbids touching `do_group_membership` (owned by
> #138). This story therefore ships the join-flow teaching content as `showcase_help.membership-models`
> copy, surfaced on the `/showcase` tour page next to the "Membership models" catalog entry
> (covered by the Playwright spec), rather than as a `data-do-tooltip` bound to the actual
> `do_group_membership` request-to-join flow. The two-axis teaching (visibility × join policy) is
> delivered; the placement is tour-page orientation, not in-flow tooltip. A follow-up story owned
> by #138 (or a successor) can bind the same copy to the live join flow if desired.
>
> **SC-F1 duplication check.** New namespace `showcase_help.*` is disjoint from `showcase.switcher.*`.
> The only thematic overlap is `showcase.switcher.directory.layout` (per-switcher tooltip: "Compact list
> favors scanning…") vs. `showcase_help.directory-presentation` (tour-page orientation: "Compact list
> packs many groups per screen…"). Roles are distinct — the switcher tooltip fires on the switcher
> instance itself; the tour ⓘ fires next to the catalog-entry title. No verbatim duplication.

## Advisory notes
- U's DDEV project `gm132-showcase-help` remains running for follow-up inspection. O may
  `ddev stop gm132-showcase-help` / `ddev delete -O gm132-showcase-help` post-merge.
- No `handoff-F.md` file on disk; F's diff (cce8d7f) + T-green cross-check + U's live run give
  full coverage. Not blocking.
