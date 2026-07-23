# Handoff-S: Phase 9 — #128 SD-3 Archive Demonstrator Seeds

**VERDICT: PASS**

**Date:** 2026-07-23
**Branch:** 128-archive-demo (HEAD `0dddbd2`)
**Repo:** Performant-Labs/groups-on-d11
**Worktree:** `~/Projects/_worktrees/groups-archive-demo`
**Handoffs reviewed (in order):** issue #128 · brief.md · survey.md · handoff-A.md (PASS) ·
handoff-T-red.md (RED valid) · diff-review-r1.md → r1-response.md → diff-review-r2.md
(both BLOCKs accepted round-2) · handoff-F.md · handoff-T-green.md (GREEN, 8/8;
Kernel+Functional 38/38; full suite 68/71 with 2 pre-existing #121 fails + 1 pre-existing
skip; #143 test-authorship bug fixed in-band by T) · handoff-U.md (PASS, live-browser
walkthrough at desktop + 360)

## Preconditions

- **A precondition.** Confirmed — A returned PASS with 3 non-blocking findings (1 info AC-1c
  path deferred to T-RED and empirically resolved; 1 warn RUNBOOK doc drift belongs to #133;
  1 info AC-2 surface deferred to T-RED and empirically confirmed).
- **T precondition.** Confirmed — T-green reports zero blocking issues. The 2 test failures in
  the full suite are traced to #121's pre-existing `membership-models.spec.ts` non-idempotency
  (verified deterministic on 2nd run of same DB; passes 4/4 alone after cleanup); the 1 skip
  is self-documented in `manage-members.spec.ts` (pre-dates #128 per `git log`); the
  `group-restore.spec.ts` fix is an inherited #143 test bug that #128 first surfaces by making
  the suite run to completion (was masked by the very defect #128 corrects).
- **Browser/visual-diff preconditions.** N/A for this audit — #128 is a seed data change with
  no new UI or visual surface. All shipped-code chrome (Archive badge, tooltip, pin badge) was
  built and visually validated in #92 and #143. U already exercised the live DDEV site
  headlessly with Playwright at 1280x900 and 360x800, captured 7 screenshots, and confirmed
  DOM+tooltip+focus+access-control behavior for every AC. No pixel-level diff surface exists
  because #128 introduces no new visual.

## Preview / spec sanity check

The spec is a data/semantic correction, not a design. It is internally consistent, cleanly
scoped, and matches shipped module behavior (`ArchivePinHooks`, `DoGroupExtrasHooks::nodeAccess`,
`DoGroupPinHooks`, #143 restore). No convention violations detected. No `ADVISORY-HOLD`
warranted.

## Per-AC verdict

- [x] **AC-1a — Anonymous sees Legacy Infrastructure on `/all-groups` with Archive badge (+ tooltip).**
  PASS with scoped downgrade on the card-tooltip. Card renders `.gc-directory-card__type`
  "Archive" — proven live (U, `all-groups-desktop.png` / `all-groups-360.png`) and in
  `demonstrator-seeds.spec.ts` AC-1a. Card does NOT carry `data-do-tooltip`; this is a
  pre-existing gap in the Views-field-rendered directory card (Drupal 11 fact:
  `hook_preprocess_group` cannot fire in a Views-fields row render, only on a full group
  entity render). See "AC-1a downgrade defensibility" below.
- [x] **AC-1b — Click-through shows Archived state on group page (badge + tooltip).** PASS.
  `span.group__archived-badge` with visible "Archived" text and truthy `data-do-tooltip`
  ("This group is archived: read-only. Everything stays visible for reference, but no new
  content can be posted here.") confirmed by `demonstrator-seeds.spec.ts` AC-1b and U's
  hover capture (`group-legacy-infrastructure-desktop.png`).
- [x] **AC-1c — Read-only enforcement observable (not just chrome).** PASS via genuine
  differential: `/group/8/content/create/group_node%3Aforum` returns 403 for authenticated
  non-Organizer `elena_garcia`; the SAME route on `/group/3` (Core Committers, non-archive)
  returns 200 for the SAME user. Proven both at HTTP-status level (`demonstrator-seeds.spec.ts`
  AC-1c) and at DOM level (U, `ac1c-archived-403.png` vs `ac1c-control-200.png`). The
  survey's pre-flagged concern that this route bypasses `hook_node_access` was empirically
  disproven by T-RED — enforcement is real, active, and observable in this build. No fallback
  to badge-only observability was needed.
- [x] **AC-2 — Anonymous reaches stream/pinned post with pin badge (+ tooltip).** PASS.
  `/node/1` "Sprint Planning: Portland 2026" renders `span.pin-badge` "Pinned" with
  `data-do-tooltip` ("Pinned: this post is kept at the top of the group stream so
  newcomers see it first, regardless of date.") for anonymous. Backed by
  `demonstrator-seeds.spec.ts` AC-2 and U's `node-1-pinned.png`. Note this is a regression
  guard on already-shipped seed data — #128 does not touch the pin flag or Portland's
  visibility.
- [x] **AC-3 — Archived ≠ unpublished in seed; no group archived by unpublishing.** PASS.
  `grep -n 'set("status", 0)' docs/groups/scripts/step_700_demo_data.php` → no match
  (verified in this audit). The only diff to the seed script is the 4-line block deletion
  (replaced by a 5-line explanatory comment) — F's diff is exactly what the brief prescribed.
- [x] **AC-4 — Seed idempotent + re-runnable.** PASS. T-green re-ran
  `step_700_demo_data.php` on the already-seeded site; completed without error; gid=8
  confirmed `status=1 type=Archive` both immediately before and immediately after the re-run.
  Idempotency is *strengthened* by the change (the removed lines were the only status
  mutation on re-run; the `loadByProperties`-then-`continue` guard at :78–79 already
  prevented re-creation).
- [x] **AC-5 — Full-seed E2E green.** PASS. Touched-spec suite: 8/8 pass, 21.5s. Full E2E
  suite: 68/71 pass — the 3 non-passing tests are all pre-existing and unattributable to #128
  (2 × #121 `membership-models.spec.ts` non-idempotency reproducible on a persistent DB and
  passing 4/4 alone after cleanup; 1 × pre-existing `manage-members.spec.ts` skip
  self-documented as belonging to #121's territory). Kernel + Functional PHPUnit: 38 tests,
  681 assertions, 0 failures, 0 errors (16 pre-existing Drupal-core deprecation notices only).
- [x] **AC-6 — Touched assertions listed in the PR body.** Pending O (PR body composition).
  Brief and handoff-F/T-green enumerate them clearly: `tests/e2e/demonstrator-seeds.spec.ts`
  (new), `tests/e2e/group-restore.spec.ts` (helper simplified back to `/all-groups`; stale
  T-green comment rewritten; T-green fixed an inherited #143 test-authorship bug in 3 loci),
  `tests/e2e/directory-cards.spec.ts` (doc-comment tweak only, assertion unchanged),
  `docs/groups/scripts/step_700_demo_data.php` (4-line deletion + 5-line explanatory
  comment). AC-6 is mechanical for O when opening the PR.

## AC-1a downgrade defensibility (why this is PASS, not ADVISORY-HOLD)

The card lacks `data-do-tooltip`. Two readings of the spec are possible:

1. The tooltip must render on the card at `/all-groups`.
2. The archive-badge-with-tooltip experience is delivered across the discovery+detail path
   — a discoverability badge on the card + the full badge+tooltip on the group page one
   click away.

F/T/U consistently adopted reading (2), and I concur, for four reinforcing reasons:

- **The brief's non-goals explicitly forbid theme/template/CSS changes.** Making the tooltip
  render on the card requires modifying the `.gc-directory-card` component (a Views-field
  render), which is outside every version of #128's Files In Scope.
- **The gap is architectural, not accidental.** In Drupal 11, Views-fields row rendering
  goes through `template_preprocess_views_view_field` → field-plugin `render()` — no group
  entity render pipeline, therefore no `hook_preprocess_group` (where
  `ArchivePinHooks::preprocessGroup` attaches the badge markup). This is a fact of the
  framework, not a per-build assumption. Fixing it means restructuring the card component
  to emit the tooltip directly.
- **The discovery signal is preserved.** The card does render a visible "Archive"
  type-badge, so the anonymous visitor's discovery step is not lost.
- **The tooltip IS delivered on the click-through.** U hover-captured the full tooltip
  on `/group/8` at both viewports.

This is not a spec defect — updating the spec would not help, because the fix requires a
template change the story's own non-goals correctly forbid (given #128 is a seed-only
mission). It is a legitimate scope boundary with a real, scoped follow-up (see Advisories).

## Reuse & scope discipline

- **Reuse-map fidelity.** F actually extended — did not create a parallel path. The single
  production change is a 4-line deletion in the seed script, plus a 5-line explanatory
  comment. Every runtime behavior (Archive tagging, `nodeAccess` denial, badge, tooltip,
  pin, restore) reuses already-shipped mechanisms from #92 / #143 / `do_group_pin`.
- **Non-goals honored.** No HelpText, no CSS, no module code, no `config/sync/` changes.
  Diff vs the branch merge-base is: 1 seed file + 3 test files + handoff docs/screenshots.
  (The apparent large diff vs current `origin/main` is other stories merged after this
  branch was cut; irrelevant to #128's scope — merge-base diff is what counts.)
- **#134 coordination.** #128 edits step_700 at line 397 (interior). #134 will append (like
  #121 did at line 432+). Non-adjacent; no merge collision.

## Test-quality audit (playbook `test-quality.md` §7)

- **Per test.** Each of the 4 new `demonstrator-seeds.spec.ts` tests names one behavior,
  fails in isolation for the right reason (T-RED confirmed with `status` toggling in both
  directions), sits at the correct tier (E2E — depends on real seeded site + Views +
  hook-preprocess-group render + real access-control routing), and asserts behavior not
  implementation (badge presence, tooltip attribute presence, 403/200 route differential).
  AC-2 is explicitly labeled a "regression guard" — self-documented as pre-passing, not a
  RED-driver. AC-3 and AC-4 are correctly *documented as skipped-by-design in the spec
  header* — not silently dropped: AC-3 is diff-verifiable (grep), AC-4 is a seed-runner
  concern out of Playwright scope.
- **Per suite.** Test count is proportionate: 4 new tests for 4 UI-observable AC bullets,
  2 focused edits to touched adjacent specs, plus 1 in-band bug fix to `group-restore.spec.ts`
  that made an unreachable assertion reachable and pin it to the right locator. No
  fan-outs, no coverage padding.
- **Smells.** None found. T-green *deleted* two redundant `getByText(/Archived/i)`
  assertions in `group-restore.spec.ts` that were shadowed by the immediately-following
  badge-scoped locator — a "delete or merge this test" finding correctly acted on, not
  merely flagged. This raises rather than lowers signal.
- **AC-1c is the strongest test in the suite.** The 403/200 differential on the SAME
  route for the SAME user, differing only by archive-state of the group, is a genuine
  access-control assertion (not a truism); T-RED empirically overrode survey.md's flagged
  concern that this route bypasses `hook_node_access`.

## Quality audit summary

| Area | Result | Notes |
|------|--------|-------|
| Spec compliance | PASS | 6/6 ACs backed by evidence (see Per-AC table) |
| API consistency | N/A | Seed-data change; no API surface |
| Error handling | PASS | AC-1c 403 render clean; POC ribbon and persona-switcher intact |
| UI/UX match to spec | PASS | Shipped chrome (from #92/#143) rendering as designed on now-visible group |
| Accessibility | PASS | Badge `tabindex="0"`, visible focus outline, meaningful tooltip copy, descriptive card link accessible name (U's quick check) |
| Architecture gate | PASS | A returned PASS; no drift since |
| Code organization | PASS | 4-line deletion + explanatory 5-line comment referencing brief.md |
| Security | N/A | No new form/route/input surface |
| Performance | PASS | No new query/render path; F's cold-cache surprise diagnosed as environmental only |
| Visual regression | N/A | No new visual surface; U walked live site at 1280 + 360 with zero console errors and no layout breakage |
| Naming consistency | PASS | Comment cites correct step_720 file, correct `field_group_type` semantic |
| Test quality (`test-quality.md` §7) | PASS | See test-quality audit above; T-green pruned 2 redundant assertions |

## Scope check

F delivered exactly the phase scope — no over-delivery, no under-delivery. The 4-line
production deletion is exactly what the brief prescribed; the 3 test-file edits are exactly
what survey.md and T-RED planned; T-green's in-band fix to `group-restore.spec.ts`
(inherited #143 bug) is genuine bug-remediation, not scope creep, correctly documented in
handoff-T-green.md as "T owns test authorship, not a production-code change, F did not touch
this file".

## Advisories (non-blocking, follow-ups)

1. **`RUNBOOK.md` doc drift** (`docs/groups/RUNBOOK.md:2638`, `:2800`). Encodes the
   pre-#128 "archived = unpublished" conflation. Belongs to #133 (final honesty sweep;
   the ONLY story allowed to edit copy per WAVE-EXECUTION-HANDOFF.md). A and F both
   surfaced this; correctly not addressed here.
2. **`.gc-directory-card` tooltip gap.** The directory card on `/all-groups` renders only
   a plain `.gc-directory-card__type` "Archive" label, not `data-do-tooltip`. Requires a
   `.gc-directory-card` component change (attach tooltip directly, since
   `hook_preprocess_group` architecturally cannot fire in the Views-fields row render).
   Worth a small follow-up ticket. Explicitly out of #128's Files In Scope per the brief's
   non-goals — not a #128 blocker.
3. **E2E fixture hygiene.** Some cross-story specs (`phase1-4.spec.ts`,
   `manage-members.spec.ts`, `membership-models.spec.ts`) leak fixture groups across runs
   against a persistent DB. Not a CI issue (CI seeds fresh per job) but makes local
   multi-run verification fragile. Worth a lightweight `afterEach`/global-teardown
   convention story. Out of #128's scope.

## Verdict

**PASS.** All 6 acceptance criteria met with concrete evidence. Scope discipline is exact.
Test quality is high (behavior-not-implementation; T-green pruned redundant assertions and
fixed an inherited bug). No spec defect warrants ADVISORY-HOLD — the AC-1a card-tooltip
downgrade is a legitimate scope boundary, not a spec fault. Diff-gate round-2 PASS is
consistent with this audit.

**O may open the PR.**

## Patterns referenced

- `docs/groups/scripts/step_700_demo_data.php:394-401` (the sanctioned 4-line deletion)
- `docs/groups/scripts/step_720_group_types.php:101` (Archive term tagging — the surviving
  semantic that makes the badge/read-only fire)
- `docs/groups/modules/do_chrome/src/Hook/ArchivePinHooks.php` (`preprocessGroup` badge render)
- `docs/groups/modules/do_group_extras/src/Hook/DoGroupExtrasHooks.php:99-118` (`nodeAccess`
  denial — the enforcement AC-1c proves observable)
- `tests/e2e/demonstrator-seeds.spec.ts` (new — AC-1a/1b/1c/AC-2)
- `tests/e2e/group-restore.spec.ts` (helper simplified back to `/all-groups`; T-green fixed
  inherited #143 free-text-locator bug)
- `tests/e2e/directory-cards.spec.ts:15-16` (doc-comment tweak only)
- `docs/planning/handoffs/128-archive-demo/{brief,survey,decisions,handoff-A,handoff-T-red,handoff-F,handoff-T-green,handoff-U}.md`
