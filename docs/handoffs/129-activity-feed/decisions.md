# Decisions log — #129 ST-7 Activity feed rendering

## Phase 4 (T-red) — 2026-07-23

- **Decided:** Author 4 kernel test files + 1 E2E spec, split by concern (render/interleaving,
  aggregation algorithm, comment snippet transform, views-data registration) rather than one
  monolithic kernel test, matching the brief's own AC numbering and avoiding duplicate coverage of
  the same behavior at more than one tier.
- **Decided:** `ActivityMembershipScope`'s registered Views filter id is
  `do_activity_feed_membership_scope`, derived from do_streams' own `do_streams_membership_scope`
  naming convention (`<module>_membership_scope`) since the brief did not pin an exact id — recorded
  as a deviation in handoff-T-red.md for F to treat as the pinned contract.
- **Decided:** Aggregation (AC-2a/b/c) and comment-snippet (AC-5) tests call the
  `ActivityAggregator`/`ActivityRowBuilder` services DIRECTLY (not through the controller's render
  array), since both are pure-PHP transform units cheaper to pin in isolation; AC-1's interleaving
  test (`ActivityFeedRenderTest`) still exercises one aggregated case end-to-end through the
  controller so the two tiers are not redundant with each other.
- **Assumed:** `ActivityAggregator::aggregate(array $messages): array` accepts a PRE-FILTERED list
  of messages of one aggregable template (matching how the tests build fixtures) rather than the
  full mixed-template result set with internal filtering. Flagged as an explicit spec ambiguity in
  handoff-T-red.md — if F's implementation expects the alternate shape, this needs a joint
  conversation before I silently rewrite the test to match.
- **Assumed:** A "new" E2E fixture drush script (`step_795_activity_feed_e2e_fixture.php`) is the
  right mechanism per the brief's own explicit menu of options (drush endpoint vs. `test.setup.ts`
  vs. companion backfill script) — chose the backfill-script-style option since it matches every
  existing fixture convention in the repo (step_700/770/790) and can pin exact `Message.created`
  offsets an HTTP-driven approach could not guarantee precisely enough to avoid flaking the
  aggregation shape.
- **Hedged:** Did not attempt to guess `ActivityFeedController`'s exact render-array row-model `type`
  vocabulary beyond what AC-1/AC-3/AC-4 need (`social_join`, `content_card`, `aggregated`) — the
  brief's six-value vocabulary vs. the wireframe's three-testid vocabulary is flagged as a
  non-blocking ambiguity for F rather than resolved unilaterally.
- **Evidence:** RED confirmed for every kernel test file — identical failure
  (`Class "Drupal\Tests\do_activity_feed\Kernel\ActivityFeedKernelTestBase" not found`), traced to
  Drupal's PHPUnit bootstrap requiring a real `.info.yml` to register a module's test namespace
  (confirmed by reading `web/core/tests/bootstrap.php`'s `drupal_phpunit_find_extension_directories()`).
  Cross-checked the harness itself was sound by running `do_streams`' own merged, working
  `StreamsScopeTest.php` with the identical `SIMPLETEST_DB` invocation — 7/7 green. E2E spec
  confirmed to `--list` cleanly (4/4 tests, no parse error) via `npx playwright test --list`.

## Phase 5 (F-implement) — 2026-07-23

- **Decided:** `ActivityAggregator::aggregate()` implements PAIRWISE-CONSECUTIVE folding by
  tracking only the PREVIOUS message's `created` time in the currently-open bucket (never the
  bucket's first/anchor message) — verified by hand against all three AC-2 boundary cases before
  running the suite (t=0/5h/13h → two buckets [1,2]; t=0/5h/10h → one bucket of 3), then confirmed
  by the actual (post-fixture-fix) kernel run.
- **Decided:** `ActivityFeedController` extends `ControllerBase` (not a plain autowired class) so
  `\Drupal::classResolver(ActivityFeedController::class)` — the exact resolution path both kernel
  test files use — passes the container through via `ContainerInjectionInterface::create()`, per
  `Drupal\Core\DependencyInjection\ClassResolver::getInstanceFromDefinition()`'s own fallback logic
  (a non-`ContainerInjectionInterface` class is `new`'d with ZERO arguments, which would break DI
  entirely for a plain class).
- **Decided:** The constructor-promoted entity-type-manager property is named
  `$entityTypeManagerService`, NOT `$entityTypeManager` — `ControllerBase` already declares its own
  non-readonly `protected $entityTypeManager` (lazily populated by its own accessor method); a
  `readonly` property of the same name in the child class fatals at class-load
  ("Cannot redeclare non-readonly property ... as readonly"). Caught by the kernel run itself
  (`ActivityFeedRenderTest`'s second/third tests fataled until renamed).
- **Decided:** Two separate route entries (`/activity`, `/activity/group/{group}`), not one route
  with an optional `{group}` path segment — Symfony route parameters cannot be conditionally upcast
  to an entity only on some requests to the same route name; matches `do_group_membership`'s own
  one-route-per-shape convention (no existing route in this codebase uses a nullable entity param).
- **Decided:** Group-scope access is gated via core's standard `_entity_access: 'group.view'` route
  requirement (`Drupal\Core\Entity\EntityAccessCheck`, wired in `core.services.yml`) — NOT a custom
  `_custom_access` controller callback, even though every existing route in this codebase
  (`do_group_membership`, `do_group_extras`) uses the custom-callback pattern. `_entity_access` is a
  real, core-provided, zero-extra-code mechanism for exactly this "gate on the upcast entity's own
  access" case; the custom-callback precedent in this codebase is for routes needing ADDITIONAL
  logic beyond plain entity access (e.g. join-policy gating), which this route does not need.
- **Decided:** The `activity_feed` Views config ships only ONE display (`default`) — the group-scope
  route path bypasses Views entirely, querying `Message` entities directly via a
  `field_group_id`-scoped `EntityQuery`. A fixed single-group condition needs no Views contextual-
  argument machinery; hand-authoring a Views argument config (`entity_target_id` plugin id, its
  required `target_entity_type_id` key, an auto-generated relationship) carries real risk of a
  subtly-wrong config I cannot easily iterate on without a full kernel-test round-trip, versus a
  directly-verifiable `EntityQuery` call.
- **Decided:** `ActivityRowBuilder::buildCommentSnippet()` uses `strip_tags()` + byte-safe `substr()`
  to exactly 180 bytes — NO ellipsis, NO word-boundary trimming (unlike this codebase's own
  `do_group_mission`'s `GroupMissionBlock::build()` truncation, which appends "…" and backs off to a
  word boundary). The pinned kernel-test contract is `strlen($snippet) <= 180` (strict, byte-based)
  — adding trailing characters or backing off risked violating that boundary or breaking the
  `assertStringStartsWith(substr($body, 0, 50), $snippet, ...)` prefix check for no benefit the AC
  or tests actually require.
- **Decided:** Appended a `page.activity` key to `docs/groups/modules/do_chrome/src/HelpText.php`
  (matching the `page.*` shape #126 established), per this story's own brief ("Ships with its
  HelpText entry (append-only)... SD-6 (#133) is the backstop, not the plan") and the EXPLICIT
  project-wide precedent that append-only `HelpText.php` edits are sanctioned across every prior
  merged story (#109/#111/#119/#120/#122/#124/#126/#127) even when a brief's "Owns" section says
  "nothing outside these paths" for its SOLE-OWNERSHIP files — #126's own brief lists
  `APPEND-ONLY: .../HelpText.php` as a category distinct from its "NEW" (sole-owner) files,
  confirming this is the established exception, not a drive-by. Deliberately did NOT wire the key
  into `do_chrome`'s `PageHelp::getRouteMap()` allowlist — that file is #126's own sole-owned file,
  outside my module's boundary; wiring it is explicitly SD-6/#133's job per this story's own brief
  wording ("do NOT block on SD-6").
- **Evidence — kernel suite is 2/12 GREEN, and every one of the 10 RED failures traces to a specific,
  reproduced T-fixture defect, none to a defect in the production code below** (see handoff-F.md
  "Tests that look wrong (for T)" for the full list + reproduction steps):
  1. `ActivityFeedKernelTestBase::setUp()` never calls `$this->installConfig(['do_activity_feed'])`
     — confirmed via a throwaway debug kernel test: `Views::getView('activity_feed')` returns NULL
     without it, and returns the correctly-scoped result set once it is added. Breaks 2 of 3
     `ActivityFeedRenderTest` tests (both my_groups-scope tests).
  2. `testFeedRendersInterleavedRowTypes()` calls `$this->createUser(['name' => 'elena_test'])` —
     `UserCreationTrait::createUser()`'s real signature is
     `createUser(array $permissions = [], $name = NULL, ...)`; the FIRST parameter is permissions,
     not values. `['name' => 'elena_test']` is interpreted as a permissions array, and
     `checkPermissions()` fails with the exact observed error, "Invalid permission elena_test.".
  3. `GroupsKernelTestBase::createGroup()`/`addNode()` fire `do_activity`'s LIVE
     `group_relationship_insert`/`group_insert` hooks as an uncontrolled side effect (confirmed via
     a throwaway debug kernel test dumping every Message row in storage after fixture setup — extra,
     unaccounted-for Messages attributed to whichever user was `\Drupal::currentUser()` at fixture-
     build time appear alongside the test's own explicit fixture Messages). `ActivityAggregationTest`
     calls `ActivityAggregator::aggregate()` on an UNFILTERED `loadMultiple()` of the whole `message`
     table (via its own `messagesByTemplate()` helper), so this contamination directly inflates
     bucket counts in all 4 aggregation tests.
  4. `ActivityFeedKernelTestBase::setUp()` never attaches a `comment_body` `FieldConfig` to the
     `'comment'` bundle it creates (only `comment.comment_body`'s field STORAGE is installed via
     `installConfig(['filter', 'field'])`+`installConfig(['do_activity'])`; the per-bundle field
     INSTANCE needs its own `FieldConfig::create()`, exactly as core's own `CommentManager::
     addBodyField()` does and as this SAME base class already does correctly for the unrelated
     `field_activity_comments` node-side field). Confirmed via a throwaway debug kernel test:
     `$comment->hasField('comment_body')` is `false`. Breaks all 3 `ActivityCommentSnippetTest`
     tests.
  5. `testAccessScopingRestrictsToViewersGroups`/`testContentRowOmittedWhenNodeNotViewable` create
     users with zero permissions and expect `$node->access('view')` to succeed for a fellow group
     member — but Drupal core's node access ALWAYS requires the base `access content` permission at
     minimum (group membership alone does not grant node view access without an explicit Group role
     permission grant). Confirmed via a throwaway debug kernel test:
     `$node->access('view', $memberA, TRUE)->getReason()` returns exactly
     `"The 'access content' permission is required."`. `do_streams`' own merged `StreamsScopeTest`
     (a working, passing sibling suite exercising the identical `GroupsKernelTestBase::addNode()`
     fixture path) explicitly grants this via `createGroupRole()` in its own `setUp()` — this
     fixture never does.
  All five root causes were reproduced via throwaway, F-authored debug kernel test files (deleted
  before this handoff; never committed) that isolated each mechanism from the pinned suite itself —
  see handoff-F.md for the full evidence trail. No line of any T-authored test file was edited.

## F — Rework round (routed from U's Phase 8 REWORK) — 2026-07-23

- **Decided:** Defect 1 (view-install) did NOT reproduce under three independent controlled
  attempts — manual `drush pmu`/`drush en` CLI cycling (twice, including U's exact `cr && pmu && en`
  sequence), and a kernel-level `module_installer->install()` call (the real install path, unlike
  `enableModules()`, which explicitly skips `hook_install()`). Read `ConfigInstaller::
  installDefaultConfig()`/`ModuleInstaller.php` core source directly: `config/install/*` (where this
  view already correctly lives) installs unconditionally in dependency order — the
  `validateDependencies()` filtering that could silently drop config only applies to
  `config/optional/`, not `config/install/`. No schema violation found (0 violations from
  `$typed->createFromNameAndData(...)->validate()` against the live config). Implemented the
  routing instructions' fix option (c) — a defensive `hook_install()` self-heal
  (`do_activity_feed.install`, mirroring `do_group_membership_install()`'s own idempotent pattern) —
  rather than "fixing" a hypothesis I could not confirm.
- **Decided:** Did NOT move the view to `config/optional/` (fix option (b)) — that path IS subject
  to `validateDependencies()`-based silent dropping, the exact failure mode Defect 1 describes;
  moving a provably-working `config/install/` entry there for an unreproduced problem would trade a
  hypothesis for a real new risk.
- **Decided:** Defect 2 (Twig `path()` TypeError) confirmed and fixed at the source —
  `ActivityRowBuilder::buildRow()` now precomputes `actor_url`/`group_url` (plain strings via
  `$entity->toUrl()->toString()`, the same idiom `ActivityFeedController::memberUrl()` and
  `GroupMissionBlock::build()` already used elsewhere in this codebase) instead of the three row
  templates calling Twig's `path('entity.user.canonical', {'user': row.actor.id})` directly (Twig's
  magic attribute getter returns the `id` FIELD, a FieldItemList, not the scalar).
- **Decided:** Found and fixed a SECOND, closely-related defect in the same three templates while
  building the rendered-output regression test: `row.group.label` (bare magic-attribute access) hits
  the identical FieldItemList trap as `.id` — `Group` entities carry a real `label` base field
  (confirmed empirically: `$group->label` returns a `FieldItemList`; only `$group->label()` returns
  the scalar). Fixed to `row.group.label()` (explicit method-call parens) in all three templates —
  same defect class, same files, not a separate/new scope; would have caused the identical class of
  500 the moment the `.id` crash was patched.
- **Decided:** Added the two regression tests O's routing decision assigned to F —
  `ActivityFeedViewInstallTest.php` (mirrors `do_streams`' own `StreamsInstallTest.php`'s
  `module_installer->install()` pattern) for Defect 1, and `ActivityFeedRowRenderTest.php` (mirrors
  `do_streams`' own `StreamsShellTest.php`'s `$renderer->renderRoot()` pattern) for Defect 2 — both
  new files left UNSTAGED per F's general no-test-staging mandate, flagged explicitly for the
  operator/T to decide on staging despite this round's specific test-authoring exception.
- **Found (not fixed, flagged for T):** One PRE-EXISTING flaky test, unrelated to either defect —
  `ActivityFeedRenderTest::testContentRowOmittedWhenNodeNotViewable` fails non-deterministically
  (~1-in-5 runs) with an array-contains assertion failure. Confirmed this predates the rework and is
  unrelated to F's two new files: reproduced running ONLY the original 4 T-authored test files
  (same ~1-in-5 rate); reproduced running ONLY F's 2 new files in isolation 5 times (5/5 clean, zero
  flakiness). Likely the same `do_activity`-live-hook-noise class T's own docblock already documents
  for a sibling test in the same file (`pruneHookNoiseMessages()`), just not yet applied to this
  third test. Not fixed here — per instructions, flagged for T.
- **Evidence:** 17/17 kernel GREEN on a clean run (510 assertions); sibling regression suites
  unaffected (do_activity 23/23, do_streams 25/25, do_group_membership 26/26 — 74/74 combined);
  phpcs 0 errors on all production files (1 accepted warning matching the do_group_membership.install
  precedent exactly). LIVE verification: `/activity` as elena_garcia (uid 4) — HTTP 200, 35 rows
  (23 social / 7 content / 5 aggregated), zero error text, real `/user/N` and `/group/N` hrefs;
  `/activity/group/6` — HTTP 200, 7 rows; `/activity` anonymous — HTTP 200. `drush watchdog:show`
  post-verification shows zero new PHP errors (only pre-existing entries from before the fix).
