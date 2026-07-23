# Brief — #143 MC-5 Group archiving RESTORE action

**Epic:** #137 MVP conformance. **Spec:** `gh issue view 143 --repo Performant-Labs/groups-on-d11`.
**Branch:** `143-archive-restore` (worktree `~/Projects/_worktrees/groups-archive-restore`).
**Review rigor:** `second-opinion` (o4-mini via `docs/playbook/workflow/dual-review.sh` at brief + diff gates).

## Objective

Add a dedicated **Restore** action that returns an archived `community_group` to active status: reassign `field_group_type` from the "Archive" term to a caller-chosen non-Archive term, with an accessible confirmation form. Reuses the existing archive state model (term-ref on `field_group_type`); does not introduce a parallel state field.

## Survey pointer

`docs/planning/handoffs/143-archive-restore/survey.md` — full archive mechanism analysis, Reuse & Analogous-Feature map, permissions/personas, forward-compat check, gotchas.

**One-line reuse summary:** extend `do_group_extras` (already owns archive enforcement) with a new `RestoreGroupForm` (analogous to `do_group_membership`'s `RemoveMemberForm`) and access controller mirroring `ManageMembersController`. No schema change; state field unchanged.

## Acceptance criteria (from GH #143)

- [ ] **AC-1** Organizer persona can restore a seeded archived group (Legacy Infrastructure) → it reappears in `/all-groups` and becomes editable (node create no longer denied).
- [ ] **AC-2** Groups-Moderate persona can restore the same group (synchronized outsider-scope role).
- [ ] **AC-3** Anonymous / non-privileged authenticated users get 403 on the restore route.
- [ ] **AC-4** Confirmation flow required before restore takes effect (form with real `<button type="submit">`, no accidental GET-based mutation).
- [ ] **AC-5** Archive → restore → archive round-trip is clean (no data loss on the group entity or its content).
- [ ] **AC-6** WCAG 2.2 AA: keyboard operable, focus visible, real button (not link styled as button), labeled controls, `aria-describedby` on the button pointing to the confirmation summary.
- [ ] **AC-7** Existing suite green (SD-3 #128 archive demonstrator seed still asserts as archived — restore doesn't run at seed time).
- [ ] **AC-8** `tests/e2e/group-restore.spec.ts` asserts archive → restore → archive round-trip against the seeded site.
- [ ] **AC-9** Kernel test in `do_group_extras`: restore reassigns `field_group_type`, `group--archived` class disappears, `node_access('create')` returns neutral (not forbidden).
- [ ] **AC-10** Functional (BrowserTestBase) test: self-installs, provisions `group_type` vocab + Archive term + test group, verifies persona access matrix + form redirect + success message.
- [ ] **AC-11** Handoff: coordinate (do NOT edit) with SD-3 #128's `step_700_demo_data.php` — the seed keeps Legacy Infrastructure archived; restore is the runtime action.

## Owned files (disjoint per story)

- `docs/groups/modules/do_group_extras/do_group_extras.routing.yml` (new file)
- `docs/groups/modules/do_group_extras/do_group_extras.links.task.yml` (new file, tab visible only when archived)
- `docs/groups/modules/do_group_extras/src/Form/RestoreGroupForm.php` (new)
- `docs/groups/modules/do_group_extras/src/Controller/RestoreGroupAccess.php` (new)
- `docs/groups/modules/do_group_extras/tests/src/Kernel/GroupRestoreTest.php` (new)
- `docs/groups/modules/do_group_extras/tests/src/Functional/GroupRestoreAccessTest.php` (new)
- `tests/e2e/group-restore.spec.ts` (new)
- Optional micro-append: `docs/groups/modules/do_chrome/src/HelpText.php` for a single restore-action copy key (only if a tooltip is warranted; likely skip).
- Optional subtheme CSS (own file) if button needs bespoke styling — likely reuse existing.

**Do NOT touch:** `step_700_demo_data.php` (SD-3 #128 territory), any other seed step, `web/modules/custom/`, `config/sync/`, other stories' modules.

## Design outline (for A to validate)

- **Route:** `do_group_extras.restore` → `GET|POST /group/{group}/restore` → `_form: RestoreGroupForm` gated by `_custom_access: RestoreGroupAccess::access`.
- **Access:** allowed iff `$group->hasPermission('administer group', $account) || $account->hasPermission('administer group')` — mirrors ManageMembers pattern; verify exact perm string for Organizer during A. **Additional gate:** access denied when group is NOT Archive-typed (there's nothing to restore).
- **Form:** extends `ConfirmFormBase` (Drupal core) → renders real `<button>` submit natively. Adds a `#type => 'select'` for target `group_type` term (excluding Archive), default `Working group`. `getQuestion()` / `getDescription()` / `getConfirmText()` / `getCancelUrl()` all wired. On submit: `$group->set('field_group_type', $target_tid)->save()`; `messenger`->addStatus; redirect to the group canonical.
- **Local task:** appears as a tab labeled "Restore group" on the group route, `route_name: do_group_extras.restore`, `title: 'Restore group'`. Visibility is controlled by the access check (tab hides on 403).
- **Round-trip note:** re-archiving is handled by the existing group edit form's Group Type widget (already surfaced by step_720). No new "Archive" action needed — the story only asks for RESTORE. The e2e round-trip archive → restore → archive uses: seed (archived) → restore via new form → group edit form to reset Type to Archive → confirm badge returns.

## Model discipline

D = Sonnet · A = Opus (inherits) · T = Sonnet · F = Sonnet · U = Sonnet · S = Opus (inherits).

## Autonomy contract

Orchestrator (me) runs autonomously to the pre-PR hold. After each sub-agent phase: commit source-only (`docs/groups/…` + `tests/e2e/…`, explicit paths, `Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>`), immediately launch next, and post `SendMessage(to: "main", ...)`: `"[143] parked after <phase>, launching <next>"`.

## Phase order

D (this story HAS a UI surface — confirmation form + tab) → o4-mini brief gate → A → T(RED) → F → T(GREEN) → o4-mini diff gate → A-dup → U → S → pre-PR hold.
