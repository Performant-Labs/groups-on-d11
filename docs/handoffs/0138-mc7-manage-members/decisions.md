# Decision Journal — Issue #138 (MC-7: Organizer Manage-members UI + group roles + Groups Moderate)

Run slug: `0138-mc7-manage-members`
Repo: `Performant-Labs/groups-on-d11` (GitHub flow — origin push + PR, NOT the drupalcode fork model)
Worktree: `/Users/andreangelantoni/Projects/_worktrees/groups-0138-mc7-manage-members`
Branch: `0138-mc7-manage-members` (off `origin/main` @ c18f417)

## Phase 0/1 — Orchestrator (O) — Survey adoption + verification

**Decided:** Adopt the salvaged Phase-1 survey
(`/private/tmp/claude-501/.../scratchpad/o138-salvage/survey.md`) as the base survey — re-verified
against the current repo (post PR #146) rather than re-run from scratch.

**Verified true (still valid post-#146):**
- `docs/groups/config/` (staging source of truth) vs `config/sync/` (live export) split, reconciled
  by `scripts/ci/assemble-config.sh` — unchanged.
- `drupal/group: 4.0.x-dev` in composer.json; no `grequest` in composer.json — confirmed absent.
- Live group roles today: `config/sync/group.role.community_group-{admin,anonymous,member,outsider}.yml`.
  `docs/groups/config/` has a *different*, newer-looking subset:
  `{admin,anon_view,insider_view,outsider_view}.yml` — confirmed still diverged/stale as the survey
  described (pre-existing drift, out of this story's scope beyond "don't make it worse").
- `community_group-member.yml` (live, `config/sync/`) confirmed: scope=individual, admin=false,
  permissions = 5x `view group_node:* entity`. This is the role I extend/reuse for "Member" per the
  issue's own naming.
- `community_group-admin.yml` confirmed: `admin: true`, `permissions: {}` (unconditional bypass) —
  confirms survey's reasoning that Organizer must be a **new**, narrower role, not a reuse of admin.
- `group.relationship_type.community_group-group_membership.yml` + the existing
  `group_roles` entity-reference field (`field.storage.group_relationship.group_roles.yml`,
  cardinality -1) confirmed as the correct mechanism for assigning Organizer/Moderator/Member to a
  membership.
- `do_notifications` routing/links.task/controller pattern confirmed as the closest UI-surface
  structural analog (route + controller + local task, entity-context-aware via `{user}` upcasting;
  I'll mirror with `{group}` upcasting per `do_discovery`'s `IcalController` pattern).
- `do_group_extras/src/Hook/DoGroupExtrasHooks.php` confirmed: `administer group` /
  `administer groups` permission-gates the existing "auto-unpublish new group" bypass logic — the
  established anchor point for "Groups Moderate."
- `GroupsKernelTestBase` (`do_tests`) confirmed present and matches the survey's description
  (community_group type + relationship bootstrap via 4.x storage APIs, `GroupTestTrait` reuse).
- `content_editor` (`config/sync/user.role.content_editor.yml`) confirmed as the site-role config
  shape analog for a new `groups_moderate`-style global role.

**New finding beyond the salvaged survey (post-#146):** `do_chrome/src/PermissionMatrix.php` (landed
in PR #146 for issue #91) is a **live artifact** that hard-codes the *old* role scheme
(anonymous/outsider/member/admin, sourced from the stale `anon_view`/`outsider_view`/`insider_view`
config) even though issue #91's own body (updated 2026-07-22) says the matrix should show
Anonymous/Member/**Organizer**/**Groups-Moderate**. This confirms #91 is only partially done and
this story (#138) is the one that must actually produce the Organizer/Groups-Moderate roles before
#91 can be updated. **Decision: do not touch `do_chrome/PermissionMatrix.php` in this story** — it's
`do_chrome`'s Owns in a separate issue; updating it to consume the new roles is #91's follow-up
work, out of scope here. Recorded so #91's eventual re-run isn't surprised.

**Confirmed via `gh issue view` (live, not just survey inference):**
- #144 (create-flow) explicitly: "Depends on: #138 (Organizer group role must be defined)".
- #120 (persona switcher) explicitly names "Organizer group role" (Maria Chen) and a
  "Groups-Moderate" global role, sourced from MC-7/#138 — confirms exact role-shape contract.
- #121/#134 own the *vestigial role cleanup* (`anon_view`/`outsider_view`/`insider_view`/stale
  `admin`/`anonymous`/`outsider` duplicates) and the two-axis visibility/join-policy model — I must
  **not** touch those files; only add `organizer`/`moderator` roles and reuse `community_group-member`
  as this issue's own Owns.
- Epic #137 confirms #138 is explicitly "foundational," underpins #144/#120/#91, and the shared
  coordination note: "group role YMLs (#138 owns positive role defs, coordinates with #121/#134
  cleanup)".

**Forward-compat check:** re-confirmed clean (matches salvaged survey's conclusion) — no conflicting
shape required by #144/#120/#91.

**Environment fix:** `OPENAI_API_KEY` was present in the canonical checkout's gitignored `.env` but
not copied into this fresh worktree (git worktrees don't share untracked/gitignored files). Copied
`.env` from `/Users/andreangelantoni/Projects/groups-on-d11/.env` into the worktree (chmod 600) so
`dual-review.sh` can run.

**Evidence:** `gh issue view 138/91/120/121/134/144/137 --repo Performant-Labs/groups-on-d11`;
direct file reads of `config/sync/group.role.community_group-{member,admin}.yml`,
`do_chrome/src/PermissionMatrix.php`, `do_group_extras/src/Hook/DoGroupExtrasHooks.php`,
`do_notifications/do_notifications.routing.yml`, `do_tests/tests/src/Kernel/GroupsKernelTestBase.php`.
