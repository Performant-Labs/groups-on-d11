# Handoff-T-red: Phase 4 - #126 SD-1 Page-level ⓘ tooltips

**Date:** 2026-07-23
**Branch:** 126-page-tooltips
**Brief / wireframe reviewed:** `docs/planning/handoffs/126-page-tooltips/brief.md`, `docs/planning/handoffs/126-page-tooltips/decisions.md` (D skipped per brief — highly patterned, no wireframe)

## A precondition
Confirmed: A returned PASS (with 2 documentation warns, both absorbed into brief.md) on the plan — see decisions.md "A — Phase 3" entry dated 2026-07-23. No BLOCK.

## Tests authored

### Unit — `docs/groups/modules/do_chrome/tests/src/Unit/HelpTextPageKeysTest.php`
- `testLiveRoutePageKeysReturnNonEmptyString` — the 5 LIVE-route keys (`page.stream`, `page.all_groups`, `page.group.stream`, `page.group.events`, `page.group.members`) each resolve to non-empty plain text. Pins AC-1/AC-4 (copy must exist for the ⓘ to render).
- `testW2PreRegisteredPageKeysReturnNonEmptyString` — the 5 W2 keys (`page.my_feed`, `page.following`, `page.trending`, `page.my_feed_events`, `page.profile_stream`) also resolve non-empty NOW, even though their routes don't exist yet. Pins AC-2 ("the map is complete so W2 stories don't need to edit do_chrome").
- `testNonexistentPageKeyReturnsEmptyString` — `page.nonexistent` → `''`. Pins the silent-empty contract PageHelp's default-deny gate depends on.
- `testAllTenPageKeysArePresentInAll` — all 10 keys are literal entries in `HelpText::all()`.
- Tier: **Unit** (pure PHP, no Drupal bootstrap) — cheapest sufficient tier for a static copy-map contract.

### Kernel — `docs/groups/modules/do_chrome/tests/src/Kernel/PageHelpRouteMapTest.php`
- `testRouteMapContainsExactlyTenEntries` — `PageHelp::getRouteMap()` equals the exact 10-entry route=>key map from brief.md §Scope (5 live + 5 W2), verified route ids against the assembled `views.view.*.yml` page displays (see Verification note below).
- `testPreprocessPageTitleRendersTriggerForLiveStreamRoute` — a `RouteMatch` for `view.activity_stream.page_1` causes `preprocessPageTitle()` to mutate `$variables['title_suffix']` into a render array whose rendered HTML contains the `page.stream` copy. Pins AC-1.
- `testPreprocessPageTitleDoesNotMutateForUnregisteredRoute` — a `RouteMatch` for `system.admin` leaves `title_suffix` byte-for-byte untouched. Pins AC-6 (default-deny).
- `testRenderedTriggerCarriesAllRequiredAttributesAndGlyph` — rendered trigger contains `data-do-tooltip`, `aria-label`, `tabindex="0"`, `role="note"`, and the ⓘ glyph (U+24D8). Pins AC-3/AC-4 and brief's skip-D `infoTrigger()`-shape requirement.
- Tier: **Kernel** — needs a real `RouteMatchInterface` + the renderer service; too heavy for Unit, doesn't need a full browser/HTTP request (Functional), so Kernel is the cheapest sufficient tier.

### E2E — `tests/e2e/page-help.spec.ts` (6 tests)
- Anon `/stream`: ⓘ present, `aria-label`/`data-do-tooltip` match "site-wide activity stream", hover shows tippy tooltip with that copy.
- Anon `/all-groups`: same shape, copy matches "Every community group".
- Authed (Elena via `/persona-switch/elena-garcia`) on a seeded group's `/group/{gid}/stream` (resolved via `/all-groups` directory, not a hardcoded id): ⓘ present, copy matches "group's activity".
- Default-deny: `/user/login` has 0 `.page-help-info`; `/admin` (anon) has 0 `.page-help-info` regardless of redirect outcome.
- Keyboard: bounded Tab loop on `/stream` until focus lands on `.page-help-info`, then asserts non-zero focus outline.
- Tier: **E2E** — the only tier that can prove tippy.js hover-activation, cross-page DOM-sibling-of-H1 placement, and real keyboard focus order against the live seeded site.

## RED confirmation

Assembled + installed a fully isolated DDEV instance for this worktree (`gm126-page-tooltips`, per project namespacing convention) — `composer install`, `assemble-config.sh`, `site:install`, `config:import`, then seeded `step_700_demo_data.php` → `step_720_group_types.php` → `step_790_persona_switcher.php`, matching `.github/workflows/test.yml`'s E2E job sequence.

**Unit** (`php vendor/bin/phpunit -c web/core/phpunit.xml.dist web/modules/custom/do_chrome/tests/src/Unit/HelpTextPageKeysTest.php`):
```
✘ Live route page keys return non empty string
  Tooltip copy for live-route key "page.stream" must exist.
  Failed asserting that two strings are not identical.
✘ W 2 pre registered page keys return non empty string
  Tooltip copy for W2 pre-registered key "page.my_feed" must exist.
✔ Nonexistent page key returns empty string   (correctly passes now — vacuous default)
✘ All ten page keys are present in all
  HelpText::all() must contain the "page.stream" key.
```
3 of 4 tests fail because HelpText has no `page.*` keys yet — right reason. The 4th (`Nonexistent...`) correctly passes both now and after F's change (it pins the unrelated existing unknown-key contract).

**Kernel** (`SIMPLETEST_DB='mysql://db:db@db:3306/db' php vendor/bin/phpunit -c web/core/phpunit.xml.dist web/modules/custom/do_chrome/tests/src/Kernel/PageHelpRouteMapTest.php`):
```
Error: Class "Drupal\do_chrome\Hook\PageHelp" not found
```
on all 4 tests — the class does not exist yet (F has not created `PageHelp.php`). Valid RED (class-not-found, not a test-authorship bug).

**E2E** (`BASE_URL="https://gm126-page-tooltips.ddev.site" npx playwright test tests/e2e/page-help.spec.ts`), run against the real fully-seeded site:
```
x 1 Anonymous: site-wide stream page ⓘ           — .do-chrome-info.page-help-info: Expected 1, Received 0
x 2 Anonymous: all-groups directory page ⓘ       — same, 0 elements
x 3 Authed (Elena) group Stream tab ⓘ            — same, 0 elements
ok 4 Default-deny: /user/login has 0 triggers    — correctly passes now (vacuous absence)
ok 5 Default-deny: /admin has 0 triggers          — correctly passes now (vacuous absence)
x 6 Keyboard: Tab reaches .page-help-info         — never reached, focused=false
4 failed, 2 passed
```
The 4 failures are exactly the feature-not-built assertion failing (0 elements where 1+ is expected) — not import/setup errors. The 2 passes are negative/absence assertions that are true both before AND after the feature ships (the login/admin pages must never carry the trigger); this is the same pattern already established in this repo by `group-type-homepage.spec.ts`'s fallback-contract describe block.

## Ready for F
Confirmed RED is valid across all three tiers. F may implement `docs/groups/modules/do_chrome/src/Hook/PageHelp.php` (+ append `page.*` keys to `HelpText.php`) against these tests.

**Note for F:** `PageHelp` must expose a public `getRouteMap(): array` method (kernel test `testRouteMapContainsExactlyTenEntries` asserts against it directly) returning the 10-entry map verified against the assembled views config:
```
view.activity_stream.page_1        => page.stream
view.all_groups.page_1             => page.all_groups
view.group_content_stream.page_1   => page.group.stream
view.group_events.page_1           => page.group.events
view.group_members.page_1          => page.group.members
view.my_feed.page_1                => page.my_feed
view.following.page_1              => page.following
view.trending.page_1               => page.trending
view.my_feed_events.page_1         => page.my_feed_events
view.profile_stream.page_1         => page.profile_stream
```
(Live 5 confirmed directly against `config/sync/views.view.*.yml` page-display ids in the assembled repo; W2 route ids for the 5 inert entries follow the same `view.<view_id>.page_1` convention pre-emptively, per brief's own naming pattern — those routes don't exist yet so nothing can execute-verify them, which is exactly the point of pre-registration.)

## Environment notes
- Worktree DDEV project was temporarily renamed to `gm126-page-tooltips` during verification (namespacing per project convention) and reverted back to tracked `.ddev/config.yaml` afterward via `git checkout --`; the container `gm126-page-tooltips` remains running for F/T-green reuse — destroy with `ddev stop --unlist gm126-page-tooltips` when no longer needed, or F can `ddev start` again from this worktree (name is untracked-change-free in git).
- No blockers.
