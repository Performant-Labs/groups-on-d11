# Brief — #121 SC-2 Membership models enforced

**Story:** #121 SC-2 Membership models enforced — request-to-join (Leadership Council) + invite-only (Core Committers)
**Epic:** #117 Showcase
**Wave:** W1 (foundation)
**Review rigor:** `second-opinion` (o4-mini via `docs/playbook/workflow/dual-review.sh` at brief + diff gates)
**HelpText serialization role:** LEADER of the HelpText-touching serialized track (#126/#127/#128/#132 rebase after)

## Objective

Make the membership + visibility model *real* on the demo. A seeded non-member (`sophie_mueller` / `alex_novak`) who visits:
- an **open** group → instant one-click join (already live via #95, keep working).
- **Leadership Council** (moderated) → sees "Request to join"; clicking creates a pending `group_membership` relationship; an organizer sees the request in a pending queue and can Approve (→ active) or Deny (→ deleted).
- **Core Committers** (invite_only) → sees NO direct join path; the "add member" organizer path still works.

Both visibility semantics (public listing, unlisted-by-URL is out-of-scope for this story — #134) and join_policy (open / request / invite) must be enforced *consistently* across the directory, group page, and join path — via **Group access** at the `group_membership.create` operation, not by hiding UI alone.

## Reuse & Analogous-Feature map

See `survey.md` §"Files this phase will touch" — the recommendation defaults to EXTEND. Manager methods extend `GroupMembershipManager`; forms extend the existing `Form/` directory pattern; hook class is new but justified (no existing group_access hook in do_group_membership).

Do NOT split `field_group_visibility` into two fields (visibility + join_policy) in this story — that is a two-axis refactor that #134 (private group) may reopen. Keep the composite; the story is defensible without a schema break.

## Input documents

- Issue: `gh issue view 121 --repo Performant-Labs/groups-on-d11` — re-read every phase.
- Survey: `docs/planning/handoffs/121-req2join/survey.md` (reuse map, personas, gotchas).
- WAVE-EXECUTION-HANDOFF.md §6 (gotchas 3, 4, 6, 9 in particular).
- PROJECT_CONTEXT.md (assemble-before-verify contract; source-only commits).
- HelpText.php lines 84–87 (the copy to correct — leader of the HelpText serialization track).

## Acceptance criteria (checkboxes — the tests T authors must cover each)

- [ ] **AC-1** Non-member `sophie_mueller` on an OPEN group (e.g. Drupal France) sees "Join" and one click makes her an active member (Kernel + E2E).
- [ ] **AC-2** Non-member `sophie_mueller` on Leadership Council (moderated) sees "Request to join"; one click creates a `group_membership` with `field_membership_status=pending`, no `group_roles`, and she is NOT visible on the members list (Kernel + Functional).
- [ ] **AC-3** Non-member `sophie_mueller` on Core Committers (invite_only) sees NO Join / Request path; direct POST to the request route is 403; organizer's AddMember still works (Kernel + Functional).
- [ ] **AC-4** Organizer on Leadership Council sees a "Pending requests" queue at `/group/{group}/members/pending`, containing Sophie's request; Approve → active membership visible on `/group/{group}/members`; Deny → relationship deleted (Kernel + Functional).
- [ ] **AC-5** Duplicate `requestJoin` for the same (group, user) throws `DuplicateMembershipException` (Kernel).
- [ ] **AC-6** Visibility HelpText copy no longer contains the string `"Not yet enforced"` for any `visibility.*` key.
- [ ] **AC-7** The corrected `visibility.invite_only` copy MUST contain the word **"visible"** (guards against a faithful-but-wrong edit that drops "not yet enforced" but keeps "hidden"). Corrected `visibility.moderated` copy must describe request + approval as live.
- [ ] **AC-8** `HelpTextTest::testVisibilityCopyIsPresentPlainTextAndHonest()` is updated (lines ~71–73) — the assertions reference the new corrected copy and stay GREEN under the RED-then-GREEN pipeline.
- [ ] **AC-9** Seed `step_700_demo_data.php` — appended only — sets Leadership Council → `moderated`, Core Committers → `invite_only`, other seeded groups → `open` (or leave default; explicit is preferred); creates one pending relationship (sophie_mueller → Leadership Council) so the organizer queue demos non-empty. Idempotent.
- [ ] **AC-10** E2E spec `tests/e2e/membership-models.spec.ts` walks all three flows against the seeded site (Sophie logs in, visits directory, exercises open / moderated / invite_only). Uses `role=button,name=/Request to join/i` OR `input[type=submit][value*=Request]` locator (G9).
- [ ] **AC-11** Access enforcement: a direct HTTP POST to `/group/{group}/join-request` on an `invite_only` group returns 403 (not merely UI-hidden) (Functional).
- [ ] **AC-12** WCAG 2.2 AA on the changed UI — visible focus, keyboard operability, semantic form controls (`<button>` or `<input type=submit>` inside `<form>`, both are OK; a `<div role=button>` is NOT), AA contrast on any new status pills / queue badges. Verified by U (playwright-ui-walkthrough) + axe.
- [ ] **AC-13** Existing kernel + functional suites stay green (no regression in #138 manage-members, #95 join, #79/#88 tooltip surfaces).
- [ ] **AC-14** Source-only feature commits (`docs/groups/…`), staged by explicit path. Assemble before verifying; CI (Kernel + Functional + E2E) green before PR is presented for human merge.

## Handoff locations

- Decisions: `docs/planning/handoffs/121-req2join/decisions.md`
- Wireframe (D): `docs/planning/handoffs/121-req2join/wireframe.md`
- Handoffs: `docs/planning/handoffs/121-req2join/handoff-<phase>.md` (D / A-plan / T-red / F / T-green / A-dup / U / S)

## Branch

`121-req2join` (worktree `~/Projects/_worktrees/groups-req2join`; origin verified = groups-on-d11).

## Model tiers (enforce)

- D, T, F, U → spawn with `model: "sonnet"` explicitly.
- A, S → default Opus (inherit from frontmatter, but call it out in the spawn).
- O (this agent) → Opus by frontmatter.

## Autonomy contract

Run autonomously to the **pre-PR hold**. Commit each phase to the worktree as source-only (explicit paths). Post one-line `SendMessage(to: "main", …)` after each phase transition. Human (aangelinsf) merges the PR.
