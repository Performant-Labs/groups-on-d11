# Brief — #236 N-8: Digest template rendering

## Objective

Add a **digest** rendering path to `do_notifications` that wraps N+ per-event summaries (via existing `EmailRenderer`, PR #240) into a single email payload aggregating multiple `Message` entities for a given recipient + window ("daily" | "weekly").

Non-goals: cron/scheduling, queue integration, mailer sends (N-1). Digest wrapper renders `['subject' => ..., 'body_text' => ..., 'body_html' => ...]` exactly like `EmailRenderer::render()`.

## Key requirements (from issue)

- **Subject:** `Your <daily|weekly> summary — <N> updates` (N = cap-clamped count actually rendered as bullets, per S-agreed interpretation below).
- **Body:** greeting → "Here's what happened this <day|week>:" → grouped-by-day bullet lists → "…and X more" (if truncated) → unsubscribe link (`/user/{uid}/notification-settings`).
- **Cap:** 50 events; extras collapsed into `"…and X more"` line where X = total_incoming − 50.
- **Group by day:** Messages grouped by their `created` day (UTC). Groups ordered newest-day-first; within a day, newest Message first.
- **Deleted entities:** N-4's `(removed)` fallback already applies at per-event level. Digest passes each message through `EmailRenderer::render()` and uses `body_text` / `body_html` fragments — no additional handling needed. If N-4 raises for a message, digest catches + skips (defensive; tested).
- **Twig, translatable:** `{{ 'string'|t }}` for every user-visible string.
- **Text + HTML variants:** `digest.html.twig` + `digest.txt.twig`, same `templates/emails/` dir. Text loads via twig env (dual-render path, same as shells).

## Design decisions (locked)

1. **New service** `Drupal\do_notifications\Email\DigestRenderer` — sibling to `EmailRenderer`. Constructor takes `EmailRenderer`, `TwigEnvironment`, `ModuleExtensionList`, `DateFormatterInterface`. Reuses N-4 rather than duplicating its logic.
2. **Public signature:**
   ```php
   public function render(array $messages, UserInterface $recipient, string $window): array
   ```
   - `$messages`: `MessageInterface[]` — caller-supplied list (N-9 scheduler will assemble it).
   - `$window`: `'daily' | 'weekly'` — drives the label. Any other value → thrown `\InvalidArgumentException` (fail-fast).
   - Returns `['subject' => string, 'body_text' => string, 'body_html' => string]`.
3. **Sub-summary extraction:** For each message, call `EmailRenderer::render($message, $recipient)`. Take the **rendered `body_text` line for text**, and the **rendered `body_html` per-event partial for html**. Since N-4 wraps everything in the SHELL (with unsubscribe + timestamp), a digest wants only the *body fragment* — so `DigestRenderer` will call a **new package-private helper on `EmailRenderer`** exposing just the per-event body pair `['text' => ..., 'html' => ..., 'created' => timestamp]`. This is the ONE new method on EmailRenderer required — smaller than duplicating the token/removed-placeholder machinery.
   - New method: `EmailRenderer::renderEventFragments(MessageInterface $message): array` returning `['text_line' => string, 'html_body' => string, 'created' => int]`.
   - Refactor `EmailRenderer::render()` to internally use it (extract-method; no behavior change; existing tests continue to pass).
4. **Cap at 50:** truncate list before rendering (don't waste work). Store `$overflow = max(0, count($incoming) - 50)`.
5. **Grouping:** Use PHP's `DateTimeImmutable` in UTC (per issue: "UTC"). Day key = `Y-m-d`. Day label = long form ('l, F j, Y' → e.g. "Wednesday, November 14, 2023") via DateFormatter.
6. **Templates:**
   - `templates/emails/digest.html.twig`, `templates/emails/digest.txt.twig`.
   - Variables: `greeting`, `window_label` ('day'|'week'), `day_groups` (array of `{label, events: [{html|text}]}`), `overflow_line` (or NULL), `unsubscribe_url`.
7. **Theme hooks:** Register `digest_html` (via HTML theme registry) in `DoNotificationsEmailHooks::theme()`. Text variant loads directly via twig env, same dual-render pattern as shells.

## Acceptance criteria (kernel)

- [x] `DigestRendererTest::testRendersDigestFor20Messages()` — build 20 real activity Messages spread across 3 days, assert:
  - subject matches `Your daily summary — 20 updates`
  - body_text contains greeting + "Here's what happened this day:" + unsubscribe path + at least one per-message fragment
  - body_html contains unsubscribe link + no raw tokens (`[message:`) + no unreplaced Twig (`{{`)
  - day-group headings appear in newest-first order
- [x] `DigestRendererTest::testCapsAt50AndReportsOverflow()` — build 60 messages, assert:
  - subject shows `— 50 updates`
  - body contains `…and 10 more` (text + html)
- [x] `DigestRendererTest::testDeletedEntityFallbackFlowsThrough()` — one message references a deleted node; assert `(removed)` appears in digest body_text.
- [x] `DigestRendererTest::testInvalidWindowThrows()` — `render($msgs, $recipient, 'monthly')` → `\InvalidArgumentException`.
- [x] `DigestRendererTest::testWeeklyLabel()` — window='weekly' → subject "Your weekly summary — N updates" + body "this week".
- [x] Existing `EmailRendererTest` still GREEN (extract-method must not regress N-4 behavior).

## Files to change

- ADD: `docs/groups/modules/do_notifications/src/Email/DigestRenderer.php`
- MODIFY: `docs/groups/modules/do_notifications/src/Email/EmailRenderer.php` (extract `renderEventFragments()` helper; call from `render()`; make public so DigestRenderer can consume)
- ADD: `docs/groups/modules/do_notifications/templates/emails/digest.html.twig`
- ADD: `docs/groups/modules/do_notifications/templates/emails/digest.txt.twig`
- MODIFY: `docs/groups/modules/do_notifications/src/Hook/DoNotificationsEmailHooks.php` (register `digest_html` theme hook)
- MODIFY: `docs/groups/modules/do_notifications/do_notifications.services.yml` (register `do_notifications.digest_renderer` service)
- ADD: `docs/groups/modules/do_notifications/tests/src/Kernel/DigestRendererTest.php`

## Review rigor

`none` (POC lean pipeline).

## Reuse & Analogous-Feature map

- **Closest analogous feature:** `EmailRenderer` (N-4, PR #240) — same shape (`['subject','body_text','body_html']`), same dual-render (html theme registry + text via twig env), same `templates/emails/` dir. **Extend: expose one new helper method (`renderEventFragments`) so digest doesn't duplicate token/removed-placeholder logic.**
- **Shell templates:** `email-shell.{html,txt}.twig` — analogous structure (`timestamp` + `body` + unsubscribe). Digest shells inherit the same conventions: `{% autoescape false %}` in text; `<style>body{font-family:sans-serif}</style>` inline in HTML.
- **Test base:** `GroupsKernelTestBase` (used by `EmailRendererTest`) — reuse identical module list + setUp.

## Non-negotiables

- `#[RunTestsInSeparateProcesses]` on the kernel test class.
- `declare(strict_types=1);` on all new PHP.
- No `t()` bypassed — every string user-visible must be translatable.
- phpcs `--standard=Drupal,DrupalPractice` clean.
- `bash scripts/ci/assemble-config.sh` exits 0.
