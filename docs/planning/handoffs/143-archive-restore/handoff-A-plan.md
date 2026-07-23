# Handoff-A: Phase 3 — #143 MC-5 Group archiving RESTORE action (up-front plan review)

**Date:** 2026-07-22
**Branch:** 143-archive-restore (worktree `_worktrees/groups-archive-restore`, at `0b1c315`)
**Brief reviewed:** `docs/planning/handoffs/143-archive-restore/brief.md`
**Reuse map:** `docs/planning/handoffs/143-archive-restore/survey.md`
**Wireframe:** `docs/planning/handoffs/143-archive-restore/wireframe.md`
**Verdict:** BLOCK

## Summary

Plan is architecturally faithful to the reuse map — extends `do_group_extras` (which already owns
archive enforcement), mirrors `do_group_membership`'s form-per-action + shared `_custom_access`
controller shape, and correctly avoids inventing a parallel state model. One BLOCK: the pinned
group-scope permission string (`'administer group'`) is **not held by the Organizer role** on
`community_group` — Organizer has `'edit group'` + `'administer members'`. As written, AC-1
(Organizer restores the seeded archived group) will 403. Two WARNs on cacheability/race guard and
a handful of NITs; otherwise the plan is ready for T.

## Findings

| # | Severity | Plan element | Drift dimension | Finding | Suggested fix |
|---|---|---|---|---|---|
| 1 | BLOCK | brief §Design outline / Access | access-check correctness | Pinned group-scope perm `'administer group'` is **not granted to `community_group-organizer`**. Config `group.role.community_group-organizer.yml` grants `'edit group'` and `'administer members'` only. `Groups-Moderate` (`admin: true`) and Admin (`admin: true`) would pass on the group-perm implicit-admin path, but Organizer will fail — AC-1 breaks. `views.view.pending_groups.yml` uses `'administer group'` as a **site-wide** perm on the admin view, not a group-scope perm on `community_group`. | Change the group-scope check to `$group->hasPermission('edit group', $account)`. Keep the site-admin escape hatch `$account->hasPermission('administer group')`. Full expression: `AccessResult::allowedIf($isArchived && ($group->hasPermission('edit group', $account) \|\| $account->hasPermission('administer group')))`. Rationale: `'edit group'` is the group-settings-scope perm (Organizer holds it; `admin:true` roles implicitly hold it; site admins via the global escape hatch). This still keeps Members-tab and Restore-tab access surfaces distinct (MMC uses `'administer members'`; restore uses `'edit group'`), which is correct — restore *is* an edit of group settings. |
| 2 | WARN | brief §Design outline / Access — cacheability | cross-cutting (caching) | Dropping `url.path` from the MMC cache-context list is correct (restore has one route, not a multi-URL surface). Fine as-is. But: because access varies on the *value* of `field_group_type` (isArchived), the AccessResult must also carry a cache tag/dependency that invalidates when that field changes. `->addCacheableDependency($group)` covers this because saving the group invalidates its cache tags, and the access result is bound to the group entity's tag. Confirmed sufficient — no change required, but call this out to F so they don't hand-roll a `Cache::invalidateTags()` in `submitForm`. | Document in F's brief: "the `$group->save()` in submitForm auto-invalidates the group's cache tags, which propagates to the tab visibility and the AccessResult — no manual invalidation needed." |
| 3 | WARN | brief §Design outline / Form — race guard | pattern consistency | Race guard in `submitForm` re-reads `field_group_type` and warns+redirects if no longer Archive. Correct and sufficient. The access controller already gates *rendering* on `isArchived`, so the race window is only between form render and form submit (small but real). Redundant-but-harmless double-check is the right posture — mirrors defensive patterns elsewhere. No fix. | (informational — no change) |
| 4 | NIT | brief §Design outline / Local task | naming | Task-plugin key `do_group_extras.restore_tab` in brief vs. MMC's convention `do_group_membership.manage_members` (task key mirrors the route name, no `_tab` suffix). | Rename plugin key to `do_group_extras.restore` (matches route name; matches MMC precedent). Doesn't affect route or URL — only the plugin id in `.links.task.yml`. |
| 5 | NIT | brief §Design outline / Form — DI | pattern consistency | Brief injects `entity_type.manager` for term storage. `RemoveMemberForm` injects a domain-service (`GroupMembershipManager`), not `entity_type.manager` directly. For a one-shot term lookup this is fine and no service exists to wrap it — direct EntityTypeManager injection is acceptable. Just cite the deviation in F's handoff so A-dup doesn't flag it. | Note in F handoff: "no domain-service wrapper for term lookup exists in `do_group_extras`; injecting `entity_type.manager` directly is the minimum-abstraction choice, matches core form patterns." |
| 6 | NIT | brief §Design outline / Form — aria-describedby | wireframe validation (Q2 from D) | D asked A to validate the post-`parent::buildForm()` wiring point. `ConfirmFormBase::buildForm()` populates `$form['actions']['submit']` (a `#type => 'submit'` Actions element), so `$form['actions']['submit']['#attributes']['aria-describedby'] = $description_id` after `parent::buildForm()` renders correctly — the render pipeline merges `#attributes` onto the emitted `<button>`. This is **exactly** the pattern `RemoveMemberForm::buildForm()` uses to add the `button--danger` class (line 97). GO. The description-paragraph `id` must be a valid HTML id (unique per page) — brief's suggestion of including the form id in the id string is sound; F should set it explicitly on the description render array via a `#markup` wrapper or by rendering `getDescription()` inside a `<p id="…">`. Since `ConfirmFormBase::buildForm` renders the description into `$form['description']['#markup']` without a wrapper id, F will need to override `$form['description']` post-parent to add the id, OR wrap the description string in `<p id="…">…</p>` inside `getDescription()`'s returned TranslatableMarkup. Either works; the second is simpler and self-contained. | Spec in F brief: return description as `t('<p id="do-group-extras-restore-desc-@gid">…</p>', ['@gid' => $group->id()])` or override `$form['description']` post-parent. Then set `aria-describedby` to that same id. |
| 7 | NIT | brief §Design outline / Form — `#type => 'submit'` renders `<button>` | wireframe validation | Confirmed. Drupal 10/11 core's `Submit` form element extends `Button`, which renders `<button type="submit">` via `template_preprocess_button`/`button.html.twig` (theme-agnostic default). `RemoveMemberForm` relies on the same and shipped GREEN in #138 with WCAG-friendly button assertions. No `#input_type` override needed. The survey.md gotcha's "#type => submit renders `<input>` not `<button>`" is inaccurate for Drupal 10/11 (was true in D7). |
| 8 | NIT | brief §Design outline / Local task visibility | pattern consistency | Brief correctly asserts Drupal's local-task access filtering hides the tab on 403, and the access callback returns 403 for non-archived groups. `do_group_membership.links.task.yml` uses only the route + base_route + weight (no explicit visibility hook); its tab visibility is fully driven by the route's `_custom_access` callback. This precedent confirms no `hook_menu_local_tasks_alter()` is needed — the access callback carries the load. Cacheability on the AccessResult (`addCacheableDependency($group)`) ensures the tab list is re-rendered when the group's field_group_type changes. |
| 9 | NIT | brief §Test locus | pattern consistency | Kernel + Functional tests under `docs/groups/modules/do_group_extras/tests/src/{Kernel,Functional}/` matches project convention (module-local fixtures per WAVE §6). No other module places these tests elsewhere. Confirmed. |
| 10 | NIT | anti-duplication scan | drift | Nothing in `do_group_extras` or `do_chrome` overlaps the proposed new files. `do_chrome/ArchivePinHooks.php` renders the badge and will auto-clear when `field_group_type` changes — plan correctly leaves it untouched. No parallel path detected. |

## Notes for O

**BLOCK (finding #1) is a one-line perm-string swap in the brief.** Change the group-scope
permission from `'administer group'` to `'edit group'` in:

- §Acceptance criteria (implicit — no change to AC text itself, but perm rationale in §Design outline)
- §Design outline / Access (both the code snippet and the "Perm string rationale" bullet)
- §Brief-gate adjudication B-1 note (mark as "revised on A review: `'edit group'` not `'administer group'` — Organizer role config `group.role.community_group-organizer.yml` does not grant `'administer group'`")

Optionally also fold NITs #4 (task-plugin key rename), #5 (DI note), #6 (aria-describedby id
mechanism) into the brief so F has the spec pinned; #6 in particular saves F a design decision.

After amendment, re-review is trivial (single perm string + rationale sanity check).

## Patterns referenced

- `docs/groups/modules/do_group_membership/do_group_membership.routing.yml`
- `docs/groups/modules/do_group_membership/do_group_membership.links.task.yml`
- `docs/groups/modules/do_group_membership/src/Controller/ManageMembersController.php` (lines 38–45 — shape of AccessResult with cacheability)
- `docs/groups/modules/do_group_membership/src/Form/RemoveMemberForm.php` (lines 37–48 DI; 93–99 post-parent buildForm additions; 104–119 try/catch submit pattern)
- `docs/groups/modules/do_group_extras/src/Hook/DoGroupExtrasHooks.php` (Archive detection via `$term->getName() === 'Archive'` — restore form must use identical check)
- `docs/groups/config/group.role.community_group-organizer.yml` (Organizer perms — the perm-string BLOCK evidence)
- `docs/groups/config/group.role.community_group-groups_moderate.yml` (`admin: true` synchronized outsider — covers AC-2 under either perm string)
- `docs/groups/config/views.view.pending_groups.yml` line 68 (`'administer group'` used as site-wide perm on admin view, not on the group scope — clarifies why the brief's rationale was plausible but wrong)
