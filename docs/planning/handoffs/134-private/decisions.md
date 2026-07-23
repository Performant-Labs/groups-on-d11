# Decisions — #134 SC-7 Private groups

Append-only. One entry per phase.

## Phase 1 — O (survey + brief)

**Decided:**
- New field `field_group_privacy` (list_string; values `public`, `unlisted`, `private`; default `public`) — a SEPARATE axis from `field_group_visibility`. Do NOT add `private` to `field_group_visibility.allowed_values` (would collapse #121's two-axis model).
- Access enforcement in `do_group_extras/src/Hook/DoGroupExtrasHooks.php` (co-located with existing archive `nodeAccess`). Two new hook methods: (a) `#[Hook('group_access')]` → forbid `op='view'` on private groups for non-members; (b) extend existing `nodeAccess` to forbid `op='view'` on nodes in private groups for non-members. Justification: `do_group_membership` owns membership-relationship access; `do_group_extras` owns site-level view filters (archive precedent).
- Directory hide relies on entity_access + view SQL rewrite (default-on). No new query alter.
- Seed step 795 adds "Security Team": privacy=private, +elena_garcia +maria_chen +james_okafor as members (Elena+Maria required for #120 demo; James optional per issue), 2 forum topics ("Coordinated disclosure process", "Q3 advisory review"). Append-only to `step_700_demo_data.php`.
- HelpText append-only: `privacy.public`, `privacy.unlisted`, `privacy.private`, `privacy.vs_invite_only` (teaching key).
- Groups_chrome theme gets a small `<span class="gc-privacy-badge">` render when `field_group_privacy=private`, using the same `data-do-tooltip` pattern the group-lead uses today.
- D phase IS RUN (small new UI surface: privacy badge + tooltip on group card).

**Assumed (must verify at T-time):**
- Drupal core's group entity has an access-check hook or entity_access system that respects a `hook_group_access` return of `forbidden()` on op='view' across all `/group/{group}` routes, canonical link, breadcrumb, contextual links. T must exercise anonymous 403 on `/group/N` AND verify no group title leaks anywhere in an anonymous session.
- `views.view.all_groups.yml` respects entity access (no `disable_sql_rewrite: true`). T verifies by kernel/functional check.
- `Group::getMembers()` / `Group::getMember($account)` is the correct membership probe (matches #121 usage).

**Hedged / risks:**
- **Vestigial role cleanup DEFERRED, contra issue body item 4.** Evidence: `community_group-admin` is a hard dependency of `group.type.community_group.yml` (removing the file breaks config import). `community_group-member` is referenced in production code paths (AddMemberForm, ChangeRoleForm, ManageMembersForm, PermissionMatrixTest, plus 15+ tests calling `addMember(..., ['community_group-member'])`). A safe cleanup requires updating all those references and re-testing — well outside this story's blast radius. Recorded here as an intentional narrowing (POC posture, overnight autonomous constraint). The 4 files remain untouched. This deviates from item 4 of the story body; flagging for operator review post-merge.
- `field_group_privacy` naming: no downstream story depends on it per WAVE-EXECUTION-HANDOFF scan.
- If entity_access doesn't cover a specific route (e.g. RSS, JSON:API), it may leak. T asserts anonymous `/all-groups`, anonymous `/group/{sec_team}` (403), anonymous search for "Coordinated disclosure" (no results). Any additional discovered leak surface becomes a targeted fix, not a scope expansion.

**Evidence:**
- Survey: `docs/planning/handoffs/134-private/survey.md`
- #121 landed hooks + tests reviewed at their landed paths (see survey §"Key files inspected").
- Vestigial-role reference grep: 15+ hits on `community_group-member` in production code; 3 hits on `community_group-admin` (group.type dep, HelpText comment, PermissionMatrixTest comment).

**Review rigor dial:** `none` (POC / overnight autonomous mode explicitly waives brief-gate + diff-gate).

## Phase 2 — D (wireframe + copy proposals) — auto-approved

**Decided (auto-approved by O per overnight autonomous mode; design is sound):**
- ONE net-new UI surface: a "Private" badge in two existing rows (`.gc-directory-card__badges`, `.gc-group-header__badges`). No new components.
- Badge markup: `<span class="gc-badge gc-badge--warning gc-privacy-badge ..." tabindex="0" role="note" aria-label="{{copy}}" data-do-tooltip="{{copy}}">Private</span>` — reuses landed `gc-badge` + `data-do-tooltip` contract (precedent: `group--full.html.twig` L113-127, #122 lead ⓘ).
- Renders ONLY on `field_group_privacy == 'private'`. Public/Unlisted silent (matches archive-badge convention).
- `gc-badge--warning` variant (verified unused elsewhere; no new tokens).
- Multi-class kept (`gc-badge` + `gc-privacy-badge` + BEM location class) — satisfies AC #9 selector and preserves badge styling.
- Copy (F consumes verbatim from wireframe §3): 4 keys, all under 200 chars, `privacy.unlisted` honestly flags NOT-enforced (only `private` is enforced this story).

**Assumed:**
- Template paths (`group--full.html.twig`, `views-view-fields--all-groups.html.twig`) match D's sketch — F verifies at implementation and picks actual paths if they differ; design intent preserved.

**Hedged:**
- D open Q1 (variant color): resolved to `gc-badge--warning`; F may swap on real visual clash, documenting.
- D open Q2 (multi-class): keep as sketched unless CSS collision surfaces at Tier 1.

**Evidence:** `docs/planning/handoffs/134-private/wireframe.md` (D output, 240 lines).

**Approval:** Auto-approved by O per overnight autonomous mode authorization (aangelinsf, 2026-07-22).

## Phase 3 — A (up-front plan review) — PASS w/ advisories

**Verdict:** PASS. All load-bearing decisions approved (new field, hook placement, deferred cleanup, AC coverage).

**Advisories folded into brief for F to consume:**
1. **Views SQL rewrite verify.** F to grep `views.view.all_groups.yml` at Tier 1 for `disable_sql_rewrite`; if `true` or bare SQL, add `hook_views_query_alter` on the `all_groups` view (still in `do_group_extras`), NOT a generic `hook_query_TAG_alter`.
2. **Cache invalidation on join/leave.** T to add one kernel assertion in `PrivacyAccessTest`: after `$group->addMember($account, ...)`, a fresh `access('view')` call returns allowed (no stale-cache leak). F to attach `group_content_list:{gid}` cache tag or `user` cache context alongside the existing `cachePerUser()`.
3. **Title-leak breadth.** T's `PrivacyDirectoryTest` asserts the literal string "Security Team" absent from anonymous /all-groups response body (not just `.gc-group-card` selector).
4. **`step_700` L91 admin-role reference — LEAVE ALONE.** Do not drive-by-fix; that touches deferred vestigial-role cleanup scope. F ships privacy without editing the seed's existing `community_group-admin` addMember() call.

**Evidence:** A subagent transcript (acb539569fb768d33).
