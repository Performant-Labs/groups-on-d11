# Decisions — #230 N-2 Subscribe Event Routing

## O — Phase 1 (survey + brief)

- **Decided:** Front the routing with a `QueueBackendInterface` in `do_notifications/src/Queue/`;
  ship a `MockQueueBackend` now; leave `DatabaseQueueBackend` for N-1 (#229).
- **Decided:** Extend `DoActivityHooks::createMessage()` (all 6 event points funnel through it)
  rather than adding routing calls in each of the 6 hook methods.
- **Decided:** Router injected nullable into do_activity, so do_activity keeps working when
  do_notifications is disabled.
- **Assumed:** `default_frequency` in settings is the per-recipient frequency for routing entries
  (no per-user preferences UI in scope for this story).
- **Assumed:** `mute_group_notifications` flag is checked against the Message's `field_group_id`
  (existing behavioral spec — see the flag's presence in `config/optional`).
- **Evidence:** `DoActivityHooks.php` lines 399–428 (single `createMessage()`);
  `do_notifications.services.yml`; `GroupAddNotificationTest.php` drain pattern.

## T — Phase 4 (RED)

- **Decided:** Single kernel test file, `SubscriptionRouterTest.php`, covering the 5 acceptance
  behaviors (dedup, author-exclusion, group-mute, per-post state suppression, no-subscribers) —
  cheapest sufficient tier; no unit/functional/e2e duplication needed since the router's only
  consumers (real entity storage, flag queries, config, State) are all kernel-testable directly.
- **Decided:** Flag fixtures for follow_content/follow_user/follow_term/mute_group_notifications
  live at `do_notifications/tests/fixtures/config/` (module-local, byte-identical copies of the
  shipped `config/optional/*.yml`), installed via `FileStorage::read()` + manual
  `->create()->save()`, NOT `installConfig(['do_notifications'])` — the shipped
  `flagTypeConfig.access_author` key has no matching flag.schema.yml entry and trips strict
  schema checking; do_streams' StreamsScopeTest/FollowingFeedTest already established this exact
  workaround for the same shipped flags.
- **Decided:** The fixture-install loop is load-or-create (checks `$flag_storage->load($id)`
  first), not blind-create — `installConfig(['do_activity'])`'s default `DefaultConfigMode::All`
  also runs `ConfigInstaller::createSiteOptionalConfig()`, which auto-installs
  `mute_group_notifications` (the one of the four flags with no `access_author` key, so it alone
  passes schema validation) from do_notifications' own `config/optional/` before the loop runs.
- **Assumed:** `activity_post_created` is sufficient to exercise the router's node-reference path
  for all 5 tests; the brief does not require covering all 6 do_activity templates in this
  story's RED (routing logic is template-agnostic per the brief's algorithm description).
- **Evidence:** RED run — `ServiceNotFoundException: ... "do_notifications.subscription_router"`
  across all 5 tests (see handoff-T-red.md). Regression: `GroupAddNotificationTest.php` — 6
  tests, 162 assertions, still green after the new file was added.

## F — Phase 5 (GREEN) — 2026-07-24

- **Decided:** `SubscriptionRouter` dedups the candidate-uid union itself (uid-keyed array) before
  ever calling `queue->enqueue()`, rather than relying solely on `MockQueueBackend`'s own
  `(uid, mid, frequency, day)` dedup to collapse an overlapping subscription. Keeps `route()`'s
  returned count trivially correct (every `enqueue()` call the router makes is guaranteed new for
  that invocation) and gives the eventual `DatabaseQueueBackend` (N-1, #229) a second, independent
  line of defense rather than the only one.
- **Decided:** `follow_term` resolution iterates the referenced entity's `getFieldDefinitions()`
  generically (any `entity_reference` field with `target_type === 'taxonomy_term'`), not a
  hard-coded `field_tags` name — matches the brief's explicit "best-effort... iterate node fields"
  instruction and works for any node type's own tag field without a future code change.
  `follow_content`'s comment-ref handling reloads the comment via
  `entityTypeManager->getStorage('comment')->load()` exactly as the brief instructs (A's warn #3),
  even though the generically-resolved `$referenced_entity` already in hand is the same comment.
- **Decided:** Extended `DoActivityHooks::createMessage()` (the shared funnel) with one new nullable
  constructor dependency and one new call site, wrapped in its own try/catch per the brief;
  `do_activity.info.yml` left untouched (do_notifications stays a soft, not hard, dependency).
  `DoNotificationsHooks.php`'s existing core-`queue` recording path was not touched at all — the
  brief's "Extend, not fork" instruction for that file.
- **Assumed:** none beyond what O/T already assumed — no new assumptions introduced; all field
  access patterns (`field_referenced_entity_type`/`_id` as a plain string field via `->value`,
  `field_group_id` as an entity_reference via `->target_id`) were confirmed by reading existing
  do_activity test files (`NodeInGroupInsertTest.php` etc.) rather than assumed from memory.
- **Hedged:** PHPCS was run with an explicit `--standard=Drupal,DrupalPractice` flag rather than the
  bare `phpcs` invocation the task literally specified, after confirming (by running the bare
  invocation against pre-existing, already-merged files like `DoActivityHooks.php` and
  `DoNotificationsHooks.php`) that the bare invocation falls back to the PEAR default standard and
  produces hundreds of false-positive errors even against already-shipped code — i.e. it is not a
  meaningful check in this project without the standard flag. Under the calibrated real standard,
  all new/edited files have 0 errors; remaining warnings (`\Drupal calls should be avoided in
  classes`) match this codebase's own pre-existing, already-accepted pattern in the same files.
- **Evidence:** `SubscriptionRouterTest.php` — 5/5 GREEN, 244 assertions (was RED per T's handoff).
  `GroupAddNotificationTest.php` — 6/6 GREEN, 162 assertions (regression, acceptance criterion 6).
  Full `do_activity` kernel suite — 23/23 GREEN, 759 assertions (regression check on the shared
  `DoActivityHooks.php` file). PHPCS (`Drupal,DrupalPractice`) — 0 errors across all 6
  new/modified files; calibration run against pre-existing `DoActivityHooks.php` /
  `DoNotificationsHooks.php` confirmed the standard choice and the warning baseline. Full command
  output and file list in `handoff-F.md`.
