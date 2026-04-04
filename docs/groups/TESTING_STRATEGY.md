# Testing Strategy for pl-drupalorg Groups Conversion

How and when to use each testing tool available in the project.

> [!IMPORTANT]
> Before running any tests, review [os_HANGING_PROCESSES.md](./os_HANGING_PROCESSES.md) for known gotchas. Kill zombie processes first: `bash scripts/kill-zombies.sh`

## Testing Layers

| Layer | Tool | Speed | What it tests | When to use |
|---|---|---|---|---|
| **Linting** | PHPCS (`Drupal` + `DrupalPractice`) | ⚡ Instant | Code style, best practices | Every commit |
| **Unit** | PHPUnit Unit | ⚡ Fast (~ms) | Pure PHP logic, no Drupal bootstrap | Service classes, utility functions |
| **Kernel** | PHPUnit Kernel | 🟡 Medium (~sec) | Database + Drupal APIs, no HTTP | Entity operations, hooks, config, queries |
| **E2E** | Playwright | 🔴 Slow (~min) | Full user flows in browser | Critical paths: create group, join, post, search |
| **Accessibility** | Nightwatch + Axe | 🔴 Slow (~10s) | WCAG compliance, ARIA | New pages/templates added by groups feature |

## Tool Details

### PHPCS (Linting)

```bash
ddev exec vendor/bin/phpcs --standard=phpcs.xml.dist web/modules/custom/do_*
ddev exec vendor/bin/phpcbf --standard=phpcs.xml.dist web/modules/custom/do_*
```

### PHPUnit (Unit / Kernel)

```bash
# Run all custom module tests
ddev exec phpunit -c phpunit.xml

# Run tests for one module
ddev exec phpunit web/modules/custom/do_discovery/tests/
ddev exec phpunit web/modules/custom/do_notifications/tests/

# Run phase integration tests
ddev exec phpunit web/modules/custom/do_tests/tests/src/Kernel/Phase1Test.php
```

#### Test file locations

Module-specific tests live **inside each custom module**:

```
web/modules/custom/do_discovery/
├── src/
├── tests/
│   └── src/
│       └── Kernel/
│           └── DiscoveryTest.php
└── do_discovery.info.yml
```

Phase integration tests (cross-cutting config verification) live in `do_tests`:

```
web/modules/custom/do_tests/
└── tests/
    └── src/
        └── Kernel/
            ├── Phase1Test.php   ← group types, fields, relationships
            ├── Phase2Test.php   ← vocabularies, views, pending groups
            ├── Phase3Test.php   ← cardinality, tags, stream views
            ├── Phase4Test.php   ← flags, statistics, hot content view
            ├── Phase5Test.php   ← follow flags, notification queue, frequency field
            ├── Phase6Test.php   ← content translation, languages, block placements
            └── Phase7Test.php   ← demo data integrity
```

### Playwright (E2E)

```bash
# Run all e2e tests
npx playwright test

# Run tests for one phase
npx playwright test tests/e2e/phase1.spec.ts
```

Tests live in `tests/e2e/phase{N}.spec.ts`. Each phase covers full user flows:
- Group CRUD, permissions, content posting
- Flag toggles, search, RSS/iCal feeds
- Notification settings, profile stats, language switching

> [!CAUTION]
> **Never use `waitForLoadState('networkidle')`** — Drupal sites with background AJAX will hang indefinitely. Use `waitForLoadState('load')` instead. See [os_HANGING_PROCESSES.md](./os_HANGING_PROCESSES.md) §1.

### Nightwatch (Accessibility)

```bash
composer nightwatch
```

Existing `tests/Nightwatch/Tests/a11yTest.js` runs Axe checks. Add test cases for group pages:
- `/group/*`, `/groups`, group creation form

## Module Test Matrix

| Module | Test File | Key Tests |
|---|---|---|
| `do_group_extras` | `GroupExtrasTest.php` | Archive access, guidelines form alter |
| `do_multigroup` | `MultigroupTest.php` | Group audience form alter, cross-post badge |
| `do_discovery` | `DiscoveryTest.php` | Hot score calculation, iCal route/controller |
| `do_notifications` | `NotificationsTest.php` | Event recording, suppression, auto-subscribe |
| `do_profile_stats` | `ProfileStatsTest.php` | Contribution stats, profile completeness |
| `do_group_pin` | `GroupPinTest.php` | Pin flag, Views query alter, sort order |
| `do_group_mission` | `GroupMissionTest.php` | Mission block, description truncation |
| `do_group_language` | `GroupLanguageTest.php` | Language negotiation plugin, group language field |

## Per-Phase Test Summary

| Phase | PHPUnit (module) | PHPUnit (integration) | Playwright | Step |
|---|---|---|---|---|
| 1 — Foundation | — | Phase1Test | phase1.spec.ts | 180 |
| 2 — Group Types | GroupExtrasTest | Phase2Test | phase2.spec.ts | 280 |
| 3 — Content | MultigroupTest | Phase3Test | phase3.spec.ts | 350 |
| 4 — Discovery | DiscoveryTest | Phase4Test | phase4.spec.ts | 490 |
| 5 — Notifications | NotificationsTest | Phase5Test | phase5.spec.ts | 560 |
| 6 — Profiles & Admin | 4 module tests | Phase6Test | phase6.spec.ts | 650 |
| 7 — Demo Data | — | Phase7Test | phase7.spec.ts | 780 |

## Pre-Commit Checklist

1. `ddev exec vendor/bin/phpcs --standard=phpcs.xml.dist web/modules/custom/do_*`
2. `ddev exec phpunit -c phpunit.xml --testsuite=kernel`
3. `ddev drush cr` — no PHP errors
4. `ddev drush config:status` — config is clean
