# Handoff-S: Phase 9 — #143 MC-5 Group archiving RESTORE action (final gate)

**Date:** 2026-07-22
**Branch:** 143-archive-restore (worktree `_worktrees/groups-archive-restore`)
**Commit reviewed:** 5d6ec46
**Issue:** #143
**Verdict:** **PASS**

## Preconditions

- **A precondition:** PASS. `handoff-A-plan-r2.md` verdict PASS (post perm-swap). `handoff-A-dup.md` verdict PASS.
- **T precondition:** PASS. `handoff-T-green.md` reports Kernel 4/4, Functional 10/10, AC-7 suite 8/8, E2E 1/1, zero blockers.
- **U precondition:** PASS. Live round-trip walked in real headless browser; one non-blocking observation carried forward.
- **Diff-gate precondition:** PASS. `diff-review-r1.md` — 0 BLOCKs, 3 WARNs + 6 NITs adjudicated (see `decisions.md`).

## AC coverage audit

Every AC has a live covering assertion; nothing is "PASS by claim." Cross-referenced test file → assertion → runtime evidence:

| AC | Backing test(s) | Runtime evidence | S judgment |
|----|-----------------|------------------|------------|
| AC-1 Organizer restore | `GroupRestoreAccessTest::testOrganizerCanRestore` (Functional, 200 + submit + redirect + field reassignment) | GREEN 10/10 | PASS |
| AC-2 Groups-Moderate restore | `testGroupsModerateCanAccessRestore` | GREEN | PASS |
| AC-3 Anonymous / non-privileged / non-archived → 403 | `testAnonymousGetsAccessDenied`, `testUnprivilegedAuthenticatedUserGetsAccessDenied`, `testOrganizerGetsAccessDeniedOnNonArchivedGroup`, `testSiteAdminGetsAccessDeniedOnNonArchivedGroup`; also live-render check in U (anon → clean 403) | GREEN + U live 403 | PASS |
| AC-4 Confirmation flow + real `<button type=submit>` | `testConfirmFormRendersRealSubmitButton` (asserts `button[type=submit]` in real DOM); U DOM inspection confirmed `inputSubmitCount: 0` on the live form | GREEN + U live | PASS |
| AC-5 Round-trip clean | Kernel `testSubmitRestoresArchivedGroup` + E2E round-trip; U pre/post `drush php:eval` showed gid=8 returned to seed baseline | GREEN + U drush check | PASS |
| AC-6 WCAG 2.2 AA (keyboard, focus, real button, labels, `aria-describedby`) | `testConfirmButtonAriaDescribedbyPointsToExistingId` (asserts `aria-describedby` resolves to real id); U confirmed keyboard reachability of Restore tab (visible blue focus ring), select→button→cancel tab order, `<label for>` on select | GREEN + U live keyboard walk | PASS |
| AC-7 No regression to existing suite | `GroupExtrasBehaviorTest` 8/8 | GREEN | PASS |
| AC-8 E2E round-trip | `tests/e2e/group-restore.spec.ts` (1/1 GREEN, 8.6s) — swap per operator ruling (c) applied | GREEN | PASS (see swap audit below) |
| AC-9 Kernel field reassignment / class disappears / node_access neutral | `GroupRestoreTest::testSubmitRestoresArchivedGroup` | GREEN | PASS |
| AC-10 Functional persona matrix + redirect + message | `GroupRestoreAccessTest` 10/10 | GREEN | PASS |
| AC-11 Coordinate, don't edit seed scripts | `git diff --stat origin/main..HEAD` — zero edits to `docs/groups/scripts/step_7*` | GREEN | PASS |

**Suite proportionality (test-quality rubric §7):** 4 Kernel + 10 Functional + 1 E2E for a single-feature story with 11 ACs — proportionate, no duplication across tiers. Each Kernel test names one behavior; the Functional persona matrix is one test per persona × outcome (not fan-out padding, each is a distinct access-matrix cell). No assertion-free, tautological, or coverage-padding smells identified.

## Deviation audit

### 1. AC-8 precondition swap (operator ruling c)

**Adjudicated:** operator ruling (c) applied by T round 2; recorded in `decisions.md` (T Phase 6 r2). Swapped node-create-403 assertion → badge + Restore-tab observability at each round-trip state.

**Round-trip semantics honest?** YES.
- Step 1 (pre-restore, Archive-typed): `span.group__archived-badge` visible + "Restore group" tab visible.
- Step 3 (post-restore): both `toHaveCount(0)`.
- Step 5 (post-re-archive): both visible again.

The badge is rendered by `ArchivePinHooks::preprocessGroup()` conditional on `field_group_type` term name === "Archive" (verified against `docs/groups/modules/do_chrome/src/Hook/ArchivePinHooks.php:54-72`). The Restore tab visibility is gated by `RestoreGroupAccess::access` on the same `isArchived` check. Both observables are wired to the exact state axis the round-trip mutates. The swap is not a workaround — it asserts against an enforced code path (chrome + access) rather than an unenforced one (`hook_node_access` on the "Add new content" chooser), which is a strict quality improvement.

### 2. F's `#pre_render` / `TrustedCallbackInterface` override for real `<button>`

**Adjudicated:** ACCEPT per O F-adjudication; WCAG-debt flagged on #138. Recorded in `decisions.md`.

**Fix scoped correctly?** YES.
- Kept private to `RestoreGroupForm.php` (A-dup confirmed no premature extraction).
- Preserves core's normal attribute/class computation via `[Button::class, 'preRenderButton']` first, then `preRenderAsButtonTag` swaps the tag.
- `Markup::create()` wrap is documented as load-bearing (prevents `Xss::filter()` from stripping the `<button>` tag).
- Implements the well-established `TrustedCallbackInterface` core contract (one static method), not a new abstraction.

**AC-4/AC-6 real satisfaction?** YES.
- Functional test asserts `button[type=submit]` exists (would fail without the override).
- U live DOM inspection confirmed `<button type="submit">` renders + `inputSubmitCount: 0` on `/group/8/restore`.
- U confirmed `aria-describedby="do-group-extras-restore-desc-8"` matches the `<p id>` exactly (programmatic wiring, not just visual).

### 3. `group_type` form-state key (vs. paraphrase `target_type_tid`)

Adjudicated. F correctly implemented against T's authored tests (contract); paraphrase drift, not a defect. `brief.md` and `wireframe.md` never named an internal key. No S concern.

### 4. Diff-gate WARNs (W-1 stranded-form / W-2 `label()` fragility / W-3 static logger)

**POC-posture rationale defensible?** YES.
- W-1 (no redirect on load-failure path): the fallback exists only for the two-instance test harness; in real HTTP `$this->group` is always populated by the upcast. Genuinely defensive-only branch.
- W-2 (`$term->label() === 'Archive'`): codebase-wide convention (`DoGroupExtrasHooks::preprocessGroup`, `nodeAccess`, `ArchivePinHooks::isArchived` all use the same idiom). Changing only #143 would create inconsistency; refactoring across three modules is out of scope.
- W-3 (static logger): matches `RemoveMemberForm`'s implicit pattern; call site is inside the defensive try/catch that is unreachable in normal operation.

All three are legitimate WARNs against future-DI-purity, correctly accepted-as-is under the POC posture with no operator-decision loose ends.

## Chrome-consistency judgment (U's non-blocking observation)

**Observation:** Live "Archived" badge renders as a gray "ARCHIVED" pill near the sub-tabs, not inline with `<h1>` as the wireframe's Surface 1 ASCII schematic showed.

**S judgment: NOT a #143 defect.**
- The badge is rendered by `do_chrome/ArchivePinHooks::preprocessGroup()` via `title_suffix` — pre-existing chrome (initial-baseline module, unrelated to #143).
- `git diff --stat origin/main..HEAD` confirms F touched zero files under `do_chrome/`.
- Wireframe was explicitly labeled "generated low-fi" schematic; ASCII inline placement is a schematic convention (adjacent-in-title), not a literal DOM positioning spec.
- The badge element itself (selector `span.group__archived-badge`, text "Archived", `tabindex=0`, `data-do-tooltip`) matches the wireframe exactly and is fully accessible.
- Round-trip observability is intact (badge appears/disappears/reappears in lockstep with archive state).

Recorded as **ADVISORY** in this handoff (see below). Does not warrant respawning F or ADVISORY-HOLD — the observation is about pre-existing chrome layout and belongs to a future do_chrome polish story if operator cares.

## "Out-of-scope observations" section audit

Reviewed `handoff-T-green.md` §"Out-of-scope observations" for verbatim PR-body use:

- **Self-contained:** YES. Reader does not need any other handoff for context — it names the observable behavior ("Add new content" not blocked in archived groups), locates the root cause ("inside the `drupal/group` contributed module's access-checking plumbing, which does not consult this project's own archive-aware permission logic"), and disclaims scope ("predates this feature entirely… not something #143 introduced or touched").
- **Factually accurate:** YES. Root cause matches the trace in `decisions.md` T Phase 6 round 1 (`GroupRelationshipCreateAnyEntityAccessCheck` never invokes `hook_node_access()`).
- **Appropriate tone:** YES. Plain-language ("documentation pages, events, etc."), no jargon, no defensive posturing, no blame. Cites POC posture for the no-issue-filed decision.
- **Scope framing:** YES. Explicit "not something #143 introduced or touched" and "recorded here for visibility so a human can decide whether/when it's worth addressing."

**Verdict:** ready for verbatim lift into PR body.

## Additional WCAG 2.2 AA spot-checks

U covered: focus visible (visible blue focus ring), keyboard operable (tab reaches Restore tab; select→button→cancel order within form), real `<button>`, `<label for>` on select, `aria-describedby` wiring.

Additional 2.2 AA criteria not explicitly walked but satisfied by construction:
- **1.3.1 Info and Relationships:** `<label for>` associates select label; `<p id>`+`aria-describedby` associates description with button. Both verified in U live DOM.
- **2.4.7 Focus Visible:** confirmed by U focus-ring screenshot.
- **2.5.8 Target Size (Minimum, 2.2 AA new):** confirm buttons/tabs use core defaults sized ≥24×24 CSS px. Not directly measured; core Olivero defaults satisfy — flagging as low-risk assumption, not a hole.
- **3.3.2 Labels or Instructions:** select has visible label + description; button has text + `aria-describedby`. Verified.
- **4.1.2 Name, Role, Value:** real `<button>` + real `<select>` + real `<a>` — native semantics; verified in U DOM inspection.

No additional WCAG holes identified. AC-6 coverage is complete for this story's surface.

## Cache invalidation / defensive branches / other

- **Cache invalidation on submit:** `$group->save()` in `submitForm` auto-invalidates the group's cache tags; `RestoreGroupAccess::access` binds via `addCacheableDependency($group)`. U confirmed tab visibility flips correctly in a single navigation cycle (no stale cache observed). PASS.
- **Race guard:** covered by Kernel `testSubmitIsNoOpWhenGroupNoLongerArchived`. PASS.
- **Empty-vocab guard:** covered by Kernel `testBuildFormRefusesWhenNoNonArchiveTermExists`. PASS.
- **Error message tone:** "Group '@label' has been restored and set to type '@type'." — factual, non-jargon, name-scoped. Error path: "The group could not be restored. Please try again." — actionable, no stack trace leakage. PASS.

## Advisory notes (non-blocking)

1. **Badge visual placement (U observation):** cosmetic gap between wireframe schematic and live `do_chrome/ArchivePinHooks` chrome placement. Not #143's territory; consider a future do_chrome polish story if operator judges the near-sub-tabs placement suboptimal.
2. **Latent WCAG debt on #138** (per O F-adjudication, `RemoveMemberForm` renders `<input type=submit>` not `<button>`; #138 tests never asserted the tag name): flagged and adjudicated; not a #143 blocker.
3. **`assemble-config.sh` host-PHP assumption:** T advisory; workaround via `ddev exec` documented.
4. **Pre-existing "Add new content" not-blocked-in-archived-groups gap:** covered by the PR-body out-of-scope section; no action needed here.

## Recommended PR

**Title:** `feat: #143 do_group_extras — Restore action for archived groups`

**Body:**

```markdown
## Summary

Adds a dedicated Restore action for archived `community_group`s: an accessible confirmation form at `/group/{gid}/restore` reassigns `field_group_type` from the "Archive" term to a caller-chosen non-Archive term, mirroring `do_group_membership`'s form-per-action pattern. Reuses the existing archive state model (term-ref on `field_group_type`); no schema change, no parallel state field.

## What's in

- `do_group_extras.routing.yml` — `GET|POST /group/{group}/restore`, `_form` + `_custom_access`.
- `do_group_extras.links.task.yml` — "Restore group" local task, visible only on archived groups.
- `RestoreGroupAccess::access` — gated by `isArchived` + `edit group` (group-scope) or `administer group` (site-admin escape hatch), with the same cacheability shape as `ManageMembersController`.
- `RestoreGroupForm extends ConfirmFormBase` — target-type `<select>` (default "Working group", "Archive" excluded), race-guard re-check, empty-vocab guard, try/catch save mirroring `RemoveMemberForm`, `aria-describedby` wiring on a real `<button type="submit">`.

All 11 acceptance criteria PASS with covering tests: Kernel (4/4), Functional (10/10), pre-existing suite (8/8, no regression), E2E round-trip (1/1). WCAG 2.2 AA verified live via headless browser (keyboard operable, focus visible, real button, labeled controls, `aria-describedby` wiring).

## Test plan

- [x] Kernel — `docs/groups/modules/do_group_extras/tests/src/Kernel/GroupRestoreTest.php` (4/4)
- [x] Functional — `docs/groups/modules/do_group_extras/tests/src/Functional/GroupRestoreAccessTest.php` (10/10)
- [x] Pre-existing `GroupExtrasBehaviorTest` — 8/8, no regression (AC-7)
- [x] E2E — `tests/e2e/group-restore.spec.ts` round-trip against seeded Legacy Infrastructure (1/1)
- [x] Live UI walkthrough — U verified admin → group canonical → Restore tab → confirmation form → submit → restored → edit → re-archived → anonymous 403; zero console errors
- [x] phpcs `--standard=Drupal,DrupalPractice` on production files — 0 errors, 0 warnings

## Out-of-scope observations

While authoring and verifying #143's end-to-end test, this round's testing surfaced a real, pre-existing gap unrelated to this feature: **archived groups in this site do not actually block users from adding new content to them.** The "Archived" badge and the ability to restore a group both work correctly and are fully tested. However, the separate mechanism that is supposed to stop people from creating new content (documentation pages, events, etc.) inside an archived group does not work on the actual page users would use to add that content — visiting an archived group and clicking "Add new content" currently offers the same options as any normal group.

This is not something #143 introduced or touched — it predates this feature entirely and lives inside the `drupal/group` contributed module's access-checking plumbing, which does not consult this project's own archive-aware permission logic on that particular page. It is a pre-existing, site-wide gap affecting every archived group, not specific to the group used in testing.

Per the project's proof-of-concept posture, no follow-up tracking issue was filed for this round; it is recorded here for visibility so a human can decide whether/when it's worth addressing.
```

## Verdict

**PASS** — story is ready to PR. All 11 ACs have live covering assertions; all three declared deviations were legitimately adjudicated with operator input where required (AC-8 swap via ruling c; `#pre_render` fix accepted with WCAG-debt-flag on #138); the "Out-of-scope observations" section is PR-body-ready; U's non-blocking badge-placement observation is pre-existing chrome, not #143. No REWORK. No ADVISORY-HOLD.

Human review + merge is aangelinsf's call — this PASS is a recommendation, not a merge.
