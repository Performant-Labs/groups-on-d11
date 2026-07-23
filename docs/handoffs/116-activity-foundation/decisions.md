# Decision Journal — 116-activity-foundation (issue #116)

## T — Phase 4 (author/RED) — 2026-07-23

- **Decided:** Ported and verified `bash scripts/ci/assemble-config.sh` must run via `ddev exec`
  (the host shell has no `php` on PATH; the script's core.extension.yml patch step shells out to
  `php -r ...` directly). Confirmed by first running it on the host (failed: `php: command not
  found`), then re-running identically inside the `gm116-activity` DDEV container (succeeded:
  103 config files copied, 14 custom modules copied incl. the new `do_activity`, core.extension
  patched).
- **Decided:** `composer install` had not yet been run in this worktree (no `vendor/` present) —
  ran `ddev composer install`, which pulled `drupal/message` 1.8.0 and `drupal/message_notify`
  1.5.0 into `web/modules/contrib/` (installer-paths, NOT `vendor/drupal/*` — a Drupal-project
  convention worth noting since it initially looked like the composer deps hadn't landed).
- **Decided:** Kernel-test bootstrap needs `SIMPLETEST_DB` set explicitly even under DDEV (DDEV's
  own `settings.ddev.php` configures the SITE's DB connection, but `KernelTestBase` requires the
  separate `SIMPLETEST_DB` env var per Drupal core's own outside-of-`run-tests.sh` contract).
  Resolved to `mysql://db:db@db:3306/db` (DDEV's own in-container DB credentials, confirmed via
  `ddev describe`). Documented the exact run command in handoff-T-red.md so F/T-green can repeat
  it without rediscovering this.
- **Decided:** Eight test files, 20 test methods total, exactly per the orchestrator's file-by-file
  spec (NodeInGroupInsertTest, CommentInsertTest, MembershipCreateTest, FlaggingInsertTest,
  GroupCreateTest, PinTogglePinTest, BackfillIdempotencyTest, DeletionHygieneTest), plus one
  non-test shared base class (`ActivityKernelTestBase`) centralizing fixture plumbing — mirrors
  the `do_streams`/`do_group_pin` precedent of NOT duplicating setUp() boilerplate across sibling
  test files in the same module.
- **Decided:** `do_activity.info.yml` is a T-authored skeleton (no hook class, no install file, no
  message template config) — documented at the top of the file with an explicit comment on why,
  per the task brief's "prefer omission" guidance being infeasible here (module enable requires
  SOME info.yml to exist) but everything else (hooks, templates, .install) deliberately omitted so
  F builds the real contract, not a stub T half-wrote.
- **Decided:** `BackfillIdempotencyTest` is written against the brief's ALREADY-RESOLVED
  architecture choice ("backfill-after-seed": subscribers stay enabled always, idempotency key
  prevents duplication) — NOT the orchestrator prompt's alternative phrasing ("seed fixtures with
  subscribers DISABLED"). The brief (`docs/planning/handoffs/116-activity/brief-amended.md`,
  "Backfill" §) is more specific and post-dates the general task template, and explicitly cites
  "Recorded per A question #3" as a settled architectural decision — treating it as binding over
  the more generic orchestrator wording. Seeds fixtures via the LIVE subscriber path, runs the
  backfill script twice, asserts the Message count is unchanged both times (the sharper test: if
  the idempotency key were wrong, the FIRST backfill run against already-hook-logged data would
  immediately double the count).
- **Decided:** Chose `dirname(DRUPAL_ROOT) . '/docs/groups/scripts/step_7xx_backfill_activity.php'`
  (repo-root-relative, NOT module-relative) as the backfill script's resolved path in the test,
  since `assemble-config.sh` copies `docs/groups/modules/*` into `web/modules/custom/` but does
  NOT copy `docs/groups/scripts/` anywhere — the script only ever exists at its source-tree path,
  in both the source checkout and CI's assembled layout, so a module-relative path would be wrong
  in exactly the "assembled layout" case the project's standing instructions warn about.
- **Decided (fixture placement):** Copied `flag.flag.{rsvp_event,follow_user,pin_in_group}.yml`
  byte-identical into `docs/groups/modules/do_activity/tests/fixtures/config/` (module-local),
  never referencing `docs/groups/config/` via a source-relative path — per the standing project
  instruction that source-relative fixture paths pass in the source tree but fail once
  `assemble-config.sh` copies the module elsewhere.
- **Decided (surprise for F, documented in handoff):** `do_group_pin` has NO dedicated pin hook or
  separate storage — it reacts to the flagging entity's own generic `entity_insert`/
  `entity_delete` (Flag 4.x fires no dedicated (un)flag hook), branching on flag id inside
  `onFlaggingChange()`. Modeled `PinTogglePinTest` the same way: pin/unpin coverage goes through
  `flagging_insert`/`flagging_delete` directly, branching on `$flagging->getFlagId()` — matching
  the brief's own hook list, not a `do_group_pin`-specific API path (confirmed by reading
  `docs/groups/modules/do_group_pin/src/Hook/DoGroupPinHooks.php` in full; there is no alternative
  storage to mirror instead).
- **Decided (bug fix, mine, during RED confirmation):** `ActivityKernelTestBase::setUp()` was
  initially missing `installSchema('flag', ['flag_counts'])` — the flag module's own
  `FlagCountManager` subscriber errored with `Base table or view not found` on the very first
  `flag()`/`unflag()` call in any test that exercises a flagging (`FlaggingInsertTest`,
  `PinTogglePinTest`, `BackfillIdempotencyTest`). This is a genuine test-authoring gap (setup
  bug), fixed directly before finalizing RED, not routed to F — confirmed the fix by re-running
  the full suite and observing the failure mode change from a fatal `EntityStorageException` to a
  clean `Failed asserting that actual size 0 matches expected size N` (the real feature-absence
  assertion), which is the valid RED shape.
- **Decided:** Confirmed 19/20 tests fail for the right reason (a Message-count/existence
  assertion failing because no production hook exists yet, or a `assertFileExists()` failing
  because the backfill script doesn't exist yet) and 1/20 passes by design
  (`NodeInGroupInsertTest::testMembershipRelationshipRecordsNoPostCreatedMessage`, a negative-space
  guard asserting the ABSENCE of an event for `group_membership` relationships — true both before
  and after F's implementation, and would flip red if F's hook wrongly fired on both relationship
  kinds). Did not treat the one passing test as an invalid RED, since its assertion is genuinely
  about correct discrimination, not an assertion that trivially holds regardless of the feature.
- **Decided:** Staged only the 13 files I authored by explicit path (`git add <path> <path> ...`),
  never `git add -A`/`.` — `config/sync/*` (assemble-config.sh output) and `web/modules/custom/*`
  + `web/autoload_runtime.php` (composer scaffold + assembled-module copies) are build artifacts
  of running the verification tooling in this worktree, not source changes, and were left
  unstaged/untracked exactly as the primary checkout's `.gitignore`/repo convention expects.
- **Assumed:** No standalone `handoff-A.md`/Phase-3-PASS artifact was found in this worktree
  (only `brief-amended.md`, which self-describes as the A-amended, binding plan) — proceeded on
  the brief's own text as the A precondition per the task brief's explicit framing ("brief +
  wireframe" as the Phase-3 output for a no-UI story), rather than blocking to search for a
  separate A handoff file that may not exist for this story.
- **Evidence:** `docs/planning/handoffs/116-activity/brief-amended.md` (full read), `gh issue view
  116` (Scope + Acceptance sections), `docs/groups/modules/do_notifications/src/Hook/
  DoNotificationsHooks.php` (full read — group_relationship_insert discrimination pattern),
  `docs/groups/modules/do_notifications/tests/src/Kernel/GroupAddNotificationTest.php` (full
  read — queue-drain kernel-test pattern), `docs/groups/modules/do_group_pin/src/Hook/
  DoGroupPinHooks.php` (full read — confirmed no dedicated pin hook/storage),
  `docs/groups/modules/do_group_pin/tests/src/Kernel/PinnedStreamOrderingTest.php` (fixture-install
  pattern), `docs/groups/modules/do_tests/tests/src/Kernel/GroupsKernelTestBase.php` (full read —
  `createGroup`/`addMember`/`addNode` fixture helpers), `docs/groups/modules/do_streams/tests/src/
  Kernel/{StreamsScopeTest,StreamsRankingTest}.php` (setUp() boilerplate precedent),
  `docs/groups/scripts/step_700_demo_data.php` (comment-creation + idempotency-echo convention),
  `web/modules/contrib/message/src/Entity/Message.php` + `MessageTemplate.php` (full reads — API
  surface: `setCreatedTime`/`getCreatedTime`/`getOwnerId`/bundle-as-template-id),
  `web/modules/contrib/message/tests/src/Kernel/MessageTest.php` (full read — `Message::create()`
  kernel-test pattern), `web/core/modules/comment/tests/src/Kernel/CommentIntegrationTest.php`
  (full read — `FieldStorageConfig`/`FieldConfig` comment-field-attach pattern),
  `web/modules/contrib/flag/config/schema/flag.schema.yml` (confirmed `follow_user`'s
  `show_on_profile`/`extra_permissions` keys are schema-valid, no stripping needed unlike
  do_streams' `follow_content` fixture), `.github/workflows/test.yml` lines 55-140 (exact CI
  kernel-job recipe: assemble-config, settings.php prep, `SIMPLETEST_DB`, phpunit invocation —
  replicated verbatim inside DDEV), live DDEV run output (20 tests, 19 failures + 1 designed pass,
  616 assertions, captured verbatim in handoff-T-red.md), `git status --porcelain` (post-stage,
  13 files only under `docs/groups/modules/do_activity/`).

## F — Phase 5 (implement/GREEN) — 2026-07-23

- **Decided:** `composer.json`/`composer.lock` needed NO changes — `drupal/message` +
  `drupal/message_notify` were already added under `require` back in commit for `#136 W0
  dependency pre-story` and are already vendored at `web/modules/contrib/{message,message_notify}`
  (confirmed via `git diff main -- composer.json` showing zero diff, and a directory listing of
  `web/modules/contrib/`). The brief's "append-only" composer step was therefore a no-op on this
  branch; not re-adding an already-present dependency.
- **Decided:** Config-entity shape for the six `message.template.*.yml` files and the three shared
  `field.storage.message.*.yml` + fifteen `field.field.message.*.yml` files was derived by reading
  `MessageTemplate`'s own `@ConfigEntityType` annotation (`config_prefix: template`, `entity_keys:
  {id: template}`) and `message.schema.yml` directly — no existing `message.template.*.yml`
  example existed anywhere in the repo to copy from, so the shape was built from the contrib
  module's own PHP source of truth, then round-trip verified via a disposable scratch kernel
  probe (see "Evidence" below) proving all six templates + attached fields install cleanly via
  `installConfig(['do_activity'])` before finalizing.
- **Decided:** `field_group_id` is attached (per T's advisory contract) to THREE templates, not
  the two T's prose advisory named — `activity_post_created`, `activity_comment_created`, AND
  `activity_membership_created`. T's handoff prose said "used by activity_post_created and
  activity_comment_created only", but the actual test bodies (`MembershipCreateTest.php:43`,
  `DeletionHygieneTest.php:98`) also read `$message->get('field_group_id')`, so the prose summary
  under-stated its own test suite. Followed the tests (the actual contract), not the prose.
- **Decided:** `DoActivityHooks::groupRelationshipInsert()`'s membership branch attributes the
  Message to `$relationship->getEntity()->id()` (the member being added), NEVER
  `\Drupal::currentUser()->id()` — required by `MembershipCreateTest::
  testGroupCreatorMembershipRecordsCreatorAsActor()`'s framing (attribute to the group owner even
  when a different account created the group programmatically) and by the general principle that
  an organizer adding another member on someone's behalf must attribute to the member, not the
  actor performing the add. Confirmed via `Group::addMember()`/`GroupRelationship::getEntity()`
  that for a `group_membership` relationship, `getEntity()` returns the member `User` account
  directly (not a wrapper needing further unwrapping).
- **Decided (real bug found + fixed, mine):** Every `getCreatedTime()` accessor across core entity
  classes (`Node`, `Comment`, `Group`) returns the field item's raw, loosely-typed storage value
  (`$this->get('created')->value`), which can be a numeric STRING rather than a native `int`.
  Under this file's own `declare(strict_types=1)`, passing that straight into `createMessage()`'s
  strictly `int`-typed `$created` parameter threw a `TypeError` at runtime (caught during my own
  Tier-1 verification, not by any authored test's assertion — it was a fatal, not an assertion
  failure). Fixed by casting `(int)` at every call site (four sites: two `group_relationship_insert`
  branches, `comment_insert`, `group_insert`; `\Drupal::time()->getRequestTime()` is already a
  native int and needed no cast).
- **Decided (real bug found + fixed, mine):** Setting an `entity_reference` field
  (`field_group_id`) via a bare scalar int in a `Message::create([...])` values array does NOT
  resolve to `target_id` — `EntityReferenceItem::setValue()` treats a non-array scalar as an
  ENTITY object assignment (`$this->set('entity', $values)`), which silently fails to produce a
  usable reference for a plain int. Confirmed by reading `EntityReferenceItem::setValue()` +
  `FieldItemList::setValue()` directly, then round-trip-verifying via a disposable scratch probe
  that the explicit `['target_id' => $id]` array form resolves correctly (`isEmpty(): false`,
  `target_id: '1'`) while the bare-scalar form did not. Fixed in BOTH
  `DoActivityHooks::createMessage()` and the backfill script's `do_activity_backfill_create()` —
  the same bug existed in both places since they mirror the same field-setting logic.
- **Decided (real bug found + fixed, mine):** `step_7xx_backfill_activity.php`'s top-level
  `function`/`const` declarations fataled with "Cannot redeclare function
  do_activity_backfill_exists()" the SECOND time `BackfillIdempotencyTest` `require`s the script
  in the same PHP process (the test deliberately runs the backfill twice in one test method to
  prove idempotency — a legitimate, intentional test pattern, not a bug in the test). Fixed by
  guarding every top-level function declaration with `function_exists()` and the const with
  `defined()`/`define()`, so a second `require` of the same script in one process is a safe no-op
  re-declaration-wise, while still using plain `require` (not `require_once`) to match
  `step_700_demo_data.php`'s own established convention for this script category.
- **Decided:** Left two cosmetic `phpcs` items in `step_7xx_backfill_activity.php` unfixed (the
  doc-comment's missing `@file` tag on line 2, and one inline namespaced-class reference not
  routed through a `use` statement) — confirmed via a direct `phpcs` run against the sibling
  `step_700_demo_data.php` that BOTH the exact same sniff violations (plus 238 more) already exist
  unfixed in that shipped, merged file. This project category (procedural drush-script includes)
  has no enforced lint gate and no precedent of being cleaned to zero `phpcs` output; matching the
  established sibling file's style was judged more valuable than an isolated, inconsistent cleanup
  of only the new file. Did fix the one non-cosmetic issue (`Unused variable $node_storage`, dead
  code) since that has no stylistic tradeoff.
- **Decided (net-new discovery this phase, for T/S):** `DoActivityHooks.php`'s three `\Drupal::`
  static-call `DrupalPractice` warnings are baseline/accepted-noise, not defects — confirmed by
  running the identical `phpcs` command against `do_notifications`'s own `DoNotificationsHooks.php`
  (the brief's named analog to mirror) and finding the SAME warning category present there too
  (plus considerably more warnings/errors of its own), in a file that is already merged and
  shipping. Not attempting dependency-injection refactors the brief did not ask for and the
  analog module itself does not follow.
- **Decided (verification method, no test-file edits):** To distinguish "my production code is
  wrong" from "T's test/test-base is wrong" without ever editing a committed test file, applied
  every candidate fix ONLY to the gitignored, disposable `web/modules/custom/do_activity/` copy
  that `scripts/ci/assemble-config.sh` produces (never the `docs/groups/...` source), verified the
  hypothesis, then reassembled from pristine source to discard the patch before moving on.
  Confirmed zero drift on the real test source at the end via both `git status --porcelain` (empty
  output) and a byte-diff against a pre-verification backup copy (identical). This is how all four
  test-authoring gaps below were confirmed as real bugs rather than guessed at.
- **Flagged for T (test bug 1 of 4, NOT fixed by F):** `ActivityKernelTestBase::setUp()` never
  calls `$this->installConfig(['do_activity'])`. Every one of the 20 authored tests needs the six
  `message_template` config entities + attached fields this module ships under
  `config/install/*.yml`, but `KernelTestBase::enableModules()` never auto-installs a module's own
  `config/install/` (only a real `ModuleInstaller::install()` on a bootstrapped site does that) —
  confirmed directly by reading `KernelTestBase::installConfig()`'s own docblock/implementation,
  and independently confirmed `drupal/message`'s OWN kernel test suite
  (`MessageTemplateCreateTrait`) never relies on config/install either — it programmatically
  creates `MessageTemplate` entities in-test for exactly this reason. Fix for T: add
  `$this->installConfig(['do_activity']);` to `ActivityKernelTestBase::setUp()` (one line, right
  after the existing `$this->installConfig(['filter', 'field']);`). Verified this one-line fix (on
  a disposable assembled copy only) resolves "No valid template found." across the whole suite.
- **Flagged for T (test bug 2 of 4, NOT fixed by F):** `NodeInGroupInsertTest.php:61,103`,
  `CommentInsertTest.php:60`, `MembershipCreateTest.php:43`, `DeletionHygieneTest.php:98` all read
  `$message->get('field_group_id')->value` — but `EntityReferenceItem` has NO `value` property at
  all (its `propertyDefinitions()` only defines `target_id` and `entity`), so `->value` silently
  returns NULL via the magic-getter fallback, and `(int) NULL === 0`, which is why every one of
  these assertions failed with "Failed asserting that 0 is identical to 1" even though the
  underlying Message correctly carried the group reference (confirmed via a disposable probe
  reading `->target_id` directly on the same saved Message, which returned the correct value).
  Fix for T: read `->target_id` instead of `->value` at all five call sites (four production test
  files; `->isEmpty()` in `CommentInsertTest.php:102` is unaffected since it is a method call, not
  a property read, and was already correct).
- **Flagged for T (test bug 3 of 4, NOT fixed by F):** `DeletionHygieneTest.php:92` calls
  `$group->getMember($member)->getGroupRelationship()` — this method does not exist.
  `GroupInterface::getMember()` is documented to return `\Drupal\group\Entity\GroupMembership`
  directly, and `GroupMembershipInterface extends GroupRelationshipInterface`, so the returned
  object already IS the relationship entity — no unwrapping call exists or is needed. Fix for T:
  `$membership = $group->getMember($member);` (drop the `->getGroupRelationship()` call entirely).
- **Flagged for T (test bug 4 of 4, NOT fixed by F):** `MembershipCreateTest::
  testGroupCreatorMembershipRecordsCreatorAsActor()`'s premise — "the `community_group` type has
  `creator_membership => TRUE`, so creating the group also creates the creator's own membership
  relationship" — does not hold for a bare, programmatic `Group::create([...])->save()` (the exact
  path `GroupsKernelTestBase::createGroup()` / `GroupTestTrait::createGroup()` use). Confirmed by
  reading `GroupType::$creator_membership`'s only consumers in the entire `group` module: it is
  read exclusively inside `GroupForm::form()`/`GroupForm::actions()` (`creatorGetsMembership()`),
  which wire up the ADD-FORM's own submit-handler enhancement — nothing in `Group`'s own
  `postSave()`/entity-storage layer, nor `GroupTestTrait::createGroup()`, ever creates a creator
  membership relationship outside an actual `/group/add` form submission. This is the only one of
  the 20 tests that cannot be made to pass by any production-code change on F's part — the
  relationship it expects to exist is simply never created in a kernel-test context, by Group 4.x's
  own design. Fix for T: either delete/rewrite the test to explicitly `addMember($owner, ...)`
  itself (matching what a real form submission would do) rather than asserting an automatic
  side-effect of `createGroup()` that does not exist at this tier, or move this assertion to a
  `Functional`/`BrowserTestBase` suite that actually submits `/group/add`.
- **Decided:** Final honest count against the REAL, unmodified test source (i.e. what CI will see
  until T applies the four fixes above): 20/20 fail — all on "No valid template found."
  (`EntityStorageException` / `MessageException`), which is bug 1 alone (the missing
  `installConfig(['do_activity'])` gates every other test from even reaching its real assertion).
  With ONLY that one line added (verified on a disposable copy, never committed), the count becomes
  19/20 GREEN + 1 failure that is bug 4 (a false test premise, not fixable by any hook change).
  With bugs 2 and 3 also applied (the `->target_id` reads and the `getGroupRelationship()` removal),
  the same 19/20 GREEN + 1 (bug 4) result holds — i.e. bugs 2/3 were masked by bug 1 until it was
  fixed, then surfaced as their own distinct failures, then were confirmed fixed in turn.
- **Evidence:** Full reads of all eight T-authored test files + `ActivityKernelTestBase.php`;
  `web/modules/contrib/message/src/Entity/{Message,MessageTemplate}.php` (full reads —
  `@ContentEntityType`/`@ConfigEntityType` annotations, base field definitions, `getOwnerId()`/
  `getCreatedTime()`/`setCreatedTime()`); `web/modules/contrib/message/config/schema/
  message.schema.yml`; `web/modules/contrib/message/tests/src/Kernel/
  MessageTemplateCreateTrait.php` (full read — confirmed contrib's OWN kernel suite never relies
  on config/install auto-install); `web/core/tests/Drupal/KernelTests/KernelTestBase.php` lines
  724-780 (`installConfig()`/`installSchema()` docblocks — confirmed config/install is never
  auto-installed by `enableModules()`); `web/core/lib/Drupal/Core/Field/Plugin/Field/FieldType/
  EntityReferenceItem.php` (full read — `propertyDefinitions()`, `setValue()`);
  `web/core/lib/Drupal/Core/Field/FieldItemList.php` (`setValue()` scalar-to-array normalization);
  `web/modules/contrib/group/src/Entity/{Group,GroupRelationship,GroupMembership}Interface.php`
  and `GroupType.php` (full reads — `getMember()` return type, `getEntity()`/`getGroup()`
  signatures, `creatorGetsMembership()` sole consumers); `web/modules/contrib/group/src/Entity/
  Form/GroupForm.php` lines 30-90 (confirmed creator-membership is form-submission-only);
  `web/modules/contrib/group/tests/src/Traits/GroupTestTrait.php` (`createGroup()` — bare
  `storage->save()`, no membership side effect); `web/core/modules/node/src/Entity/Node.php`
  (`getCreatedTime()` raw field-value return); `docs/groups/modules/do_notifications/src/Hook/
  DoNotificationsHooks.php` (mirrored pattern + `phpcs` baseline comparison);
  `docs/groups/modules/do_group_pin/src/Hook/DoGroupPinHooks.php` (full read — pin/unpin
  generic-lifecycle model); `docs/groups/scripts/step_700_demo_data.php` (full read — idempotency
  cadence + `phpcs` baseline comparison: 240 pre-existing violations, confirming no enforced lint
  gate on this script category); three disposable scratch kernel probes (never committed — one
  confirming `installConfig(['do_activity'])` resolves template-loading, one confirming the
  `target_id` array form resolves `field_group_id` correctly where a bare scalar does not, and
  direct patches to the four affected test files, applied ONLY to the assembled
  `web/modules/custom/` copy and discarded via `bash scripts/ci/assemble-config.sh` before every
  git-visible verification); live DDEV kernel-suite runs (multiple iterations, final honest run:
  `Tests: 20, Assertions: 569, Errors: 20` against the real unmodified source; `Tests: 20,
  Assertions: 654, Failures: 1` with the four flagged fixes applied locally-only); `php -l` syntax
  checks on all three new PHP files; `phpcs --standard=Drupal,DrupalPractice` on all new PHP files
  plus the two comparison baselines (`do_notifications`, `step_700_demo_data.php`); `git status
  --porcelain` + byte-diff-against-backup (confirmed zero drift on T's test source throughout);
  `git diff --cached --stat` (confirmed 31 files staged, exactly F's owned paths, zero test files).

## T — Phase 6 (verify/GREEN + Tier 2) — 2026-07-23

- **Decided:** Independently re-verified all four bugs F flagged against the actual COMMITTED test
  source (not F's prose alone) before applying any fix — read `ActivityKernelTestBase.php`,
  `MembershipCreateTest.php`, `DeletionHygieneTest.php`, `NodeInGroupInsertTest.php`,
  `CommentInsertTest.php` in full, plus the three `field.storage.message.*.yml` config files to
  confirm `field_group_id` is genuinely `type: entity_reference` (no `value` property) while
  `field_referenced_entity_type`/`_id` are genuinely `string`/`integer` (where `->value` IS
  correct) — F's diagnosis was correct on all four counts, no additional gaps found.
- **Decided (fix 1):** Added `$this->installConfig(['do_activity']);` to
  `ActivityKernelTestBase::setUp()`, directly after the existing
  `installConfig(['filter', 'field'])` call — one line, matches F's suggested fix exactly.
- **Decided (fix 2):** Changed `->get('field_group_id')->value` to `->get('field_group_id')->target_id`
  at all five call sites F named (`NodeInGroupInsertTest.php:61,103`, `CommentInsertTest.php:60`,
  `MembershipCreateTest.php:43`, `DeletionHygieneTest.php:98`); left the `->isEmpty()` call at
  `DeletionHygieneTest.php:102` and all `field_referenced_entity_type`/`_id` `->value` reads
  unchanged (confirmed correct given their `string`/`integer` field types).
- **Decided (fix 3):** Replaced `$group->getMember($member)->getGroupRelationship()` with
  `$group->getMember($member)` in `DeletionHygieneTest.php` — `getMember()` already returns the
  relationship entity directly per `GroupMembershipInterface extends GroupRelationshipInterface`.
- **Decided (fix 4, chose F's option (a)):** Rewrote
  `MembershipCreateTest::testGroupCreatorMembershipRecordsCreatorAsActor()` as
  `testOwnerAddedAsMemberRecordsOwnerAsActor()`, constructing the membership via
  `$this->addMember($group, $owner)` (the kernel-reachable `Group::addMember()` path used by every
  other membership test in this suite) rather than relying on `community_group`'s
  form-only `creator_membership` side effect. Kept the test at kernel tier (not moved to
  Functional/BrowserTestBase) per F's stated preference — matches the pattern of the already-passing
  `testAddMemberRecordsOneMembershipMessage`/`testTwoMembersRecordTwoDistinctMessages`. Did not
  delete the test; the acceptance intent (membership creation logs an activity Message with correct
  actor attribution, including the owner-as-member case) remains covered by a premise that actually
  holds at this tier.
- **Decided (spot-check, not requested by the task brief but required by the T-green playbook
  duty to confirm rewritten tests still pin real behavior):** On the disposable, gitignored
  `web/modules/custom/do_activity/` assembled copy only, disabled the `group_membership` branch of
  `DoActivityHooks::groupRelationshipInsert()` (`if (false && $plugin_id === 'group_membership')`)
  and re-ran `MembershipCreateTest`. All three methods failed for the right reason — including the
  rewritten `testOwnerAddedAsMemberRecordsOwnerAsActor` (`Failed asserting that an array is not
  empty.`) — proving the rewrite is a genuine RED-capable assertion, not a vacuous pass. Reassembled
  from pristine source immediately after; `git status --porcelain` on
  `docs/groups/modules/do_activity/tests/` confirms only the five intentional edits, no drift from
  the spot-check patch.
- **Decided:** While touching `MembershipCreateTest.php` and `DeletionHygieneTest.php` for the
  required fixes, also condensed two pre-existing multi-line docblock short-descriptions (a
  `phpcs` `Drupal` standard violation predating my edits, on lines already being touched) to
  single-line summaries with the elaboration moved to a following paragraph — zero behavior
  change, brings module-wide `phpcs` to 0 errors. Judged in-scope since it was on lines I was
  already editing, not a separate unrelated cleanup pass.
- **Decided:** Ran a Tier-2 peer-regression smoke check against `do_notifications`
  (`GroupAddNotificationTest`) and `do_streams` (`StreamsScopeTest`) rather than the full project
  suite, per the task's "skip if it takes >5 min" guidance — both green (`Tests: 13, Failures: 0,
  Errors: 0`) in well under the time budget, confirming #116 introduced no cross-module regression.
- **Decided:** phpstan Tier 2 check skipped — no `phpstan.neon`/`phpstan.dist.neon` found anywhere
  in the repo, confirming it is genuinely not configured for this project rather than a
  configuration I failed to locate.
- **Assumed:** `handoff-F-green.md` (untracked in git per `git status --porcelain`) is F's own file
  to stage/commit separately; I did not stage it as part of my commit, staging only the test-file
  edits + my own handoff/decisions-journal entries, consistent with "stage precisely" guidance.
- **Evidence:** Full reads of `ActivityKernelTestBase.php`, `MembershipCreateTest.php`,
  `DeletionHygieneTest.php`, `NodeInGroupInsertTest.php`, `CommentInsertTest.php` (pre- and
  post-fix); `field.storage.message.{field_group_id,field_referenced_entity_type,
  field_referenced_entity_id}.yml` (field-type confirmation); `GroupsKernelTestBase.php` lines
  130-186 (`createGroup()`/`addMember()`/`addNode()` signatures, confirming `addMember()` is the
  kernel-reachable membership-creation path); live DDEV runs: `bash scripts/ci/assemble-config.sh`
  (exit 0, 103 config files / 14 modules, both pre- and post-fix), full kernel suite
  (`Tests: 20, Assertions: 655, Failures: 0, Errors: 0, Risky: 2`, exit 0, final run after all fixes
  + docblock cleanup + reassembly), disposable-copy spot-check run (`Tests: 3, Failures: 3` with
  the membership hook branch disabled), `phpcs --standard=Drupal,DrupalPractice` on
  `docs/groups/modules/do_activity/` (0 errors, pre-existing warnings only, matches F's reported
  baseline), peer smoke run on `do_notifications`/`do_streams` (`Tests: 13, Failures: 0, Errors: 0`,
  exit 0); `git status --porcelain` (confirmed exactly 5 modified test files + this decisions.md
  entry + new handoff-T-green.md, no drift from any disposable verification patch).

## T — Round 2 (post diff-gate BLOCK, regression tests) — 2026-07-23

- **Decided:** Added `FlaggingDeleteTest.php` as a new file rather than extending
  `FlaggingInsertTest.php` — insert and delete are distinct lifecycle events with distinct hooks
  (`flagging_insert` vs `flagging_delete`), and the existing suite already separates insert/delete
  concerns into different files elsewhere (`NodeInGroupInsertTest` + `DeletionHygieneTest` for
  node lifecycle). Keeps each file's `@group`/class docblock scoped to one hook.
- **Decided:** `testUnflagFollowUserRemovesFlaggingMessage` (W-1 coverage-gap test) passes BOTH
  with and without F's `582ea59` scoping fix — verified directly during the revert-and-run sanity
  check. This is expected and correct: the coverage gap it closes is "nothing asserted unflag
  removes the Message at all", not a scoping ambiguity (a lone `follow_user` flagging's
  `(user, uid)` referenced-entity pair is not shared with any OTHER template in that specific
  test's fixture, so the unscoped pre-fix delete also happens to satisfy this assertion). The two
  REGRESSION tests (`testMembershipDeleteDoesNotDeleteUnrelatedFollowMessages`,
  `testUnpinDoesNotDeletePostCreatedMessage`) are the ones that construct the actual ambiguous
  shared-key scenario and DO fail without the fix — confirmed below.
- **Decided:** Added `testMembershipDeleteDoesNotDeleteUnrelatedFollowMessages` to
  `DeletionHygieneTest.php` (not a new file) — it is a deletion-hygiene test for the same
  `group_relationship_delete` hook `testMembershipRelationshipDeleteRemovesMembershipMessage`
  already covers in that file, just asserting the negative-space (unrelated Message survives)
  half diff-gate's B-1 finding said was missing.
- **Decided:** Added `testUnpinDoesNotDeletePostCreatedMessage` to `PinTogglePinTest.php` (not
  `DeletionHygieneTest.php`) — pin/unpin's deletion hygiene is already established in that file as
  living alongside the pin/unpin insert tests (`testUnpinRemovesTheMessage` is already there), per
  that file's own docblock explicitly carving pin/unpin out of `DeletionHygieneTest`'s scope.
- **Evidence:** `bash scripts/ci/assemble-config.sh` via `ddev exec` — exit 0, 103 config files /
  14 modules copied, core.extension patched. Full kernel suite post-assemble:
  `Tests: 23, Assertions: 757, Deprecations: 14, PHPUnit Deprecations: 32, Risky: 2` — `OK, but
  there were issues!` (no Failures/Errors line; the 2 "Risky" markers are `BackfillIdempotencyTest`'s
  pre-existing expected-stdout flags, unrelated to this round). `--testdox` output confirmed all
  three new methods print `✔`.
- **Evidence — revert-and-run sanity check:** patched ONLY the disposable, gitignored
  `web/modules/custom/do_activity/src/Hook/DoActivityHooks.php` (never `docs/groups/...`) to strip
  the `$template` argument from `flaggingDelete()`'s and `groupRelationshipDelete()`'s
  `deleteMessagesReferencing()` calls (restoring the exact pre-`582ea59` unscoped behavior), then
  reran the three new methods filtered: `Tests: 3, Assertions: 102, Failures: 2` —
  `testMembershipDeleteDoesNotDeleteUnrelatedFollowMessages` and
  `testUnpinDoesNotDeletePostCreatedMessage` both failed exactly as designed ("Failed asserting
  that actual size 0 matches expected size 1" on the unrelated-Message-survives assertion);
  `testUnflagFollowUserRemovesFlaggingMessage` passed (expected — see decision above, it pins the
  coverage gap, not a scoping regression). Restored via a fresh `bash scripts/ci/assemble-config.sh`
  run (never a manual patch-revert) — confirmed via a second full-suite run:
  `Tests: 23, Assertions: 757` restored, no Failures/Errors. `git status --porcelain` against
  `docs/groups/modules/do_activity/` throughout: only the three intended test-file changes
  (`FlaggingDeleteTest.php` new, `DeletionHygieneTest.php` + `PinTogglePinTest.php` modified) —
  `src/Hook/DoActivityHooks.php` under `docs/groups/...` was never touched, only its disposable
  assembled copy under `web/modules/custom/...` (gitignored).
- **Evidence:** `phpcs --standard=Drupal,DrupalPractice` on all three new/modified test files —
  0 errors, 0 warnings, exit 0. `php -l` clean on all three.
