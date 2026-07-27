# Handoff-A: Phase 3 — #234 N-6 Daily digest worker (up-front plan review)

**Date:** 2026-07-26
**Branch:** 234-n6-daily-digest
**Brief reviewed:** docs/planning/handoffs/234-n6-daily-digest/brief.md
**Reuse map:** docs/planning/handoffs/234-n6-daily-digest/survey.md
**Wireframe:** N/A (no UI)
**Verdict:** PASS

## Summary

The plan correctly extends the existing `QueueBackendInterface` for read/delete and introduces a
narrow, parallel `DigestQueueBackendInterface` + new `do_notifications_digest_queue` table for the
aggregated payload — an object with a genuinely distinct contract (no `mid`, carries rendered
body columns) that cannot be honestly folded into the per-message queue's `(uid, mid, frequency,
day)` dedup key. The new-table decision also sidesteps a merge collision with the open N-5 PR
#261. Naming, DI wiring, schema idioms, and interface shape all match the neighboring N-1/N-2/N-8
patterns. One nit on the update-hook version number; otherwise no drift.

## Findings

| # | Severity | Plan element | Drift dimension | Finding | Suggested fix |
|---|---|---|---|---|---|
| 1 | warn | `hook_update_9001()` | naming/versioning | Drupal 11 convention is `hook_update_N` where N encodes core-major + sequence, i.e. `hook_update_11001()` for the first D11 update. `9001` would signal a D9 update on a D11 module and confuse `drush updatedb`. | Rename to `do_notifications_update_11001()`. |
| 2 | warn | `services.yml` DI for `DigestCommands` | pattern consistency | Drush 12+ discovers command classes via `drush.services.yml` (which the brief already lists as NEW). Duplicating the class in `do_notifications.services.yml` risks double-instantiation. Keep DI wiring in `drush.services.yml` only unless the command is *also* used as a plain service elsewhere (it isn't). | Register `do_notifications.digest_command` in `drush.services.yml` only; drop from `services.yml`. |
| 3 | warn | `DigestQueueBackendInterface::drain()` | contract consistency | `QueueBackendInterface::drain()` is documented as a "test/inspection helper". For the digest queue, N-3 will be the real consumer, and a read-and-delete `drain()` used by production is a different contract shape than the sibling. `all()` (read-only) + a separate `deleteByIds()` mirrors the same shape the brief adopts for the per-message queue and gives N-3 the same claim-then-delete idempotency the brief cites as its rationale for splitting `claimDaily` from `deleteByIds`. | Consider dropping `drain()` from the digest interface (or explicitly marking it test-only) and adding `deleteByIds(array $ids): void` so N-3 gets the same work-then-delete pattern. Non-blocking; T-red can lock. |

## Answers to O's key questions

1. **New table vs. extend existing:** correct. The per-message queue's dedup key requires a `mid`; a digest row has none. Jamming nullable `mid` + payload columns would violate the documented contract *and* collide with #261. New table is the right call — and it's the *same* pattern N-1 established (narrow interface + `Database*Backend` + `Mock*Backend`).

2. **`claimDaily` + `deleteByIds` vs. single `claimAndDelete`:** the split is right. The brief's rationale (mid-run failure → next-run idempotent replay because originals still exist) is a real correctness win, and the two-method shape also lets N-7 add `claimWeekly` in the same idiom without touching the daily path. `claimAndDelete` would force all-or-nothing atomicity across an N-message render loop that can partially fail per user.

3. **Duplication check:** none missed. `DigestRenderer` is reused verbatim (per survey). `EmailRenderer`, `FrequencyResolver`, state, time, entity_type.manager all reused. The two "NEW" objects (`DigestQueueBackend*`, `DigestCommands`) have no existing analogue in the module — `ls src/Commands` empty was confirmed.

4. **`hook_update_9001()`:** wrong number. See finding #1. This module targets D11; use `11001`. Not a design flaw, but a real ops footgun — flagged as `warn` since it doesn't invalidate the plan.

5. **Omissions that will bite T-red / F:**
   - Brief says the command reads `state.get('do_notifications.digest_daily.hour_utc', 2)` but is "informational only". T-red should not assert on it (there's no observable behavior). Fine as-is, just noting.
   - Brief doesn't spell out what happens to a queue row whose `mid`'s Message has been deleted (loadMultiple returns nothing for that id). Two reasonable behaviors: (a) drop from the digest, delete the queue row anyway (garbage-collect); (b) drop from digest, leave the row (retry next run). Pick one in T-red — I'd recommend (a) so orphaned rows don't accumulate forever.
   - AC8's summary shape (`users_digested`, `items_consumed`, `digests_enqueued`) is already hedged as "T-red locks the exact surface" — good.

## Notes for O

None required (PASS). Recommend F address findings #1 and #2 inline while implementing; #3 is optional and can be locked by T-red.

## Patterns referenced

- `docs/groups/modules/do_notifications/do_notifications.install` — schema idiom + no prior update hooks (so #11001 is the first).
- `docs/groups/modules/do_notifications/do_notifications.services.yml` — DI wiring cadence.
- `docs/groups/modules/do_notifications/src/Queue/QueueBackendInterface.php` — narrow-interface + Database/Mock pair pattern.
- `docs/groups/modules/do_notifications/src/Queue/DatabaseQueueBackend.php` — `Connection::merge()` / transactional drain idioms.
- `docs/groups/modules/do_notifications/src/Email/DigestRenderer.php` — the object being reused verbatim.
