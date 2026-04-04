# Refactoring Plan — Hooks to Services

Extract procedural hook logic into OOP service classes with dependency injection. Five phases, one module per phase, each with explicit test coverage.

> [!CAUTION]
> **MANDATORY READING**: Before starting ANY phase of this refactoring, you **MUST** read and understand [os_HANGING_PROCESSES.md](os_HANGING_PROCESSES.md). It documents 21 categories of process hangs that can occur during Drupal development/testing workflow. Ignoring it will lead to wasted hours debugging phantom freezes. **Read it. Know it. Follow it.**

---

## Pre-Phase Checklist (Do This Before EVERY Phase)

> [!WARNING]
> **Reference: [os_HANGING_PROCESSES.md](os_HANGING_PROCESSES.md)** — Follow the Diagnostic Checklist and cleanup steps documented there before starting each phase.

1. **Kill zombie processes** — Run `kill-zombies.sh` (see [HANGING_PROCESSES §3, §12](os_HANGING_PROCESSES.md))
2. **Verify DDEV is healthy** — `ddev describe` (see [HANGING_PROCESSES §4, §11](os_HANGING_PROCESSES.md))
3. **Stop unused DDEV projects** — `ddev list` and stop any you're not using (see [HANGING_PROCESSES §11](os_HANGING_PROCESSES.md))
4. **Flush opcache** — `ddev restart` after adding/moving PHP files (see [HANGING_PROCESSES §10](os_HANGING_PROCESSES.md)). `ddev drush cr` alone is NOT enough.
5. **Use `SafeToAutoRun: true`** for non-destructive commands (see [HANGING_PROCESSES §21](os_HANGING_PROCESSES.md))

---

## Existing Test Coverage

| Test file | Scope | Assertions |
|---|---|---|
| `do_discovery/tests/.../DiscoveryTest.php` | Module, views_data, schema | 9 |
| `do_notifications/tests/.../NotificationsTest.php` | Module, routes, flags | 11 |
| `do_tests/.../Phase4Test.php` | discovery, flags, views | 20 |
| `do_tests/.../Phase5Test.php` | notifications, frequency field | 21 |
| `do_tests/.../Phase6Test.php` | profile_stats, pin, mission, language | 32 |

---

## Phase R1 — `do_multigroup` (D → A)

> [!IMPORTANT]
> After creating `MultigroupManager.php`, you **MUST** run `ddev restart` — not just `ddev drush cr`. New PHP classes require an opcache flush. See [HANGING_PROCESSES §10 — PHP Opcode Cache Stale Class](os_HANGING_PROCESSES.md).

### Changes

#### [NEW] `src/MultigroupManager.php`
Service with DI (`entity_type.manager`, `group.membership_loader`, `current_route_match`):
- `getPostableContentTypes()` — derives from installed group_node plugins, replaces constant
- `getUserGroupOptions(AccountInterface)` — replaces 25-line form alter block
- `getExistingGroupIds(NodeInterface)` — replaces relationship query in form alter
- `syncRelationships(NodeInterface, array $gids)` — replaces submit handler
- `getGroupsForNode(NodeInterface)` — replaces preprocess query

#### [NEW] `do_multigroup.services.yml`
#### [MODIFY] `do_multigroup.module` — reduce to ~30 lines of thin hook wrappers

### Tests

| Type | File | What it covers |
|---|---|---|
| **New Unit** | `do_multigroup/tests/src/Unit/MultigroupManagerTest.php` | `syncRelationships()`, `getGroupsForNode()` with mocked storage |
| **Existing Kernel** | `do_tests/.../Phase3Test.php` | Regression: multigroup module enabled, content types have group_node relationships |

### Verification
```bash
# Unit test (new)
ddev exec bash -c "cd /var/www/html && vendor/bin/phpunit web/modules/custom/do_multigroup/tests/src/Unit/MultigroupManagerTest.php"
# Regression (existing)
ddev exec bash -c "cd /var/www/html/web/core && php ../../vendor/bin/phpunit --configuration . ../../web/modules/custom/do_tests/tests/src/Kernel/Phase3Test.php"
```

> [!WARNING]
> If `ddev exec` hangs with no output, check [HANGING_PROCESSES §4](os_HANGING_PROCESSES.md) and [§21](os_HANGING_PROCESSES.md). Verify DDEV is running with `ddev describe` first.

---

## Phase R2 — `do_notifications` (C → A)

> [!IMPORTANT]
> After creating `NotificationEventRecorder.php`, run `ddev restart` to flush opcache. See [HANGING_PROCESSES §10](os_HANGING_PROCESSES.md).

### Changes

#### [NEW] `src/NotificationEventRecorder.php`
Service with DI (`queue`, `entity_type.manager`, `flag`, `datetime.time`):
- `recordNodeEvent(NodeInterface)` — replaces `hook_node_insert()` + `_do_notifications_record_event()`
- `recordCommentEvent(CommentInterface)` — replaces comment hook event logic
- `autoSubscribe(CommentInterface)` — extracts auto-subscribe concern
- `getGroupIds($entity)` — replaces `_do_notifications_get_group_ids()`

#### [NEW] `do_notifications.services.yml` — updated to register new service
#### [MODIFY] `do_notifications.module` — thin wrappers; replace `\Drupal::state()` per-entity suppression with service method

### Tests

| Type | File | What it covers |
|---|---|---|
| **New Unit** | `do_notifications/tests/src/Unit/NotificationEventRecorderTest.php` | `recordNodeEvent()`, `autoSubscribe()` with mocked flag/queue |
| **Existing Kernel** | `do_notifications/tests/.../NotificationsTest.php` | Regression: module, routes, settings, flag configs |
| **Existing Kernel** | `do_tests/.../Phase5Test.php` | Regression: flags, frequency field, permissions |

### Verification
```bash
ddev exec bash -c "cd /var/www/html && vendor/bin/phpunit web/modules/custom/do_notifications/tests/src/Unit/NotificationEventRecorderTest.php"
ddev exec bash -c "cd /var/www/html/web/core && php ../../vendor/bin/phpunit --configuration . ../../web/modules/custom/do_notifications/tests/src/Kernel/NotificationsTest.php"
```

---

## Phase R3 — `do_group_extras` (C → B+)

> [!IMPORTANT]
> After creating new PHP classes, run `ddev restart` to flush opcache. See [HANGING_PROCESSES §10](os_HANGING_PROCESSES.md).

### Changes

#### [NEW] `src/GroupModerationService.php`
Service with DI (`current_user`, `queue`, `logger.factory`):
- `shouldDefaultToUnpublished(GroupInterface)` — replaces presave logic
- `notifyModerators(GroupInterface)` — replaces `_do_group_extras_notify_moderators()`

#### [NEW] `src/Access/ArchivedGroupAccessCheck.php`
`AccessInterface` implementation replacing `hook_node_access()`. Removes `\Drupal::routeMatch()` coupling.

#### [NEW] `do_group_extras.services.yml`
#### [MODIFY] `do_group_extras.module` — keep `hook_form_alter()` + `hook_preprocess_group()` as thin wrappers

### Tests

| Type | File | What it covers |
|---|---|---|
| **New Unit** | `do_group_extras/tests/src/Unit/GroupModerationServiceTest.php` | `shouldDefaultToUnpublished()`, `notifyModerators()` |
| **Existing Kernel** | `do_tests/.../Phase2Test.php` | Regression: group_extras module, extras View, permissions |

### Verification
```bash
ddev exec bash -c "cd /var/www/html && vendor/bin/phpunit web/modules/custom/do_group_extras/tests/src/Unit/GroupModerationServiceTest.php"
ddev exec bash -c "cd /var/www/html/web/core && php ../../vendor/bin/phpunit --configuration . ../../web/modules/custom/do_tests/tests/src/Kernel/Phase2Test.php"
```

---

## Phase R4 — `do_group_pin` (C → B)

> [!IMPORTANT]
> After creating `PinDetector.php`, run `ddev restart` to flush opcache. See [HANGING_PROCESSES §10](os_HANGING_PROCESSES.md).

### Changes

#### [NEW] `src/PinDetector.php`
Service with DI (`flag.count`):
- `isPinned(NodeInterface): bool`

#### [NEW] `do_group_pin.services.yml`
#### [MODIFY] `do_group_pin.module`
- `hook_preprocess_node()` → 3-line wrapper
- `hook_page_attachments()` → conditional load (group pages only)
- `hook_views_query_alter()` — keep as-is (no OOP alternative)

### Tests

| Type | File | What it covers |
|---|---|---|
| **New Unit** | `do_group_pin/tests/src/Unit/PinDetectorTest.php` | `isPinned()` with mocked flag.count |
| **Existing Kernel** | `do_tests/.../Phase6Test.php` | Regression: pin module, flag exists |

### Verification
```bash
ddev exec bash -c "cd /var/www/html && vendor/bin/phpunit web/modules/custom/do_group_pin/tests/src/Unit/PinDetectorTest.php"
ddev exec bash -c "cd /var/www/html/web/core && php ../../vendor/bin/phpunit --configuration . ../../web/modules/custom/do_tests/tests/src/Kernel/Phase6Test.php"
```

---

## Phase R5 — `do_discovery` (B → A)

> [!IMPORTANT]
> After creating `HotScoreCalculator.php`, run `ddev restart` to flush opcache. See [HANGING_PROCESSES §10](os_HANGING_PROCESSES.md).

### Changes

#### [NEW] `src/HotScoreCalculator.php`
Service with DI (`database`, `datetime.time`):
- `recomputeScores(int $cutoffDays = 7): int`
- `seedScore(int $nid): void`

#### [NEW] `do_discovery.services.yml`
#### [MODIFY] `do_discovery.module` — 1-line cron, 1-line insert, keep `hook_views_data()` as-is

### Tests

| Type | File | What it covers |
|---|---|---|
| **New Unit** | `do_discovery/tests/src/Unit/HotScoreCalculatorTest.php` | Score math, seed logic with mocked DB |
| **Existing Kernel** | `do_discovery/tests/.../DiscoveryTest.php` | Regression: module, schema, views_data |
| **Existing Kernel** | `do_tests/.../Phase4Test.php` | Regression: statistics, flags, views |

### Verification
```bash
ddev exec bash -c "cd /var/www/html && vendor/bin/phpunit web/modules/custom/do_discovery/tests/src/Unit/HotScoreCalculatorTest.php"
ddev exec bash -c "cd /var/www/html/web/core && php ../../vendor/bin/phpunit --configuration . ../../web/modules/custom/do_discovery/tests/src/Kernel/DiscoveryTest.php"
```

---

## Summary

| Phase | Module | New files | New unit tests | Existing regression tests |
|---|---|---|---|---|
| R1 | `do_multigroup` | 3 | `MultigroupManagerTest` | Phase3Test |
| R2 | `do_notifications` | 2 | `NotificationEventRecorderTest` | NotificationsTest, Phase5Test |
| R3 | `do_group_extras` | 3 | `GroupModerationServiceTest` | Phase2Test |
| R4 | `do_group_pin` | 2 | `PinDetectorTest` | Phase6Test |
| R5 | `do_discovery` | 2 | `HotScoreCalculatorTest` | DiscoveryTest, Phase4Test |
| **Total** | | **12 new files** | **5 new unit tests** | **7 existing kernel tests** |

> [!IMPORTANT]
> These are **code-only refactors** — no config changes expected. Each phase is independent and committed separately. If any regression test fails, the refactor is rolled back before proceeding.

> [!CAUTION]
> **REMINDER**: Consult [os_HANGING_PROCESSES.md](os_HANGING_PROCESSES.md) at the first sign of any freeze, hang, or "class not found" error. The answers are already documented there. Do not waste time re-debugging known issues.
