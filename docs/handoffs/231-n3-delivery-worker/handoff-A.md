# Handoff-A: Phase 3 — #231 N-3 delivery worker (up-front plan review)

**Date:** 2026-07-26
**Branch:** 231-n3-delivery-worker
**Brief reviewed:** docs/handoffs/231-n3-delivery-worker/brief.md
**Reuse map:** brief §"Reuse & Analogous-Feature map"
**Verdict:** PASS

## Summary

Plan extends the three named seams (drain signature, EmailRenderer, DigestRenderer, LogMailer) rather than duplicating them, and the one new object (DoNotificationsCommands) is correctly justified — the module has no existing drush surface. No parallel-path risk. Proceed to T-red.

## Findings

| # | Severity | Plan element | Drift dimension | Finding | Suggested fix |
|---|---|---|---|---|---|
| 1 | warn | AC #7 — `drain(?string $frequency = null)` | layering / interface change | BC preserved for callers, but only 2 impls + a couple of tests touch it today, so signature extension is cheap and correct over a sibling method. Watch: interface docblock still says "Removes and returns EVERY currently queued entry" — F should update the docblock to describe the optional filter, not just add the parameter. | Update QueueBackendInterface::drain() docblock in same change. |
| 2 | warn | AC #3 digest — one LogMailer call per recipient with `mid: first-mid` | abstraction / contract fit | LogMailer's watchdog+file-sink rows are 1:1 with `deliver()` calls, not with logical emails. Using first-mid as the identifier is a lossy collapse (the other N-1 mids in the digest are dropped from the log). Fine for POC per decisions.md, but note the ambiguity for N-9/real-mailer story — a future digest-audit query cannot recover which messages were bundled. | No change now; call out in handoff-F §Deferred so N-9 planning sees it. |
| 3 | warn | New drush surface | pattern consistency | Module has no prior drush commands; project has none either (verified — no `drush.services.yml` under docs/groups/modules/do_*). Drush 13 attributes + drush.services.yml is the correct current pattern. No local precedent to mirror. | None. |

## Notes for O

None — PASS.

## Patterns referenced

- docs/groups/modules/do_notifications/src/Queue/QueueBackendInterface.php
- docs/groups/modules/do_notifications/src/Queue/DatabaseQueueBackend.php
- docs/groups/modules/do_notifications/src/Plugin/Notifier/LogMailer.php
- docs/groups/modules/do_notifications/src/Email/{EmailRenderer,DigestRenderer}.php (referenced via brief)
- No prior drush.services.yml in any do_* module (verified absence).
