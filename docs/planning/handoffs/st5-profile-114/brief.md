# Brief — #114 ST-5 Profile activity stream

**Worktree:** `C:/Users/aange/Projects/_worktrees/groups-st5-profile-114`
**Branch:** `114-profile-activity` (tracks origin/main)
**Review-rigor:** `none` (per issue) — POC lean pipeline: skip brief-gate + A-dup + pre-PR-hold.

## Objective

Add a "Recent posts" block on `/user/{uid}` that lists the profile owner's **published** authored nodes, filtered by the **viewer's** node_access grants (so private-group content stays private), newest first. Delivered as a Views block placed on the user canonical page.

## Survey summary

See [`survey.md`](survey.md). Full extend/reuse: new view `user_activity` (mirrors `group_content_stream` pattern but uses `node_field_data.uid` author argument + `node_access` filter + `status=1`). Block YAML places it on `/user/*`. Uses existing `stream_card` view mode. One new CSS file. No new PHP required for base story; optional Kernel test file for access-scope assertion.

## Design mode

Mode (b): **no user-supplied wireframe.** A simple sectioned list under existing profile chrome. D produces the wireframe. Auto-approve per POC lean pipeline.

## Files touched (all NEW)

- `docs/groups/config/views.view.user_activity.yml`
- `docs/groups/config/block.block.do_streams_user_activity.yml`
- `docs/groups/modules/do_streams/css/profile-activity.css`
- `docs/groups/modules/do_streams/do_streams.libraries.yml` — EXTEND to register the new CSS library
- `docs/groups/modules/do_streams/do_streams.module` — EXTEND to attach the library to the block render (or via view's #attached; minimize surface)
- `docs/groups/modules/do_streams/src/HelpText.php` if it exists, else `docs/groups/modules/do_chrome/src/HelpText.php` — append-only helptext entry
- `tests/e2e/profile-activity.spec.ts` (new)
- `docs/groups/modules/do_streams/tests/src/Kernel/UserActivityViewTest.php` (new — access-scope + published-only)

## Acceptance criteria (checkboxes)

- [ ] `views.view.user_activity.yml` present with: base_table `node_field_data`, author uid contextual argument (default from URL user, path `user/%`), status=1 filter, `node_access` filter, created DESC sort, row plugin entity:node view_mode stream_card, block display.
- [ ] `block.block.do_streams_user_activity.yml` places the block, visibility scoped to `/user/*`, title = "Recent posts".
- [ ] `profile-activity.css` scoped under a `.do-streams-profile-activity` wrapper class; no global selectors.
- [ ] Playwright `tests/e2e/profile-activity.spec.ts` asserts on the seeded site: visit Maria's profile, the "Recent posts" section is present, contains at least three titles including "Sprint Planning: Portland 2026", newest-first ordering.
- [ ] Kernel test asserts: (a) unpublished node absent; (b) node in a private group is absent for a viewer not in the group; (c) node in an accessible group is present.
- [ ] HelpText entry appended (append-only) for "Profile activity" surface.
- [ ] `phpcs` clean; existing PHPUnit + Playwright suites remain green.
- [ ] WCAG 2.2 AA: section heading semantics, keyboard operability, focus visible, AA contrast. No color-only status. U-agent verifies.

## Verification commands (T will run)

- Assemble: `bash scripts/ci/assemble-config.sh`
- Kernel: `php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox web/modules/custom/do_streams/tests/src/Kernel/UserActivityViewTest.php`
- Lint: `php vendor/bin/phpcs web/modules/custom/do_streams`
- E2E: `npx playwright test tests/e2e/profile-activity.spec.ts`

## Out of scope

- Social actions ("joined X", "RSVP'd Y") — that's #116 / ST-F2 (message-based).
- Pagination beyond a small "recent" cap (view default items_per_page = 10 is fine).
- Redesign of the user page.

## Handoffs directory

`docs/planning/handoffs/st5-profile-114/`
