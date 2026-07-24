# Handoff-F: Phase 5 - do_activity_feed (Activity feed rendering)

**Date:** 2026-07-23
**Branch:** 129-activity-feed-render
**Issue:** #129

## What was done

- `docs/groups/modules/do_activity_feed/do_activity_feed.info.yml` — new module manifest.
- `docs/groups/modules/do_activity_feed/do_activity_feed.services.yml` — registers `do_activity_feed.aggregator`, `do_activity_feed.row_builder`, `do_activity_feed.hooks`.
- `docs/groups/modules/do_activity_feed/do_activity_feed.routing.yml` — `/activity` (my_groups) and `/activity/group/{group}` (single-group, gated by core's `_entity_access: 'group.view'`).
- `docs/groups/modules/do_activity_feed/do_activity_feed.libraries.yml` — the `activity_feed` CSS library.
- `docs/groups/modules/do_activity_feed/src/Hook/DoActivityFeedHooks.php` — `#[Hook('views_data')]` (registers the `do_activity_feed_membership_scope` synthetic filter field on `message_field_data`), `#[Hook('theme')]` (4 theme hooks), `#[Hook('preprocess_activity_feed')]` (maps each raw row-model array to its themed sub-render-array).
- `docs/groups/modules/do_activity_feed/src/Controller/ActivityFeedController.php` — `renderFeed(string $scope, ?GroupInterface $group = NULL): array`, `myGroups()`, `groupScope()`, `groupScopeTitle()`. Loads messages (Views executable for my_groups; direct `EntityQuery` for group scope), splits by template, aggregates the two aggregable templates via `ActivityAggregator`, builds rows via `ActivityRowBuilder`, drops access-denied content rows, sorts the combined list by `created` DESC.
- `docs/groups/modules/do_activity_feed/src/Service/ActivityAggregator.php` — `aggregate(array $messages): array`, pairwise-consecutive 6h-window bucket folding.
- `docs/groups/modules/do_activity_feed/src/Service/ActivityRowBuilder.php` — `buildRow()` (full row-model construction, including the `$node->access('view')` drop-on-deny gate) and `buildCommentSnippet(MessageInterface $message): string` (strip_tags + byte-safe 180-char truncation).
- `docs/groups/modules/do_activity_feed/src/Plugin/views/filter/ActivityMembershipScope.php` — the `do_activity_feed_membership_scope` Views filter plugin, EXISTS-shape on `{message__field_group_id}` joined to `{group_relationship_field_data}` for `plugin_id = 'group_membership'`.
- `docs/groups/modules/do_activity_feed/config/install/views.view.activity_feed.yml` — one `default` display, base table `message_field_data`, `template` bundle filter (3 surfacing templates) + the membership-scope filter, sort `created` DESC, limit 50.
- `docs/groups/modules/do_activity_feed/templates/activity-feed.html.twig`, `activity-row--social.html.twig`, `activity-row--content.html.twig`, `activity-row--aggregated.html.twig` — semantic `<ol>/<li>` wrapper + `.gc-empty` reuse; native `<details>/<summary>` for aggregated rows (WCAG-native keyboard operability, no JS); `data-testid` attributes matching the wireframe (`activity-feed-shell`, `activity-feed-empty`, `activity-row-social`, `activity-row-content`, `activity-row-aggregated`).
- `docs/groups/modules/do_activity_feed/css/activity-feed.css` — reused the approved wireframe's low-fi tokens verbatim (same visual language as the do_streams shell).
- `docs/groups/modules/do_chrome/src/HelpText.php` — **append-only**: one new `page.activity` key, per this story's own AC-8 and the project-wide append-only HelpText contract every prior story used.

## Design decisions

- **`ActivityFeedController` extends `ControllerBase`** (not a plain autowired class): the T tests resolve it via `\Drupal::classResolver(ActivityFeedController::class)`, and `ClassResolver::getInstanceFromDefinition()` only passes the container through for a `ContainerInjectionInterface` implementer — any other class is `new`'d with zero arguments.
- **Constructor property named `$entityTypeManagerService`, not `$entityTypeManager`**: `ControllerBase` already declares its own non-readonly `$entityTypeManager`; a `readonly` redeclaration of the same name fatals at class-load. Found and fixed via the kernel run itself.
- **Two separate route entries**, not one route with an optional `{group}` segment — Symfony can't conditionally upcast the same route name's param; matches `do_group_membership`'s one-route-per-shape convention.
- **Group-scope access via core's `_entity_access: 'group.view'`** route requirement (not a custom `_custom_access` callback, unlike every existing route in this codebase) — `_entity_access` is a real, zero-extra-code Drupal core mechanism for exactly "gate on the upcast entity's own access"; every existing custom-callback precedent here (`do_group_membership`, `do_group_extras`) needed ADDITIONAL logic beyond plain access, which this route does not.
- **The shipped view has only one `default` display** — the group-scope route path bypasses Views entirely via a direct `EntityQuery` (`field_group_id = $group->id()`). A hand-authored Views contextual-argument config (`entity_target_id` plugin, its `target_entity_type_id` key, an implicit relationship) is real, hard-to-iterate-on risk for a fixed single-value condition an `EntityQuery` expresses directly and verifiably.
- **`buildCommentSnippet()` truncates with byte-safe `substr()`, no ellipsis, no word-boundary trim** — deliberately simpler than this codebase's own `do_group_mission` truncation convention, because the pinned test contract is a strict `strlen() <= 180` byte assertion plus a raw-prefix `assertStringStartsWith()` check; appending characters risked both for no benefit the AC/tests require.
- **`ActivityAggregator::aggregate()` tracks only the immediately-previous folded message's `created` time**, never the bucket's first/anchor message — this is what makes the AC-2c "chain" case (t=0/5h/10h → one bucket) distinct from an anchor-based window (which would split at the 10h gap from t=0 to t=10h).

## Reuse / extend-vs-new

Per survey.md's Reuse map: **NEW module** `do_activity_feed` (clean boundary — `do_activity` is intentionally storage-only, `do_streams` is node-focused). **NEW** Views filter `ActivityMembershipScope`, modeled directly on `do_streams\Plugin\views\filter\MembershipScope`'s EXISTS pattern (different base table + join key: `message__field_group_id` → `group_relationship_field_data`, not `group_node:%`). **NEW** view `activity_feed` (base table `message_field_data`, cannot extend `do_streams_demo`). **EXTENDED** the existing `node.stream_card` view mode for content rows — `ActivityRowBuilder::buildRow()` calls `$entityTypeManager->getViewBuilder('node')->view($node, 'stream_card')` and the template black-boxes the card interior, matching the #109 wireframe's own convention. No parallel path was created where the brief named an extension target.

## Architecture notes for A

- **Layers touched:** new module (controller, 2 services, 1 Views filter plugin, 1 hooks class, 4 Twig templates, 1 CSS file, 1 Views config, 1 routing file). No changes to `do_activity` or `do_streams` production code (read-only reuse of their conventions/patterns, per PROJECT_CONTEXT.md's "extend, don't duplicate" and the "no drive-by" rule).
- **Shared/cross-module edit:** `do_chrome/src/HelpText.php` — one appended key (`page.activity`), following the exact append-only shape #126 established (confirmed `HelpTextTest`/`HelpTextPageKeysTest` are unaffected — neither asserts an exact key COUNT, only presence of specific named keys).
- **New dependency edges:** `do_activity_feed` depends on `do_activity` (message templates/fields) and `do_streams` (per the brief/survey; not structurally consumed by any class in this implementation beyond the shared architectural convention it mirrors — `do_streams` remains an `.info.yml` dependency per survey.md's explicit direction, satisfied here even though no `do_streams` class is imported).
- **Route-collision check (PROJECT_CONTEXT.md's known gotcha):** `/activity` and `/activity/group/{group}` are genuinely new paths — confirmed no `drupal/group` optional-config view or existing route collides (checked `config/sync/views.view.activity_stream.yml`, which lives at `/stream`, not `/activity`). The `hook_install()`-strip-and-rebuild pattern (`do_group_membership.install`'s own docblock) does not apply here — that pattern is specific to routes at paths `drupal/group` contrib ships COLLIDING optional views for (e.g. `/group/{group}/members`); neither of my routes matches that shape.
- **`Message` entity is non-revisionable but translatable** (confirmed by reading `web/modules/contrib/message/src/Entity/Message.php`'s entity annotation) — its dedicated field-storage tables (`message__field_group_id`) follow the standard `entity_id`/`{field}_target_id` column shape my Views filter's raw SQL assumes; confirmed against the live DB schema (`DESCRIBE`) during Tier-1 verification, not merely assumed.

## Deviations from spec / wireframe

None from the brief or wireframe itself. One deviation from my own initial draft, corrected during verification: the group-scope Views contextual-argument approach I first drafted in the view config was replaced with a direct `EntityQuery` (see Design decisions above) before it was ever run — an intentional simplification made during implementation, not a deviation discovered by a failing test.

## Tier 1 self-check (incl. tests now GREEN)

**Assemble:**
```
$ ddev exec 'cd /var/www/html && bash scripts/ci/assemble-config.sh'
==> assemble-config: repo root = /var/www/html
==> config: copied 128 file(s), excluded 7 env-specific file(s)
==> modules: copied 15 custom module(s) into web/modules/custom/
==> core.extension: registered custom do_* modules + flag as enabled
==> assemble-config: done
```
Exit 0.

**phpcs** (`Drupal,DrupalPractice`, production source only, T's tests excluded):
```
$ ddev exec 'cd /var/www/html && php vendor/bin/phpcs --standard=Drupal,DrupalPractice \
    --extensions=php,module,install,inc,theme --ignore=*/tests/* web/modules/custom/do_activity_feed/'
(no output — 0 errors, 0 warnings)
```

**Kernel suite** (`SIMPLETEST_DB` inside DDEV, matching T-red's own invocation):
```
$ ddev exec 'cd /var/www/html && SIMPLETEST_DB="mysql://db:db@db:3306/db" php vendor/bin/phpunit \
    -c web/core/phpunit.xml.dist --testdox web/modules/custom/do_activity_feed/tests/src/Kernel/'

Activity Views Filter
 ✔ Activity membership scope is registered on message field data
 ✔ Activity membership scope filter plugin is discoverable

Tests: 12, Assertions: 381, Failures: 10, Deprecations: 15 (pre-existing framework noise — see below).
```

**2/12 GREEN.** The remaining 10 are T-fixture defects, each independently reproduced and traced — see "Tests that look wrong (for T)" below. None trace to a defect in the production code above (each root cause was confirmed empirically via a throwaway, F-authored debug kernel test that isolated the exact mechanism — every debug file was deleted before this handoff and never committed).

**Deprecation noise is pre-existing, not introduced by this change** — confirmed by running `do_streams`' own merged, passing `StreamsScopeTest.php` with the identical invocation: 7/7 pass with the SAME `@ViewsField`/`@ViewsFilter`/`@EntityType` annotation-deprecation warnings from the `flag` contrib module, matching T-red's own verification method.

**No regression to sibling modules** (AC-7):
```
do_activity:          23/23 GREEN (deprecation noise only)
do_streams:            25/25 GREEN (deprecation noise only)
do_group_membership:   26/26 GREEN (deprecation noise only)
```

## Tests that look wrong (for T)

**Do not edit these — flagging per instructions.** Every failure below was independently reproduced via a throwaway debug kernel test (never committed, deleted before this handoff) that isolated the exact mechanism from the pinned test file itself.

1. **`ActivityFeedKernelTestBase::setUp()` never calls `$this->installConfig(['do_activity_feed'])`.** The base class's own docblock already documents this EXACT gap for `do_activity`'s config ("never auto-installed by enableModules()") but the same understanding was not applied to `do_activity_feed`'s own shipped `views.view.activity_feed.yml`. Without it, `Views::getView('activity_feed')` returns `NULL` inside `ActivityFeedController::loadMessagesForMyGroups()`, so `renderFeed('my_groups')` always returns zero rows. Breaks 2 of `ActivityFeedRenderTest`'s 3 tests (`testAccessScopingRestrictsToViewersGroups`, `testContentRowOmittedWhenNodeNotViewable`). **Suggested fix:** add `$this->installConfig(['do_activity_feed']);` to `ActivityFeedKernelTestBase::setUp()`.

2. **`ActivityFeedRenderTest::testFeedRendersInterleavedRowTypes()` misuses `createUser()`.** Lines 49-51 call `$this->createUser(['name' => 'elena_test'])` (and `alex_test`/`maria_test`), but `UserCreationTrait::createUser()`'s real signature is `createUser(array $permissions = [], $name = NULL, $admin = FALSE, array $values = [])` — the FIRST positional parameter is a PERMISSIONS array, not a values array. `['name' => 'elena_test']` is interpreted as a permission string to validate, producing the exact observed error: `"Invalid permission elena_test."`. **Suggested fix:** `$this->createUser([], 'elena_test')` (empty permissions, name as the 2nd positional arg), or `$this->createUser([], NULL, FALSE, ['name' => 'elena_test'])`.

3. **`GroupsKernelTestBase::createGroup()`/`addNode()`/`addMember()` fire `do_activity`'s LIVE hooks as an uncontrolled side effect**, and `ActivityAggregationTest`'s own `messagesByTemplate()` helper loads the ENTIRE `message` table unfiltered by actor — so extra Messages generated by fixture-setup calls (attributed to whichever user is `\Drupal::currentUser()` at that moment, which the aggregation tests never explicitly set before calling `addNode()`/`createGroup()`) contaminate every bucket count. Confirmed via debug dump: a 2-message fixture test showed 5 total `message` rows in storage, 3 of them uncontrolled side effects. Breaks all 4 `ActivityAggregationTest` tests. **Suggested fix:** either call `$this->setCurrentUser($actor)` before every `addNode()`/`createGroup()`/`addMember()` call so side-effect Messages attribute to the SAME actor the test already expects (making them legitimately foldable, or filterable), or have `messagesByTemplate()` additionally filter by the specific actor uid(s) each test constructs.

4. **`ActivityFeedKernelTestBase::setUp()` never attaches a `comment_body` `FieldConfig` to the `'comment'` bundle it creates.** Only the field's STORAGE (`field.storage.comment.comment_body`) is installed (via `installConfig(['filter', 'field'])` + core's own `comment` module dependency chain) — the per-bundle field INSTANCE needs an explicit `FieldConfig::create(['entity_type' => 'comment', 'bundle' => 'comment', 'field_name' => 'comment_body'])->save()` call, exactly as this SAME `setUp()` already does correctly for the unrelated node-side `field_activity_comments` field, and exactly as core's own `CommentManager::addBodyField()` does. Confirmed via debug dump: `$comment->hasField('comment_body')` is `false` on every comment this fixture creates. Breaks all 3 `ActivityCommentSnippetTest` tests. **Suggested fix:** add a `FieldConfig::create([...])->save()` call for `comment_body` on the `'comment'` bundle in `setUp()`.

5. **`testAccessScopingRestrictsToViewersGroups`/`testContentRowOmittedWhenNodeNotViewable` create users with zero permissions and expect `$node->access('view')` to succeed for a fellow group member** — but Drupal core node access always requires the base `access content` permission at minimum; group membership alone never grants node view access without an explicit Group role permission grant. Confirmed via debug: `$node->access('view', $memberA, TRUE)->getReason()` returns exactly `"The 'access content' permission is required."`. `do_streams`' own merged, PASSING `StreamsScopeTest` (exercising the identical `GroupsKernelTestBase::addNode()` fixture path) explicitly grants this via `createGroupRole(['permissions' => ["view group_node:$node_type entity", ...]])` in its own `setUp()` — this fixture never does. **Suggested fix:** add the same `createGroupRole()` grant pattern `StreamsScopeTest::setUp()` uses, scoped to the `post` bundle at minimum.

None of these were fixed by editing the test files — per instructions, I implemented production code against the tests as written and am flagging the fixture gaps here for T to address in Phase 6.

## Known issues

None beyond the flagged test-fixture gaps above (which, once T resolves them, should turn every remaining RED green against the production code already shipped here — I did not change my implementation to "route around" any of these five gaps, since doing so would mean guessing at a shape the tests don't actually assert).

## Files changed

- `docs/groups/modules/do_activity_feed/do_activity_feed.info.yml` (new)
- `docs/groups/modules/do_activity_feed/do_activity_feed.services.yml` (new)
- `docs/groups/modules/do_activity_feed/do_activity_feed.routing.yml` (new)
- `docs/groups/modules/do_activity_feed/do_activity_feed.libraries.yml` (new)
- `docs/groups/modules/do_activity_feed/src/Hook/DoActivityFeedHooks.php` (new)
- `docs/groups/modules/do_activity_feed/src/Controller/ActivityFeedController.php` (new)
- `docs/groups/modules/do_activity_feed/src/Service/ActivityAggregator.php` (new)
- `docs/groups/modules/do_activity_feed/src/Service/ActivityRowBuilder.php` (new)
- `docs/groups/modules/do_activity_feed/src/Plugin/views/filter/ActivityMembershipScope.php` (new)
- `docs/groups/modules/do_activity_feed/config/install/views.view.activity_feed.yml` (new)
- `docs/groups/modules/do_activity_feed/templates/activity-feed.html.twig` (new)
- `docs/groups/modules/do_activity_feed/templates/activity-row--social.html.twig` (new)
- `docs/groups/modules/do_activity_feed/templates/activity-row--content.html.twig` (new)
- `docs/groups/modules/do_activity_feed/templates/activity-row--aggregated.html.twig` (new)
- `docs/groups/modules/do_activity_feed/css/activity-feed.css` (new)
- `docs/groups/modules/do_chrome/src/HelpText.php` (modified — append-only, one new `page.activity` key)

No test files were created, modified, or deleted by F.
