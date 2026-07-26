# Handoff-F: Phase 6 - #236 N-8: Digest template rendering

**Date:** 2026-07-26
**Branch:** (worktree) groups-n8-digest-template-236
**Issue:** #236

## What was done

- `docs/groups/modules/do_notifications/src/Email/EmailRenderer.php` (modified) —
  extract-method refactor: added public `renderEventFragments(MessageInterface $message): array`
  returning `['text_line', 'html_body', 'created']`; `render()` now calls it internally
  (no behavior change); added `renderHtmlPartial()` (isolated per-event HTML render) and
  `renderHtmlShellFromFragment()` (wraps a pre-rendered fragment string back into the HTML
  shell via `Markup::create()`); added N-8 cross-reference to the class docblock.
- `docs/groups/modules/do_notifications/src/Email/DigestRenderer.php` (new) — the
  `do_notifications.digest_renderer` service: validates `$window`, caps at 50 + computes
  overflow, collects fragments via `EmailRenderer::renderEventFragments()` (per-message
  try/catch), groups by UTC day (newest-day/newest-message first), renders subject +
  HTML (theme registry) + text (twig env) bodies.
- `docs/groups/modules/do_notifications/templates/emails/digest.html.twig` (new) —
  HTML digest wrapper: greeting, intro line, day-grouped `<ul>` of `{{ event.html_body|raw }}`,
  optional overflow line, unsubscribe link. Mirrors `email-shell.html.twig`'s doctype/style.
- `docs/groups/modules/do_notifications/templates/emails/digest.txt.twig` (new) — text
  counterpart, `{% autoescape false %}`, loaded directly via twig env (dual-render path).
- `docs/groups/modules/do_notifications/src/Hook/DoNotificationsEmailHooks.php` (modified) —
  registered `digest_html` theme hook (`templates/emails/digest.html.twig`); did NOT
  register `digest_text` (dual-render precedent, matches the 6 `_text` hooks that already
  exist but are never called).
- `docs/groups/modules/do_notifications/do_notifications.services.yml` (modified) —
  registered `do_notifications.digest_renderer` with the brief's locked 4-arg constructor.

## Design decisions

- **`html_body` safety round-trip:** `renderInIsolation()` returns `Markup` (safe,
  pre-escaped); casting to `string` for the fragment array (required — the test asserts
  `assertIsString()`) strips that marking. `DigestRenderer`'s own digest template
  re-marks it via `{{ event.html_body|raw }}` (brief-specified). Inside `EmailRenderer`
  itself, `renderHtmlShellFromFragment()` re-wraps the string fragment in
  `Markup::create()` before placing it on `#markup`, relying on
  `Renderer::ensureMarkupIsSafe()`'s `instanceof MarkupInterface` skip — this reproduces
  the pre-refactor nested-render-array shell output byte-for-byte (confirmed: identical
  assertion count, 415, before/after the phpcs cleanup pass).
- **Day-label timezone:** passed `'UTC'` explicitly as `DateFormatter::format()`'s 4th
  arg (brief's snippet omitted it). Necessary for correctness, not a deviation: the day
  key is computed via `gmdate()` (UTC), so formatting the label in the site's default
  timezone could show a mismatched calendar date. Verified `'custom'` type + non-empty
  format string never touches the `core.date_format.*` config entity (same kernel-test
  safety EmailRenderer's own `TIMESTAMP_FORMAT` comment documents).
- **`RendererInterface` via `\Drupal::service('renderer')`:** the brief's constructor is
  locked to exactly 4 args (no renderer), but HTML must render through `#theme` +
  `renderInIsolation()` per the brief. Matches existing, already-merged precedent in this
  same module (`DoNotificationsHooks::commentInsert()`, `CancelAllSubscriptionsForm`) —
  a phpcs *warning*, not error; the only remaining phpcs finding across all 6 production
  files.
- **Per-message defensive try/catch:** brief called this "tested" but no test exercises a
  fragment-render *exception* (only the already-covered `(removed)` deleted-entity path,
  which never throws). Implemented per the brief's literal instruction regardless.

## Reuse / extend-vs-new

Extended `EmailRenderer` per the brief's locked Reuse map: added exactly the one new
public method (`renderEventFragments()`) the brief specified, rather than duplicating its
token/`(removed)`-placeholder machinery in a new class. `DigestRenderer` is a new sibling
service — the brief explicitly justified this as new (not an extension of `EmailRenderer`)
since it aggregates N messages against a different signature/shape need (day-grouping,
cap/overflow), reusing the shell/template-directory/dual-render-path conventions instead.

## Architecture notes for A

No schema changes. New service depends only on `EmailRenderer` (not on `Token`/
`EntityTypeManagerInterface` directly) — token/removed-placeholder logic stays
single-sourced in `EmailRenderer`. `EmailRenderer`'s public surface grew by one method;
its constructor signature is unchanged. One new theme hook (`digest_html`); the 14
existing email hooks are untouched.

## Deviations from spec / wireframe

None. No UI surface (backend/kernel only, confirmed by T's handoff).

## Tier 1 self-check (incl. tests now GREEN)

Assembled via ddev (host has no `php` binary): `ddev -p gm236-digest exec bash -c "bash scripts/ci/assemble-config.sh"` — exits 0.

Target suite:
```
ddev -p gm236-digest exec bash -c "SIMPLETEST_DB=mysql://db:db@db/db SIMPLETEST_BASE_URL=https://web php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox web/modules/custom/do_notifications/tests/src/Kernel/DigestRendererTest.php web/modules/custom/do_notifications/tests/src/Kernel/EmailRendererTest.php"
```
Result: `DDDDDDDDD 9/9 (100%)` — **DigestRendererTest 6/6 GREEN**, **EmailRendererTest 3/3
GREEN**. `OK, but there were issues!` / `Tests: 9, Assertions: 415, Deprecations: 18.`
(exit 0). The 18 deprecations are the same pre-existing core/contrib deprecations T's RED
handoff already documented on the 2 baseline EmailRendererTest methods — none introduced
by this change.

Full-module regression check (not required, run for confidence): entire
`do_notifications` Kernel suite — `GroupAddNotificationTest` (6/6) and
`SubscriptionRouterTest` (5/5) unaffected. `Tests: 20, Assertions: 821, Deprecations: 18.`
(exit 0).

phpcs `--standard=Drupal,DrupalPractice` against all 6 production files:
- `EmailRenderer.php`, `DoNotificationsEmailHooks.php`, `do_notifications.services.yml`,
  `digest.html.twig`, `digest.txt.twig` — **0 errors, 0 warnings**.
- `DigestRenderer.php` — **0 errors, 1 warning** (`\Drupal calls should be avoided...`,
  the `\Drupal::service('renderer')` call discussed above; pre-existing pattern elsewhere
  in this module, not a new practice).

## Tests that look wrong (for T)

None functionally. Lint-only, FYI for T (not blocking, did not edit): a bare `phpcs
--standard=Drupal,DrupalPractice` run against T's two test files surfaces pre-existing
doc-comment/line-length findings unrelated to this issue's correctness (T's RED handoff
only ran phpunit, not phpcs) — `DigestRendererTest.php` lines 150/211/244 (doc-comment
capitalization/single-line) and `EmailRendererTest.php` lines 18/269 (line length 81
chars / doc-comment capitalization). Flagging since the task's exit bar was "phpcs
clean"; these are outside my file list to touch.

## Known issues

None. All 6 acceptance-criteria test methods pass; existing EmailRendererTest behavior
unchanged (byte-identical assertion count before/after refactor).

## Files changed

- `docs/groups/modules/do_notifications/src/Email/EmailRenderer.php` (modified)
- `docs/groups/modules/do_notifications/src/Email/DigestRenderer.php` (new)
- `docs/groups/modules/do_notifications/templates/emails/digest.html.twig` (new)
- `docs/groups/modules/do_notifications/templates/emails/digest.txt.twig` (new)
- `docs/groups/modules/do_notifications/src/Hook/DoNotificationsEmailHooks.php` (modified)
- `docs/groups/modules/do_notifications/do_notifications.services.yml` (modified)
