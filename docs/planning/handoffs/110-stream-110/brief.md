# Brief — #110 ST-1 My Feed at `/my-feed`

**Story:** #110 (Epic #108 Streams). Spec: `gh issue view 110 --repo Performant-Labs/groups-on-d11`.
**Branch:** `110-stream-110` in worktree `~/Projects/_worktrees/groups-stream-110`.
**Review rigor:** none (POC).
**UI surface:** YES — full pipeline (D → A → T-red → F → T-green → diff-gate → U → S → PR).

## Objective
Ship a live authenticated `/my-feed` page rendering the shared `do_streams_shell` (from merged #109) wrapping a `my_feed` view scoped to the current user's group memberships, with `stream_card` rows, pager, friendly empty state, and a nav-link "My Feed" seeded for auth users. Anonymous keeps `/stream`. Front page config UNTOUCHED. HelpText entry appended.

## Acceptance criteria (test-backed)
- [ ] AC-1: `GET /my-feed` unauthenticated → 403 (or login redirect).
- [ ] AC-2: `GET /my-feed` authenticated → 200 rendering `<div class="shell do-streams-shell" data-testid="do-streams-shell">`.
- [ ] AC-3: Shell shows `data-scope-id="my_feed"` tab as `is-active` / `aria-current="true"`.
- [ ] AC-4: Shell shows `data-ranking-id="recent"` pill as active.
- [ ] AC-5: For a user in ≥1 group with published content: results contain rendered `stream_card` nodes from ONLY the user's groups.
- [ ] AC-6: For a user in 0 groups: `data-testid="do-streams-shell-empty"` renders with the "Join a group..." copy and a visible CTA link to `/all-groups`.
- [ ] AC-7: Pager renders when results > 10.
- [ ] AC-8: Nav-link "My Feed" appears in the main menu for authenticated users, ordered between Activity and My Groups; anonymous users do NOT see it.
- [ ] AC-9: Elena (`elena_garcia`) sees "Sprint Planning: Portland 2026" leading (pinned) and content from her five groups; sees NO content from Thunder Distribution / Drupal Deutschland.
- [ ] AC-10: HelpText entry `stream.my_feed` exists (append-only; no existing entries mutated).
- [ ] AC-11: Front page config unchanged (`system.site.yml` diff = 0).
- [ ] AC-12: WCAG 2.2 AA — axe-core clean on `/my-feed` (labels, keyboard operability, visible focus, contrast, non-color status).

## Reuse & Analogous-Feature map (extend-first, from survey.md)
- **Shell theme hook + preprocess (`DoStreamsHooks::theme()`, `preprocessDoStreamsShell()`, template `do-streams-shell.html.twig`)** → EXTEND with an optional `empty_cta` render-array variable (default `[]`); template renders it inside the `gc-empty` block when non-empty. Forward-compat: #111-#115 will reuse the CTA slot for their scopes' empty states.
- **`MembershipScope` filter (`do_streams_membership_scope` on `node_field_data`)** → USE AS-IS in the view's `filters:` block.
- **`views.view.activity_stream.yml`** → COPY as template for structure/pager/style/row; the new view `my_feed` differs by (a) added membership scope filter, (b) `authenticated` role access, (c) DEFAULT display only (no page display — we own the route).
- **`step_780_nav_menu.php`** → APPEND 5th link entry keyed `st1-nav-my-feed`, weight between Activity(1) and My Groups(2). Route-level `_role: authenticated` requirement means Drupal's default menu-link-access filter hides the link from anonymous users.
- **`HelpText::get()`** → APPEND entry keyed `stream.my_feed`.

## New files (owned by this story)
- `docs/groups/config/views.view.my_feed.yml` (new)
- `docs/groups/modules/do_streams/do_streams.routing.yml` (new — first route in this module)
- `docs/groups/modules/do_streams/src/Controller/MyFeedController.php` (new)
- `docs/groups/modules/do_streams/css/my-feed.css` (new; can be minimal)
- `docs/groups/modules/do_streams/do_streams.libraries.yml` (new if CSS attached via library; verify one doesn't exist)
- `tests/e2e/my-feed.spec.ts` (new)
- Optional: kernel/functional test file for controller behavior and empty-state (new file — no shared test file editing).

## Extended files (surgical, additive)
- `docs/groups/modules/do_streams/src/Hook/DoStreamsHooks.php` — add `empty_cta` var to theme hook + pass through in preprocess.
- `docs/groups/modules/do_streams/templates/do-streams-shell.html.twig` — render `empty_cta` inside `gc-empty` when set.
- `docs/groups/modules/do_chrome/src/HelpText.php` — APPEND-ONLY: new `stream.my_feed` entry.
- `docs/groups/scripts/step_780_nav_menu.php` — APPEND-ONLY: new nav-link entry.

## Non-goals / out of scope
- Do NOT change the front page. Q1 stays open.
- Do NOT touch `activity_stream.yml` or `/stream` (anonymous fallback).
- Do NOT wire Following/Trending scopes or ranking switcher behavior — this is scope=my_feed, ranking=recent only.
- Do NOT edit any existing HelpText entry; do NOT edit any other nav link.
- Do NOT commit `web/modules/custom/**` or `config/sync/**`.

## Verification
- Kernel/Functional (assembled layout): PHPUnit runs via `php vendor/bin/phpunit -c web/core/phpunit.xml.dist $(find web/modules/custom -type d -path '*/tests/src/Kernel')`.
- E2E: `npx playwright test tests/e2e/my-feed.spec.ts` against seeded site.
- Lint: `php vendor/bin/phpcs docs/groups/modules/do_streams docs/groups/modules/do_chrome`.
- Assemble: `bash scripts/ci/assemble-config.sh` before every verification.

## Handoffs
- `docs/planning/handoffs/110-stream-110/survey.md` (done)
- `docs/planning/handoffs/110-stream-110/brief.md` (this)
- `docs/planning/handoffs/110-stream-110/wireframe.html` (D writes)
- `docs/planning/handoffs/110-stream-110/handoff-*.md` (each phase)
- `docs/planning/handoffs/110-stream-110/decisions.md` (append every phase)
