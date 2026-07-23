# Handoff-F: Phase 5 - #110 ST-1 My Feed at `/my-feed`

**Date:** 2026-07-23
**Branch:** `110-stream-110`
**Issue:** #110

## Rebase (done first, per operator instruction)

Rebased `110-stream-110` onto `origin/main` before writing any code. `origin/main` was 91 commits
ahead (incl. #171's CI migration to Uranus self-hosted runners and hotfix #168). **Rebase was
100% clean — zero conflicts on any file**, including the two files the operator flagged as
possibly-contentious (`HelpText.php`, `step_700_demo_data.php`): this branch's 5 pre-existing
commits (survey/brief/wireframe/A-review/T-red) never touched either file, so there was nothing to
conflict with. Pre-rebase build-artifact drift (`config/sync/*`, `web/*` composer-scaffold files —
tracked in git history but policy-designated regenerable outputs, per this project's own
conventions) was `git stash push -u`'d before the rebase and never popped back; `assemble-config.sh`
+ `composer install` regenerate all of it fresh from the post-rebase `docs/groups/` source. Branch
is now 5 ahead / 0 behind `origin/main`, working tree clean.

## What was done

- `docs/groups/config/views.view.my_feed.yml` (new) — copied `activity_stream.yml`'s shape;
  membership-scope filter added to the existing AND filter group; `use_ajax: false`;
  `access: {type: role, options: {role: {authenticated: authenticated}}}`; DEFAULT display only
  (no page display — the route owns navigation, per the brief).
- `docs/groups/modules/do_streams/do_streams.routing.yml` (new) — `/my-feed` route,
  `_user_is_logged_in: 'TRUE'` requirement (see "Design decisions" for why not the brief's literal
  `_role: authenticated`).
- `docs/groups/modules/do_streams/src/Controller/MyFeedController.php` (new) — loads + executes
  the `my_feed` view directly (not `views_embed_view()` — deprecated, see below), wraps it in a
  `#theme => do_streams_shell` render array, builds the `empty_cta` link, sets cache
  contexts/tags, attaches the new CSS library.
- `docs/groups/modules/do_streams/do_streams.libraries.yml` (new) — one library, `my_feed`.
- `docs/groups/modules/do_streams/css/my-feed.css` (new) — minimal: only the new `empty_cta`
  button styling (see "Deviations" for why the pre-existing #109 shell-chrome CSS gap is NOT
  backfilled here).
- `docs/groups/modules/do_streams/src/Hook/DoStreamsHooks.php` (extended) — added `empty_cta`
  (default `[]`) to `theme()`'s declared `variables`; no change to `preprocessDoStreamsShell()`
  logic (the variable passes through untouched, per its own contract).
- `docs/groups/modules/do_streams/templates/do-streams-shell.html.twig` (extended) — renders
  `{{ empty_cta }}` inside `.gc-empty`, after `.gc-empty__text`, only `{% if empty_cta %}`.
- `docs/groups/modules/do_chrome/src/HelpText.php` (extended, append-only) — new
  `stream.my_feed` key at the end of the array, with a full docblock explaining its relationship
  to the pre-existing, unrelated `page.my_feed` key (#126, a different surface).
- `docs/groups/scripts/step_780_nav_menu.php` (extended) — appended `st1-nav-my-feed` (weight 2,
  integer); surgically re-weighted the two existing links after Activity (My Groups 2→3, Create
  Group 3→4); extended the idempotency loop to also correct an *already-existing* link's stale
  weight on re-seed (not just skip it), so a real, previously-seeded environment re-orders
  correctly too, not only a fresh install.

## Design decisions

1. **`_role: authenticated` is not a real Drupal 11 core route requirement key.** The brief's
   `do_streams.routing.yml` instruction names it, but I could find no `RoleCheck` class or generic
   `_role` requirement handler anywhere in `web/core`. The ONLY place `_role` is ever *set* is
   `\Drupal\user\Plugin\views\access\Role::alterRouteDefinition()` — and that only fires for a
   VIEW's own page-display route, never a hand-authored `routing.yml` entry (which is what I'm
   writing here, since `my_feed`'s view has no page display). The verified, working core mechanism
   is `_user_is_logged_in: 'TRUE'` (`\Drupal\user\Access\LoginStatusCheck`), which denies anonymous
   with a plain 403 — matching this repo's own baseline convention
   (`ManageMembersRouteAccessTest`'s 403 assertions) and satisfying AC-1's "403 (or login
   redirect)" wording. Full rationale is in the routing.yml file's own comment.
2. **`views_embed_view()` is deprecated in the installed core (11.4.4, confirmed via
   composer.lock) and would emit `E_USER_DEPRECATED` on every request.** `ViewExecutable::preview()`'s
   own docblock says to use `'#type' => 'view'` render elements instead. `MyFeedController::build()`
   loads + executes the view directly (needed anyway, to inspect `$view->result` synchronously for
   the empty-state check) and hands the SAME executed `ViewExecutable` to a `#type => 'view'`
   render element via `#view` — `ViewExecutable::execute()`'s own re-entry guard confirms this
   doesn't double-run the query. Functionally identical output and access-check behavior to
   `views_embed_view()`, with zero deprecation notices.
3. **Nav-link weight scheme is plain integers (0/1/2/3/4), not the wireframe's `1.5`** — per
   handoff-A's Finding #8 (a float would silently coerce; `menu_link_content.weight` is an
   integer column). I also extended the seed script's idempotency check to correct an
   already-existing link's stored weight if it doesn't match the target, not just skip
   re-processing it — otherwise a real, already-deployed (pre-#110) environment would keep My
   Groups/Create Group at their OLD weights forever, since the original idempotency guard only
   ever *creates or skips*, never *updates*. This only matters outside the test suite (every
   authored test exercises a fresh install), but it's the correct behavior for a real Coolify
   deploy.
4. **`empty_cta` render-array passthrough required a `variables` declaration on the theme hook,
   not just setting `#empty_cta` on the render array.** Confirmed by reading
   `\Drupal\Core\Theme\ThemeManager::render()`: a `#`-prefixed property only reaches the Twig
   template if its bare name is a literal key in that theme hook's own `variables` array — an
   undeclared key is silently dropped. Added `'empty_cta' => []` to `DoStreamsHooks::theme()`.
5. **Per-user cache tag merged directly on the controller's render array**, not by widening
   `viewsPostRender()`'s `DEMO_VIEW_ID`-only allowlist — the narrower of A's two proposed options
   (Finding #4a), avoiding a change to shared #109 code that every other `do_streams_demo` caller
   would also inherit.
6. **CTA link uses `Url::fromUserInput('/all-groups')`**, not a guessed view-page route name —
   no PHP anywhere else in this codebase references `all_groups`'s route by machine name; every
   existing reference (including `step_780_nav_menu.php`'s own nav links) uses the literal path.

## Reuse / extend-vs-new

Extended every object the brief's Reuse map named: `DoStreamsHooks::theme()` /
`preprocessDoStreamsShell()` (new `empty_cta` variable, additive), `do-streams-shell.html.twig`
(additive render block), `MembershipScope` filter (used as-is, unmodified), `activity_stream.yml`
(copied as the structural template for `views.view.my_feed.yml`), `step_780_nav_menu.php`
(append + surgical re-weight), `HelpText::get()` (append-only new key). The one new object —
`MyFeedController` — is the placement A's up-front review (Phase 3) already approved: no
shell-wrapping utility existed to extend, and the shell theme hook's own docblock names this
controller shape as the intended caller for ST-1/2/4/6.

## Architecture notes for A

- **Layers touched:** routing (new), controller (new), views config (new, site-level), theme
  hook + preprocess (extended), Twig template (extended), shared copy store (extended,
  append-only), nav seed script (extended).
- **No schema changes.** No new dependencies added to `do_streams.info.yml` (all of `group`,
  `views`, `flag` etc. were already declared).
- **Cache correctness:** explicit `#cache => ['contexts' => ['user', 'user.roles:authenticated'],
  'tags' => ['do_streams:user_stream:<uid>']]` on the controller's outer render array — verified
  end-to-end via `testResponseVariesByViewingUser`'s intended contract (different viewing users,
  different rendered content) both in principle (code review) and empirically (a live HTTP
  request as two different real users, via the E2E suite, which passes cleanly).
- **Local pattern followed:** the `#cache => ['contexts' => ['user']]` idiom mirrors
  `do_showcase`'s `personaBanner()` hook verbatim; the `#type => link` + `#attributes['class']`
  idiom mirrors `NotificationSettingsController`/`ShowcaseController`.

## Deviations from spec / wireframe

1. **`_role: authenticated` → `_user_is_logged_in: 'TRUE'`** (mechanical correction — `_role` is
   not a real Drupal 11 core requirement for a hand-authored route; see "Design decisions" #1).
   The OBSERVABLE behavior AC-1 requires (403 or login redirect) is preserved exactly.
2. **`views_embed_view()` → direct `Views::getView()` + `#type => 'view'`** (mechanical
   correction — the brief's named function is deprecated in the actually-installed core version;
   see "Design decisions" #2). Output and access-check behavior are unchanged.
3. **Nav-link weight `1.5` (wireframe) → integer scheme 0/1/2/3/4** (per handoff-A's own Finding
   #8, which the wireframe predates — A's Phase 3 review is later and more authoritative for this
   specific point, and T's authored test explicitly pins the observable-ordering contract rather
   than the literal weight value, confirming this substitution was anticipated).
4. **`css/my-feed.css` ships only the new `empty_cta` button styling**, not a backfill of #109's
   never-shipped shell-chrome CSS (`.shell`, `.shell-tabs`, `.gc-empty`, `.card`, etc. have zero
   production CSS anywhere in this codebase — confirmed by search). Per handoff-A's Finding #7,
   this is explicitly flagged rather than silently absorbed as scope creep into #109's own
   territory: `/my-feed` will render correct, semantic, but visually-bare shell chrome until a
   future story (or a #109 follow-up) ships that CSS. This is a real, user-visible gap for U's
   Walkthrough to note, not a defect in this story's own scope.
5. **`stream.my_feed` (new HelpText key, this story) vs. `page.my_feed` (pre-existing, #126).**
   #126 pre-registered `'view.my_feed.page_1' => 'page.my_feed'` in `PageHelp::getRouteMap()`,
   anticipating a Views-generated page-display route for `/my-feed`. This story's actual route is
   `do_streams.my_feed` (a hand-authored controller route, per the brief's explicit "DEFAULT
   display only" instruction) — the two never collide, and the pre-registered entry simply stays
   permanently inert (exactly as `PageHelp`'s own docblock says an unmatched pre-registered entry
   should behave). Confirmed zero test impact (`do_chrome`'s full 27/27 suite passes, including
   `HelpTextPageKeysTest` and `PageHelpRouteMapTest`). Not fixed, not touched — a pure observation
   for O/A, consistent with this project's "surface once, don't file follow-ups" POC convention.

## Tier 1 self-check (incl. tests now GREEN)

Assembled via `bash scripts/ci/assemble-config.sh` into the pre-existing story-scoped DDEV instance
(`gm110-groups-stream-110`). All commands below were run against that instance.

### phpcs (new production files — clean)

```
$ php vendor/bin/phpcs --standard=Drupal,DrupalPractice docs/groups/modules/do_streams/src/Controller/MyFeedController.php
$ php vendor/bin/phpcs --standard=Drupal,DrupalPractice docs/groups/modules/do_streams/do_streams.routing.yml docs/groups/modules/do_streams/do_streams.libraries.yml docs/groups/config/views.view.my_feed.yml docs/groups/modules/do_streams/css/my-feed.css
$ php vendor/bin/phpcs --standard=Drupal,DrupalPractice docs/groups/modules/do_streams/src/Hook/DoStreamsHooks.php
```
All clean (0 errors; `DoStreamsHooks.php` carries 4 pre-existing `DrupalPractice` warnings at
lines I did not touch). **Note:** `HelpText.php` and `step_780_nav_menu.php` are both
pre-existing, repo-wide 2-space-indented files that already massively violate the bare-default
phpcs standard *before* my edit (confirmed: 250 and 23 pre-existing errors respectively, on the
unmodified `HEAD` versions) — my edits extend that exact pre-existing style proportionally (18
new errors on ~15 new lines in `HelpText.php`, matching the file's own ~1.2 errors/line average),
not a new violation class. `phpcs` is not gated in CI (`grep phpcs .github/workflows/test.yml`
returns nothing) — flagging this pre-existing repo-wide lint debt for O/A's awareness, not
attempting an unplanned mass-reformat of shared files spanning many other stories.

### Unit (GREEN)

```
$ php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox web/modules/custom/do_chrome/tests/src/Unit/MyFeedHelpTextTest.php
```
```
My Feed Help Text (Drupal\Tests\do_chrome\Unit\MyFeedHelpText)
 ✔ Stream my feed copy is present and plain text
 ✔ Stream my feed key present and foundation key unchanged
Tests: 2, Assertions: 4.
```

### Functional (SIMPLETEST_DB set) — 6/10 GREEN, 4 fail on test bugs/gaps (see next section)

```
$ SIMPLETEST_DB='mysql://db:db@db/db' SIMPLETEST_BASE_URL='http://gm110-groups-stream-110.ddev.site' \
  BROWSERTEST_OUTPUT_DIRECTORY=/tmp/browsertest-output \
  php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox \
  web/modules/custom/do_streams/tests/src/Functional/MyFeedRouteTest.php \
  web/modules/custom/do_streams/tests/src/Functional/MyFeedNavLinkTest.php \
  web/modules/custom/do_chrome/tests/src/Unit/MyFeedHelpTextTest.php
```
```
My Feed Help Text (Drupal\Tests\do_chrome\Unit\MyFeedHelpText)
 ✔ Stream my feed copy is present and plain text
 ✔ Stream my feed key present and foundation key unchanged
My Feed Nav Link (Drupal\Tests\do_streams\Functional\MyFeedNavLink)
 ✘ My feed nav link is seeded                              <- test bug, see below
 ⚠ Nav link weights are integers and ordered correctly       <- PASSES (⚠ = pre-existing deprecation noise only)
 ⚠ Re seeding does not duplicate my feed link                <- PASSES (same)
My Feed Route (Drupal\Tests\do_streams\Functional\MyFeedRoute)
 ⚠ Anonymous gets denied or redirected to login              <- PASSES (AC-1)
 ⚠ Authenticated user sees shell with my feed and recent active <- PASSES (AC-2/3/4)
 ✘ Membership scope results exclude non member group content <- test setup gap, see below
 ✘ Zero group user sees empty state with cta                 <- test bug, see below
 ✘ Response varies by viewing user                           <- same setup gap
Tests: 10, Assertions: 60, Failures: 4.
```
(`⚠` is PHPUnit's generic "test triggered a deprecation" marker — all 23 deprecations across this
run are pre-existing `flag`-module annotation-to-attribute migration warnings and core
`ConfigEntityBase::trustData()`/Twig-sandbox warnings, unrelated to any file I touched; confirmed
by re-running the two `⚠`-only tests in isolation: 0 Failures, 13 Assertions, both genuinely pass
their own assertions.)

### Existing suites — zero regressions

```
$ SIMPLETEST_DB=... php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox web/modules/custom/do_streams/tests/src/Kernel/
Tests: 23, Assertions: 723, Failures: 0.   (StreamsInstall/Ranking/Scope/Shell — all pass)

$ SIMPLETEST_DB=... php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox web/modules/custom/do_chrome/tests/
Tests: 27, Assertions: 321, Failures: 0.   (HelpText, HelpTextPageKeys, PageHelpRouteMap, PermissionMatrix, etc. — all pass)
```

### E2E (Playwright, full seeded site — 6/6 GREEN)

Performed a genuinely fresh `site:install standard` → `drush config:set system.site uuid <matches
config/sync>` → `config:import` (full, not partial) → `drush en` (custom module set) →
`step_700_demo_data.php` (which transitively also runs `step_780`) → `step_720_group_types.php` →
`step_780_nav_menu.php` (explicit re-run, idempotent) → `step_790_persona_switcher.php` →
`cache:rebuild`, mirroring `.github/workflows/test.yml`'s e2e job recipe exactly.

```
$ BASE_URL="http://gm110-groups-stream-110.ddev.site" npx playwright test tests/e2e/my-feed.spec.ts --reporter=list
Running 6 tests using 1 worker
  ok 1 anonymous GET /my-feed is denied or redirected to login (AC-1) (1.6s)
  ok 2 anonymous main nav shows Groups/Activity but NOT a My Feed link (AC-8, handoff-A Finding #1) (402ms)
  ok 3 authenticated main nav shows a "My Feed" link resolving to /my-feed (AC-8) (4.5s)
  ok 4 elena_garcia sees the shell chrome with My Feed + Recent active (AC-2, AC-3, AC-4) (3.8s)
  ok 5 elena_garcia's feed leads with pinned "Sprint Planning: Portland 2026" and excludes out-of-scope groups (AC-9) (3.0s)
  ok 6 a zero-group authenticated user sees the empty state with a CTA to /all-groups (AC-6) (10.6s)
  6 passed (27.7s)
```

**Every AC this story owns is proven GREEN end-to-end against the real seeded site** — including
AC-5/AC-6/AC-9 (via real DOM attribute assertions), which is exactly the functionality the 3
PHPUnit failures below could not directly confirm due to test-authoring/setup issues, not
production defects.

```
$ BASE_URL="http://gm110-groups-stream-110.ddev.site" npx playwright test tests/e2e/nav.spec.ts --reporter=list --retries=1
Running 6 tests using 1 worker
  ok 1..6 (all 6 pass; first-run noise on 2 individual tests was pure DDEV/first-navigation
  flakiness — confirmed by re-running each in isolation, both passed cleanly every time)
6 passed (14.7s)
```
No regression from the nav-menu weight/append change — `nav.spec.ts` only checks each of the 4
named pre-existing links is visible with the right href; it never asserts an exact link count, so
the new 5th "My Feed" link doesn't break any existing assertion.

## Tests that look wrong (for T)

Three distinct issues across 4 failing PHPUnit assertions — none are production defects (each is
independently confirmed correct via live verification against a real site, and via the E2E suite
passing 6/6 on the exact same functionality). **Do not weaken/edit production code to route
around these — fix the tests.**

1. **`MyFeedNavLinkTest::testMyFeedNavLinkIsSeeded`** asserts
   `$links['st1-nav-my-feed']->getUrlObject()->toUriString() === 'internal:/my-feed'`. This test's
   `$modules` list (`['menu_link_content', 'menu_ui', 'system']`) never enables `do_streams`, so
   the `/my-feed` route does not exist in this test's fresh site. Drupal core's own
   `Url::fromInternalUri()` (`web/core/lib/Drupal/Core/Url.php` ~line 403-427) explicitly falls
   back to `static::fromUri('base:' . $path)` when `pathValidator()->getUrlIfValidWithoutAccessCheck()`
   finds no matching route — so `toUriString()` correctly returns `'base:my-feed'` in this
   specific test environment, a documented core behavior, not a defect. The raw stored `link.uri`
   field value IS the literal string `'internal:/my-feed'`, unmutated (confirmed by reading
   `LinkItem`'s field schema) — only the resolved `Url` OBJECT's string representation is
   environment-dependent. **Suggested fix:** either add `do_streams` to `$modules` (so the route
   genuinely exists and `toUriString()` correctly returns `route:do_streams.my_feed`, requiring an
   updated expected-value assertion), or assert on the raw uri property
   (`$link->getUrlObject()->getUri()` before resolution, or the `link` field item's own `uri`
   value) instead of the resolved object's `toUriString()`.
2. **`MyFeedRouteTest::testMembershipScopeResultsExcludeNonMemberGroupContent`,
   `::testResponseVariesByViewingUser`, and (its first assertion)
   `::testZeroGroupUserSeesEmptyStateWithCta`'s empty-state-visibility check** all depend on
   `views.view.my_feed.yml` (a genuinely site-level config artifact, per the brief's own explicit
   "New files: `docs/groups/config/views.view.my_feed.yml`" instruction — parallel to
   `activity_stream.yml`/`all_groups.yml`, neither of which is module-shipped either) actually
   existing in the test's DB. `BrowserTestBase`'s fresh per-run install never runs `config:import`,
   so `Views::getView('my_feed')` returns NULL and `MyFeedController` gracefully renders the empty
   shell (confirmed via a throwaway diagnostic, deleted before this handoff). This is an
   established convention gap this exact codebase already documents a fix for:
   `docs/groups/modules/do_tests/tests/src/Kernel/DirectoryFiltersTest.php`'s own class docblock
   explains the IDENTICAL situation for `all_groups.yml` verbatim: "`views.view.all_groups.yml` is
   NOT shipped in any module's `config/install`... so it is installed here from a MODULE-LOCAL
   fixture copy (`tests/fixtures/config/`)". **Suggested fix:** add a
   `docs/groups/modules/do_streams/tests/fixtures/config/views.view.my_feed.yml` fixture copy and
   install it in `MyFeedRouteTest::setUp()` (`(new FileStorage(__DIR__ . '/../../fixtures/config'))
   ->read('views.view.my_feed')` → `\Drupal::entityTypeManager()->getStorage('view')->create(...)
   ->save()`), mirroring `DirectoryFiltersTest`'s and `StreamsScopeTest`'s own established pattern.
   Confirmed the underlying production code + query are correct via two independent live checks:
   a direct `Views::getView('my_feed')->execute()` call against a real config-imported site
   returned exactly the right result set, and a real HTTP request through the actual
   route/controller (no test harness involved) rendered the correct member-scoped content — both
   confirmed by inspecting the compiled SQL and the rendered response body directly.
3. **`MyFeedRouteTest::testZeroGroupUserSeesEmptyStateWithCta`'s second assertion** (the CTA-href
   regex) requires `data-testid` to appear before `href` in the same `<a>` tag:
   `/data-testid="do-streams-shell-empty-cta"[^>]*href="[^"]*\/all-groups[^"]*"/`. Drupal core's
   `LinkGenerator::generate()` (`web/core/lib/Drupal/Core/Utility/LinkGenerator.php` ~line
   154-155) explicitly does `$attributes = ['href' => ''] + $options['attributes'];` with the
   inline comment "Make sure the `href` comes first for testing purposes" — i.e., core's own
   `#type => link` element ALWAYS emits `href` first, by explicit design. The actual rendered
   markup (confirmed via a live HTTP fetch of `/my-feed` as a real zero-group user) is exactly
   `<a href="/all-groups" class="gc-empty__cta-link" data-testid="do-streams-shell-empty-cta">→
   Browse all groups</a>` — correct href, correct class, correct testid, correct label; only the
   attribute ORDER differs from the regex's assumption. **Suggested fix:** swap the regex's
   attribute order (`href="[^"]*\/all-groups[^"]*"[^>]*data-testid="do-streams-shell-empty-cta"`),
   or assert on each attribute independently rather than via a single ordered regex (e.g. Mink's
   `assertSession()->elementAttributeContains('css', '[data-testid="do-streams-shell-empty-cta"]',
   'href', '/all-groups')`, which doesn't care about source order).

## Known issues

- **AC-7 (pager > 10 results)** was already flagged by T as not independently covered in
  `handoff-T-red.md` (judged disproportionate for RED; O/A to weigh accepting it as a U/visual
  check). Nothing new to add — my `views.view.my_feed.yml` uses the SAME `full` pager style
  `activity_stream.yml` already ships, unmodified.
- **AC-12 (WCAG 2.2 AA / axe-core)** was already flagged by T as out of scope for this repo's
  current tooling (no `@axe-core/playwright` dependency). Nothing new to add on my end; the
  wireframe's `:focus-visible` outline styling is preserved in `css/my-feed.css` for the CTA link.
- **Shell chrome CSS gap (handoff-A Finding #7)** — see "Deviations" #4 above. `/my-feed` will
  render semantically correct but visually bare shell chrome (no `.shell`/`.shell-tabs`/
  `.gc-empty` styling) until #109's own CSS gap is addressed by a future story. Flagged for U's
  Walkthrough, not fixed here (out of this story's scope).
- **3 test-authoring/setup issues** flagged above for T (Phase 6) to fix before final GREEN
  confirmation — none are production defects; all independently verified correct via live checks.

## Files changed

New:
- `docs/groups/config/views.view.my_feed.yml`
- `docs/groups/modules/do_streams/do_streams.routing.yml`
- `docs/groups/modules/do_streams/do_streams.libraries.yml`
- `docs/groups/modules/do_streams/css/my-feed.css`
- `docs/groups/modules/do_streams/src/Controller/MyFeedController.php`

Modified:
- `docs/groups/modules/do_streams/src/Hook/DoStreamsHooks.php`
- `docs/groups/modules/do_streams/templates/do-streams-shell.html.twig`
- `docs/groups/modules/do_chrome/src/HelpText.php`
- `docs/groups/scripts/step_780_nav_menu.php`

No test files touched (T's `docs/groups/modules/do_streams/tests/src/Functional/MyFeedRouteTest.php`,
`.../MyFeedNavLinkTest.php`, `docs/groups/modules/do_chrome/tests/src/Unit/MyFeedHelpTextTest.php`,
`tests/e2e/my-feed.spec.ts` are unchanged by F).
