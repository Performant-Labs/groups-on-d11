# Handoff-S: Phase 9 — #120 SC-1 Persona Switcher (Spec Audit)

**Date:** 2026-07-23
**Branch:** 120-persona-switcher (base merge-base `e269c66`; origin/main has since advanced with #153/#121 — see "PR-readiness note" below)
**Issue:** #120
**Handoffs reviewed:** brief.md + brief-amendments.md (amendments supersede), wireframe.md (APPROVED), handoff-A-plan.md (BLOCK), handoff-A-plan-2.md (PASS), handoff-T-red.md, handoff-F.md (incl. Phase 5-fix + Phase 6.5), handoff-T-green.md (incl. Phase 6-followup), handoff-U.md (PASS), diff-review-o4mini(.md/-r2.md/-response.md), decisions.md.

## Preconditions

- **A precondition:** PASS. `handoff-A-plan-2.md` verdict PASS after 8 amendments resolved all 3 blockers + 6 warnings.
- **T precondition:** PASS. `handoff-T-green.md` Phase 6-followup reports zero blocking issues; all suites GREEN (19/19 Kernel do_showcase, 31/31 Unit, 17/17 Functional do_showcase, 123/123 full custom-module Kernel regression, 46/46 full custom-module Functional regression, 4/4 E2E against seeded stack).
- **U precondition:** PASS. All 30 checklist items green (anonymous, Maria, Groups-Moderate, Elena, uid-1 guard, method enforcement, WCAG spot-checks, zero console errors, zero 500s).
- **Diff-gate:** o4-mini Round 2 PASS after 3 BLOCKs (Url::fromRoute, referer validation, DI of ShowcaseCatalog) were fixed in Phase 6.5.
- **Browser/visual-diff:** N/A for this audit — U already ran comprehensive live walkthrough with computed-style checks against real seeded DDEV stack; S is code+spec compliance only. No new visual delta to diff.

## AC compliance table (source: `gh issue view 120`)

| AC bullet | Evidence | Verdict |
|---|---|---|
| Anonymous visitor sees "Browse as" dropdown in header; per-option `do_chrome` tooltips render | `PersonaSwitcherDropdownTest` (Functional, label + 4 options + `title` attrs); `PersonaSwitcherRenderTest` (Kernel, render array + cache contexts); `HelpTextPersonaKeysTest` (Kernel, 4 keys ≤140 chars); U items 1–7 PASS live; `personaSwitcherWidget()` hook renders on every page via 3rd sibling `#[Hook('page_top')]` | **PASS** |
| Switch to each persona: session becomes that user; banner shows "You're browsing as X — switch back"; switch-back returns to anonymous cleanly | `PersonaBannerTest` (exact copy for Elena); `PersonaSwitchControllerMethodTest`; `PersonaUidOneGuardTest`; E2E `persona-switcher.spec.ts` full-switch for Groups-Moderate + Maria (4/4 PASS after F's Phase-5 `label` field fix); U items 8–20 PASS live | **PASS** |
| Organizer persona (Maria) demonstrates capability Elena lacks (edit group / manage members) | `PersonaAccessPositiveTest` (Maria 200 vs Elena 403 on Organizer routes); U item 11 (Maria sees View/Edit/Manage members tabs on group 1) vs U item 19 (Elena does not) | **PASS** |
| Groups-Moderate can administer a group it isn't a member of (pending queue, approve, archive/restore); can do NOTHING beyond that scope (negative test) | `PersonaAccessPositiveTest` (Moderator 200 on `/group/{n}/members` never-joined + POST restore); `PersonaAccessNegativeTest` (403 on `/admin/config`, `/admin/people`, `/admin/modules`); `GroupsModerateRoleConfigShapeTest` (Unit — `admin: false` + enumerated perms); U items 15–16 PASS live | **PASS** |
| uid 1 unreachable via any persona-switch path (at access-check level, not just UI) | `PersonaAccessCheckTest` (Kernel, fabricated uid-1 always denied); `PersonaUidOneGuardTest` (Functional, real route 403); `PersonaAccessCheck::access()` line 84: `(int) $target_user->id() === 1` — by uid, not uname string; U item 21 PASS (curl POST → 403) | **PASS** |
| Per-option tooltips render | `PersonaSwitcherDropdownTest::testEachOptionHasTitleAttribute`; `HelpTextPersonaKeysTest`; wireframe §1 open-question resolved (native `<option title=...>` + one wrapper `data-do-tooltip`); U item 4 PASS live | **PASS** |
| Existing suite stays green | Full custom-module Kernel 123/123, Unit 62/62, Functional 46/46; T fixed 1 stale test (`GroupRoleConfigShapeTest` in do_group_membership from #138) squarely in scope per Amendment 1 supersession | **PASS** |
| Playwright covers full switch → verify → switch-back for ≥ 2 personas incl. Moderator | `tests/e2e/persona-switcher.spec.ts` — Groups-Moderate + Maria full-switch, keyboard-only, visible-focus (4 tests, all PASS against seeded stack) | **PASS** |
| Ships with HelpText entry (append-only) for new user-facing surface | HelpText.php diff: 16 insertions, 0 deletions — 4 new `persona.*` keys only (verified via `git diff`); `HelpTextPersonaKeysTest` pins ≤140 chars each | **PASS** |
| WCAG 2.2 AA — labels, keyboard operability, visible focus, AA contrast, non-color status | `PersonaSwitcherDropdownTest` (label/for/id); E2E keyboard-only + visible-focus 2px `#4da3ff` outline; banner reuses ribbon AA-checked dark/light pairing; U items 25–28 PASS (computed style `outline: 2px solid rgb(77,163,255)`); banner leading `▶` glyph is decorative, text carries meaning | **PASS** |
| D11 masquerade compat verified & recorded — SUPERSEDED by Amendment 3 to "masquerade dep DROPPED; bespoke path" recorded on issue + PR body | Amendment 3 in `brief-amendments.md` lines 28–42; `decisions.md` line 119 records the dispensation; `PersonaSwitchController.php` lines 21–22 document the rationale in code | **PASS (spec-substituted)** — see PR-body note below |
| Delivery per epic: branch → CI green → PR → merge on green | 14 clean commits on branch; branch is behind origin/main (see PR-readiness note) | **PASS (with rebase caveat)** |

## Spot-check results

| Spot-check | Result |
|---|---|
| HelpText.php append-only | **PASS** — `git diff` shows 16 insertions, 0 deletions; append-block at end of `all()` array; no existing key edited |
| `group.role.community_group-groups_moderate.yml` — Amendment 1 exact shape | **PASS** — `admin: false`; `permissions: [administer members, edit group]` exactly; `scope: outsider`; `global_role: groups_moderate` unchanged. Third "archive perm" bullet correctly dropped (F's investigation of `RestoreGroupAccess::access` confirmed only `edit group` is checked — matches A's own non-blocking note in `handoff-A-plan-2.md`) |
| `ShowcaseCatalog::personas()` extended per Amendment 2 (+`label` for Phase-5 fix) | **PASS** — all 4 personas carry `id/name/label/description/uname/tooltip_key`; `personaSpec(id): ?array` helper present; unames match Amendment 2 (`NULL`, `elena_garcia`, `maria_chen`, `groups_moderate_demo`); tooltip_keys match (`persona.anonymous/elena/maria/moderator`) |
| Bespoke persona-switch (Amendment 3: masquerade dep dropped) | **PASS (with lockfile dispensation)** — `composer.json` still lists `drupal/masquerade: ^2.2` (unchanged from base branch — per Amendment 3 line 33 & F Design decision "leaving lockfile alone is acceptable"). `docs/groups/config/core.extension.yml` is a generated artifact (not tracked under `docs/groups/config/`), and the assembled build does not enable `masquerade`. F never touched composer manifests. Bespoke `PersonaSwitchController` + `PersonaAccessCheck` present |
| Route-level access check via `_persona_access` service tag; uid-1 by UID | **PASS** — `do_showcase.persona_access` tagged `access_check`, `applies_to: '_persona_access'` (services.yml:65); `requirements: {_persona_access: 'TRUE'}` on route (routing.yml:25); uid-1 guard is `(int) $target_user->id() === 1` (PersonaAccessCheck.php:84) — never uname string comparison |
| POST for state-change, GET only for switch-back | **PASS** — `methods: [GET, POST]` at route level; controller branches: GET on non-anonymous → 405, POST on non-anonymous → login+302, GET on `anonymous` → logout+302 (`PersonaSwitchControllerMethodTest`; U items 22–24 PASS live) |
| `Url::fromRoute` for ALL persona-switch URLs — no `/persona-switch/` literals | **PASS** — `PersonaSwitcher.php:144` (`$initial_action`), `152` (sentinel-prefix derivation), `DoShowcaseHooks.php:300` (banner switch-back). Sentinel technique cleanly handles the JS `onchange` prefix. Only remaining `/persona-switch/` mentions are in doc comments |
| Referer validated same-origin with scheme/host/port + default-port normalization | **PASS** — `isSameOriginReferer()` (PersonaSwitchController.php:177–199): parses scheme/host/port; case-insensitive scheme+host compare; port normalization for 80/443 defaults; off-site/malformed → `<front>` fallback; parsed-component compare (not string-prefix — deliberately guards against `example.com.attacker.test/`) |
| `DoShowcaseHooks` constructor-injects `ShowcaseCatalog` — no `new ShowcaseCatalog()` | **PASS** — `grep -c "new ShowcaseCatalog"` returns 0 across the whole file. Second constructor-promoted param + services.yml class-name alias for `ShowcaseCatalog` present (services.yml:52) |
| Two sibling `#[Hook('page_top')]` methods (`personaSwitcherWidget` + `personaBanner`); `pageTop()` ribbon untouched | **PASS** — 3 total `#[Hook('page_top')]` methods at lines 115 (pageTop, ribbon — untouched vs main), 165 (personaSwitcherWidget), 237 (personaBanner). Confirmed via `git diff` that pageTop() body was not modified |
| Cache contexts `['user']` on both widget + banner render arrays | **PASS** — `PersonaSwitcher.php:178`, `DoShowcaseHooks.php:249, 269, 318` — all render arrays declare `#cache['contexts'] => ['user']`. Kernel `PersonaSwitcherRenderTest` asserts |
| Seed `step_790_persona_switcher.php` idempotent, append-only, uses `STATUS_PENDING` const | **PASS** — imports `GroupMembershipManager`; writes via `GroupMembershipManager::STATUS_PENDING` (line 154); every mutation gated by an "Exists" branch (lines 49, 65, 112, 141, 147); T verified live idempotency during Task 3 seeded run |
| E2E workflow: `.github/workflows/test.yml` invokes step_790 with the other 3 seed scripts | **PASS** — line 480 renamed to include "personas"; line 501 declares `PERSONA_SCRIPT`; lines 530–536 invoke via drush php:script wrapper matching the existing pattern for step_700/720/780 |
| All PHPUnit + E2E GREEN | **PASS** — T-green Phase 6-followup: 19+31 do_showcase, 17 do_showcase Functional, 123 full custom-module Kernel, 46 full custom-module Functional, 4/4 E2E against seeded stack |
| WCAG 2.2 AA verified via U walkthrough | **PASS** — U items 25–28: focus 2px solid rgb(77,163,255) on select/Go/switch-back; label association; role=status; non-color glyph; keyboard-only round-trip; contrast reuses ribbon's AA pairing |
| PR body records masquerade-dep dispensation | **NOT-YET-WRITTEN** but rationale is fully ready in `decisions.md` line 119 + `brief-amendments.md` Amendment 3 + `PersonaSwitchController.php:21–22` code comment. O has everything needed to paste into PR body verbatim. Non-blocking for S PASS; flag for O at PR-open time |

## Test-quality audit (per `testing/test-quality.md` §7)

- **Per-test validity:** each test names one behavior (uid-1 denied, allowlist enforced, banner exact copy, cache contexts, etc.); each fails in isolation for the right reason (RED confirmation table in handoff-T-red.md shows 17 legitimate REDs against missing class/service/method/field/route/markup — never a test-authorship bug).
- **Tier discipline:** Kernel for render arrays + access-check calculation (cheapest sufficient); Unit for on-disk YAML config-shape (correct tier per this repo's own precedent `do_group_membership/tests/src/Unit/GroupRoleConfigShapeTest.php`); Functional for real HTTP route access + BrowserTestBase-installed uid-1 semantics; E2E only for the full switch → verify → switch-back round-trip against a seeded stack (which is the actual AC bullet). No over-reach.
- **Suite proportionality:** 4 Kernel + 6 Functional + 1 Unit + 1 E2E spec (4 tests) is proportionate to a feature story with 12+ AC bullets. No fan-outs, no snapshot-everything, no mock-shaped tests.
- **Behavior-not-implementation:** exact-copy DOM assertions, real HTTP status codes, computed-style focus outlines — behavior pins. The 2 documented pre-existing-true "regression guard" tests (`PersonaBannerTest::testAnonymousSessionHasNoBanner`, `PersonaUidOneGuardTest::testUnknownPersonaIdIsDenied`) are explicitly documented in T's handoff as guards, not silent false-positives — legitimate.
- **No smells identified.** T's own investigation (handoff-T-red.md "Non-RED, documented" section) shows they explicitly reasoned about which assertions were vacuous vs. genuine RED, rather than accepting green tests silently. That's exactly the review discipline the rubric asks for.

## Quality audit

| Area | Result | Notes |
|---|---|---|
| API consistency | PASS | Route id/path/method convention matches `do_showcase.showcase`. Access check tag matches core's `access_check.custom` shape |
| Error handling | PASS | Access check returns `AccessResult::forbidden(reason)` with reasons; controller falls back to `<front>` on off-origin referer; 405 on GET-to-non-anonymous is HTTP-correct |
| UI/UX match to spec | PASS | Wireframe locked; U walkthrough confirms verbatim banner copy for all 3 authenticated personas; dropdown matches native `<select>` decision |
| Accessibility | PASS | Label association, keyboard operability, 2px `#4da3ff` visible focus, `role=status`, non-color glyph — all verified live by U |
| Architecture gate | PASS | A returned PASS at Phase 3 re-review after 8 amendments |
| Code organization | PASS | New files under `src/Persona/`, `src/Access/`, `src/Controller/`, `src/Plugin/Block/` — clean layering; hook methods split by concern |
| Security | PASS | Uid-1 guard by UID; allowlist by persona id; route-level access check runs before controller; POST for state change; Referer same-origin parsed by component, not prefix; no CSRF-token gap because state-change requires POST + `access_check`+ allowlist (POC scope) |
| Performance | PASS | Cache contexts `['user']` on both render surfaces; no N+1 in access check; one entity load per switch |
| Visual regression | N/A | No formal VR baseline suite in this repo; U walkthrough covered live rendering across full multi-persona round-trip with zero console errors / zero 500s |
| Naming consistency | PASS | `PersonaSwitcher`/`PersonaAccessCheck`/`PersonaSwitchController`/`PersonaSwitcherBlock`/`persona-switcher.css` all coherent; `do_showcase.persona_*` service naming matches Amendment 4 |
| Test quality (§7) | PASS | See section above |

## Scope check

F delivered exactly the phase scope defined by brief.md + amendments. One in-scope stale-test fix (T-green: `do_group_membership/tests/src/Unit/GroupRoleConfigShapeTest.php`) was necessary because Amendment 1 supersedes the shape that test pinned — legitimate scope, documented, near-duplicate assertion intentionally preserved across both suites. No scope creep observed.

## PR-readiness note (not a verdict blocker; O to handle)

- **Branch is behind origin/main by ~14 commits** (merged #121/#153 landed after this branch's base `e269c66`). A `git diff origin/main` shows spurious "deletions" of #121 artifacts; the actual code diff against merge-base is clean and confined to #120's own files. **O must rebase onto origin/main before opening the PR** (or GH will show the wrong diff and CI may fail on merge). This is standard hygiene, not an S finding against F's work.
- **PR body must record the masquerade-dep dispensation** per AC + Amendment 3 + O's own note. Suggested wording (already drafted in `decisions.md:119` and `PersonaSwitchController.php:21–22`): _"D11 masquerade compat: dep DROPPED in favor of bespoke controller — anonymous→persona switching is not what masquerade is designed for (authenticated→authenticated with unmasquerade session preservation); a full logout+login flow is simpler, honest to the mechanism, and satisfies every AC. `composer.json`/lock intentionally left with the dep declared but the module is not enabled in `core.extension.yml`."_ Non-blocking for S PASS; flag for O at PR-open time.

## Verdict

**VERDICT: PASS** — all 12 AC bullets met, every spot-check verified against real code (not just handoff claims), full test pyramid GREEN against a genuinely seeded stack, U walkthrough independent-live-verified all 30 checklist items including the previously-buggy Groups-Moderate banner copy, diff-gate BLOCK×3 resolved and re-verified. Story is ready for O to rebase + open PR + drive CI + merge.

## Advisory notes (non-blocking)

1. **Consider a Functional-tier `PersonaBannerTest::testGroupsModerateSession...` method** paralleling the existing Elena test. Would have caught the Phase-5 label bug at PHPUnit tier (cheaper than E2E). T's own advisory flags this; O's judgment call for a follow-up story, not this one.
2. **Deferred WARNs from diff-gate** (W-1 inline-onchange/CSP, W-2 `user_logout()` deprecation, W-3 raw `t()`/`\Drupal::` in DoShowcaseHooks) are documented in `diff-review-o4mini-response.md` with rationale (matches pre-existing `pageTop()` convention; CSP not enforced in this POC; deprecation shim still supported). Follow-up tickets, not blockers.
3. `composer.json` retains `drupal/masquerade` declaration despite the module not being enabled — a future housekeeping story could `composer remove drupal/masquerade` to prune the dep cleanly. Non-blocking; Amendment 3 explicitly authorizes leaving it.
