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
