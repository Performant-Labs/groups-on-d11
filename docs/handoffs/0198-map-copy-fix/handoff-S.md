# Handoff-S: Phase 9 — #198 Docs parity: map-copy fix

**Date:** 2026-07-24
**Branch:** 198-map-copy-fix (base 5a8d188)
**Issue:** #198
**Handoff-T-green reviewed:** `docs/handoffs/0198-map-copy-fix/handoff-T-green.md`
**Handoff-A reviewed:** `docs/handoffs/0198-map-copy-fix/handoff-A-plan.md`
**Handoff-F reviewed:** `docs/handoffs/0198-map-copy-fix/handoff-F.md`
**Operator-facing report:** N/A (no visual surface change; two-string copy fix)

## A precondition
Confirmed: A returned PASS on the plan (Phase 3), zero blocks/warns.

## T precondition
Confirmed: T-green reported zero blocking issues (86/86 wider sweep, 13/13 target, phpcs
clean, grep-guard clean in shipped code).

## Preview / spec sanity check
No defect in the source of truth. Issue #198 proposed three copy paths (add "coming
soon" / remove Map from copy / mark Map available). The delta takes a defensible fourth:
#125 SC-6 shipped Map live between issue-filing and now, so the copy was stale for the
opposite reason the issue assumed. Reconciling by naming Map matches the shipped reality —
the correct move.

## U-skip defensibility
Agree. U is defensible-N/A here: two-word decision_sentence rewrite + code-comment trim,
no interactive-behavior change, no new route/render path, no accessibility surface. The
`testDirectoryPresentationEntryNamesMapVariant` unit test pins the exact substrings
("Map" AND "geograph") that a U walkthrough would eyeball, and the grep-guard in T-green
proves the stale phrase is gone from shipped code. A U cycle would produce zero new
signal. POC lean pipeline correctly applied.

## Spec compliance
| Acceptance criterion | Compliant | Evidence |
|---|---|---|
| `ShowcaseCatalog.php:52` decision_sentence names Map | YES | source line 52 verified: "Compares list, cards, and a Map that plots groups geographically…" |
| `ShowcaseController.php:223` comment no longer says "Map unavailable" | YES | source line 223 verified: "…Compact list / Cards / Map). Options are shared…" — clause deleted cleanly, sentence remains grammatical |
| ShowcaseCatalogTest gains ONE assertion, FAILS→PASSES | YES | T-red RED evidence + T-green GREEN evidence; keyword pinning "Map"+"geograph" matches HelpText.php:169 voice |
| phpcs clean on touched files | YES | `--standard=Drupal` exit 0 (F+T-green both ran) |
| No other test regresses | YES | do_showcase wider sweep 86/86 |
| U walkthrough | N/A defensible | see U-skip section above |

## Drift audit (Q3)
Ran independent grep across `docs/groups/` for stale-copy fragments (`list vs.`,
`Map unavailable`, `list-vs-cards`, `list versus card`, `two variants`,
`unavailable.*[Mm]ap`). Findings:

- `ShowcaseCatalogTest.php:280,298` — test docblock quoting the RED-time string as
  history. Intentional documentation, not stale copy. Keep.
- `VariantSwitcherTest.php:175,204` — fixture semantics: the switcher's fallback-behavior
  tests use "map" as the unavailable-option ID inside a manually-stubbed option array.
  This is a testing pattern (exercise the fallback path), not stale copy about the shipped
  Map view. Keep.
- `DirectoryTogglePreRenderTest.php:36,265` — the second (line 265) already ASSERTS that
  "Map (soon)" is absent post-#125; the line-36 phrase-clause docblock describes the
  fixture. Both correct. Keep.
- `HelpText.php:169`, `HelpText.php:332` — already carry the live-Map voice (confirmed at
  brief Background + A-plan finding #3).

No other production-copy drift. Q3: clean.

## Reviewer-surprise check (Q4)
- New `decision_sentence` reads naturally and mirrors the "Compares X — the decision: Y"
  shape of the neighboring `discovery-ranking` entry (line 45). Extended trailing clause
  to a three-way tradeoff since a third variant is compared — reviewer-defensible.
- ShowcaseController comment reads cleanly with the clause removed: "Compact list /
  Cards / Map). Options are shared with DoShowcaseHooks::viewsPreRender()…" — no orphan
  punctuation, no broken sentence.
- No tone shift; both edits use the existing catalog voice.

## Quality audit
| Area | Result | Notes |
|------|--------|-------|
| API consistency | N/A | no API change |
| Error handling | N/A | no logic change |
| UI/UX match to spec | PASS | copy names shipped variants |
| Accessibility | N/A | no UI surface change |
| Architecture gate | PASS | A returned PASS, no drift added |
| Code organization | PASS | in-place string edit, no restructuring |
| Security | N/A | no input surface |
| Performance | N/A | no logic change |
| Visual regression | N/A | no rendered surface change |
| Naming consistency | PASS | "Map"+"geograph" root aligned with HelpText.php:169 |
| Test quality | PASS | one behavior-named unit test, cheapest sufficient tier, no duplication of adjacent `testStreamModelEntryIsLive…`, proportionate to a two-string fix (no over-production) |

## Scope check
Exact phase scope delivered: two files touched, one test added. No scope creep (no
VariantSwitcher/HelpText edits, no new module/hook/twig).

## Verdict

**PASS** — all acceptance criteria met, spec-compliant, U-skip defensible, no drift
elsewhere, no reviewer surprises. Ready for O to open PR.

## Advisory notes
- T-green's local-Kernel-runner note (`SIMPLETEST_DB=mysql://db:db@db:3306/db` required)
  is worth folding into RUNBOOK.md or TEST_PLAN.md at some point — recurring paper-cut,
  not a blocker for this PR.
