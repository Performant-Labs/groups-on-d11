# Handoff-S: Phase 6 — #231 N-3 delivery worker

**Date:** 2026-07-26
**Branch:** 231-n3-delivery-worker
**Issue:** #231 (epic #237)
**Handoffs reviewed:** brief.md, decisions.md, handoff-A.md (PASS), handoff-T-red.md (5 tests valid RED), handoff-F.md.

## Preconditions

A: PASS. T-RED: 5/5 correct-reason failures at `\Drupal::service('do_notifications.deliver_commands')`. No visual surface (CLI-only) — visual-diff preconditions N/A.

## Acceptance-criteria walk

| AC | Verified in | Result |
|---|---|---|
| #1 Class + `do_notifications:deliver` + alias + `--type` option | `DoNotificationsCommands.php` L156–158 (`#[CLI\Command]`, `#[CLI\Option]`, alias `do:notif:deliver`) | PASS |
| #2 Frequency-filtered drain, non-matching left queued | `deliver()` L168–169 passes filter into `drain()`; DB impl WHERE-filters + deletes by id; `testDeliverImmediatelyType…` asserts 2 daily entries remain | PASS |
| #3 Immediately individual; digest one call per recipient, `mid: first-mid`, `template: digest_<window>` | `deliverImmediately()` L252–270; `deliverDigestGroup()` L301–351; `testDeliverAllType…` asserts `template=digest_daily`/`digest_weekly`; `testDeliverGroupsDailyDigest…` pins per-recipient collapse + first-mid | PASS |
| #4 Idempotent | `deliver()` L170–173 early-return on empty drain; `testDeliverIsIdempotentOnEmptyQueue` asserts double-run inertness | PASS |
| #5 Skip-with-warning missing Message/recipient | `loadMessage()`/`loadRecipient()` L368–405 log WARNING; `testDeliverSkipsMissingMessageWithWarning` asserts severity=4 count=1, entry drained, no delivery | PASS |
| #6 Kernel test wiring | 5 tests, 175 assertions GREEN in F's rerun; scenario in AC #6 is exactly `testDeliverImmediatelyType…` | PASS |
| #7 `drain(?string = null)` on interface + both impls, existing tests still pass | Interface L79, DB impl L64, Mock L57; F's full-suite rerun shows `DatabaseQueueBackendTest` (2) + `SubscriptionRouterTest` (5) still GREEN | PASS |

## Scope-creep / deviation review

1. **`EmailRenderer::renderEventFragments()` `(int)` cast.** Root cause fully traced by F (`Timestamp::value` is not cast; `SqlContentEntityStorage::mapFromStorageRecords` doesn't cast; PDO returns numeric string; first caller to `load()` a Message by id is this worker; `DigestRenderer::groupByDay()` fatals under `strict_types=1`). One-line change at the single funnel both renderers use; a no-op for the in-memory-Message callers the two renderer test suites exercise. This is a legitimate, minimal in-scope bug fix — the worker cannot reach GREEN without it, and splitting it into a separate PR would gate this story on itself. In-line comment documents provenance. **Accept.**

2. **Service also registered in `do_notifications.services.yml`.** F's `LegacyServiceInstantiator` trace is correct — Drush's `drush.services.yml` does not populate Drupal's container. T's kernel test resolves via `\Drupal::service()`, which requires real container registration. Correct fix, not a workaround. **Accept.**

3. **Nullable `$notifierManager` (`'@?…'`).** Necessary because sibling suites (`DatabaseQueueBackendTest`, `Digest/EmailRendererTest`) enable `do_notifications` without `message_notify`; Symfony validates references at compile time. Runtime `notifierManagerOrFail()` guard preserves fail-fast. Uses established core pattern (`datetime.services.yml`). **Accept.**

## Test-quality audit (per `testing/test-quality.md` §7)

Five kernel tests, one per AC — proportionate, no fan-out. Each pins one behavior with distinct assertions (frequency filter / full-drain+digest / idempotency / skip-with-warning / per-recipient collapse+first-mid). Uses real queue, real Messages, real watchdog, real file sink — no mocks. Severity=4 assertion in AC #5 test correctly distinguishes WARNING from info-level delivery rows. No tautological/duplicate/snapshot-everything smells. Correct tier (kernel) — the worker's job IS wiring four real subsystems; a unit test with mocks would pin the mocks.

## Parallel-path / reuse check

No re-implementation. `EmailRenderer::render`, `DigestRenderer::render`, `LogMailer::deliver` all invoked via existing plugin-manager path (mirrors `LogMailerTest`). `drain()` extended, not sibling-methoded.

## Docblocks / style

Class-level docblock names epic + story + reuse contract. Per-method docblocks cite ACs and cross-reference. `services.yml` comments explain the deviations in-place. Matches module's existing verbose style (`SubscriptionRouter`, `DigestRenderer`).

## CI-realism

F ran full kernel suite (28/28, 1022 assertions) with `SYMFONY_DEPRECATIONS_HELPER=disabled` — CI uses `weak`, which is more permissive, so this passes. Per-module wrapper (#223) runs each module's suite in isolation; `do_notifications` is self-contained and green standalone. PHPCS clean (Drupal + DrupalPractice).

## Verdict

**PASS.** All 7 ACs met with tests. Two documented deviations are load-bearing, minimal, and correctly reasoned. Scope-creep concern on `EmailRenderer` cast resolves to a legitimate in-scope bug fix. Ready for O to open PR and self-merge on CI green.

## Advisory (non-blocking)

- Branch is 2 commits behind `origin/main` — rebase before pushing so CI runs against current base (picks up per-module wrapper #223, deploy playbook merge).
- A's finding #2 (digest `mid`-collapse lossiness) remains deferred to N-9/real-mailer planning as originally noted.
