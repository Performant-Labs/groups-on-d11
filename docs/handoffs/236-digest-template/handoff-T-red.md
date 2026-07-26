# Handoff-T-red: Phase 4 - #236 N-8: Digest template rendering

**Date:** 2026-07-26
**Branch:** (worktree) groups-n8-digest-template-236
**Brief / wireframe reviewed:** `docs/handoffs/236-digest-template/brief.md` (no UI surface — backend/kernel only, no wireframe)

## A precondition

Confirmed: A's design decisions are locked in the brief itself (`DigestRenderer::render()` signature,
`EmailRenderer::renderEventFragments()` return shape `['text_line', 'html_body', 'created']`, service
name `do_notifications.digest_renderer`). No separate `handoff-A-plan.md` exists for this issue; the
brief document doubles as the approved plan per POC lean pipeline (`Review rigor: none`).

## Tests authored

**`docs/groups/modules/do_notifications/tests/src/Kernel/DigestRendererTest.php`** (new file, Kernel tier — requires a real DB, real `activity_*` Message entities, and a real container to resolve the not-yet-existent service):

1. `testRendersDigestFor20Messages` — pins the core happy path: 20 real `activity_post_created` Messages across 3 UTC days render one payload with subject `Your daily summary — 20 updates`, the daily greeting line, unsubscribe path in both bodies, no raw token/Twig remnants, and at least one per-event fragment surfacing in the combined body.
2. `testCapsAt50AndReportsOverflow` — pins the 50-cap + overflow-reporting behavior with 60 messages: subject shows `— 50 updates`, both bodies contain `…and 10 more`.
3. `testDeletedEntityFallbackFlowsThrough` — pins that N-4's `(removed)` fallback flows through the digest wrapper for one message in a 3-message batch, without the digest itself needing extra handling.
4. `testInvalidWindowThrows` — pins the fail-fast contract: an invalid `$window` throws `\InvalidArgumentException`.
5. `testWeeklyLabel` — pins the 'weekly' window's distinct subject/body phrasing.
6. `testEmptyMessagesReturnsSubjectWithZero` — edge case: zero messages still renders a well-formed payload with `Your daily summary — 0 updates`.

All six sit at the Kernel tier (cheapest sufficient tier) because they exercise a real DI container, real Message/Group/Node entities, and real Twig template loading — no cheaper (unit) tier can validate the end-to-end token-replacement + template-rendering pipeline this service wraps, and this mirrors the sibling `EmailRendererTest`'s own tier choice.

**`docs/groups/modules/do_notifications/tests/src/Kernel/EmailRendererTest.php`** (added one method to the existing file):

7. `testRenderEventFragmentsReturnsLockedShape` — pins the A-locked extract-method contract: `EmailRenderer::renderEventFragments($message)` returns exactly `['text_line' => non-empty string, 'html_body' => non-empty string, 'created' => int matching Message::getCreatedTime()]`. This guards the refactor F must perform without regressing N-4.

## RED confirmation

Assembled config first: `bash scripts/ci/assemble-config.sh` (run via `ddev exec` since the DDEV
container, not the host, has PHP — the worktree's host environment has no `php` binary).

Command (from worktree root):
```
ddev exec 'SIMPLETEST_DB=mysql://db:db@db/db SIMPLETEST_BASE_URL=https://web php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox web/modules/custom/do_notifications/tests/src/Kernel/DigestRendererTest.php'
```
Result: `EEEEEE 6/6 (100%)` — all 6 fail with:
```
Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException:
You have requested a non-existent service "do_notifications.digest_renderer".
```
This is the RIGHT reason: the service, class, and templates F must add don't exist yet.

**Self-caught invalid-RED fix:** `testInvalidWindowThrows` initially used
`$this->expectException(...); $this->renderer()->render(...)` — a single expression — which meant
PHPUnit's `expectException(InvalidArgumentException::class)` was armed before the
`ServiceNotFoundException` fired inside the same call, and PHPUnit reported it "passing" (green)
before any code existed — an invalid RED. Fixed by resolving `$renderer = $this->renderer();`
**before** calling `expectException()`, so the missing-service error now surfaces as an unhandled
`Error` outside the exception expectation, correctly failing this test too. Re-run confirmed:
```
✘ Invalid window throws
   Symfony\...\ServiceNotFoundException: You have requested a non-existent service "do_notifications.digest_renderer".
```

Command:
```
ddev exec 'SIMPLETEST_DB=mysql://db:db@db/db SIMPLETEST_BASE_URL=https://web php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox web/modules/custom/do_notifications/tests/src/Kernel/EmailRendererTest.php'
```
Result: `DDE 3/3` → `Tests: 3, Assertions: 178, Errors: 1, Deprecations: 18.`
- `testRendersAllSixEventTypes` — PASS (warning-only, from unrelated core deprecations)
- `testMissingReferencedEntityRendersRemoved` — PASS (warning-only)
- `testRenderEventFragmentsReturnsLockedShape` — FAILS with:
  ```
  Error: Call to undefined method Drupal\do_notifications\Email\EmailRenderer::renderEventFragments()
  ```
  Right reason: the method doesn't exist yet (extract-method not yet performed).

Both existing `EmailRendererTest` methods remain GREEN — the extract-method refactor F must perform
has not yet touched `EmailRenderer::render()`'s public behavior, confirming this RED does not
regress N-4's existing surface.

## Ready for F

Confirmed RED is valid (7 new/added test methods, all failing for the right reason — missing
service, missing class, or missing method — with existing N-4 tests unaffected). F may implement
against `DigestRendererTest.php` and the new `EmailRendererTest::testRenderEventFragmentsReturnsLockedShape`.

DDEV project `gm236-digest` created in this worktree (`ddev config --project-name=gm236-digest --auto`;
had to delete a stale `.ddev/config.gm139.yaml` override left over from a prior worktree copy, and
`ddev stop --unlist gm139-multilang-rtl` to clear a registry name collision).
