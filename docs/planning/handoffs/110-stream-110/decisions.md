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

## Ready for F
T-red complete, RED is valid. F may implement against the authored tests.
