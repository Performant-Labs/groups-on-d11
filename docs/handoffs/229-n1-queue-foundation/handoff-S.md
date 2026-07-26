# Handoff-S: Phase 9 — N-1 Queue foundation + LogMailer (#229)

**Date:** 2026-07-26
**Branch:** 229-n1-queue-foundation (worktree `groups-n1-queue-foundation-229`)
**Issue:** #229 (epic #237)
**Handoff-T-green reviewed:** `docs/handoffs/229-n1-queue-foundation/handoff-T-green.md`
**Handoff-F reviewed:** `docs/handoffs/229-n1-queue-foundation/handoff-F.md`
**Handoff-T-red reviewed:** `docs/handoffs/229-n1-queue-foundation/handoff-T-red.md`
**Brief reviewed:** `docs/handoffs/229-n1-queue-foundation/brief.md`

## A precondition
POC lean pipeline (review-rigor `none`, no A gate). Treated the approved brief as the plan artifact per O's Phase-1 decision.

## T precondition
Confirmed: T-green reports 16/16 do_notifications kernel suite GREEN, 42/42 cross-module (do_activity + do_activity_feed) GREEN, zero blocking issues.

## Preview / spec sanity
Brief is internally consistent with epic #237 Option A trim (retry deferred to N-3/#231). No convention violations.

## Spec compliance (against brief §Acceptance criteria)
| # | Criterion | Compliant | Evidence |
|---|---|---|---|
| 1 | `DatabaseQueueBackend implements QueueBackendInterface`, identical signatures | YES | `src/Queue/DatabaseQueueBackend.php:26` implements only enqueue/drain/count — no `retry` |
| 2 | `services.yml` swaps class + injects `@database` | YES | `do_notifications.services.yml:11-14` — also injects `@datetime.time` (F's deliberate DI improvement over the literal snippet, documented) |
| 3 | `.install` creates `do_notifications_queue` with unique key | YES | `do_notifications.install:73-75` — `uid_mid_frequency_day` unique key present |
| 4 | LogMailer is `@Notifier` plugin under `Plugin/Notifier/` | YES | `src/Plugin/Notifier/LogMailer.php:33-40` annotation, id `log_mailer`, viewModes `{email}` |
| 5 | Kernel: 3 enqueue → count=3, drain=3, empty; 3 deliver → 3 watchdog + 3 file lines | YES | `DatabaseQueueBackendTest::testEnqueueDrainCount` + `LogMailerTest::testDeliverWritesWatchdogAndFile` |
| 6 | Dedup: repeat tuple → count=1 | YES | `DatabaseQueueBackendTest::testEnqueueDedupOnTuple` |
| 7 | phpcs clean | YES | T-green Tier 1 table, exit 0 |
| 8 | (non-goal guard) no retry/claim/release/cron worker | YES | Interface + backend surface inspected — no extra methods |

## Spot-checks against audit list
- **`merge()` not try/catch:** `DatabaseQueueBackend::enqueue()` uses `$this->database->merge(TABLE)->keys([...])->fields([...])->execute()`. No try/catch on unique violation. PASS.
- **`getTempDirectory()` not hardcoded `/tmp`:** `LogMailer.php:120` — `$this->fileSystem->getTempDirectory() . '/' . self::LOG_FILENAME`. PASS.
- **UTC timestamp:** `LogMailer.php:111` — `gmdate('Y-m-d\TH:i:s\Z', ...)`. PASS.
- **LogMailer returns TRUE unconditionally:** `LogMailer.php:123` — unconditional `return TRUE;` after both sink writes. PASS.
- **LogMailer writes both channels:** watchdog via injected `logger.channel.do_notifications` (`.php:114-118`), file via `file_put_contents(..., FILE_APPEND | LOCK_EX)` (`.php:121`). PASS.
- **`MockQueueBackend` retained:** `src/Queue/MockQueueBackend.php` — class body intact, only docblock updated to past-tense. PASS.
- **No parallel-path duplication:** services.yml points `do_notifications.queue` exclusively at `DatabaseQueueBackend`; Mock is not a competing production wire. PASS.
- **Layering:** LogMailer does NOT touch DB (writes logger + filesystem only); DB access confined to `DatabaseQueueBackend`; no hook changes. PASS.
- **Deferred concerns documented:** brief §Non-goals + `.install`'s table description explicitly name N-3+ (retry, digest worker). PASS.
- **Source-of-truth discipline:** all production changes in `docs/groups/`; no `web/modules/custom/` edits in the diff. PASS.

## Quality audit
| Area | Result | Notes |
|---|---|---|
| API consistency | PASS | Interface signatures preserved verbatim |
| Error handling | PASS | `drain()` wraps SELECT+DELETE in transaction with explicit rollback on exception |
| Accessibility | N/A | No UI surface |
| Code organization | PASS | Constructor-promoted readonly props match `SubscriptionRouter` pattern |
| Security | PASS | No new input surface; internal service-to-service only |
| Naming consistency | PASS | Table/column names, service ids, plugin id all match brief and Drupal conventions |
| Test quality (§7) | PASS | 3 tests, each pins one behavior; raw-DB counts avoid implementation-circular assertions; kernel tier is cheapest sufficient (real unique-key + real plugin discovery). No delete/merge candidates. Proportionate to the change |
| Scope | PASS | No retry, no cron worker, no UI. Exactly the brief's 4 files + test-authorship fix to `SubscriptionRouterTest` (T-owned) |

## Deviations noted (all net-positive, documented)
1. `TimeInterface` injected into `DatabaseQueueBackend` (not `\Drupal::time()` static). Rationale in F handoff §Design decisions — DI-idiomatic, phpcs-clean, matches this module's own `SubscriptionRouter` pattern. Accepted.
2. `logger.channel.do_notifications` registered as a service (not `\Drupal::logger()` static in `deliver()`). Same rationale, mirrors `message_notify`'s own channel service. Accepted.
3. Bonus `frequency_day` index in schema — anticipates N-3+ worker query pattern, brief said "consider," non-required. Accepted.

## Verdict

**PASS** — all 8 acceptance criteria met, spec-compliant, quality acceptable, no scope drift, no layering violations. Ready for O to open PR and self-merge on CI-green.

## Advisory notes
- The pre-existing `SubscriptionRouterTest` regression the queue swap surfaced was correctly diagnosed by F (KernelTestBase never runs `hook_schema()`) and cleanly fixed by T with a one-line `installSchema()` add. Clean handoff loop.
- Line-endings note from T-green (CRLF-then-LF hiccup on Windows) is tooling-only, not a project defect.
