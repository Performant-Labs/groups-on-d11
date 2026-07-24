# Survey — #134 SC-7 Private groups (visibility axis)

## What #121 landed (the two-axis groundwork)

- `field_group_visibility` (list_string, allowed_values: `open`, `moderated`, `invite_only`) is the **JOIN-POLICY axis**. Corrected in copy by #121: "controls whether joining is instant, request-based, or invite-only"; **every group stays viewable regardless of this value.**
- `GroupMembershipManager::joinPolicyFor()` classifies visibility -> `'open'|'request'|'invite'`.
- `GroupAccessHook::groupRelationshipCreateAccess()` (hook_group_relationship_create_access) closes the invite-only join gap.
- `RequestJoinForm` + `RequestJoinFlowTest` (kernel) + `JoinPolicyEnforcementTest` (functional) cover the join axis fully.
- HelpText keys `visibility.field/open/moderated/invite_only` explicitly frame joining vs viewing and state "hidden/unlisted is Private (#134), a distinct not-yet-built value".

## What is MISSING (#134's scope)

No **VIEW-ACCESS axis** exists. There is no `private` value anywhere — no field, no access hook, no view filter, no node-access hook, no seed. `field_group_visibility` cannot host `private` without collapsing the two axes back into one (which the reframed issue explicitly forbids).

## Key files inspected

- `docs/groups/config/field.storage.group.field_group_visibility.yml` — list_string, 3 values (join axis, do NOT add `private` here).
- `docs/groups/config/field.field.group.community_group.field_group_visibility.yml` — required, default `open`.
- `docs/groups/config/group.role.community_group-*.yml` — **docs side has 7**: admin, anon_view, groups_moderate, insider_view, moderator, organizer, outsider_view.
- `config/sync/group.role.community_group-*.yml` — **4 files**: admin, anonymous, member, outsider. The 3 lower ones (anonymous/member/outsider) have NO docs-side counterpart -> `assemble-config.sh` cannot delete them by re-copying; they must be deleted from `config/sync/` directly. `admin` exists in BOTH dirs and can be dropped from both.
- `docs/groups/modules/do_group_membership/src/Hook/GroupAccessHook.php` — pattern to reuse for the entity_access hook (Attribute-based #[Hook], cache tags on the group, ->cachePerPermissions()->cachePerUser()).
- `docs/groups/modules/do_group_extras/src/Hook/DoGroupExtrasHooks.php` — already implements `hook_node_access` (archive), `hook_preprocess_group` (archive badge), `hook_form_alter` (group forms), `hook_entity_presave`/`hook_entity_insert` (moderation queue). This is the NATURAL home for private-visibility view-access and node-hide hooks.
- `docs/groups/config/views.view.all_groups.yml` — 2 filters (status=1, label exposed). Needs a **visibility filter** hiding privacy=private for non-members. Since view filters cannot easily "unless-member", the cleanest is a `hook_query_TAG_alter` on the group listing (tag `entity_access`) OR let the entity_access hook do the work and add a `views_pre_render` / `hook_query_alter` on the group listing to hide forbidden groups. **Preferred: rely on entity_access** — Drupal's `EntityQuery` with access check enabled will filter automatically. Confirm the view has `disable_sql_rewrite: false` (default).
- `docs/groups/scripts/step_700_demo_data.php` — 483 lines. Step 730 creates groups; step 790 sets join-policy visibility. #134 appends Step **795** for the "Security Team" private group (privacy=private, +Elena +Maria +2 forum topics).
- `docs/groups/modules/do_chrome/src/HelpText.php` — append-only pattern proven; add `privacy.public/unlisted/private` + one teaching key.
- `docs/groups/modules/do_showcase/src/ShowcaseCatalog.php` L142-155 — Elena (uname `elena_garcia`) and Maria (uname `maria_chen`) are the seeded personas. Making them Security Team members makes the #120 persona-switch demo work: Anonymous -> Elena -> "Security Team" appears.

## Reuse & Analogous-Feature map (DEFAULT: EXTEND, not new)

| Concern | Analogous feature | Object to reuse | Extend vs new |
|---|---|---|---|
| Group view-access gate | `GroupAccessHook::groupRelationshipCreateAccess` (#121) | Same module, or `do_group_extras` (has `hook_node_access` already) | **EXTEND** `do_group_extras`. Add new `#[Hook('group_access')]` method on `DoGroupExtrasHooks` (co-located with archive's `hook_node_access`; access-gate hooks belong together). Justification: `do_group_membership` owns MEMBERSHIP access (create-relationship); `do_group_extras` owns SITE-LEVEL access filters (archive, now privacy). No new module. |
| Node hide from streams | `DoGroupExtrasHooks::nodeAccess` (archive) | Same method | **EXTEND** — add a second condition path (op='view' AND node in a private group AND account not a member -> forbidden). Same class, same file, same test. |
| Privacy value storage | `field_group_visibility` | **DO NOT REUSE** — that field is the join axis. | **NEW** field `field_group_privacy` (list_string: public/unlisted/private, default=public). Justification recorded: extending `field_group_visibility` would collapse the two axes the reframe requires. This is the load-bearing "new object" of the story. |
| Directory hide (all-groups) | Drupal EntityQuery access check | Views' default access-check | **EXTEND (config-only)** — verify `disable_sql_rewrite: false` on all_groups view; the entity_access hook handles filtering. Add a Functional test that asserts anonymous session sees no `.gc-group-card` for Security Team. If SQL rewrite is off on the view, this is a config edit, still not code. |
| Seed | step_700_demo_data.php step 790 pattern | Same file, next block (795) | **EXTEND** append-only (existing rule). |
| Teaching copy | `HelpText::all()` (append-only) | Same class | **EXTEND** — 3 privacy keys + 1 teaching key contrasting Private vs Invite Only. |
| Tooltip render on group card | `groups_chrome` theme (renders group cards) | Existing card template | **EXTEND** — add a small `<span class="gc-privacy-badge" data-do-tooltip="...">Private</span>` when privacy=private, reusing the do_chrome tooltip attribute pattern. |
| Vestigial role cleanup | (no analogue) | — | **DELETE** 3 files from `config/sync/` directly; delete `admin` from both dirs; run `bash scripts/ci/assemble-config.sh` and verify the sync copy is gone. |

## Forward-compat check

No downstream wave depends on `field_group_privacy` naming per WAVE-EXECUTION-HANDOFF.md scan. #145 (WCAG backstop) and #133 (copy sweep) are late-wave and read whatever ships. Safe to introduce the field name now.

## Risks / notes

- **NEVER add `private` to `field_group_visibility` allowed_values.** That would silently break #121's `joinPolicyFor()` mapping (default branch returns `'open'`) and re-collapse the axes.
- Group view-access hooks fire for MANY paths (title-block on child pages, breadcrumb, canonical, contextual links, /group/N/members, etc.) — a `forbidden()` return has broad reach. Test both `op='view'` on `/group/N` directly (403) AND absence of the group's title anywhere in an anonymous session.
- Entity_access hook does NOT filter Views by default UNLESS the view has entity-access checking enabled. Confirmed pattern in Drupal: EntityQuery is access-aware by default; Views' "SQL rewrite" (disable_sql_rewrite) governs it. If the view has `access` display option that queries entity access, we're covered.
- Content hiding: content nodes inside private groups need `hook_node_access(op='view')` -> forbidden for non-members, so search/streams don't leak titles. `do_group_extras::nodeAccess` already exists — extend it.
- Persona demo dependency: Elena AND Maria must both be added so switching to either surfaces Security Team; #120's persona list is fixed at these two + moderator + anonymous.
- Vestigial `admin` role has `permissions: {}` and is superseded by the `*_view` + `organizer` + `moderator` set. Confirm no seed script or code references `community_group-admin` before deletion. `step_700` L91 uses it: `$group->addMember($admin_user, ['group_roles' => ['community_group-admin']]);` — MUST update to use `community_group-organizer` or drop that line (organizer is the modern equivalent).
