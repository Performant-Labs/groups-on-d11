# Decision Journal — #120 SC-1 Persona Switcher

## O — Phase 1 (survey + brief)

**Decided:** Use `drupal/masquerade ^2.2` (already locked at 2.2.0 in composer.lock; declares
`drupal/core: ^10.3 || ^11.0 || ^12.0`; security-covered by Drupal SA). D11 compatibility CONFIRMED
by lockfile; no fallback (scoped custom switcher) required. Will record this on the GH issue and in
the PR body per AC.

**Decided:** New Persona code lives in `do_showcase` per issue ("Owns" section) —
`docs/groups/modules/do_showcase/src/Persona*` — reusing the module's existing service +
`DoShowcaseHooks` attribute-hook pattern.

**Decided:** Groups-Moderate persona = existing `user.role.groups_moderate` (already in
`docs/groups/config/`; empty permissions today). This story appends the scoped moderation
permissions (pending-queue / approve / archive/restore only — nothing else).

**Decided:** Header integration = append-only to the site-header account-menu area. Rendered via a
new block (`plugin: persona_switcher`) placed in `secondary_menu` (where account menu lives), OR a
`hook_page_top` shell — DESIGNER decides which is more idiomatic; both are append-only.

**Decided:** All four personas fully public per updated MVP model. Dropdown widget (visible for
anonymous), per-option `do_chrome` tooltips (via `HelpText::get()` — append 4 new keys
`persona.anonymous|elena|maria|moderator`, do NOT edit any existing key).

**Decided:** Safety uid 1 exclusion enforced at access layer (a `MasqueradeAccessCheck` service or
`hook_masquerade_target_alter` equivalent — masquerade contrib provides
`masquerade.masquerade_users` config OR a `hook_masquerade_deny`; tester determines mechanism from
the shipped module).

**Assumed:** masquerade 2.2 supports 4-target allowlist via its `masquerade.settings` config
(`allowed_roles` / `masquerade_users`). Tester + Feature-implementor verify against installed
module code.

**Assumed:** The seed file for personas is `step_700_demo_data.php` (Maria + Elena already seeded
there). Groups-Moderate account creation + pending join-request seed is a **new append-only**
`step_790_persona_switcher.php` (numbered after 780 nav) to avoid touching #121-owned seeds.

**Evidence:** `composer.lock` masquerade 2.2.0 D11 ok; `docs/groups/scripts/step_700_demo_data.php`
already seeds Maria+Elena; `docs/groups/config/user.role.groups_moderate.yml` exists;
`docs/groups/modules/do_chrome/src/HelpText.php` is append-only tooltip store.

## D — Phase 2 (design)

**Decided:** Widget = native `<select>` (auto-submitting `<form>`, progressive enhancement; real
`<button type="submit">Go</button>` no-JS fallback — never `#type => submit`). Justified against
every WCAG 2.2 AA bullet in the AC (keyboard, focus, SR announce, non-color state, no-JS, mobile)
in a comparison table; rejected a custom `<details>`/listbox disclosure since it adds ARIA/keyboard
engineering with no AC gap to justify it. Banner reuses the `hook_page_top` idiom already
established by `DoShowcaseHooks::pageTop()` (POC ribbon) rather than inventing a new attach point.

**Decided:** Per-persona banner copy is the issue's exact phrasing instantiated per name/role
("You're browsing as Elena Garcia — Member — switch back", etc.); `role="status"`, non-color `▶`
glyph + text, real `<a>` switch-back link, inline position (not fixed).

**Assumed:** Switching is always logout+login (per O's Phase-1 decision, carried forward
unchanged) — dropdown re-selection while a persona is already active is not a distinct UI state,
just the same one-control interaction.

**Hedged:** Native `<select>` cannot host a live do_chrome/tippy tooltip per `<option>` (browser-
native popup, outside the DOM) — proposed one wrapper-level combined `ⓘ` tooltip + native `title=`
per option as the closest achievable reading of "each option carries a tooltip." Flagged as Open
Question #1 for explicit operator sign-off before Architecture/Feature build against it.

**Evidence:** `VariantSwitcher.php` (one-tooltip-per-wrapper convention, non-color state glyph),
`do_showcase.css` (focus-ring token, ribbon contrast pairing), `DoShowcaseHooks::pageTop()`
(page_top idiom), `HelpText::all()` (append-only plain-text tooltip store, allowHTML disabled),
`ShowcaseCatalog::personas()` (existing 4-persona id/name/description list — reused verbatim for
tooltip copy grounding), issue #120 body (exact banner copy, "dropdown over chips" rationale).

## O — D-gate (auto-approval)

**Decided:** Wireframe APPROVED. All three open questions resolved:
1. Per-option tooltip = wrapper `ⓘ` (do_chrome/tippy) + native `title=` per `<option>` — accepted
   as the correct engineering interpretation of "each option carries a tooltip" given native
   `<select>` constraints. AC satisfied via `title=`; tippy tooltip is on the wrapper for the whole
   widget. (No user-facing surprise — POC scope, and Tester will assert the `title=` attributes on
   each `<option>` to prove per-option help *does render*.)
2. Banner position = **inline** (not fixed). Avoids stacking chrome under the POC ribbon on mobile.
3. Post-switch destination that 403s → fallback to `<front>`. Cleaner UX than a hard 403 immediately
   after a successful persona switch.

**Evidence:** wireframe.md §7 open questions; issue #120 AC language ("Each option carries a
do_chrome tooltip").

## A — Phase 3 plan review

**Decided:** BLOCK — plan requires 6 numbered amendments before T authors RED tests. See
`handoff-A-plan.md` for the full list.

**Decided:** The three architectural blockers: (1) Groups-Moderate enforcement site is the
group-scoped `group.role.community_group-groups_moderate.yml`, not the user role — the plan
targets only the user role; (2) `ShowcaseCatalog::personas()` already ships the 4-persona list,
so the proposed `PersonaRegistry` is a parallel path and must fold into `ShowcaseCatalog`;
(3) masquerade is enabled but the "always full logout+login" rule bypasses every masquerade
mechanism — either use it or drop the dep. Recommend dropping.

**Assumed:** The composer.lock declaration of masquerade 2.2 is enough for the plan review; the
actual `web/modules/contrib/masquerade/` tree is absent from this worktree (composer install
not yet run) so the `masquerade_users` vs `allowed_roles` question is deferred to Feature-time
verification IF the module is kept.

**Hedged:** Whether the group-role Groups-Moderate should keep `admin: true` (grants all group
perms via bypass — over-broad but simplest) or flip to `admin: false` and enumerate the exact
perms (`administer members`, `edit group`, plus archive perm). Recommended (b) with an explicit
scope journal; O picks in the amendment cycle.

**Evidence:** `ManageMembersController.php:39` and `RestoreGroupAccess.php:50` both check
`$group->hasPermission(...)` (group perms, not user perms); `group.role.community_group-groups_moderate.yml`
exists with `permissions: {}` + `admin: true`; `ShowcaseCatalog::personas()` lines 97-120 ships
`anonymous / elena-garcia / maria-chen / moderator` verbatim.

## O — Phase 3 amendments after A BLOCK

**Decided (blocker 1):** Groups-Moderate scoped perms live on `group.role.community_group-groups_moderate.yml` (flip `admin: true` → `admin: false`; grant `administer members` + `edit group` + archive perm to be enumerated by F from `do_group_extras` archive-access check). User role `user.role.groups_moderate.yml` also gets minimal `access content`. Negative test asserts no site-admin reachable.

**Decided (blocker 2):** DROP `PersonaRegistry`; extend `ShowcaseCatalog::personas()` with `uname` + `tooltip_key` fields; add `ShowcaseCatalog::personaSpec(id): ?array`. `PersonaSwitcher` consumes it via DI.

**Decided (blocker 3):** DROP `drupal/masquerade` dependency entirely — its guarantees (uid-1 guard, unmasquerade, audit) are bypassed by the always-logout+login flow. Ship bespoke `PersonaSwitchController` + route-level `PersonaAccessCheck` service (tagged `access_check`, applies_to `_persona_access`). AC's "D11 masquerade compat verified" is replaced with "masquerade dep dropped; bespoke path — rationale recorded on issue + PR body." Simpler and honest.

**Decided (warn 5):** Split banner into a second `#[Hook('page_top')]` method `personaBanner()` on `DoShowcaseHooks` — do not touch `pageTop()` (ribbon).

**Decided (warn 7):** Both widget + banner render arrays declare `#cache['contexts'] => ['user']`. T asserts.

**Decided (warn 11):** Trim the four `persona.*` HelpText values to ≤ 140 chars (draft in amended brief).

**Decided (warn 8):** Seed writes `field_membership_status` via `GroupMembershipManager::STATUS_PENDING`.

**Decided (warn 10):** ONE route `do_showcase.persona_switch` at `/persona-switch/{persona}`; POST for switch-to-persona (state change), GET allowed only when `persona=anonymous` (banner link).

**Evidence:** A handoff-A-plan.md findings 1-11; ShowcaseCatalog.php lines 97-120 (existing personas list); group.role.community_group-groups_moderate.yml (admin: true, permissions: {}); group.role.community_group-organizer.yml (real perm names); ManageMembersController.php line 39 + RestoreGroupAccess.php line 50 (real permission enforcement sites).

## A — Phase 3 re-review

**Decided:** PASS. All 3 blockers + 6 warnings from `handoff-A-plan.md` resolved by the 8 amendments in `brief-amendments.md`. T may author RED tests. See `handoff-A-plan-2.md` for the resolution table.

**Evidence:** A1 adds group role edit with enumerated perms; A2 drops PersonaRegistry and extends `ShowcaseCatalog::personas()` (no field-name collision with existing `{id,name,description}`); A3 drops masquerade dep and routes uid-1/allowlist enforcement through A4's route-level `PersonaAccessCheck` (tagged `access_check`, POST-only for state-change); A5 splits banner into a sibling `#[Hook('page_top')]`; A6 pins `#cache[contexts]=>[user]`; A7 trims HelpText to ≤135 chars; A8 uses `STATUS_PENDING` const.

**Non-blocking note for F:** `RestoreGroupAccess::access` only checks `edit group` (group) or `administer group` (user); no separate archive-only group perm exists in `do_group_extras`. The "archive perm" third bullet in A1 will collapse to just `edit group` on inspection — harmless if kept, cleaner if dropped with a one-liner.
