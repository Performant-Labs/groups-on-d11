# #128 SD-3 Archive Demonstrator Seeds — Brief

**Repo:** Performant-Labs/groups-on-d11 · **Branch:** `128-archive-demo` ·
**Worktree:** `~/Projects/_worktrees/groups-archive-demo` · **Review rigor:** none
(POC lean pipeline, seed-only change; #128 is a redefinition, not a design).
**Model tiers:** Sonnet for D/T/F/U; Opus for A/S (inherited).
**Skip-D justification:** no new UI or visual designed. The Archive badge/tooltip
+ Pin badge/tooltip + Restore action all shipped in #92 and #143. #128 makes them
*appear to anonymous* by removing a redundant unpublish line in the seed. There
is nothing to wireframe.

## Objective (from issue #128, definitive)

Redefine Legacy Infrastructure so it is PUBLISHED + Archive-typed, and confirm a
publicly visible pinned post — so an anonymous visitor experiences the archive
badge + read-only enforcement and the pinned badge without logging in. Fix (not
work around) test assertions that encode the old `archived = unpublished`
conflation.

## Reuse map summary (from survey.md §"Reuse & Analogous-Feature Map")

- **Extend (sanctioned, single line):** remove `$g->set("status", 0); $g->save();`
  from `docs/groups/scripts/step_700_demo_data.php` (lines 397–400 block).
  Legacy Infrastructure remains status=1; `step_720` continues to tag it
  Archive-typed → badge + read-only kick in for anonymous.
- **Extend (tests):** `tests/e2e/group-restore.spec.ts` lookup helper simplifies
  to `/all-groups`; `directory-cards.spec.ts` doc comment updates.
- **New:** `tests/e2e/demonstrator-seeds.spec.ts` — one anonymous-persona spec
  covering AC-1 + AC-2.
- **No new modules, no config/sync changes, no HelpText edits.**

## Acceptance Criteria (from #128, verbatim + testable)

- [ ] **AC-1a** Anonymous visitor loads `/all-groups` and sees a card for
  Legacy Infrastructure carrying the Archive badge (visible text + tooltip
  attribute).
- [ ] **AC-1b** Anonymous visitor clicks that card, lands on `/group/{gid}`, and
  the group page shows the Archived state (badge visible).
- [ ] **AC-1c** Read-only enforcement is *observable*: an anonymous or
  authenticated non-Organizer user attempting to reach a content-create route
  for this group is denied (403 or access-denied render). T-RED picks whichever
  create route DoGroupExtrasHooks::nodeAccess() actually protects (see survey
  follow-up note).
- [ ] **AC-2** Anonymous visitor can reach a stream (or the pinned node) with a
  visible pin badge and its tooltip attribute.
- [ ] **AC-3** Archived ≠ unpublished holds in the seed. Grep for
  `set("status", 0)` in `step_700_demo_data.php` returns no result on
  Legacy Infrastructure (or on any group).
- [ ] **AC-4** Seed remains idempotent on re-run.
- [ ] **AC-5** Full-seed E2E job green (all specs pass).
- [ ] **AC-6** Touched assertions listed in the PR body:
  `tests/e2e/group-restore.spec.ts` (helper + comment revisit),
  `tests/e2e/directory-cards.spec.ts` (doc comment only, assertion unchanged),
  `tests/e2e/demonstrator-seeds.spec.ts` (new).

## Non-Goals

- No new HelpText, no copy edits, no new module, no config/sync change.
- No changes to `#143`'s restore mechanism.
- No visual/CSS changes.

## Files In Scope (exhaustive)

- `docs/groups/scripts/step_700_demo_data.php` — remove 4 lines (the archive block).
- `tests/e2e/group-restore.spec.ts` — simplify helper; revisit lines 86-92
  comment; possibly add positive enforcement assertion.
- `tests/e2e/directory-cards.spec.ts` — doc-comment tweak only.
- `tests/e2e/demonstrator-seeds.spec.ts` — NEW.

## Handoffs

- `docs/planning/handoffs/128-archive-demo/{survey,brief,decisions,handoff-A,handoff-T-red,handoff-F,handoff-T-green,handoff-U,handoff-S}.md`

## Overnight autonomous mode

Authorized by aangelinsf (2026-07-22). Merge on green CI:
`gh pr merge <N> --repo Performant-Labs/groups-on-d11 --merge --delete-branch`.
