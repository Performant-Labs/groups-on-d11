# Brief — #134 SC-7 Private groups (visibility axis)

**Spec source of truth:** `gh issue view 134 --repo Performant-Labs/groups-on-d11` (reframed 2026-07-22).
**Read alongside:** `survey.md` (in this dir), `decisions.md`, and `docs/planning/handoffs/121-req2join/handoff-F.md` (the two-axis groundwork this extends).

## Objective

Add a **view-access axis** to community groups: a new `field_group_privacy` field (list_string: `public`/`unlisted`/`private`, default `public`) that, when set to `private`, hides the group from non-members entirely (403 on canonical, absent from /all-groups, content hidden from streams). Ship one seeded "Security Team" private group so the #120 persona-switcher demo works: Anonymous sees nothing → switch to Elena/Maria → Security Team materializes.

## Acceptance criteria (each backed by a T-authored test)

1. `field_group_privacy` config exists (storage + field on `community_group`), allowed_values are `public`/`unlisted`/`private`, default is `public`.
2. **Anonymous user** visiting `/group/{security_team_id}` gets **403** (functional test).
3. **Anonymous user** visiting `/all-groups` does **NOT** see Security Team in the rendered list (functional test asserting label absence).
4. **Anonymous user** searching or visiting any stream does not see Security Team's content nodes (functional test on node view access for a Security Team forum node).
5. **Elena** (`elena_garcia`, member) visiting `/all-groups` **DOES** see Security Team; visiting `/group/{sec_team}` returns 200 (functional).
6. **`GroupMembershipManager::joinPolicyFor()` behaviour is UNCHANGED** for the 3 existing values (regression test — do NOT break #121).
7. Seed is idempotent: running `step_700_demo_data.php` twice produces no duplicate memberships and no duplicate Security Team group (kernel or functional).
8. HelpText keys `privacy.public`, `privacy.unlisted`, `privacy.private`, and one teaching key that contrasts Private (hidden) vs Invite Only (visible-but-closed) exist and return non-empty strings (unit test extending existing `HelpTextTest`).
9. Group card (or badge on canonical) renders a "Private" indicator when `field_group_privacy=private`, with tooltip attribute pointing at the `privacy.private` key (functional test asserting DOM `.gc-privacy-badge[data-do-tooltip]` presence on Elena's session viewing Security Team).
10. Assembly script (`bash scripts/ci/assemble-config.sh`) exits 0 with the new field configs; existing kernel + functional tests still pass (`JoinPolicyEnforcementTest`, `RequestJoinFlowTest`, etc.).

## Files to touch (extend, not create-new, where possible)

- **NEW** `docs/groups/config/field.storage.group.field_group_privacy.yml`
- **NEW** `docs/groups/config/field.field.group.community_group.field_group_privacy.yml`
- **EDIT (extend)** `docs/groups/modules/do_group_extras/src/Hook/DoGroupExtrasHooks.php` — add `#[Hook('group_access')]` method for view-op forbid on non-members of private groups; extend existing `nodeAccess()` to also forbid `op='view'` on nodes in private groups for non-members. Both attach cacheable dependency on the group + `cachePerUser()`.
- **EDIT (extend)** `docs/groups/modules/do_chrome/src/HelpText.php` — append 4 keys per the append-only contract.
- **EDIT (extend)** `docs/groups/scripts/step_700_demo_data.php` — append Step 795 (Security Team group + members + 2 forum nodes; idempotent guards per existing pattern).
- **EDIT (extend)** `web/themes/custom/groups_chrome/groups_chrome.theme` — extend `preprocess_group` (or existing hook) to render a `gc-privacy-badge` variable when private; template edit to render it with the `data-do-tooltip` attribute.
- **NEW** `docs/groups/modules/do_group_extras/tests/src/Kernel/PrivacyAccessTest.php` — kernel test for group_access + node_access hook behavior (member vs non-member vs anonymous).
- **NEW** `docs/groups/modules/do_group_extras/tests/src/Functional/PrivacyDirectoryTest.php` — anonymous /all-groups omits, anonymous /group/N 403, Elena /group/N 200, Elena sees badge.
- **EDIT (append)** `docs/groups/modules/do_chrome/tests/src/Unit/HelpTextTest.php` (or add new small unit test) — assert the 4 new keys exist.
- **NEW** `tests/e2e/private-group.spec.ts` — one E2E happy path: anonymous → /all-groups (Security Team absent) → login as elena → /all-groups (Security Team present) → click card → 200. Marked `test.describe.serial` if needed to reuse a single session.

## Files NOT to touch (out of scope for this story)

- `docs/groups/config/field.storage.group.field_group_visibility.yml` and `field.field.group.community_group.field_group_visibility.yml` — the join-policy axis; unchanged. Do NOT add `private` to its allowed_values.
- `docs/groups/modules/do_group_membership/**` — the membership axis (#121); this story does not touch it. `GroupMembershipManager::joinPolicyFor()` remains untouched.
- Vestigial role files (`config/sync/group.role.community_group-{anonymous,member,outsider}.yml` + `admin` in both dirs) — **DEFERRED**, see `decisions.md` §"Hedged / risks". These 4 files are NOT edited or deleted by this story. This is a documented deviation from the issue body item 4; recorded for operator post-merge review.

## Reuse map summary (from survey)

Every new hook has a landed analogue in the same module. Every new config file has a mirror in the same directory pattern. The seed extends an existing step block. The theme change reuses the `data-do-tooltip` attribute contract. The ONLY genuinely new object is `field_group_privacy` — justified because reusing `field_group_visibility` would collapse the two-axis model #121 established.

## Verification (Tier 1 for F, Tier 2 for T)

- Tier 1 (F self-check): `bash scripts/ci/assemble-config.sh` exits 0.
- Tier 2 (T): kernel + functional per file list above; `npx playwright test tests/e2e/private-group.spec.ts` green vs a seeded site.

## Persona demo dependency

Elena AND Maria MUST both be Security Team members (both are `#120`-catalog personas). James Okafor added as a third member (issue's "optionally"). Two forum topics gives the reveal substance.

## Review rigor

`none` — POC / overnight autonomous. Skip brief-gate o4-mini and diff-gate.
