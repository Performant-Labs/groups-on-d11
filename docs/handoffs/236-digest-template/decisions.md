# Decision journal — #236 N-8: Digest template rendering

## T (Phase 4 — RED)
**Decided:** Authored 6 new `DigestRendererTest` methods (happy path across 20 messages/3
days, 50-cap + overflow, deleted-entity fallback flow-through, invalid-window throw,
weekly-label phrasing, empty-message-list edge case) plus one new `EmailRendererTest`
method (`testRenderEventFragmentsReturnsLockedShape`) pinning the A-locked
`renderEventFragments()` extract-method contract (`text_line`/`html_body`/`created`
keys).

**Self-caught invalid-RED fix:** `testInvalidWindowThrows` initially resolved the
service and called `render()` inside a single `expectException()`-armed expression,
which would have made the test pass falsely (green) via the container's
`ServiceNotFoundException` before any feature code existed. Fixed by resolving
`$renderer = $this->renderer();` **before** arming `expectException()`, so the
missing-service error surfaces as an unhandled `Error` outside the expectation,
correctly failing until the service exists.

**Evidence:** RED confirmed — `DigestRendererTest` 6/6 fail with
`ServiceNotFoundException: You have requested a non-existent service
"do_notifications.digest_renderer"`; `EmailRendererTest`
`testRenderEventFragmentsReturnsLockedShape` fails with `Error: Call to undefined
method ...::renderEventFragments()`; the 2 pre-existing `EmailRendererTest` methods
remain GREEN (extract-method not yet performed, confirming no pre-existing regression).

**Decided (DDEV):** Created DDEV project `gm236-digest` for this worktree; had to
delete a stale `.ddev/config.gm139.yaml` override and `ddev stop --unlist
gm139-multilang-rtl` to clear a registry name collision (copy-paste artifact from a
prior worktree, not a project defect).

**Report:** `handoff-T-red.md`.

## F (Phase 5 — implementation)
**Decided:** Extracted `EmailRenderer::renderEventFragments()` (public) per the brief's
locked shape; `render()` now calls it internally, then re-wraps the returned
`text_line`/`html_body` strings in the existing shells. No behavior change — confirmed
by an identical assertion count (415) across `DigestRendererTest` + `EmailRendererTest`
before and after a later phpcs-driven doc-comment cleanup pass.

**Decided (Markup safety):** `renderInIsolation()` returns `Markup` (pre-escaped safe
HTML); casting to `string` for the `html_body` fragment (required by
`assertIsString()`) strips that marking. Re-wrapped the fragment string in
`Markup::create()` before placing it back on the HTML shell's `#markup` key, relying on
`Renderer::ensureMarkupIsSafe()`'s `instanceof MarkupInterface` skip so the fragment
is not re-filtered/re-escaped — reproduces the pre-refactor nested-render-array output
byte-for-byte. `DigestRenderer`'s own digest template re-marks the same fragment safe
via Twig's `|raw` filter (brief-specified), the equivalent mechanism at the template
layer.

**Decided (day-label timezone):** Passed `'UTC'` explicitly as `DateFormatter::
format()`'s 4th argument, though the brief's format-string snippet didn't show it.
Necessary for correctness: the day-grouping key is computed via `gmdate()` (UTC), so
letting the label format in the site's default timezone could show a mismatched
calendar date for sites not on UTC. Verified `'custom'` type + non-empty format string
never touches `core.date_format.*` config entities, preserving the kernel-test safety
`EmailRenderer::TIMESTAMP_FORMAT`'s own comment documents.

**Decided (renderer access):** Used `\Drupal::service('renderer')` inside
`DigestRenderer` rather than adding a 5th constructor argument, since the brief's
constructor signature (`EmailRenderer`, `TwigEnvironment`, `ModuleExtensionList`,
`DateFormatterInterface`) is locked and HTML must render through `#theme` +
`renderInIsolation()` per the brief. Confirmed this matches existing, already-merged
precedent in this exact module (`DoNotificationsHooks::commentInsert()`,
`CancelAllSubscriptionsForm`) — a phpcs *warning*, not error, and the only remaining
phpcs finding across all 6 production files touched.

**Assumed:** The per-message defensive try/catch in `collectFragments()` (brief:
"tested") has no test actually exercising a fragment-render *exception* — only the
already-covered `(removed)` deleted-entity path, which `EmailRenderer` handles without
throwing. Implemented per the brief's literal instruction regardless; flagged in the
handoff as a note, not a blocker (no test looks wrong — this is scope the brief
described without T pinning it, not a defect in T's suite).

**Evidence:** GREEN confirmed —
`ddev -p gm236-digest exec bash -c "SIMPLETEST_DB=mysql://db:db@db/db
SIMPLETEST_BASE_URL=https://web php vendor/bin/phpunit -c web/core/phpunit.xml.dist
--testdox web/modules/custom/do_notifications/tests/src/Kernel/DigestRendererTest.php
web/modules/custom/do_notifications/tests/src/Kernel/EmailRendererTest.php"` →
`Tests: 9, Assertions: 415, Deprecations: 18` (exit 0) — DigestRendererTest 6/6,
EmailRendererTest 3/3, all GREEN. Wider regression check (full `do_notifications`
Kernel suite, not required): `Tests: 20, Assertions: 821, Deprecations: 18` (exit 0) —
`GroupAddNotificationTest` and `SubscriptionRouterTest` unaffected. phpcs
`--standard=Drupal,DrupalPractice`: 5 of 6 production files 0 errors/0 warnings;
`DigestRenderer.php` 0 errors/1 warning (the `\Drupal::service()` call above).

**Report:** `handoff-F-green.md`.
