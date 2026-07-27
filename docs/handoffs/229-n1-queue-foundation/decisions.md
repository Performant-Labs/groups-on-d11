# Decisions — #229 N-1 Queue foundation + LogMailer

## O — Phase 1 (open)
- **Decided:** POC lean pipeline (O → A → T(RED) → F → T(GREEN) → diff-gate → S → PR → self-merge). No D, no U.
- **Decided:** Coordinator Option A — trim to match N-2's `QueueBackendInterface` (enqueue/drain/count, no retry). Retry deferred to N-3 (#231).
- **Decided:** LogMailer as `message_notify` `@Notifier` plugin — writes to `\Drupal::logger('do_notifications')->info(...)` AND `/tmp/notifications.log` (one-liner per delivery). Returns TRUE always.
- **Decided:** DB table `do_notifications_queue`, dedup on (uid, mid, frequency, day) tuple via unique key.
- **Assumed:** `/tmp/notifications.log` is writeable in kernel test environment (PHPUnit temp dir). If not, mailer must degrade (log-only) — test will surface.
- **Assumed:** Nothing already installed the module's schema (fresh `hook_schema` in `.install`).
- **Evidence:** N-2 interface (`docs/groups/modules/do_notifications/src/Queue/QueueBackendInterface.php`), MockQueueBackend, services.yml swap comment.

## T — Phase 4 (RED authored)
- **Decided:** Authored `DatabaseQueueBackendTest.php` and `LogMailerTest.php` under
  `docs/groups/modules/do_notifications/tests/src/Kernel/` (source of truth). Both are
  KernelTestBase suites — persistence/dedup and plugin-registration/watchdog/filesystem behavior
  are not crisply mockable at unit tier without losing the assertions that matter (real DB unique
  key, real plugin discovery).
- **Decided:** LogMailer invoked via `deliver(array $output)` directly (not `send()`), obtained
  from `\Drupal::service('plugin.message_notify.notifier.manager')->createInstance('log_mailer',
  [], $message)`. Confirmed the correct service id by reading
  `web/modules/contrib/message_notify/message_notify.services.yml` — the task brief's guessed id
  `plugin.manager.message_notify.notifier` does not exist; the real id is
  `plugin.message_notify.notifier.manager`. `send()` renders the Message via the entity view
  builder into view-mode-keyed output, which doesn't match LogMailer's uid/mid/template payload
  shape — `deliver()` is the correct, brief-literal entry point.
- **Decided:** Added a minimal `MessageTemplate` config entity fixture
  (`do_notifications_test_template`) in `LogMailerTest::setUp()` — required because `Message`
  is bundled by `message_template` and won't save without one; no `text` setting needed since
  LogMailer's contract never renders the Message.
- **Decided:** Added `flag` to both suites' `$modules`. `do_notifications.services.yml`'s
  `do_notifications.subscription_router` service has an unconditional `@flag` argument, so the DI
  container fails to compile for any kernel test enabling `do_notifications` without it — a
  pre-existing constraint already worked around identically in `GroupAddNotificationTest` and
  `SubscriptionRouterTest`, not something new to this story.
- **Assumed:** No A handoff exists for this story (review-rigor `none`, POC lean) — treated the
  approved brief itself as the Phase-3 plan artifact per O's Phase-1 decision.
- **Evidence:** RED run output (see `handoff-T-red.md`) — `DatabaseQueueBackendTest` fails with
  `LogicException: do_notifications module does not define a schema for table
  'do_notifications_queue'`; `LogMailerTest` fails with `PluginNotFoundException: The "log_mailer"
  plugin does not exist`. Both are the exact "code under test doesn't exist yet" RED the brief
  predicts — not setup/import errors.
- **Environment note (not a test-authorship issue):** this worktree had no `vendor/`/`web/core`
  (fresh worktree, no `composer install` run) and a stale `.ddev/config.yaml` name collision with
  another project — resolved by running `ddev composer install` inside the already-running
  `gm229-n1-queue` ddev project (confirmed via `ddev list`) and using
  `scripts/dev/run-kernel.sh`'s documented `SIMPLETEST_DB=mysql://db:db@db/db
  SIMPLETEST_BASE_URL=https://web` recipe for kernel test env vars.

## F — Phase 5 (implementation, GREEN)
- **Decided:** `do_notifications.install` — `do_notifications_schema()` returns
  `do_notifications_queue` (serial PK `id`; `uid`/`mid` unsigned int; `template` varchar(128);
  `frequency` varchar(32); `day` varchar(10); `created` unsigned int). Unique key
  `uid_mid_frequency_day` on `(uid, mid, frequency, day)` — the dedup enforcement. Added a
  non-required `frequency_day` index anticipating the N-3+ digest worker's query pattern (brief
  explicitly said "consider," not "require"). Followed the project's own established
  `hook_schema()` style (`do_discovery.install`) — plain function, no `declare(strict_types=1)`
  (that declaration only appears in `.install` files with typed function signatures elsewhere in
  this codebase, e.g. `do_activity.install`, `do_group_membership.install`; a bare
  `array`-returning `hook_schema()` has none).
- **Decided:** `DatabaseQueueBackend implements QueueBackendInterface`, constructor-promoted
  `readonly Connection $database` + `readonly TimeInterface $time` (PHP 8 promotion, matching
  `SubscriptionRouter`'s established constructor style in this same module).
  - `enqueue()`: `$database->merge(TABLE)->keys([uid,mid,frequency,day])->fields([template,
    created])->execute()` exactly per the brief and A's notes — `merge()`, not try/catch on a
    unique-violation exception.
  - `drain()`: wrapped the SELECT (ordered `id` ASC) + DELETE pair in
    `$database->startTransaction()` with an explicit `rollBack()` on any exception, so a caller
    can never observe a partial drain (rows returned but not removed, or vice versa) — the brief
    said "wrap in a transaction to keep read+delete atomic" and this is the idiomatic Drupal
    `Connection::startTransaction()` pattern (scope-based commit-on-destruct, explicit rollback
    only needed on the failure path).
  - `count()`: `SELECT COUNT(*)` via `countQuery()`, cast to `int`.
  - Injected `TimeInterface` (`Drupal\Component\Datetime\TimeInterface`, matching
    `SubscriptionRouter`'s existing import) instead of calling `\Drupal::time()` statically inside
    `enqueue()` — the task instructions' literal snippet used the static call, but that trips
    DrupalPractice's "`\Drupal` calls should be avoided in classes, use dependency injection
    instead" warning. Free to fix (one constructor arg, one services.yml arg), so did — gets this
    new file to a clean 0-errors/0-warnings phpcs bar rather than accepting the same
    already-tolerated pattern `DoDiscoveryHooks.php` (pre-existing, shipped) carries. Confirmed via
    `SIMPLETEST_DB=... phpunit --testdox` re-run that this had no test-behavior effect (identical
    3/3 GREEN before and after).
- **Decided:** `LogMailer extends Drupal\message_notify\Plugin\Notifier\MessageNotifierBase` (NOT
  a class literally named `Base` — inspected `message_notify`'s actual `src/Plugin/Notifier/`
  directory: `MessageNotifierBase` is the abstract parent `Email` extends; there is no `Base`
  class in this contrib module). Followed `Email.php`'s own pattern exactly: override
  `__construct()` to accept one additional injected service beyond the parent's
  (logger/entity_type.manager/renderer/message), override the static `create()` factory to pull
  that extra service from the container and delegate to `new static(...)`.
  - The one extra injected service is `FileSystemInterface` (`@file_system`), needed for
    `getTempDirectory()`.
  - `deliver(array $output = [])` reads `uid`/`mid`/`template` directly from `$output` (confirmed
    via T's `LogMailerTest::testDeliverWritesWatchdogAndFile` — it calls
    `$notifier->deliver($payload)` where `$payload = ['uid' => ..., 'mid' => ..., 'template' =>
    ...]`, NOT the parent's normal view-mode-keyed render output). This mirrors T's Phase-4
    decision (recorded above) that `deliver()`, not `send()`, is the correct entry point — the
    class docblock says so explicitly so a future reader isn't tempted to route through `send()`
    and get view-mode-keyed `$output` instead.
  - Timestamp format: `gmdate('Y-m-d\TH:i:s\Z', \Drupal::time()->getRequestTime())` — UTC per the
    epic, matches T's regex `^\[\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\]`. Left this one call as
    `\Drupal::time()` (not injected) — phpcs's DrupalPractice DI-avoidance sniff did NOT flag it
    (verified with a verbose single-file run, 164 sniffs registered, 0 findings), and
    `MessageNotifierBase`'s fixed constructor signature (which `LogMailer` must remain
    compatible with, since `Manager::createInstance()` calls `$plugin_class::create(...)`
    positionally) makes adding a fourth injected service pure ceremony for a clean sniff run
    with no reported violation.
  - Watchdog: injected `logger.channel.do_notifications` via `LoggerChannelInterface $logger`
    (the same constructor parameter `MessageNotifierBase` already declares) rather than calling
    `\Drupal::logger('do_notifications')` statically inside `deliver()`. Registered
    `logger.channel.do_notifications` as a new `parent: logger.channel_base` entry in
    `do_notifications.services.yml`, mirroring `message_notify.services.yml`'s own
    `logger.channel.message_notify` entry byte-for-byte in structure. This is functionally
    identical to the brief's literal `\Drupal::logger('do_notifications')->info(...)` — both
    produce a `watchdog.type = 'do_notifications'` row (verified: T's raw-DB assertion on
    `watchdog` WHERE `type = 'do_notifications'` passes) — but is the DI-idiomatic path already
    established by the exact base class this plugin extends.
  - Log message uses placeholder tokens (`'uid=@uid mid=@mid template=@template'`,
    `['@uid'=>...,'@mid'=>...,'@template'=>...]`) per Drupal's PSR-3-with-placeholders logger
    convention (avoids a raw `sprintf()`-into-logger anti-pattern) — the file-sink line uses the
    brief-mandated literal format string separately, since the two sinks have different format
    contracts (brief only pins the FILE line's exact format).
  - Returns `TRUE` unconditionally, per the brief.
- **Decided:** `do_notifications.services.yml` — swapped `do_notifications.queue`'s class to
  `DatabaseQueueBackend`, `arguments: ['@database', '@datetime.time']`. Updated the inline comment
  from "N-1 will swap this class" (future tense) to "N-1 (#229) completed" (past tense) — the
  brief's own task instructions asked for this comment update as an alternative to deleting it.
  Preserved every other service entry byte-for-byte (only added, never reordered or reformatted).
- **Decided:** `MockQueueBackend`'s class docblock updated per the task instructions — from "The
  shippable default for #230 (N-2) until DatabaseQueueBackend (N-1, #229) lands" (future tense,
  now stale) to "Was the shippable default... Retained in the codebase for unit tests" (past
  tense + restated purpose). Class body untouched — the brief explicitly says "keep
  MockQueueBackend intact for unit tests."
- **Decided:** `do_notifications.info.yml` — added `drupal:message_notify` (the project's own
  established dependency-notation convention, confirmed against `do_activity.info.yml` — which
  already lists `drupal:message_notify` for the exact same contrib module — NOT the task
  instructions' literal `message_notify:message_notify` guess, which doesn't match any other
  module in this codebase).
- **Decided:** ran `phpcs` with an explicit `--standard=Drupal,DrupalPractice` flag, NOT the bare
  invocation the task instructions specified. Verified first: `phpcs --config-show` reports no
  `default_standard` pinned and no `phpcs.xml`/`.phpcs.xml.dist` exists anywhere in this repo, so a
  bare invocation falls back to PHP_CodeSniffer's built-in PEAR standard (85-char line limit,
  strict multi-line-call indentation) — NOT the standard this project is actually written to. This
  exact gap and fix were already documented by a prior story in this same repo
  (`docs/planning/handoffs/193-sd4-tooltip-consumers/handoff-F.md`, "Important caveat on the bare
  `phpcs` invocation" section) and by #230/#112's own T handoffs
  (`--standard=Drupal,DrupalPractice`) — followed that established project convention rather than
  the task instructions' literal (unqualified) command.
- **Found (regression, flagged for T, NOT fixed by F):** running the FULL `do_notifications`
  kernel suite (not just the two new files) surfaces 5 pre-existing failures, all in
  `SubscriptionRouterTest.php` (an N-2/#230 file), all
  `Drupal\Core\Database\DatabaseExceptionWrapper: ... Table '...do_notifications_queue' doesn't
  exist`. Root cause: `SubscriptionRouterTest::setUp()` never calls `installSchema('do_notifications',
  ['do_notifications_queue'])` — it didn't need to when `do_notifications.queue` resolved to the
  in-memory `MockQueueBackend` (N-2's default), but this story's entire purpose is swapping that
  service to the real DB-backed `DatabaseQueueBackend`, which now genuinely requires the table.
  Confirmed this is NOT fixable from production code: `KernelTestBase::enableModules()` (the code
  path every kernel test's `$modules` static property triggers) deliberately never calls
  `hook_schema()`-driven table creation — only the real `ModuleInstaller::install()` (a completely
  separate, heavier code path used by BrowserTestBase / production installs, not KernelTestBase)
  auto-creates `hook_schema()` tables. Every kernel test in this codebase that needs a module's DB
  table opts in via an explicit `installSchema()` call in its own `setUp()` — there is no
  do_notifications-level hook or shared base-class override that could make this automatic without
  editing the test file itself. Per my role boundary (F does not edit tests), left
  `SubscriptionRouterTest.php` untouched and recorded this in `handoff-F.md`'s "Tests that look
  wrong (for T)" section for T to add the missing `installSchema()` call in Phase 6.
- **Verified:** DatabaseQueueBackendTest + LogMailerTest GREEN (3 tests, 26 assertions, 0
  failures/errors — 7 deprecations, all pre-existing core/contrib noise per T's RED handoff, not
  new). Full do_notifications suite: 16 tests, 546 assertions, 5 errors (100% isolated to the
  SubscriptionRouterTest gap above; GroupAddNotificationTest's 6/6 and EmailRendererTest's 2/2
  unaffected — GroupAddNotificationTest uses Drupal core's own `@queue` service, an entirely
  different queue from `do_notifications.queue`). phpcs `--standard=Drupal,DrupalPractice` on all
  3 new/changed PHP production files (`DatabaseQueueBackend.php`, `LogMailer.php`,
  `do_notifications.install`) plus the docblock-only-changed `MockQueueBackend.php`: 0
  errors/warnings on all four, exit code 0.
- **Evidence:** command transcripts in `handoff-F.md`.

## T — Phase 6 (GREEN verification)
- **Decided:** Fixed the regression F flagged. Added
  `$this->installSchema('do_notifications', ['do_notifications_queue']);` to
  `SubscriptionRouterTest::setUp()` (docs/groups/modules/do_notifications/tests/src/Kernel/
  SubscriptionRouterTest.php), immediately after the existing `installSchema('node', ...)` call,
  exactly mirroring the idiom `DatabaseQueueBackendTest::setUp()` already uses. All 5 previously
  -failing SubscriptionRouterTest methods now pass. This is a test-authorship fix (T owns tests),
  not a change to F's production code — F's diagnosis (KernelTestBase::enableModules() never runs
  hook_schema(); only ModuleInstaller::install() does) was correct and is the right root cause.
- **Found (self-inflicted, fixed):** the first edit (via a Python script) introduced CRLF line
  endings into the otherwise-LF file, which phpcs's Drupal standard flags
  (`End of line character is invalid; expected "\n" but found "\r\n"`). Normalized to LF before
  re-assembling; confirmed phpcs clean afterward (0 errors/warnings, exit 0) on the assembled copy.
- **Verified:** full `do_notifications` kernel suite is 16/16 GREEN (0 errors, 20 deprecations —
  all pre-existing core/contrib noise, none new), confirmed identically via both the explicit
  Kernel/ directory invocation and the `find ... -path '*/tests/src/Kernel'` discovery-based
  invocation F/O both use. Cross-module sanity: `do_activity` + `do_activity_feed` kernel suites
  are 42/42 GREEN (0 errors, exit 0) — unaffected by the queue swap (they use core's own `@queue`
  service, not `do_notifications.queue`).
- **Decided:** did not add any new test — the brief's 8 acceptance criteria are already covered by
  T's Phase-4 `DatabaseQueueBackendTest` + `LogMailerTest`, now GREEN against F's implementation.
  Spot-checked that `DatabaseQueueBackendTest::testEnqueueDrainCount` still fails for the right
  reason if the behavior is removed (already proven identically in the RED handoff — the same
  assertions, same file, no changes to the test's assertions in Phase 6).
- **Evidence:** command transcripts in `handoff-T-green.md`.

## S — Phase 9 (spec audit)
- **Decided:** Verdict **PASS**. All 8 brief acceptance criteria met and backed by passing tests. Spec-compliant, no scope drift, no layering violations.
- **Verified:** `merge()` (not try/catch) in `DatabaseQueueBackend::enqueue()`; `getTempDirectory()` (not hardcoded `/tmp`) in `LogMailer`; UTC via `gmdate('Y-m-d\TH:i:s\Z', ...)`; unconditional `return TRUE` in `deliver()`; both watchdog + file sinks written; `MockQueueBackend` class body intact; no parallel-path duplication (services.yml points queue exclusively at DB backend); LogMailer stays out of DB; deferred concerns (retry, cron worker) documented as N-3+ in brief and `.install` docblock; all production changes limited to `docs/groups/` source-of-truth.
- **Accepted deviations from literal task snippets** (all net-positive, F- and T-documented): `TimeInterface` injected instead of `\Drupal::time()` static; `logger.channel.do_notifications` registered as a service instead of `\Drupal::logger()` static; bonus `frequency_day` schema index (brief said "consider").
- **Evidence:** `handoff-S.md`.
