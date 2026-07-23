# Handoff-S: Phase 9 — Spec Audit — ST-5 Profile activity stream (#114)

**Date:** 2026-07-23
**Branch:** `114-profile-activity`
**Worktree:** `C:/Users/aange/Projects/_worktrees/groups-st5-profile-114`
**Issue:** #114
**Verdict:** **PASS**

## Preconditions
- A (Phase 3): PASS.
- T-GREEN rev-1: 0 blocking issues (Kernel 6/6, E2E 1/1, phpcs clean on delta).
- U (Phase 8): PASS.
- Visual-diff tooling precondition N/A — this story reuses the existing `stream_card` view mode; no novel visual surface beyond wrapper spacing rules. U-agent captured wireframe-conformance screenshots at 360 and desktop; those are the operator-facing evidence per POC-lean pipeline convention for text-list surfaces.

## AC coverage table (issue text + brief checkboxes)

| # | Acceptance criterion (source) | Test/evidence | Result |
|---|---|---|---|
| 1 | Maria's profile lists her three topics (Sprint Planning: Portland 2026, Weekly Standup Notes, Budget Allocation Q3 2026) — issue AC1 | `tests/e2e/profile-activity.spec.ts` "Recent posts section renders on Maria's profile with her seeded topics" (GREEN) + U check #2 (live: `/node/1`, `/node/6`, `/node/10` verified) | PASS |
| 2 | Access-safe: outsider viewer doesn't see private-group content; unpublished never renders — issue AC2 | Kernel `testAccessScopingExcludesPrivateGroupNodeForNonMember` + `...IncludesPrivateGroupNodeForMember` (sanity companion; not vacuous) + `testPublishedOnlyExcludesUnpublishedNode` | PASS |
| 3 | Playwright rendered-DOM spec on the seeded site; existing suite stays green — issue AC3 | `profile-activity.spec.ts` (1/1); T rev-1 full-suite check confirms no regressions in `do_streams` Kernel (31/31) or `do_chrome` unit (16/16) | PASS |
| 4 | Ships with HelpText entry (append-only) — issue AC4 / SD-6 backstop | `HelpText.php` diff = pure append of `profile_activity.section`; distinct from reserved `page.profile_stream` (#126) with rationale documented | PASS |
| 5 | WCAG 2.2 AA — issue AC5 | U checks 1/4/7 + spot-check block: real `<h2>`, focus outline `rgb(27, 39, 51)` 2px, muted text ≈ 12.6:1 (well above 4.5:1), no color-only status; wireframe honors non-leaky "No posts yet." empty state | PASS |
| 6 | Delivery per epic (throwaway-DB rendered-DOM + local Playwright green + PR + merge on green CI) — issue AC6 | T-GREEN rev-1 ran full assemble → config:import → seed → live browser assert on `gm114-profile.ddev.site`; E2E 1/1 green with double-run stability check | PASS |
| 7 | Brief checkbox — view YAML shape (base_table `node_field_data`, uid contextual arg default from URL user, status=1, node_access filter, created DESC, row = `entity:node`/`stream_card`, block display) | Direct inspection of `docs/groups/config/views.view.user_activity.yml`: `base_table: node_field_data` (L14), `arguments.uid` with `plugin_id: numeric`, `default_action: default`, `default_argument_type: user` (L43-63), `filters.status.value: '1'` (L79-93), `filters.nid` on `table: node_access` `plugin_id: node_access` (L94-129), `sorts.created` `order: DESC` (L30-42), `row.type: 'entity:node'` `view_mode: stream_card` (L140-144), `block_1` display (L177-183) | PASS |
| 8 | Brief checkbox — block YAML places block on `/user/*` with title "Recent posts" | `block.block.do_streams_user_activity.yml`: `visibility.request_path.pages: '/user/*'` (L24-29), `label: 'Recent posts'`, `label_display: visible`, correct theme `groups_chrome` (F's evidence: `bluecheese` blocks are EXCLUDEd by assemble script) | PASS |
| 9 | Brief checkbox — CSS scoped under `.do-streams-profile-activity`, no global selectors | `profile-activity.css`: both rules gated on `.do-streams-profile-activity` prefix; zero color declarations (reuses `--gc-color-text-muted` per D's flag) | PASS |
| 10 | Brief checkbox — Kernel test asserts (a) unpublished absent, (b) private-group node absent for non-member, (c) accessible-group node present | `UserActivityViewTest.php` tests a/b/c above; plus author-scoping, newest-first ordering, distinct/no-fan-out (A-gate hedges) — 6/6 GREEN | PASS |
| 11 | Brief checkbox — phpcs clean; suites remain green | T rev-1: phpcs clean on delta, 31/31 `do_streams` Kernel + 16/16 `do_chrome` unit, E2E 1/1 | PASS |

## Audit-dimension review

1. **AC coverage** — every AC pinned; access-safety at Kernel (correct tier per T's decision after site-baseline `access user profiles` gap surfaced).
2. **Scope discipline** — the working-tree changes to `docs/groups/` + `tests/e2e/` are exactly the "Owns" set: 3 new files + 3 extended (do_streams libraries/hook + do_chrome HelpText — all documented as append-only or additive). Other `M` entries in `git status` (config/sync/*, web/*) are pre-existing worktree noise unrelated to this diff (assemble artifacts + inherited Drupal core noise, common to every worktree). No unrelated file edits within the story's owned surface.
3. **Reuse discipline** — F correctly extended, not duplicated:
   - Follows `group_content_stream.yml` shape for status/DESC/distinct.
   - Uses `following_feed.yml`'s established `block+stream_card` precedent for row plugin — no new plugin invented.
   - `preprocessBlock()` mirrors existing `preprocessViewsView()`'s guard convention (specific-plugin-id gate) rather than introducing a new attachment mechanism.
   - CSS follows `following.css`'s "small container tweaks only" pattern — no new color tokens, honors D's contrast reuse directive.
   - Reads Drupal core source for correct argument/validator/node_access shape (documented in decisions §F, with core file cites).
4. **Access-safety** — Kernel tests use real Group entities via `GroupsKernelTestBase` (`createGroup`/`addMember`/`addNode`), non-member viewer via `setCurrentUser`, insider grant via `createGroupRole(scope: INSIDER_ID)`. Genuine `node_access` gate under test — not simulated. Sanity companion (member sees the node) rules out vacuous exclusion.
5. **WCAG 2.2 AA** — real `<h2>` (not styled div), focus outline verified `rgb(27, 39, 51)` 2px, muted text ≈ 12.6:1, no color-only status, access-safe "No posts yet." copy doesn't leak inaccessible content existence. All four D-flagged WCAG concerns addressed.
6. **Documentation** — HelpText entry present (`profile_activity.section`); rationale for NOT reusing `page.profile_stream` (a distinct future surface) is documented in-line.
7. **Epic-contract consistency** — new sibling view respects do_streams shell contract; `group_content_stream` cannot be parameterized to serve a user URL (per-group by contract via `group_relationship` relationship + `group/%group/stream` path), so `user_activity` as a sibling is architecturally correct, not a hack. Confirmed by A gate and re-verified here.

## Test-quality audit (`testing/test-quality.md` §7)

- **Per-test validity:** each of the 6 Kernel tests names one behavior, fails in isolation for the right reason (verified during T-RED — all 6 failed at `shippedConfigDir()`'s `$this->fail`), asserts on `$view->result` row membership + order, not implementation internals.
- **Tier proportionality:** access-scoping + ordering + distinct + published-only + author-scoping live at Kernel (cheapest tier that can prove the compiled-SQL contract); E2E carries only the two things that can only be proven live (real `<h2>` present on `/user/{uid}` + rendered link markup for Maria's titles). Ordering was correctly demoted from E2E to Kernel-only after T rev-1 discovered the seed script's same-second timestamp tie made the E2E premise invalid — right call.
- **Suite proportionality:** 6 Kernel + 1 E2E for a feature story with 5 distinct behavioral guarantees is proportionate; no fan-out, no re-proving one branch, no snapshot padding.
- **Smells:** none detected. No mock-shaped tests (real Group/Node entities). No tautologies. The one deleted E2E case (outsider-anonymous) was correctly deleted because coverage already existed at Kernel — this is exactly the "delete or merge" muscle the rubric asks for.

## Advisories

- **Site-baseline `access user profiles` permission gap** (surfaced by T, accepted by O as out of scope for #114): neither `anonymous` nor `authenticated` roles grant `access user profiles`, so no non-admin persona can view another user's `/user/{uid}` at all. This is a pre-existing, cross-cutting site-config issue — every subsequent profile-surface story will hit it. Not filing a follow-up per POC-no-follow-ups memory rule; flagged here for the operator to notice at merge time and decide whether to open a housekeeping ticket.
- **Pre-existing `jQuery is not defined` console error** on `/user/{uid}` (grep confirms not introduced by `do_streams` — zero jQuery refs in the module). Not this story's issue.
- **`.ddev/config.yaml` project-name collisions across worktrees** (surfaced by T-RED). Not this story's issue; operator/housekeeping concern.

## Verdict

**PASS** — all acceptance criteria met at appropriate test tiers, spec-compliant, reuse-disciplined, access-safe with real Group entities, WCAG-verified live. Ready for O to rebase → CI check → PR → self-merge on green.
