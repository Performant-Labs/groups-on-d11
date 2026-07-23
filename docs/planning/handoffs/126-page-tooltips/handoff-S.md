# Handoff-S: Phase 9 — #126 SD-1 Page-level ⓘ tooltips

**Date:** 2026-07-23
**Branch:** 126-page-tooltips (HEAD `bba2863`)
**Issue:** #126
**Worktree:** `C:\Users\aange\Projects\_worktrees\groups-page-tooltips`

**Handoffs reviewed:**
- `docs/planning/handoffs/126-page-tooltips/brief.md`
- `docs/planning/handoffs/126-page-tooltips/decisions.md`
- `docs/planning/handoffs/126-page-tooltips/handoff-F.md`
- `docs/planning/handoffs/126-page-tooltips/handoff-T-red.md`
- `docs/planning/handoffs/126-page-tooltips/handoff-T-green.md` (incl. Phase 6b rework re-verification)
- `docs/planning/handoffs/126-page-tooltips/handoff-U.md` (incl. Phase 8b re-walk PASS)

## Preconditions
- **A precondition:** PASS with 2 documentation warns absorbed into brief (default-deny explicit, `aria-label` chosen). Confirmed.
- **T precondition:** T-green (rework) reports 8/8 pinned suite GREEN + 6/6 e2e GREEN + 23/24 full-`do_chrome` (1 unrelated pre-existing `PermissionMatrixPanelTest` fail, zero-diff on targets). Zero blocking issues. Confirmed.
- **Visual-diff / browser preconditions:** N/A — story is a text-tooltip surface, no visual-regression baseline required per issue #126 (POC bar); U's headless screenshots (`screenshots/*.png`) serve as the operator visual evidence.

## Spec sanity (source-of-truth check)
Brief's target route `view.group_members.page_1` was superseded by #138's `do_group_membership.manage_members` controller that landed after this story's #126 brief was written. U detected this at Phase 8; O dispatched a rework-in-place fix (`80325ba` src + `c82eb6c` test) rather than an advisory-hold, correctly — the defect was in the brief's assumption, not the spec's intent (which is unambiguously "ⓘ on the Members tab"). No further ADVISORY-HOLD trigger present.

## AC-by-AC checklist

| AC | Requirement | Status | Evidence |
|---|---|---|---|
| AC-1 | Anon visitor sees ⓘ + correct copy on all 5 LIVE pages | **PASS** | U Phase 8b: 5/5 pages render `.do-chrome-info.page-help-info` with matching `aria-label` copy; Members-tab fix confirmed after `80325ba`. Kernel `testPreprocessPageTitleRendersTriggerForLiveStreamRoute` + e2e 3 anon-page assertions. |
| AC-2 | 5 W2 keys pre-registered, inert until routes exist | **PASS** | `PageHelp::getRouteMap()` lines 77–81 contain all 5 W2 route names; `HelpTextPageKeysTest::testW2PreRegisteredPageKeysReturnNonEmptyString` pins non-empty copy for all 5. U verified `/my-feed` returns 404, no crash, no orphan ⓘ. |
| AC-3 | Keyboard accessible (Tab → focus → Enter/Space opens) | **PASS** | U: `tabindex="0"` present, focus outline `solid 2px` computed, Enter opens tippy. E2e keyboard-focus test 6 GREEN. |
| AC-4 | WCAG 2.2 AA (labels, contrast, focus, non-color) | **PASS** | U: contrast 5.42:1 (fg `rgb(0,103,184)` on bg `rgb(246,248,248)`) — exceeds 4.5:1 AA floor and beats the 5.36:1 #122 baseline. Non-empty `aria-label`, visible focus outline, text-only status. |
| AC-5 | Existing suite green; Playwright samples 2 anon + 1 authed | **PASS** | Full `do_chrome` 23/24 (1 unrelated pre-existing fail, T & F both confirmed zero-diff on `PermissionMatrix*`). `page-help.spec.ts` 6/6 GREEN: 2 anon (`/stream`, `/all-groups`) + 1 Elena-authed group Stream + 2 default-deny + 1 keyboard. |
| AC-6 | Default-deny: no ⓘ on any unregistered route | **PASS** | Kernel `testPreprocessPageTitleDoesNotMutateForUnregisteredRoute` (byte-for-byte `title_suffix` equality) + e2e `/user/login` + `/admin`. U verified `/user/login`, `/admin`, `/node/1/edit`, `/user/register` all absent. |

## Additional audit questions

3. **Copy honest / plain / visitor-facing / no Drupal jargon?** Read all 10 `page.*` strings. Verdict: **PASS**. Every string names what the page shows in plain English ("recent posts, replies, and events", "Every community group on the site"). No mentions of "views", "nodes", "entities", "routes", "config". Members entry honestly names the 3 visibility values (Open / Moderated / Invite Only) matching #121's now-live enforcement. W2 keys are appropriately terse (1 sentence each — they're inert placeholders).

5. **Append-only HelpText contract respected?** Verified via `git diff ca76f95~1 ca76f95 -- HelpText.php`: 24 insertions, 0 deletions, placed after the `persona.*` block, before closing `];`. No prior key edited by this story. **PASS**.

6. **Sole ownership — no cross-story collisions?** Story-owned commits (`25ef47f`, `ca76f95`, `80325ba`, `c82eb6c`) touch only: `PageHelp.php` (new), `HelpText.php` (append), `do_chrome.services.yml` (new service entry), `PageHelpRouteMapTest.php` (new), `HelpTextPageKeysTest.php` (new), `page-help.spec.ts` (new). Companion #127 (Card tooltips) has not landed and is uncontended. **PASS**.

7. **Scope creep?** None. Zero layout, route, field, or template changes. `groups_chrome` theme untouched; core `page-title.html.twig` `title_suffix` slot used verbatim. **PASS**.

## Quality audit

| Area | Result | Notes |
|---|---|---|
| API consistency | PASS | Hook attribute + DI constructor match `DoChromeHooks` pattern exactly. |
| Error handling | PASS | Two early returns (unregistered route; empty copy) — both silent, both tested. |
| UI/UX match to spec | PASS | ⓘ after H1 via `title_suffix`; identical shape to #89 `GroupTypeContentHelp::infoTrigger()` plus `page-help-info` marker class. |
| Accessibility | PASS | AC-4 evidence above; 5.42:1 contrast, `role="note"`, `aria-label`, visible focus. |
| Architecture gate | PASS | A verdict PASS, 2 warns absorbed. |
| Code organization | PASS | Single sole-owner class; explicit service registration matches sibling `do_chrome.hooks` convention (F's decision to prefer explicit over autowire fallback is sound and documented). |
| Security | N/A | Read-only preprocess; plain-text copy; no user input. |
| Performance | PASS | 10-entry array lookup per page-title preprocess; negligible. |
| Visual regression | N/A | Text tooltip surface; U's DOM+screenshot evidence sufficient at POC bar. |
| Naming consistency | PASS | `page.*` key namespace parallels existing `persona.*`, `visibility.*`, `permissions.*`. Route-name keys are Drupal-canonical. |
| Test quality (§7) | PASS | 4 unit + 4 kernel + 6 e2e — each names one behavior, tiered correctly (static map contract in Unit, hook/render behavior in Kernel, cross-page/keyboard in E2E), no duplicated signal across tiers, no snapshot-everything or coverage-padding. Proportionate to a 10-route allowlist + 1 hook. |

## Scope check
F delivered exactly the phase scope: 1 new hook class + append-only copy + 1 service entry + tests. No over- or under-delivery. Rework was a 1-line src fix + 1-line test fix, correctly scoped.

## Advisory notes (non-blocking)
- Two recurring DDEV environment traps documented by T (ddev-router cross-project registration; stale compiled container after `assemble-config.sh`) are worth folding into the standing per-story runbook. Not this story's remit.
- `PermissionMatrixPanelTest` pre-existing failure survives in this codebase and continues to muddy every full-`do_chrome` regression run — worth a separate remediation issue so future stories aren't hand-verifying "same failure signature" each time. Not this story's remit.

## Verdict

**PASS** — all 6 acceptance criteria met, spec-compliant, quality acceptable. Story ready for O to open PR / merge on green CI.
