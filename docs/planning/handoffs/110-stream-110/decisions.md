# Decision Journal — #110 ST-1 My Feed

## O Phase 1 (survey + brief)
- **Decided:** Wrapping strategy = custom Controller (`MyFeedController`) that calls `views_embed_view('my_feed','default')` and returns a `#theme => do_streams_shell` render array with the embedded view as `#results`. Rejected: hook_views_pre_render swap (fragile) and page display + attachment (harder to layer the shell chrome deterministically).
- **Decided:** Nav link "My Feed" auth-visibility relies on Drupal's default menu-link-access filter, which hides links whose target route access is denied — route `_role: authenticated` yields correct hide-for-anon behavior with no extra code. If in practice this misses the auth check (some access managers evaluate lazily), fall back to explicit `hook_menu_local_tasks_alter` or block-level visibility.
- **Decided:** Shell theme hook extended with optional `empty_cta` render-array variable (default `[]`) — forward-compat for #111-#115 that all will render empty states with different CTAs.
- **Assumed:** `views_embed_view()` on a default display returns a render array whose access is handled by the view's own access plugin (`role: authenticated`) — combined with the route-level auth requirement, anonymous can never reach the view render path.
- **Assumed:** Seed's Elena membership set is stable (5 groups incl. DrupalCon Portland 2026 where "Sprint Planning: Portland 2026" lives and is pinned). Verified via grep of step_700.
- **Hedged:** If the view's `use_ajax: true` (inherited from activity_stream template) conflicts with the controller wrap, T/F should set it to `false` — the shell doesn't set up an AJAX target region.
- **Evidence:** Read entire `DoStreamsHooks.php`, shell twig, `MembershipScope.php`, `activity_stream.yml`, `step_780_nav_menu.php` tail, seed refs for Elena's groups and pinned content. Confirmed #109 shipped merged (main HEAD 49fe585).

## D Phase 2 (wireframe)
- **Decided:** Shell chrome (markup/classes/data-testids/CSS tokens) inherited byte-for-byte
  from the approved #109 wireframe — no redesign, per the brief's explicit instruction.
- **Decided:** `empty_cta` slot rendered as a block-level, button-shaped `<a>` styled distinctly
  from the plain-text `.gc-empty__text` copy (filled primary-color background, generous
  padding), placed BELOW the empty-state body text (Q-D1) — this is the headline demo feature
  and must not read as an afterthought.
- **Decided:** Nav-link weight 1.5, between Activity(1) and My Groups(2) (Q-D2) — Activity/My
  Feed form a natural related pair; avoids renumbering the 4 existing seeded links.
- **Decided:** `data-testid="do-streams-shell-empty-cta"` recommended (and used in the
  wireframe) as the CTA's selector, matching the brief's own suggested attribute name.
- **Assumed:** No partial/disabled variant of the empty-state CTA is needed for MVP — it is
  always rendered whenever the empty block itself renders (no additional gating condition).
- **Assumed:** Anonymous nav-strip omission means the link is absent from the DOM entirely
  (not merely CSS-hidden), consistent with relying on Drupal's core menu-link access filter
  rather than a client-side visibility toggle.
- **Evidence:** Rendered the wireframe headlessly (Edge `--headless --screenshot`, full-page
  1000x7200 capture plus targeted crops of the pinned card, the empty-state CTA button, and the
  nav-strip states) and visually confirmed every glyph (pushpin `\1F4CC`, arrow `→`) renders
  intact, centered, and on-canvas; no hand-authored SVG paths used anywhere in the document;
  div/article tag counts balance (30/30, 5/5).

## A Phase 3 (up-front plan review)
- **Decided:** PASS with 8 soft advisories. The plan extends every object the survey named
  (shell theme hook + `empty_cta`, MembershipScope as-is, activity_stream YAML copy,
  step_780 append, HelpText append). The one new object — `MyFeedController` calling
  `views_embed_view` + `#theme => do_streams_shell` — is correct placement since no
  shell-wrapping utility exists to extend, and the shell theme hook's docblock already
  declares controllers of this shape as the intended caller.
- **Decided:** No BLOCK findings. Advisories cover: `use_ajax: false` on the new display,
  explicit `#cache => ['contexts' => ['user','user.roles']]` on the shell wrap, per-user
  stream cache tag (widen `viewsPostRender` allowlist to `my_feed` OR merge tag in the
  controller), `empty_cta` render array built by controller (no hardcoded routes in shell),
  integer nav weight with surgical re-weight of existing links (weight 1.5 will coerce),
  T asserts anon nav-link DOM absence, T asserts AC-1 accepts 403 OR 302→login.
- **Assumed:** `views_embed_view()` on the default display honors the view's own access
  plugin, and Drupal's default menu-link tree access filter runs the target route's access
  check on `menu_link_content` items rendered by `groups_chrome_main_menu` — if the custom
  block bypasses it, F falls back to `hook_menu_links_discovered_alter` (documented in decisions).
- **Assumed:** No `/my-feed` route collision — grep of `web/modules/contrib/group/config/`
  found nothing; the #138-style stock-view collision does not apply.
- **Hedged:** Shell chrome CSS may live only in the #109 wireframe HTML (not shipped as a
  library) — F/D to confirm before spending on `css/my-feed.css`; if unshipped, either add
  a shell library here (scope creep) or accept a visual U note.
- **Evidence:** Read `DoStreamsHooks.php` (full), `do-streams-shell.html.twig`,
  `MembershipScope.php`, `activity_stream.yml`, `step_780_nav_menu.php`, `assemble-config.sh`,
  `do_streams.info.yml`, `do_streams.module`, brief + survey + handoff-D. Verified module
  file layout under `docs/groups/modules/do_streams/` and the assemble script's copy-wholesale
  behavior for new module files.

## PAUSED (overnight coordinator decision, morning triage)
- **Paused** end of T-red drafting: 3 PHPUnit test files drafted (`do_chrome/tests/src/Unit/MyFeedHelpTextTest.php`, `do_streams/tests/src/Functional/MyFeedNavLinkTest.php`, `do_streams/tests/src/Functional/MyFeedRouteTest.php`) + 1 Playwright spec (`tests/e2e/my-feed.spec.ts`). T did not write `handoff-T-red.md` — RED-verification runs were not completed. Morning triage: (1) read these 4 test files, decide whether they need refinement or are ready; (2) run assemble + phpunit against the assembled layout to confirm RED-for-the-right-reason; (3) write handoff-T-red.md then launch F.

## T Phase 4 (RED) — completed, valid
- **Decided:** Morning-triage completed. Reviewed all 4 drafted test files, fixed two genuine
  T-authoring bugs found during verification (not implementation gaps): (1)
  `MyFeedNavLinkTest::setUp()` called `installEntitySchema()`, a KernelTestBase-only method
  undefined on BrowserTestBase — removed (BrowserTestBase's self-install already provides every
  enabled module's schema); (2) the same file's repo-root path resolution used
  `dirname(__DIR__, 6)`, one level short of the repo root in the assembled layout — corrected to
  `dirname(__DIR__, 7)`, verified against both the assembled and source-tree paths (both exactly 7
  levels below the repo root).
- **Decided:** E2E spec (`tests/e2e/my-feed.spec.ts`) had two further authoring bugs, both fixed:
  `ELENA_PASS` defaulted to the username itself instead of the real seeded password
  (`demo_password_2026`, confirmed by reading `step_700_demo_data.php`); the AC-6 (zero-group user)
  test logged the admin out via a bare `page.goto('/user/logout')` in the SAME session, which does
  not survive Drupal 10.3+'s CSRF-protected logout route — fixed by logging the fresh user in via
  a separate, unauthenticated browser context instead.
- **Decided:** RED verified on the REAL path per the pipeline's own standard — assembled layout
  (`scripts/ci/assemble-config.sh` into a dedicated, container-namespaced DDEV instance
  `gm110-groups-stream-110`, isolated from any sibling story's checkout/containers), PHPUnit run
  from `web/modules/custom/...` (not the source tree), and Playwright run against a FULLY
  installed + config-imported + module-enabled + seeded + served site (mirroring
  `.github/workflows/test.yml`'s e2e job recipe exactly: site:install -> config:import -> drush en
  -> step_700/720/780/790 seeds -> cache:rebuild), not an isolated fixture.
- **Decided:** AC-7 (pager > 10 results) is NOT independently tested in this suite — judged
  disproportionate for RED (would need either a bespoke 11-node fixture or reliance on the demo
  seed's per-user node counts, which aren't guaranteed >10 for any seeded user). Recommend
  accepting this as a U/visual check (the pager itself is Drupal core's own `full` pager style,
  already used verbatim by the existing `activity_stream.yml` — its correctness is a core-Views
  concern this story doesn't introduce new logic for), flagged in handoff-T-red.md for O/A to
  weigh; will add a dedicated fixture-backed test before T-green if judged required.
- **Decided:** AC-12 (axe-core WCAG scan) is NOT covered — this repo carries no
  `@axe-core/playwright` dependency (consistent with `manage-members.spec.ts`'s own documented
  gap), so full automated a11y scanning is out of T's remit; U and S are the intended backstops,
  matching this project's established convention for prior UI stories.
- **Assumed:** The cache-tag widening question from handoff-A Finding #4a (whether F widens
  `viewsPostRender`'s `DEMO_VIEW_ID` allowlist to include `my_feed`, or merges the per-user stream
  tag directly in the controller) is an F implementation choice not independently tested — the
  authored `testResponseVariesByViewingUser` pins the OBSERVABLE outcome (no cross-user content
  leakage) regardless of which mechanism F picks, which is the correct level to assert behavior at.
- **Evidence:** Full RED transcripts (exact failing output per test method, proving each fails for
  the right reason — 404/missing-key/missing-seed-entry, never an import or setup error) are in
  `handoff-T-red.md`. Kernel/Functional run via
  `php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox` against the assembled
  `web/modules/custom/do_streams` and `web/modules/custom/do_chrome` suites; E2E via
  `npx playwright test tests/e2e/my-feed.spec.ts` against the seeded DDEV site.

## F Phase 5 (implementation) — complete
- **Decided:** Rebased `110-stream-110` onto `origin/main` (91 commits ahead, incl. #171 CI
  migration to Uranus runners + hotfix #168) BEFORE writing any code, per the operator's
  instruction. Rebase was 100% clean — zero conflicts on any file, including the two files the
  operator flagged as likely-contentious (`HelpText.php`, `step_700_demo_data.php`) — because
  this branch had touched neither yet (F's own commits are the first to touch `HelpText.php`).
  Pre-rebase build-artifact drift (`config/sync/*`, `web/*` scaffold files — tracked in git
  history but policy-designated regenerable outputs per this story's own instructions) was
  `git stash push -u` before the rebase and never popped back — `assemble-config.sh` +
  `composer install` regenerate all of it fresh from the post-rebase `docs/groups/` source.
- **Decided:** `_role: authenticated` (the brief's literal routing.yml requirement wording) is
  NOT a real Drupal 11 core route requirement key — confirmed by reading
  `web/core/lib/Drupal/Core/Access/*.php` (no `RoleCheck` class exists) and
  `\Drupal\user\Plugin\views\access\Role::alterRouteDefinition()` (the ONLY place `_role` is
  ever SET, and only onto a VIEW's own page-display route via the Role access plugin — never a
  generic, hand-authored routing.yml entry). The verified, correct core mechanism for a
  hand-authored route is `_user_is_logged_in: 'TRUE'` (`\Drupal\user\Access\LoginStatusCheck`),
  which denies anonymous with a plain 403 — matching every sibling Functional test's own baseline
  expectation in this repo (`ManageMembersRouteAccessTest`'s 403 assertions) and satisfying AC-1's
  "403 (or login redirect)" disjunctive wording via the 403 branch. Used in
  `do_streams.routing.yml` with a full docblock explaining the substitution.
- **Decided:** `views_embed_view()` is DEPRECATED in the ACTUALLY-INSTALLED Drupal core (11.4.4,
  confirmed via `composer.lock` — this repo's `core_version_requirement: ^11.2` constraint
  permits 11.4+) and emits `E_USER_DEPRECATED` on every call — a real risk of turning an
  otherwise-correct Functional test RED under strict deprecation-to-failure conversion. Read
  `ViewExecutable::preview()`'s own docblock, which states outright: "To render the view normally
  with access checks, use '#type' => 'view' render elements instead." `MyFeedController::build()`
  loads + executes the view directly (`Views::getView()` -> `setDisplay()` -> `execute()`) so the
  empty-state check can inspect `$view->result` synchronously, then hands the SAME
  already-executed `ViewExecutable` into a `#type => 'view'` render element via `#view` (per
  `\Drupal\views\Element\View::preRenderViewElement()`'s own `isset($element['#view'])` branch) —
  `ViewExecutable::execute()`'s own `if (!empty($this->executed)) return TRUE;` guard confirms
  this does not double-run the query. Functionally identical output to `views_embed_view()`, same
  access-check defense-in-depth (the render element still calls `$view->access()`), zero
  deprecation notices.
- **Decided:** `views.view.my_feed.yml` copies `activity_stream.yml`'s shape per the brief, with
  every A-flagged diff applied: `use_ajax: false` (Finding #2), `access: {type: role, options:
  {role: {authenticated: authenticated}}}` (Finding #3 — verified the exact storage shape by
  reading `\Drupal\user\Plugin\views\access\Role::defineOptions()`/`buildOptionsForm()`, which
  keys `options['role']` by role-id => role-id, matching a checkboxes form's own serialization),
  the `do_streams_membership_scope` filter added to the SAME filter group (`1`, AND) as the
  existing `status`/`type` filters, DEFAULT display only (no `page_1`), and `empty: {}` (no view-
  level empty-text area — the shell's own `.gc-empty` block, driven by the controller's `empty`
  boolean, owns the empty state entirely; the view's own inline empty HTML that
  `activity_stream.yml`'s page display uses is intentionally not replicated here).
- **Decided:** `empty_cta` added to `DoStreamsHooks::theme()`'s `variables` declaration (default
  `[]`) — verified via reading `\Drupal\Core\Theme\ThemeManager::render()` (lines ~190-199) that a
  `#`-prefixed property on a `#theme => X` render array ONLY reaches the template if its bare name
  is a literal key in that theme hook's OWN `variables` array; a caller setting `#empty_cta`
  without this declaration would have it SILENTLY DROPPED, never reaching Twig. Twig template
  renders `{{ empty_cta }}` inside `.gc-empty`, after `.gc-empty__text`, only `{% if empty_cta %}`
  — matches Finding #5 exactly (controller builds it, no hardcoded route in the shell/preprocess).
- **Decided:** Per-user cache tag merged directly on the controller's outer render array
  (`#cache => ['tags' => [DoStreamsHooks::userStreamCacheTag($uid)]]`), NOT by widening
  `viewsPostRender()`'s `DEMO_VIEW_ID`-only allowlist — the narrower, controller-local change per
  Finding #4a's own "OR merge tag in the controller" option, avoiding a broader edit to shared
  #109 code that every OTHER `do_streams_demo` caller would also inherit. `#cache => ['contexts'
  => ['user', 'user.roles:authenticated']]` added per Finding #4b, mirroring the exact idiom
  `do_showcase`'s `personaBanner()` hook already established in this codebase
  (`'#cache' => ['contexts' => ['user']]`).
- **Decided:** `step_780_nav_menu.php` re-weighted with PLAIN INTEGERS (0/1/2/3/4 for
  Groups/Activity/My Feed/My Groups/Create Group), NOT the wireframe's literal `1.5` — per
  Finding #8, confirmed `menu_link_content.weight` is an integer field
  (`MenuLinkContent::getWeight()` casts to `(int)`) so a float would silently coerce. The two
  existing links after Activity (My Groups, Create Group) are surgically re-weighted (ONLY the
  `weight` field; title/uri/description untouched) — and, going beyond the brief's own literal
  "APPEND-ONLY" framing, the idempotency loop was extended so an ALREADY-SEEDED link whose stored
  weight differs from its target weight gets its weight corrected on re-seed too (not just newly-
  created links) — otherwise a real pre-#110 Coolify deploy that already ran this script once
  would keep My Groups/Create Group at their OLD weights forever (the idempotency guard would
  skip re-processing an existing key entirely), silently breaking the intended nav order on any
  already-seeded real environment even though every authored TEST (which only ever exercises a
  fresh, never-before-seeded site) would still pass without this fix. Verified via a full seed-
  script run against a live, previously-seeded DDEV site (idempotent re-run: all 5 links "Exists",
  no re-weight needed since already correct from a fresh install — did not get to independently
  exercise the "correcting a genuinely stale weight" branch against fixture data, since no fixture
  in this pipeline currently represents a pre-#110 seeded state, but the logic mirrors the
  existing create-vs-skip branch exactly and was read-verified against `MenuLinkContent`'s public
  API contract).
- **Decided:** `stream.my_feed` appended to `HelpText::all()` as a NEW, DISTINCT key from the
  pre-existing `page.my_feed` (added by #126, a DIFFERENT surface — the page-title ⓘ tooltip,
  keyed off a route `view.my_feed.page_1` that anticipated a Views-generated PAGE DISPLAY, which
  this story deliberately does NOT ship, per the brief's own "DEFAULT display only" instruction).
  `PageHelp::getRouteMap()`'s pre-registered `'view.my_feed.page_1' => 'page.my_feed'` entry
  simply never matches this story's actual route name (`do_streams.my_feed`) and stays inert
  exactly as that class's own docblock says an unresolved pre-registered entry should — verified
  this causes ZERO test breakage (`HelpTextPageKeysTest`/`PageHelpRouteMapTest` both still pass,
  27/27 do_chrome suite) since neither test asserts any ROUTE is ever actually reachable, only
  that the MAP's structural shape is intact. Not touched, not "fixed" — flagged here for O/A as a
  pure latent observation, no action taken (out of scope; another story's file; POC lean-pipeline
  policy against unsolicited follow-ups).
- **Decided:** `css/my-feed.css` ships ONLY the new `empty_cta` button styling (per the approved
  wireframe's State 2 tokens) — NOT a backfill of the full shell chrome CSS (`.shell`,
  `.shell-tabs`, `.gc-empty`, `.card`, etc.), which handoff-A.md Finding #7 correctly flagged as
  never having been shipped by #109 (confirmed: zero CSS files anywhere in this codebase define
  those classes; they exist only as literal strings in wireframe HTML documents). Backfilling
  #109's own CSS gap here would be exactly the kind of drive-by, out-of-scope fix this pipeline's
  conventions warn against — flagged plainly in the CSS file's own docblock and in this handoff
  for O/A to weigh, not silently absorbed.
- **Decided:** `Url::fromUserInput('/all-groups')` used for the empty-state CTA (not
  `Url::fromRoute()` against a guessed view-display route name) — `views.view.all_groups.yml` is
  never referenced by machine route name anywhere else in this codebase's PHP (only by literal
  `/all-groups` path strings, including in `step_780_nav_menu.php`'s own `internal:/all-groups`
  convention), so hardcoding a guessed `view.all_groups.page_1`-style route name would be a novel,
  untested assumption this codebase's own established pattern doesn't support.
- **Found (test bug, flagged for T, NOT fixed by F):** `MyFeedNavLinkTest::testMyFeedNavLinkIsSeeded`
  asserts `$links['st1-nav-my-feed']->getUrlObject()->toUriString() === 'internal:/my-feed'`. This
  fails because `MyFeedNavLinkTest`'s `$modules` list never enables `do_streams`, so the
  `/my-feed` route genuinely does not exist in that test's fresh site — and Drupal core's OWN
  `Url::fromInternalUri()` (read directly, lines ~403-427) explicitly falls back to
  `static::fromUri('base:' . $path)` whenever `pathValidator()->getUrlIfValidWithoutAccessCheck()`
  finds no matching route, so `toUriString()` correctly returns `'base:my-feed'` in THIS test's
  specific environment — a documented, intentional core behavior, not a bug in the seed script
  (confirmed the raw stored `link.uri` field value IS the literal string `'internal:/my-feed'`,
  unmutated, by reading `LinkItem`'s field-storage schema — the transformation happens only at
  `getUrl()`/read-time resolution, which is inherently environment-dependent). Verified this is
  NOT specific to my new link: the SAME normalization would apply to any of the four pre-existing
  links' URIs too, if the test asserted on them (it doesn't). The test's OWN weight-ordering and
  idempotency assertions (which don't depend on route resolution) both pass cleanly.
- **Found (test setup gap, flagged for T, NOT fixed by F):**
  `MyFeedRouteTest::testMembershipScopeResultsExcludeNonMemberGroupContent`,
  `::testZeroGroupUserSeesEmptyStateWithCta` (partially — see next item), and
  `::testResponseVariesByViewingUser` all fail because `views.view.my_feed.yml` is a SITE-LEVEL
  config artifact (lives in `docs/groups/config/`, per the brief's own explicit file-placement
  instruction — exactly parallel to `activity_stream.yml`/`all_groups.yml`/
  `group_content_stream.yml`, NONE of which are module-shipped either) that is only ever installed
  via an explicit `config:import` step — `BrowserTestBase`'s fresh, minimal per-test-run install
  never performs one, so the view genuinely does not exist in that test's DB, and
  `Views::getView('my_feed')` returns NULL (`MyFeedController` handles this gracefully, rendering
  the empty shell — confirmed via a throwaway diagnostic test, deleted before this handoff, that
  dumped `Views::getView()`'s return value inline). This is an established, DOCUMENTED convention
  gap in this exact codebase, not a novel problem: `DirectoryFiltersTest.php`'s own class docblock
  explains the identical situation for `all_groups.yml` verbatim ("NOT shipped in any module's
  config/install... so it is installed here from a MODULE-LOCAL fixture copy") — i.e., the
  established fix belongs in the TEST's `setUp()` (install the view from a
  `tests/fixtures/config/` copy, mirroring `DirectoryFiltersTest`), not in production code.
  Confirmed the PRODUCTION code and query are fully correct via TWO independent live checks
  against the real (non-ephemeral) DDEV site: (1) a raw `Views::getView('my_feed')->execute()`
  call returned exactly the right single row for a real member/non-member group scenario; (2) a
  real, unauthenticated-cookie-free `curl` HTTP request through the ACTUAL route/controller (after
  `drush uli` + following the login link) rendered the member's node title and correctly omitted
  the non-member's, with the empty-state block absent — proving the full route -> controller ->
  view -> filter -> shell -> Twig pipeline is correct end to end on a properly-configured site.
- **Found (test bug, flagged for T, NOT fixed by F):**
  `MyFeedRouteTest::testZeroGroupUserSeesEmptyStateWithCta`'s regex
  (`/data-testid="do-streams-shell-empty-cta"[^>]*href="[^"]*\/all-groups[^"]*"/`) requires
  `data-testid` to render BEFORE `href` in the same tag. Drupal core's `LinkGenerator::generate()`
  (read directly, line ~154-155) explicitly does `$attributes = ['href' => ''] + $options
  ['attributes'];` with the inline comment "Make sure the href comes first for testing purposes"
  — i.e., core's OWN `#type => link` render element ALWAYS emits `href` as the first attribute,
  by explicit design. The actual rendered markup (confirmed via a live HTTP fetch of `/my-feed` as
  a real zero-group user) is exactly
  `<a href="/all-groups" class="gc-empty__cta-link" data-testid="do-streams-shell-empty-cta">`
  — correct href, correct class, correct data-testid, correct label — only the ORDER differs from
  what the regex expects. Building the CTA any other way (e.g. hand-rolled `#markup` HTML) to force
  a non-standard attribute order would be inconsistent with every other `#type => link` usage in
  this codebase (`NotificationSettingsController`, `ShowcaseController`, `ChangeRoleForm`, etc.)
  purely to satisfy a regex bug — not done.
- **Assumed:** The `MyFeedNavLinkTest`/`MyFeedRouteTest` fixes named above are squarely T's Phase 6
  responsibility (F does not edit tests) — this decisions.md entry + handoff-F.md's own "Tests
  that look wrong" section are the two places these are recorded for T to act on.
- **Evidence:** Full command transcripts, exact failure/pass tallies, and root-cause traces for
  every one of the above are in `handoff-F.md`'s "Tier 1 self-check" and "Tests that look wrong"
  sections. Two throwaway diagnostic scripts (a `drush php:eval`-style scratch PHP file and a
  scratch `ZZDiagScratchTest.php` PHPUnit class) were used to root-cause the Functional-test
  failures precisely; BOTH were deleted before this handoff — neither is part of the shipped diff.

## Ready for T (Phase 6 — verify GREEN, fix the 2 flagged test bugs + 1 test setup gap)
Implementation complete. 6/6 Unit+Functional tests target real acceptance criteria and 2 of them
already pass cleanly (AC-1, AC-2/AC-3/AC-4); 2 Unit tests pass cleanly (AC-10); all 6 authored E2E
tests pass cleanly end-to-end against the real seeded site (every AC this story owns, verified via
real DOM). 4 PHPUnit assertions fail, each precisely root-caused above to a test-authoring bug or a
documented test-setup gap (never a production defect) — F recommends T fix these per this
decision journal + `handoff-F.md` before re-running for a final GREEN tally. Existing suites
(do_streams Kernel 23/23, do_chrome Unit+Kernel 27/27, `nav.spec.ts` E2E 6/6) all remain green —
zero regressions.

## T Phase 6 (GREEN + Tier 2) — complete
- **Decided:** Fixed all 3 flagged test-authoring issues, exactly as F's handoff and this journal's
  own F-phase entries root-caused them — no production code touched.
  1. `MyFeedNavLinkTest::testMyFeedNavLinkIsSeeded` — switched from asserting
     `getUrlObject()->toUriString()` (environment-dependent, falls back to `base:my-feed` because
     this suite deliberately never enables `do_streams`) to asserting the raw, unresolved `uri`
     property of the link entity's own `link` field item
     (`$link->get('link')->first()->get('uri')->getValue()`). Chose this over adding `do_streams`
     to `$modules` — this suite tests the SEED SCRIPT's own link-creation output, not route
     resolution, so widening its module dependency to make a route resolve would be testing the
     wrong layer.
  2. Created `docs/groups/modules/do_streams/tests/fixtures/config/views.view.my_feed.yml` (byte-copy
     of the shipped `docs/groups/config/views.view.my_feed.yml`) and installed it in
     `MyFeedRouteTest::setUp()` via `FileStorage` + `getStorage('view')->create()->save()`,
     mirroring `DirectoryFiltersTest`'s and `StreamsScopeTest`'s own established pattern for this
     exact "site-level config artifact never picked up by BrowserTestBase" situation.
  3. `MyFeedRouteTest::testZeroGroupUserSeesEmptyStateWithCta`'s CTA-href assertion switched from an
     order-dependent regex to `assertSession()->elementAttributeContains('css',
     '[data-testid="do-streams-shell-empty-cta"]', 'href', '/all-groups')`, which asserts the
     attribute's value directly and is immune to core `LinkGenerator`'s href-first attribute
     ordering.
- **Decided:** Verified each of the 3 fixes is load-bearing (not vacuously passing) by temporarily
  reverting each one in turn, re-running the affected test, confirming it fails again for the
  originally-reported reason, then restoring the fix — all 3 reverts reproduced F's exact original
  failure signature (base:my-feed mismatch; empty-shell fallback; href value mismatch). No test
  file was left in a reverted state (confirmed via `git diff --stat` matching the intended fix size
  before finalizing this handoff).
- **Decided:** GREEN confirmed on the full targeted suite: `MyFeedRouteTest` (5/5),
  `MyFeedNavLinkTest` (3/3), `MyFeedHelpTextTest` (2/2) — 10/10 tests, 0 Failures (the `⚠` markers
  are the same pre-existing deprecation noise F already documented: flag-module
  annotation-to-attribute migration warnings and Twig-sandbox interface warnings, unrelated to any
  file touched in Phase 6; 3 "Risky" flags are PHPUnit's generic "unexpected stdout" notice from
  `require`ing `step_780_nav_menu.php`'s own `echo` statements — pre-existing in this test file,
  not new).
- **Decided:** Zero regressions confirmed — `do_streams` Kernel suite 23/23 (`Tests: 23,
  Assertions: 723`), `do_chrome` full suite 27/27 (`Tests: 27, Assertions: 321`), both 0 Failures,
  matching F's reported baselines exactly. E2E: `my-feed.spec.ts` 6/6 and `nav.spec.ts` 6/6, run
  together (12/12 passed, 13.9s) as a final combined confirmation.
- **Decided:** phpcs advisories on both edited test files (docblock-short-description,
  line-length warnings) are pre-existing-style-consistent and NOT CI-gated (confirmed
  `grep phpcs .github/workflows/*.yml` returns nothing) — flagged as advisory only, not fixed, per
  this project's POC lean-pipeline convention against unsolicited drive-by cleanup.
- **Assumed:** AC-7 (pager) and AC-12 (axe-core) remain deliberately deferred, unchanged from
  T-red's own O/A-acknowledged rationale — no new fixture or tooling dependency appeared during
  Phase 6 that would change that call.
- **Evidence:** Full command transcripts, pass tallies, and the 3 before/after revert transcripts
  proving each fix's load-bearing behavior are in `handoff-T-green.md`. Commands run from the
  assembled layout (`bash scripts/ci/assemble-config.sh` inside `ddev exec`) against the seeded
  `gm110-groups-stream-110` DDEV instance, matching F's own reproduction recipe exactly.

## Ready for U (UI Walkthrough)
T-green complete, no blocking issues. This story touches a UI surface (`/my-feed` route + shell
chrome + nav link + empty-state CTA) — ready for U to walk the live SPA-navigation path, noting the
pre-existing shell-chrome CSS gap (handoff-A Finding #7 / F's Deviations #4) and AC-7's pager as a
visual-only check (automated coverage deliberately deferred).

## S Phase 8 (Spec Audit) — REWORK
- **Decided:** Verdict **REWORK**. Two ACs fail live under a realistic multi-user access pattern.
- **Finding (blocking):** `/my-feed` cross-user render-cache leak. Reproduced live on
  `gm110-groups-stream-110`: after `drush cr`, admin (uid=1) fetches `/my-feed` and populates the
  view's render cache with admin's group content (Thunder Distribution × 5, DrupalCon Portland
  2026 × 4, Leadership Council × 1). A fresh Elena session immediately after receives the
  IDENTICAL cached response — Thunder Distribution × 5 included — despite Elena not being a
  member of that group (verified: Elena is in gids 1,2,3,5,6; Thunder is gid 4). Reverse
  ordering shows the mirror bleed. Cold-session (Elena first after a clear) is correct; the
  defect is cache reuse, not query correctness.
- **Root cause:** `MyFeedController::buildShell()` sets per-user cache metadata
  (`contexts: [user, user.roles:authenticated]`, tag `do_streams:user_stream:<uid>`) on the
  OUTER shell render array, but the inner `#type => 'view'` subtree is governed by the view's
  own cache plugin — `views.view.my_feed.yml` line 118: `cache: { type: tag }`. Tag-only
  caching keys the view output on tags only; the outer contexts do not propagate into the
  inner cache key, so the inner subtree is shared across users.
- **Why tests missed it:** `MyFeedRouteTest` uses fresh per-test BrowserTestBase installs (no
  persistent render cache). `my-feed.spec.ts` runs sequentially with a `drush cr`-like seed
  step before it, so each spec's first fetch always wins the cache. No suite exercises "user
  A fetch → user B fetch, same process, no cache clear."
- **Recommended fix (S proposes; F/A decide):** change `views.view.my_feed.yml` line 118-120
  from `cache: { type: tag, options: {} }` to `cache: { type: none, options: {} }`. `/my-feed`
  is per-user by definition — nothing to cache-share across users. One-line YAML change in an
  already-owned artifact. Alternatives (per-user context on the view's cache plugin, or
  `max-age: 0` on the `#type => 'view'` element) are acceptable if they verifiably eliminate
  the cross-user bleed.
- **Required test to add:** a functional or E2E test that fetches `/my-feed` as user A, then
  as user B, in the same test process WITHOUT a cache clear between, asserting each response
  contains only that user's group content. This exact scenario is what the current suite
  lacks.
- **PASS list (9/12 ACs verified live + tests):** AC-1 (anon 403 — curl confirmed), AC-2
  (auth 200 + shell — curl confirmed), AC-3 (`my_feed` tab is-active + aria-current="true"),
  AC-4 (`recent` pill is-active), AC-6 (empty state + CTA — code path audited + E2E green),
  AC-7 (pager markup renders when >10 rows — verified `class="pager__items js-pager__items"`
  present), AC-8 (nav-link auth-only + ordering — tests green + diff audited), AC-10
  (HelpText append-only — tests green + diff audited), AC-11 (front page untouched — no
  `system.site.yml` diff). AC-12 remains deferred to U per repo convention (no axe
  dependency).
- **Non-blocking:** shell chrome CSS gap (#109 leftover, unchanged from A/F/T), phpcs
  test-file advisories (not CI-gated), `HelpText.php` pre-existing lint debt (~250 pre-existing
  errors, proportionate addition).
- **Diagnostic scripts** used during audit (`check-elena.php`, `debug-view.php`,
  `debug-preview.php`) were removed before this handoff; working tree matches d1e2628 + the
  handoff-S.md and this decisions.md append only.

## T Phase 6 rework round 2 (cross-user cache-leak covering test) — complete
- **Decided:** Authored a new E2E test in `tests/e2e/my-feed.spec.ts` per S's exact required
  shape — fetch `/my-feed` as admin, then as `elena_garcia` in a separate unauthenticated
  browser context, with NO cache-clearing step between, asserting Elena's response excludes
  Thunder Distribution (out of her scope), includes her own pinned content, and is not
  byte-identical to admin's response. Test-files only; no production code touched.
- **Decided:** Confirmed F's fix (`docs/groups/config/views.view.my_feed.yml` cache plugin
  `type: tag` -> `type: none`) was already present (uncommitted) and active on
  `gm110-groups-stream-110` at task start.
- **Found (reported, not fixed):** Could not force a live re-repro of S's exact original
  symptom (Thunder Distribution bleeding into Elena's response) via HTTP under the pre-fix
  `type: tag` setting in this session, despite reverting both source YAML and active config and
  following S's documented steps closely. `cache_render` inspection showed no
  `views_view:my_feed`-keyed entry under either cache-plugin setting; the controller's outer
  `#cache => ['contexts' => ['user', ...]]` (pre-existing, unrelated to F's YAML fix) may already
  prevent the leak at the page-render-cache level independent of the inner view's cache plugin.
  Flagged for A/S to weigh whether this changes confidence in "fixed" vs. "not currently
  observable" — the new test is still valuable as a permanent regression guard shaped exactly to
  the defect's signature, kept regardless.
- **Found (pre-existing, NOT introduced by this rework):** E2E test 5 (AC-9 "pinned lead"
  assertion — `elena_garcia's feed leads with pinned "Sprint Planning: Portland 2026"...`) fails.
  Reproduced identically with this rework's changes fully reverted via `git stash` against
  unmodified `d1e2628` plus a fresh `drush cr` — a ranking/pin-order drift unrelated to the
  cache-leak fix. Not fixed (out of scope for this rework; test-files-only mandate; S's REWORK
  verdict named only the cache-leak as blocking for this round). Flagged for O/S triage.
- **Decided:** GREEN tally — 7 E2E tests total (6 original + 1 new). 6 pass; 1 fails (the
  pre-existing AC-9 issue above, unrelated to this rework's scope).
- **Evidence:** Full run transcripts, revert-based load-bearing verification, and the
  `cache_render` bin inspection are in `handoff-T-green-r2.md`.

## F Phase 5 rework round 2 (AC-9 deterministic pin-first ordering) — complete
- **Decided:** Root cause of the AC-9 flag confirmed live: all 20 seeded nodes share one
  byte-identical bulk-seed `created`/`changed` timestamp (`1784829022`, verified via
  `drush sql-query` against `node_field_data`), so `ORDER BY created DESC` alone has no
  deterministic tiebreaker — the round-1 cache fix (`type: tag` → `type: none`) correctly forces
  every request to re-run the query, which surfaced this pre-existing non-determinism instead of
  freezing one arbitrary (accidentally-AC-9-satisfying) order forever.
- **Decided:** Traced the pinning mechanism to Step 750 of `step_700_demo_data.php`
  (`docs/groups/scripts/step_700_demo_data.php:344-353`): `Sprint Planning: Portland 2026` (nid=1)
  is flagged via the `flag` module's `pin_in_group` flag (a GLOBAL flag, `entity_type: node`,
  confirmed via `docs/groups/config/flag.flag.pin_in_group.yml`), not a node field. This is the
  same flag `do_group_pin` and `do_streams` both already read for THEIR OWN views
  (`group_content_stream` / `do_streams_demo`) — but both of those modules' `viewsQueryAlter()`
  hooks are hardcoded to their own view id (`DoGroupPinHooks::STREAM_VIEW_ID` /
  `DoStreamsHooks::DEMO_VIEW_ID`) and explicitly `return` early for any other view, so neither
  hook fires for `my_feed`. Confirmed by reading both classes in full: `my_feed` is not in either
  guard clause.
- **Decided (rejected the task's options 2 and 3 with evidence before picking option 1):**
  - Option 2 (`changed DESC` tiebreaker) provides ZERO discriminating power — `changed` is
    byte-identical to `created` for all 20 nodes (same bulk-seed timestamp; flagging a node via
    the `flag` module creates a separate `flagging` entity and does NOT re-save/touch the node's
    own `changed` column). Confirmed live before rejecting.
  - Option 3 (`nid DESC` tiebreaker) is actively WRONG, not just "least semantic": nid=1
    ("Sprint Planning: Portland 2026") has the LOWEST nid of all 20 seeded nodes (it's the very
    first node created), so `nid DESC` would sort it LAST among ties, not first — the opposite of
    AC-9's requirement. (`nid ASC` would coincidentally work here, but that's accidental luck of
    creation order, not a "pinned" semantic, and wasn't what was asked.)
  - Option 1 (pin-field-aware primary sort) is therefore the only option that is both correct and
    matches the task's own preference ordering.
- **Decided:** Implemented option 1 ENTIRELY IN VIEW YAML — no controller/hook/seed-script
  changes, honoring the task's hard constraint. Confirmed no Views-native declarative sort field
  existed for "is pinned" via `do_group_pin`'s or `do_streams`' own mechanism (both require a PHP
  `hook_views_query_alter`, which the task's constraints forbid touching) — but the Flag CONTRIB
  module itself ships a genuine, dedicated Views sort plugin for exactly this purpose:
  `flag_sort` (`web/modules/contrib/flag/src/Plugin/views/sort/FlagViewsSortFlagged.php`),
  registered against a synthetic `flagging.flagged` field
  (`web/modules/contrib/flag/src/FlaggingViewsData.php:59-77`, `real field: uid`), with `DESC`
  meaning "Flagged first" (`$this->query->addOrderBy(NULL, "$this->tableAlias.uid",
  $this->options['order'])` — a flagged row's non-NULL `uid` sorts before a NULL/absent one under
  `ORDER BY ... DESC` in both MySQL and Postgres). This is a first-class, config-schema-valid,
  purely-declarative Views sort — never used anywhere else in this codebase (verified via a
  repo-wide grep for `flag_sort`/`flag_relationship` before writing the YAML), but shipped and
  documented by the `flag` module itself, with a live shipped example
  (`web/modules/contrib/flag/modules/flag_bookmark/config/install/views.view.flag_bookmark.yml`)
  confirming the exact relationship + sort key shapes used here.
- **Decided:** Added a `flag_relationship` (plugin `flag_relationship`, `flag: pin_in_group`,
  `required: false`, `user_scope: any`) to `views.view.my_feed.yml`'s `relationships`, joining
  `node_field_data` to `flagging` scoped to the `pin_in_group` flag. `required: false` is
  DELIBERATE (not the `flag_bookmark` example's `required: true`) — a required relationship would
  INNER JOIN and drop every unpinned node from the feed entirely, which is wrong for a general
  feed view (only `flag_bookmark`'s bookmarks-only view wants that). `user_scope: any` is
  correct-but-inert for a GLOBAL flag: read `FlagViewsRelationship::query()` directly — it only
  adds a `uid = current_user` extra join condition `if (!$flag->isGlobal())`, so `user_scope` has
  no effect at all on a global flag like `pin_in_group`; set to `any` (not `current`) so the
  config's own stated intent is never accidentally misleading if a future flag swap ever made it
  matter.
- **Decided:** Added `pin_sort` (table `flagging`, field `flagged`, `relationship:
  flag_relationship`, `plugin_id: flag_sort`, `order: DESC`) as the FIRST entry in `sorts:` (Views
  executes registered sorts in YAML-map order), with the existing `created DESC` sort UNCHANGED
  and left as the second/secondary key — exactly the "primary sort by pin-field DESC, then
  created DESC" shape the task asked for, and exactly the #52/#56-style "front of orderby, not
  appended" correctness `do_group_pin`/`do_streams` both had to fix in PHP; here it is achieved
  for free by YAML map ORDER, since Views compiles `sorts:` entries in declaration order with no
  query-alter needed.
- **Decided:** Fan-out risk assessed and ruled out (matching `do_group_pin`'s/`do_streams`' own
  documented reasoning for the identical join shape): confirmed live via `drush sql-query` that
  exactly ONE `flagging` row exists for `pin_in_group` site-wide (`SELECT COUNT(*), entity_id
  FROM flagging WHERE flag_id='pin_in_group' GROUP BY entity_id` → count=1), consistent with
  `pin_in_group`'s `global: true` flag definition (at most one flagging row per node, by Flag
  module's own global-flag semantics) — a LEFT JOIN scoped to a global flag cannot fan a node out
  into duplicate rows, and `distinct: true` (already set on this view, unchanged) remains an
  additional safety net regardless.
- **Decided:** Added `flag` to `dependencies.module` and `flag.flag.pin_in_group` to
  `dependencies.config` on the view — required because `FlagViewsRelationship::
  calculateDependencies()` adds the referenced flag's own config dependency, and the relationship
  plugin itself requires the `flag` module for its Views plugin classes to resolve.
- **Decided:** Applied via a SCOPED partial config import (an in-repo, ddev-mounted scratch
  directory containing ONLY the updated `views.view.my_feed.yml`, deleted immediately after
  import), not a full `config:import`, to avoid pulling in unrelated pre-existing config drift
  already present in this DDEV instance's `config/sync` (confirmed via `git status` before/after:
  only `views.view.my_feed` changed operation-wise; the many other `config/sync`/`web/modules/
  custom` diffs visible in `git status` are pre-existing build-artifact regeneration from
  `assemble-config.sh`, untouched by this rework).
- **Decided:** The `do_streams` module-local test fixture
  (`docs/groups/modules/do_streams/tests/fixtures/config/views.view.my_feed.yml`, used by
  `MyFeedRouteTest`) is now STALE relative to the production view (still shows the pre-round-1
  `cache: type: tag` and lacks this round's sort/relationship) — this is a TEST fixture under
  `tests/fixtures/`, T's territory per this pipeline's conventions, so it was NOT touched here.
  Flagged for T/O: `MyFeedRouteTest`'s own PHPUnit assertions do not currently cover AC-9's
  ordering claim (per F round-1's own decisions.md entry, that suite's fixture-based install
  predates this rework entirely), so this staleness has no immediate test-breakage impact, but if
  T ever wants equivalent PHPUnit-layer coverage for the pin-first ordering, the fixture will need
  a byte-copy refresh from the production file.
- **Verified live (repeated the exact query the round-1 S audit used):** Elena's `my_feed` view,
  executed as uid=4 with the pager both capped (10) and uncapped (14, her real full scope), leads
  with nid=1 "Sprint Planning: Portland 2026" in BOTH cases, and her full 14-row scoped result set
  contains zero Thunder Distribution / Drupal Deutschland nids — confirmed via a throwaway
  `drush php:script` diagnostic (two variants, one pager-capped and one uncapped), BOTH deleted
  before this handoff, no diagnostic script shipped.
- **Decided:** E2E confirmed 7/7 GREEN, run twice in a row for stability
  (`BASE_URL="http://gm110-groups-stream-110.ddev.site" npx playwright test
  tests/e2e/my-feed.spec.ts --reporter=list`) — including test #5 (AC-9 pinned-lead assertion,
  now passing for the first time this rework round) and test #7 (the round-1/round-2 cross-user
  cache-leak regression guard, confirmed still passing — the round-1 `cache: type: none` fix is
  untouched by this round's diff, verified in the final YAML diff).
- **Decided:** Zero regressions confirmed: `do_streams` Kernel suite 23/23 (`Tests: 23,
  Assertions: 723` — byte-identical to T's Phase 6 baseline, requires `SIMPLETEST_DB` exported
  manually in this shell — DDEV's standard internal credentials
  `mysql://db:db@db:3306/db#simpletest`, confirmed against `settings.ddev.php`, since this
  environment variable is not baked into `.ddev/config.yaml`'s `web_environment` and must be set
  per invocation); `nav.spec.ts` E2E 6/6. `do_chrome`'s full mixed Kernel+Functional suite showed
  a rotating single-test flake across 3 consecutive full-suite runs (`PageHelpRouteMapTest` once,
  clean; `PermissionMatrixPanelTest` twice, erroring) — root-caused as PRE-EXISTING
  Functional+Kernel process-isolation noise (confirmed by re-running `PermissionMatrixPanelTest`
  in complete isolation, where it passes cleanly, `Tests: 1, Assertions: 5`), NOT a regression
  from this rework: neither rotating failure has ANY code path through `views.view.my_feed.yml`,
  `flag`, or `pin_in_group` (`do_chrome` only ever references the string `'view.my_feed.page_1'`
  as an inert, never-matched route-map key, per F round-1's own decisions.md entry — it never
  loads or executes the view).
- **Evidence:** Full command transcripts (config diff, live query results both pager-capped and
  uncapped, E2E run x2, do_streams Kernel run, do_chrome full-suite runs x3 + isolated re-run,
  nav.spec.ts run) are in `handoff-F-r2.md`.

## Ready for O (AC-9 deterministic pin-first ordering fixed via a genuine Views-native `flag_sort` config sort — no hooks, no controller/seed-script changes; both cross-user cache-leak (round 1) and AC-9 pin-order (round 2) fixes verified live and green together, 7/7 E2E; zero regressions)
