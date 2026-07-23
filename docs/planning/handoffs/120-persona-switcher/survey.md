# Survey — #120 SC-1 Persona Switcher

## Spec (canonical)
`gh issue view 120 --repo Performant-Labs/groups-on-d11` — re-read every phase. Key AC:
- Anonymous sees "Browse as" dropdown in header, becomes each of 4 personas, banner shows,
  switch-back works, sessions end cleanly.
- Organizer (Maria) demonstrates Organizer-role capability plain Member lacks.
- Groups-Moderate persona: pending queue / approve / archive/restore on groups it isn't a member of,
  AND **nothing beyond that scope** (negative test).
- uid 1 unreachable via any masquerade path (test asserts).
- Per-option tooltips render; suite stays green.
- Playwright: full switch → verify → switch-back for at least two personas incl. Moderator.
- HelpText entry (append-only) ships with feature.
- WCAG 2.2 AA.
- POC bar; branch → PR → merge on green CI.

## Reuse & Analogous-Feature map (EXTEND-first)

| Concern | Extend WHAT | New/Extend | Notes |
|---|---|---|---|
| Dropdown widget PHP | `do_showcase` module (existing pattern: `VariantSwitcher` service = render-array builder for a labeled radiogroup) | **NEW class** `PersonaSwitcher` in same module, service `do_showcase.persona_switcher` — analogous to `VariantSwitcher`. Not extending VariantSwitcher (different UX: `<select>` dropdown vs radiogroup, different session semantics), but MUST follow the same conventions (autowire: false, string_translation, per-render cache keys). | Justified new: dropdown ≠ radiogroup; masquerade session state ≠ variant query param. |
| Header placement | `docs/groups/scripts/step_780_nav_menu.php` (main menu) + block placement in `secondary_menu` region (account menu region already there per #83) | **NEW** block plugin `PersonaSwitcherBlock` OR `hook_page_top`. Designer picks the more idiomatic; both append-only. | Do NOT modify step_780. |
| Tooltips | `do_chrome` `HelpText::all()` | **EXTEND (append-only)** 4 keys: `persona.anonymous|elena|maria|moderator` (or `persona.member|organizer|moderate` — Designer chooses). | Must not touch existing keys. #121 also appends here — coordinate by unique prefix `persona.*`. |
| Persona registry | none | **NEW** `PersonaRegistry` (or constant map on `PersonaSwitcher`): 4 personas with {id, label, tooltip_key, target_uname (null for anonymous)}. Analogous to `ShowcaseCatalog`'s code-constant list. | Follows `ShowcaseCatalog` pattern. |
| Groups-Moderate role permissions | `docs/groups/config/user.role.groups_moderate.yml` (exists, permissions `{}`) | **EDIT** — append the scoped moderation perms only. This is the ONE source-of-truth edit outside `do_showcase`. | Must NOT grant anything beyond pending-queue / approve / archive/restore. #138 defined the role shell; we now populate it (previous unmerged assumption). |
| Global masquerade config | none in repo | **NEW** `docs/groups/config/masquerade.settings.yml` with the 4-user allowlist and role-target exclusion; uid 1 excluded via `masquerade.masquerade_users` (or the module's actual key — Tester+Feature verify). | Whitelist mechanism, not blacklist. |
| Anonymous → user session | masquerade contrib itself only handles authenticated→authenticated. | **NEW behavior**: for the anonymous→persona flow, we need a bespoke `PersonaSwitchController` that logs the anonymous visitor into the target account (`user_login_finalize`) directly (still filtered by the same 4-user allowlist + uid-1 guard). "Switch back" = plain logout + return to prior URL. For authenticated (persona → different persona), reuse masquerade's mechanism if already logged in, else logout→login. Simpler: ALWAYS treat switching as logout+login for the 4-persona demo, since sessions are ephemeral. Designer/Architecture-reviewer to lock the pattern. | POC scope — full-login pattern is simpler + safer + WCAG-clean. |
| Persistent banner | none | **NEW** `hook_page_top` in `DoShowcaseHooks` — shown when active user's uname ∈ persona allowlist; "switch back" link → logout route (`user.logout`) with `destination=` prior page. Include screen-reader landmark. | |
| Seed | `step_700_demo_data.php` (Maria+Elena+Alex+...seeded) | **NEW** `docs/groups/scripts/step_790_persona_switcher.php` (append-only, numbered post-780): creates `groups_moderate_demo` user with Groups-Moderate global role; grants Maria the Organizer group role on an existing seeded group (per MC-7 #138 role model, append-only); creates one pending join request (via #121's future field_membership_status pending — SAFE because #121 hasn't merged so this seed writes the pending status directly using the field, which #138 already shipped). | Numbered 790 to sequence after 780 nav. |
| E2E | `tests/e2e/*.spec.ts` | **NEW** `tests/e2e/persona-switcher.spec.ts` — full switch→verify→switch-back for Moderator + one other persona. | |
| Kernel/Functional tests | module-local `docs/groups/modules/do_showcase/tests/src/{Kernel,Functional}` | **NEW** test files. Kernel: PersonaRegistry, uid 1 guard, allowlist. Functional (BrowserTestBase): dropdown renders anonymous, banner appears when persona active, moderator negative test (cannot reach `/admin/config`, `/admin/people`), moderator positive (can reach pending queue at `/group/{n}/members/pending` — from #138), Maria positive (edit group), plain Elena negative (cannot edit). | |

## Forward-compat check
- **#121 (membership models)** will edit `HelpText` (join-policy keys), edit `field_membership_status`
  logic, and edit seeds. Our appends are prefix-scoped (`persona.*`), our seed is a NEW file
  (`step_790_*`), and our Groups-Moderate role edit is disjoint from anything #121 touches. No conflict.
- **#122 (group-type homepages, already merged)** — no shared surface.
- **#126–#128 (help/tooltips)** — they append to `HelpText` too but with disjoint prefixes;
  serialize per WAVE-EXECUTION §6.
- **#134 (private group)** — no shared surface.

## Key files touched (final projection)
- `docs/groups/modules/do_showcase/src/Persona/PersonaSwitcher.php` (new, service)
- `docs/groups/modules/do_showcase/src/Persona/PersonaRegistry.php` (new, constant map)
- `docs/groups/modules/do_showcase/src/Controller/PersonaSwitchController.php` (new)
- `docs/groups/modules/do_showcase/src/Access/PersonaAccessCheck.php` (new — uid 1 + allowlist)
- `docs/groups/modules/do_showcase/src/Plugin/Block/PersonaSwitcherBlock.php` (new, if Designer picks block plugin)
- `docs/groups/modules/do_showcase/src/Hook/DoShowcaseHooks.php` (EDIT — add page_top banner + block placement)
- `docs/groups/modules/do_showcase/do_showcase.routing.yml` (EDIT — add switch/switch-back routes)
- `docs/groups/modules/do_showcase/do_showcase.services.yml` (EDIT — register persona services)
- `docs/groups/modules/do_showcase/do_showcase.info.yml` (EDIT — depend on `masquerade`)
- `docs/groups/modules/do_showcase/css/persona-switcher.css` (new)
- `docs/groups/modules/do_showcase/templates/persona-banner.html.twig` (new)
- `docs/groups/modules/do_showcase/tests/src/{Kernel,Functional}/Persona*.php` (new)
- `docs/groups/modules/do_chrome/src/HelpText.php` (EDIT — append 4 `persona.*` keys ONLY)
- `docs/groups/config/user.role.groups_moderate.yml` (EDIT — append scoped moderation perms)
- `docs/groups/config/masquerade.settings.yml` (new — allowlist + uid-1 guard)
- `docs/groups/config/core.extension.yml` (EDIT — enable masquerade + updated do_showcase deps if needed; verify against assemble script)
- `docs/groups/scripts/step_790_persona_switcher.php` (new — Groups-Moderate account, Maria Organizer role, pending join req)
- `tests/e2e/persona-switcher.spec.ts` (new)
- `composer.json` — NO CHANGE (masquerade already there)

## Gotchas already flagged
1. Verify masquerade allowlist mechanism against the installed module source (Tester reads `web/modules/contrib/masquerade/…` after assemble).
2. Anonymous→persona is NOT masquerade's designed use case — full-login pattern chosen (see map above); Architecture Reviewer to validate.
3. Groups-Moderate role edit is the ONE cross-module edit — must not accidentally expand scope.
4. E2E must self-provision or work against seeded demo (WAVE §6.6).
5. `#type => submit` renders `<input>` not `<button>`; dropdown widget will be `<select>` or a `hook_form_alter`-free custom render array — no submit-button pitfall.
6. Banner needs cache context (per-user); block needs cache context (per-user).

---

## AMENDMENTS after A BLOCK (2026-07-22)

- `PersonaRegistry` REMOVED. `ShowcaseCatalog::personas()` extended in place (adds `uname` + `tooltip_key` fields, plus helper `personaSpec(id)`).
- `drupal/masquerade` DROPPED. Bespoke `PersonaSwitchController` + `PersonaAccessCheck` service (route-level access-check, tag `access_check`).
- `group.role.community_group-groups_moderate.yml` added to files touched (the real enforcement site for `administer members` / `edit group` group-scoped perms). `admin: true` → `admin: false`, enumerated perms.
- Banner rendered via a sibling `#[Hook('page_top')]` method `personaBanner()` on `DoShowcaseHooks` — pageTop() (ribbon) untouched.
- Widget + banner cache contexts `['user']`.
- `persona.*` HelpText values ≤ 140 chars (fits `<option title=…>`).
- Seed uses `GroupMembershipManager::STATUS_PENDING` const.
- Route id `do_showcase.persona_switch` at `/persona-switch/{persona}`; POST for switch, GET for switch-back.
