# Handoff-S: Phase 8 (Spec Audit) — #110 ST-1 My Feed at `/my-feed`

**Date:** 2026-07-23
**Branch:** `110-stream-110` (5 ahead of `origin/main`; one feat commit `d1e2628`)
**Issue:** #110
**Handoff-A reviewed:** `docs/planning/handoffs/110-stream-110/handoff-A.md` (PASS + 8 advisories)
**Handoff-T-green reviewed:** `docs/planning/handoffs/110-stream-110/handoff-T-green.md` (0 blockers)
**Handoff-F reviewed:** `docs/planning/handoffs/110-stream-110/handoff-F.md`

## Preconditions
- **A precondition:** PASS — A returned PASS + 8 non-blocking advisories in handoff-A.md.
- **T precondition:** PASS — T-green reports 0 blocking issues; 10/10 PHPUnit + 6/6 E2E green.
- **Live audit:** performed against seeded DDEV instance `gm110-groups-stream-110` (BASE_URL http://gm110-groups-stream-110.ddev.site), with `bash scripts/ci/assemble-config.sh` already applied and `drush cr` executed between fetches for repro isolation.
- **Visual-diff-tool precondition:** N/A — this audit found a **functional AC-5/AC-9 defect at Tier 3** before any visual comparison would have added information. No pixel-level VR was performed for this cycle.

## Live verification results (AC-by-AC)

Every AC below was independently checked against the running seeded instance in addition to reading T's green suite output. Where I list "verified live" the check is a curl-based session against the actual seeded site as the named user.

| AC | Requirement | Verification | Result |
|---|---|---|---|
| AC-1 | Anon `GET /my-feed` -> 403 or login redirect | `curl -o /dev/null -w '%{http_code}' /my-feed` (no session) -> `403` | PASS |
| AC-2 | Auth `GET /my-feed` -> 200 + shell chrome | Elena session -> `status=200`, `data-testid="do-streams-shell"` present | PASS |
| AC-3 | `my_feed` tab is-active + aria-current="true" | `data-scope-id="my_feed"` element carries `aria-current="true"` (grep confirmed) | PASS |
| AC-4 | `recent` ranking pill is-active | `data-ranking-id="recent"` element with `is-active` class present | PASS |
| AC-5 | Membership-scope excludes non-member group content | **FAIL under cross-user cache leak** — see finding below | **FAIL** |
| AC-6 | Zero-group user sees empty state + CTA -> /all-groups | E2E test 6 GREEN; controller code path (`buildShell([], TRUE)` + `buildEmptyCta`) audited | PASS |
| AC-7 | Pager renders when results > 10 | View config `pager.type: full`, `items_per_page: 10`; rendered page contains `class="pager__items js-pager__items"` when >10 rows visible via cold-session; verified live | PASS |
| AC-8 | Nav link "My Feed" auth-only, ordered Activity→My Feed→My Groups | E2E tests 2/3 GREEN; `step_780_nav_menu.php` diff confirms weight scheme 0/1/2/3/4 with My Feed at 2; `MyFeedNavLinkTest` 3/3 GREEN | PASS |
| AC-9 | Elena leads with pinned "Sprint Planning: Portland 2026"; no Thunder/Deutschland content | **Cold-session** (drush cr, then Elena first): pinned lead present, no Thunder/Deutschland. **Warm-cache** (admin fetched first, then Elena): Elena is served ADMIN's cached feed including Thunder Distribution × 5. See finding below. | **FAIL** |
| AC-10 | `stream.my_feed` HelpText entry present, append-only | `MyFeedHelpTextTest` 2/2 GREEN; diff of `HelpText.php` confirms strictly-appended entry | PASS |
| AC-11 | Nav link seeded via `step_780`, front page unchanged | `step_780_nav_menu.php` diff confirms link append; `git diff` shows no `config/system.site.yml` touch | PASS |
| AC-12 | WCAG 2.2 AA | Not automated (no `@axe-core/playwright` — established repo convention); focus styles present in `css/my-feed.css`; audit deferred to U | DEFERRED |

## Blocking finding: cross-user render-cache leak on `/my-feed` (defeats AC-5 and AC-9)

### Symptom (reproduced live)

Sequence, each command run against `gm110-groups-stream-110`:

1. `ddev exec drush cr`
2. Fetch `/my-feed` in an authenticated **admin** (uid=1) session.
   Result: 10 stream cards from admin's groups (Thunder Distribution × 5, DrupalCon Portland 2026 × 4, Leadership Council × 1).
3. Fetch `/my-feed` in a **fresh Elena** session, immediately after step 2, cookies not shared.
   Result: identical 10 stream cards — Thunder Distribution × 5 included. Elena is **NOT a member of Thunder Distribution** (verified: Elena is in gids 1, 3, 5, 6, 2; Thunder Distribution is gid 4).

Repro of the reverse (Elena first, then admin) shows the mirror: admin sees Elena's feed. First user after a cache clear "wins" the cache; every subsequent user gets the previous user's results until the cache is invalidated.

### Root cause

The controller (`MyFeedController::buildShell`) sets `#cache => contexts: ['user', 'user.roles:authenticated'], tags: ['do_streams:user_stream:<uid>']` on the **outer shell** render array. But the inner results subtree is a `#type => 'view'` render element whose cacheability is controlled by the view config's own cache plugin — `views.view.my_feed.yml` line 118: `cache: { type: tag }`. Tag-based caching keys the view's rendered output on tags only; the outer contexts do **not** propagate down into the inner view's cache key. Result: the first user's rendered view result is cached and re-served to every subsequent user until an invalidation event matches one of the view's own tags.

The `do_streams:user_stream:<uid>` tag on the outer render array does not invalidate the inner cache (different subtree, no shared cache bin key).

### Why the tests missed it

- **`MyFeedRouteTest::testResponseVariesByViewingUser`** installs the view from a fixture in a fresh per-test BrowserTestBase site; no persistent render cache, so a single request per user always cold-executes. Cannot observe cross-request cache reuse.
- **E2E `my-feed.spec.ts`** runs Playwright specs sequentially in a single worker; the seed step runs `drush cr` shortly before. Each spec's login-then-fetch pattern happens to be the FIRST auth fetch after that clear, so it wins the cache. The suite never exercises "user A fetched, then user B fetches immediately."
- Cross-user cache correctness under a `type: tag` view is exactly the failure mode a real multi-user demo (or Coolify deploy under real traffic) exhibits first.

### Fix options (S proposes; F/A decide)

Any ONE of the following is sufficient. Listed cheapest first:

1. **Change the view's cache plugin to `type: none`** in `docs/groups/config/views.view.my_feed.yml` (line 118-120). Every /my-feed request cold-executes the query; matches the "personal feed, per-user" nature of the surface. This is the smallest, most local fix and cannot regress other views.
2. **Set the view's cache to `type: tag`** but add a per-user cache context via the view's own `display.default.display_options.cache.options` — requires confirming the tag cache plugin's contexts option surface, more moving parts.
3. **Set `#cache_properties` or `#cache` on the `#type => 'view'` element itself** with `max-age: 0` OR add a per-user context to force per-user caching. Requires understanding how `\Drupal\views\Element\View::preRenderViewElement` propagates the element's own cache metadata (there is precedent — `#cache` on a render array is honored, but only if it's not overridden by the child view's own cache plugin).

Option 1 is my recommendation because (a) it's a one-line YAML change in an already-owned artifact, (b) `/my-feed` is per-user by definition — there's nothing to cache-share across users, and (c) it eliminates the failure class entirely rather than adding another cache axis that could itself be miscomputed.

### Test to add on rework

A single functional or E2E test that fetches `/my-feed` as user A, then as user B in the SAME test-process (no cache clear between), and asserts each sees only their own group's content. This is the exact scenario the current suite lacks. Kernel-level would also suffice but functional/E2E is more representative.

## Non-blocking findings

- **Shell chrome CSS gap** (handoff-A Finding #7, handoff-F Deviations #4): confirmed inherited-from-#109 gap. Visually bare shell rendering is expected until #109's follow-up CSS ships. Not a defect in this story's scope; noted for U.
- **AC-12 (WCAG axe)**: no `@axe-core/playwright` dependency in the repo (established convention). Deferred to U's Walkthrough for a manual keyboard + focus-visible + contrast pass on the shell tabs, ranking pills, and empty-state CTA.
- **phpcs test-file advisories**: not CI-gated, consistent with pre-existing repo style debt — no action.
- **`HelpText.php` pre-existing lint debt** (~250 pre-existing errors, F added ~18 proportionate): not new, not fixed here.
- **Test quality (§7 rubric)**: the added tests each pin one behavior, sit at the right tier (unit for HelpText, functional for route/nav, E2E for real seeded auth flow), and were spot-checked by T for load-bearing behavior via revert. Suite size (10 PHPUnit + 6 E2E) is proportionate to the change; nothing to delete or merge. However, this suite **lacks a test that would have caught the render-cache leak** — see "Test to add on rework" above. That gap becomes a REWORK requirement, not a suite-quality nit.

## Quality audit summary

| Area | Result | Notes |
|------|--------|-------|
| API consistency | N/A | No public API added; route + controller only. |
| Error handling | PASS | `Views::getView()` NULL fallback → empty shell; `assertNotFalse` guard on fixture. |
| UI/UX match to spec | PASS (structure) / DEFERRED (visual) | Shell chrome structure matches wireframe; visual CSS gap is #109 leftover. |
| Accessibility | DEFERRED to U | No automated axe run in this repo. |
| Architecture gate | PASS | A returned PASS; no drift from A's plan. |
| Code organization | PASS | Controller placement matches A's approved pattern; theme-hook extension is additive; nav-script re-weight is surgical. |
| Security | PASS | Route gated by `_user_is_logged_in: 'TRUE'`; view has `role: authenticated` defense-in-depth; no unescaped user input. |
| Performance | ADVISORY | Cache correctness failure identified; performance itself is fine but correctness is wrong (see finding). |
| Visual regression | N/A this cycle | Not attempted — functional failure short-circuits value of a visual diff. |
| Naming consistency | PASS | `stream.my_feed` HelpText key matches sibling `stream.*` namespace pattern. |
| Test quality (`testing/test-quality.md` §7) | PASS structure / FAIL coverage | Existing tests are well-shaped; a cross-user-cache test is missing and would have caught the AC-5/AC-9 defect. |

## Scope check

F delivered exactly the phase scope named in the brief — the 9 owned/extended files match `handoff-F.md § Files changed`. No over-delivery, no unrelated drift. The `check-elena.php` / `debug-view.php` diagnostic scripts I created during this audit were removed before this handoff (`git status` clean beyond the branch's known WIP).

## Verdict

**REWORK.**

Two of the twelve acceptance criteria (**AC-5**, **AC-9**) fail live under a realistic multi-user access pattern because the `/my-feed` view's inner render subtree is cached by tags only, without a per-viewing-user cache context or a per-user tag. The demo scenario the issue explicitly names — "any seeded persona (esp. `elena_garcia`) must land on a full, varied feed... pinned 'Sprint Planning: Portland 2026' leading" — is defeated the moment a second user visits after the first.

Required changes before re-audit:

1. **Fix the cross-user cache leak on `/my-feed`.** S recommends changing `docs/groups/config/views.view.my_feed.yml` line 118-120 from `cache: { type: tag, options: {} }` to `cache: { type: none, options: {} }`. Alternative acceptable fixes listed above. Any option is acceptable if it verifiably eliminates the cross-user bleed.
2. **Add a test that would have caught this.** A functional or E2E test that: fetches `/my-feed` as user A, then as user B in the SAME test run WITHOUT a cache clear in between, and asserts each response contains only that user's group content. This suite currently lacks any assertion that pins per-user cache correctness across sequential requests, and without one this defect could regress silently.
3. **Re-run all suites plus a live cross-user repro** on `gm110-groups-stream-110` (admin fetch → Elena fetch, no `drush cr` between) and paste the exact `Counter` of group badges observed in the Elena response into the T-green update.

Everything else — AC-1, AC-2, AC-3, AC-4, AC-6, AC-7, AC-8, AC-10, AC-11 — is verified PASS. AC-12 remains an intentional U-backstop deferral consistent with repo convention. Once the cache-leak fix lands and its test is added and green, this story is ready for U (or, if the operator prefers, straight to PR under the POC lean-pipeline convention where E2E already covers the UI-visible behavior).

## Advisory notes (non-blocking, for after rework lands)

- Consider whether the same tag-only view-cache pattern exists on `activity_stream.yml` and the other scope-specific views planned for #111-#115. `/stream` is a shared global feed so the failure mode does not apply there, but any future personalized scope (Following, Trending-scoped-to-user, etc.) will hit the same trap. A short A-owned note on "views serving per-user content must not use `cache: type: tag`" would be worth adding to the streams module's README.
- The empty-CTA link uses `Url::fromUserInput('/all-groups')` — fine and locally-consistent, but a `Url::fromRoute('view.all_groups.page_1')` style would be more robust against future path changes. Not a defect. Noted for future consideration.
