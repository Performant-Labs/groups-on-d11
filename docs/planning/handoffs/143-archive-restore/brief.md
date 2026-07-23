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
- [ ] **AC-3** Anonymous / non-privileged authenticated users get 403 on the restore route. **Non-archived groups also return 403** (denied by `_custom_access`, same status as unauthorized — follows MMC's `AccessResult::allowedIf` convention; 404 would leak existence).
- [ ] **AC-4** Confirmation flow required before restore takes effect (form with real `<button type="submit">`, no accidental GET-based mutation).
- [ ] **AC-5** Archive → restore → archive round-trip is clean (no data loss on the group entity or its content).
- [ ] **AC-6** WCAG 2.2 AA: keyboard operable, focus visible, real button (not link styled as button), labeled controls, `aria-describedby` on the button pointing to the confirmation summary.
- [ ] **AC-7** Existing suite green (SD-3 #128 archive demonstrator seed still asserts as archived — restore doesn't run at seed time).
- [ ] **AC-8** `tests/e2e/group-restore.spec.ts` asserts archive → restore → archive round-trip against the seeded site. **Explicit sequence** (per wireframe Surface 4): (1) assert Legacy Infrastructure is Archive-typed (badge visible, Restore tab visible, node-create denied); (2) navigate `/group/{gid}/restore`, keep default "Working group", submit; (3) assert redirect to canonical, success flash, badge gone, Restore tab gone, node-create allowed; (4) navigate `/group/{gid}/edit`, set Group Type back to "Archive" via the existing widget, save; (5) assert badge returns and Restore tab returns.
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

- **Route:** `do_group_extras.restore` → `GET|POST /group/{group}/restore` → `_form: \Drupal\do_group_extras\Form\RestoreGroupForm` gated by `_custom_access: \Drupal\do_group_extras\Controller\RestoreGroupAccess::access`. `{group}` upcasts to `GroupInterface` via existing `group` param converter.
- **Access (pinned per adjudicated brief-review B-1):** `AccessResult::allowedIf($isArchived && ($group->hasPermission('administer group', $account) || $account->hasPermission('administer group')))` where `$isArchived = ($group->hasField('field_group_type') && !$group->get('field_group_type')->isEmpty() && $group->get('field_group_type')->entity?->label() === 'Archive')`. Cacheability: `->addCacheableDependency($group)->cachePerPermissions()->cachePerUser()` (mirror MMC; drop MMC's `url.path` context — restore is per-group, not per-path-shape). Both non-privileged AND non-archived → 403 (single denial path; 404 would leak existence).
    - Perm string rationale: `'administer group'` is the group-settings-scope perm (Organizer holds it on `community_group`). MMC uses `'administer members'` for the membership surface; restore is a group-settings mutation, so the settings-scope perm is correct.
- **Form:** `RestoreGroupForm extends ConfirmFormBase` (Drupal core) → renders real `<button type="submit">` submit natively.
    - **DI:** constructor promotion + static `create(ContainerInterface $container)` matching `RemoveMemberForm::create`. Inject: `entity_type.manager` (for term storage). `messenger` + `stringTranslation` are provided by `ConfirmFormBase`'s trait chain; no extra injection needed.
    - **buildForm:** call `parent::buildForm()` first; then add a `#type => 'select'` for target `group_type` term (options = all vocab terms except "Archive"; default = tid of "Working group" term; `#title => t('Set group type to')`; `#description` explains the exclusion rationale); then set `$form['actions']['submit']['#attributes']['aria-describedby']` to the description paragraph's `id` (same post-parent pattern MMC's RemoveMemberForm uses to add its danger-button class).
    - **buildForm — empty-vocab guard:** if the filtered options array is empty (all-Archive vocab), refuse to render the confirm/select controls and return a `#markup` block with the wireframe's actionable message.
    - **validateForm:** trust Drupal Form API's server-side `#options` validation for the select (rejects tampered TIDs automatically); no extra validator needed.
    - **submitForm:** re-check `field_group_type` is currently Archive (race guard per wireframe Surface 2). If NOT: `messenger()->addWarning()` with the "no longer archived — no changes were made" copy; redirect to canonical; return early. If YES: wrap the reassignment in try/catch (mirror `RemoveMemberForm`'s pattern):
        ```
        try {
          $group->set('field_group_type', $target_tid);
          $group->save();
          messenger()->addStatus(t("Group '@label' has been restored and set to type '@type'.", …));
        }
        catch (\Exception $e) {
          $this->logger('do_group_extras')->error('Restore failed for group @gid: @msg', …);
          messenger()->addError(t('The group could not be restored. Please try again.'));
        }
        $form_state->setRedirectUrl($this->getCancelUrl());
        ```
        (Addresses adjudicated B-2/B-5.)
    - `getQuestion()` / `getDescription()` / `getConfirmText()` / `getCancelUrl()` per wireframe Surface 2. All strings use `t()` via `TranslatableMarkup` return types (matches `RemoveMemberForm` — automatic in `ConfirmFormBase` overrides).
- **Local task:** `do_group_extras.links.task.yml` — `do_group_extras.restore_tab: route_name: do_group_extras.restore, base_route: entity.group.canonical, title: 'Restore group', weight: 30`. Visibility controlled by the access check (tab hides on 403 via Drupal's standard local-task access filtering).
- **Round-trip note:** re-archiving is handled by the existing group edit form's Group Type widget (already surfaced by `step_720`). No new "Archive" action needed — the story only asks for RESTORE. The e2e round-trip archive → restore → archive uses: seed (archived) → restore via new form → group edit form to reset Type to Archive → confirm badge returns. (Cross-ref: AC-8 spells out the exact click sequence.)

## Brief-gate adjudication (round 1)

o4-mini raised 6 BLOCKs; 4 were real gaps (folded into the outline above), 2 were spurious (rejected as noise). Detail:

- **B-1 access (real, addressed):** pinned exact perm strings + AccessResult shape above; both site-admin escape hatch and group-scope Organizer perm covered.
- **B-2 validation (partial, addressed):** empty-vocab guard added; tampering rejected as non-vector (Drupal Form API validates `#options` server-side); missing-Archive-term is out of scope (site-owned vocab; would break enforcement site-wide, not just restore); save exception → try/catch below.
- **B-3 403 vs 404 (real, addressed):** pinned 403 for both non-privileged and non-archived, per MMC convention.
- **B-4 AC-8 sequence (real, addressed):** cross-referenced wireframe Surface 4 into AC-8 with explicit 5-step click path.
- **B-5 save failure (real, addressed):** try/catch pattern mirroring `RemoveMemberForm::submitForm` folded into the submit outline.
- **B-6 DI spec (spurious, rejected):** the analogous `RemoveMemberForm` sets the DI convention (constructor promotion + static `create`) — F mirrors it; no separate DI-strategy section warranted for a 1-service form. Recorded as convention-followed.

**WARN/NIT triage.** W-1 (term-storage load-and-validate) — Form API `#options` covers this. W-2 (local-task hide) — Drupal handles via access filtering; verified working in `do_group_membership.links.task.yml`. W-3 (E2E session isolation) — Playwright per-test contexts already give this. NIT-1 (route naming) — `do_group_extras.restore` matches project convention (see `do_group_membership.remove_member`, not `do_group_membership.group.remove_member`). NIT-2 (`t()`) — automatic via `TranslatableMarkup` return types. NIT-3 (unique aria-describedby id) — id includes the `ConfirmFormBase` form id, guaranteed unique per page.

## Model discipline

D = Sonnet · A = Opus (inherits) · T = Sonnet · F = Sonnet · U = Sonnet · S = Opus (inherits).

## Autonomy contract

Orchestrator (me) runs autonomously to the pre-PR hold. After each sub-agent phase: commit source-only (`docs/groups/…` + `tests/e2e/…`, explicit paths, `Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>`), immediately launch next, and post `SendMessage(to: "main", ...)`: `"[143] parked after <phase>, launching <next>"`.

## Phase order

D (this story HAS a UI surface — confirmation form + tab) → o4-mini brief gate → A → T(RED) → F → T(GREEN) → o4-mini diff gate → A-dup → U → S → pre-PR hold.
