# Survey — #143 MC-5 Group archiving RESTORE action

Run slug: `143-archive-restore`. Worktree: `~/Projects/_worktrees/groups-archive-restore`, branch `143-archive-restore` off `origin/main` (a254035).

## Archive mechanism — how the site actually models "archived"

The archive state is a **taxonomy term reference**, not a boolean/state field:

- Field: `field_group_type` on `group.community_group` (entity_reference → `taxonomy_term`, cardinality 1). Provisioned at runtime by `docs/groups/scripts/step_720_group_types.php` (not in `config/sync`).
- Vocabulary: `group_type`, terms: Geographical, Working group, Distribution, Event planning, **Archive**.
- **A group is "archived" iff `field_group_type` → term named `Archive`.** All enforcement/chrome keys off `$term->getName() === 'Archive'`.
- Seed (SD-3 #128): "Legacy Infrastructure" is published + Archive-typed. That's the group RESTORE targets.

Enforcement/chrome that flow from Archive-typing (all inspected):
- `docs/groups/modules/do_group_extras/src/Hook/DoGroupExtrasHooks.php` — `preprocess_group` adds `group--archived` class; `node_access` denies `create` op in archived groups (cache-tagged on the group).
- `docs/groups/modules/do_chrome/src/Hook/ArchivePinHooks.php` — renders the `<span class="group__archived-badge" data-do-tooltip>` badge in `title_suffix`.

**There is NO existing dedicated "archive action" UI/route.** Groups get their type via the standard group edit form (widget surfaced by step_720). RESTORE therefore introduces the *first* dedicated action for this state axis. "Extend, don't duplicate" means we reuse **the same field + the same term-based state model**, not a new state field.

## Original-type retention

`field_group_type` does not remember a prior value. Options considered:
1. Add a shadow field storing prior type (schema-heavy, off-POC).
2. Prompt the restorer to pick a target type (aligns with story's "confirmation flow", zero schema).
3. Default to a sensible type ("Working group") in confirmation.

**Recommendation: option 2/3 hybrid** — confirmation form with a `<select>` prefilled to `Working group` (POC bar). Zero schema change; user-facing; matches the pattern of the Manage-members forms.

## Permissions / personas

- `hasPermission('administer group', $account)` — the Organizer path (Organizer group role holds this on `community_group`; see `docs/groups/config/group.role.community_group-organizer.yml` if present, otherwise `administer members` was the #138 rung — verify the exact permission for "administer group settings" during A).
- `Groups-Moderate` — synchronized global role, `scope: outsider`, `global_role: groups_moderate`. Grants across all `community_group`s. Pattern established in #138 `ManageMembersController::access`.
- Analogous access controller: `docs/groups/modules/do_group_membership/src/Controller/ManageMembersController.php`. Same shape reused for restore.

## Reuse & Analogous-Feature map

| Concern | Extend / reuse | Rationale |
|---|---|---|
| State model | **Reuse** `field_group_type` term ref; no new field | Story hard rule: reuse existing mechanism |
| Route access | **Extend** the ManageMembersController pattern (custom_access, cache contexts) | Same persona set (Organizer + Groups-Moderate + site-admin) |
| Form-based action | **Extend** ManageMembersForm/RemoveMemberForm pattern (ConfirmFormBase-style; `<button>` submit) | Guarantees WCAG 2.2 AA control affordance; matches #138 AC-7/AC-15 |
| Home module | **Extend** `do_group_extras` (owns archive enforcement) — put restore route + form + hook there | Cohesion — archive/restore live together; matches `#78/#92` enforcement locus |
| Local task tab | Add `do_group_extras.links.task.yml` w/ tab visible only on archived groups | Symmetry with #138's `do_group_membership.links.task.yml` |
| Tooltip / help | If any new copy is needed, append to `do_chrome/HelpText.php` (append-only, HelpText serialization risk — coordinate) | Story owns disjoint files; keep HelpText additions minimal or skip |
| E2E | New `tests/e2e/group-restore.spec.ts` (owned per story) | Explicit in story "Owns" list |
| Kernel test | New `docs/groups/modules/do_group_extras/tests/src/Kernel/GroupRestoreTest.php` | Locus with enforcement code; module-local fixtures |
| Functional | `docs/groups/modules/do_group_extras/tests/src/Functional/GroupRestoreAccessTest.php` (BrowserTestBase self-installs) | Verify persona access, form submit, redirect, message |

**Extend-vs-new default:** every touchpoint above extends an existing analogous object; no wholly new architecture.

## Forward-compat check

- `#144 create → auto-organizer` — orthogonal; no conflict.
- `#145 WCAG audit` — final; our `<button>` submit + real form + focus mgmt satisfies future audit.
- SD-3 #128 seed — we MUST NOT edit `step_700_demo_data.php` (would collide with #128). Restore reads the seed's archived group; no seed change needed.
- HelpText append: minimal risk; likely a single key (e.g., `archive.restore_action`).

## Key gotchas to remember (from WAVE §6)

- CI runs assembled layout — `bash scripts/ci/assemble-config.sh` before phpunit.
- Fixtures must be module-local.
- BrowserTestBase self-installs (no demo seed) → functional tests must self-provision the vocabulary + terms + a group with Archive-typed field.
- Playwright runs vs seeded site (Legacy Infrastructure is the archived target).
- `#type => submit` renders `<input>` not `<button>` — WCAG-friendly `<button>` requires explicit `#type => 'submit', '#attributes' => ...` **or** the form pattern used in `#138` (verify what it does). Story explicitly demands real `<button>`.
- Do NOT touch `web/modules/custom/` or `config/sync/`.

## Open items for A to adjudicate

1. Which permission gates restore for Organizer — `administer group` vs a more specific group perm? (verify via existing `do_group_membership` config).
2. Target-type default in confirmation: prefilled `Working group` vs freeform pick? (POC bar suggests prefilled).
3. Whether the local task tab should say "Restore group" (only visible when archived) or reuse a generic "Actions" tab.
4. Whether restore should also clear the `group--archived` cache tag / invalidate the group's cache (yes — the group entity save handles it, but confirm).
