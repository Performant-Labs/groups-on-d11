# Handoff-T-red: Phase 4 - #134 SC-7 Private groups (view-access axis)

**Date:** 2026-07-22
**Branch:** 134-private
**Brief / wireframe reviewed:** `docs/planning/handoffs/134-private/brief.md`, `survey.md`, `decisions.md` (Phases 1-3), `wireframe.md`

## A precondition

Confirmed: A returned PASS (w/ 4 advisories) on the plan at Phase 3 — see
`docs/planning/handoffs/134-private/decisions.md` §"Phase 3 — A (up-front plan review)". All 4
advisories are baked into the tests below.

## Tests authored

1. **`docs/groups/modules/do_group_extras/tests/src/Kernel/PrivacyAccessTest.php`** (NEW, kernel)
   - `testFieldStorageExistsWithAllowedValues` / `testFieldConfigExistsWithPublicDefault` — AC-1
     (field storage + config exist, allowed_values in order, default `public`).
   - `testNonMemberForbiddenFromViewingPrivateGroup`,
     `testAnonymousForbiddenFromViewingPrivateGroup`,
     `testMemberNotForbiddenFromViewingPrivateGroup` — AC-2 kernel half (group-level view gate).
   - `testAnonymousNotForbiddenFromViewingPublicGroup`,
     `testAnonymousNotForbiddenFromViewingUnlistedGroup` — negative: gate must not over-apply to
     `public`/`unlisted`.
   - `testNonMemberForbiddenFromViewingNodeInPrivateGroup`,
     `testMemberNotForbiddenFromViewingNodeInPrivateGroup`,
     `testNonMemberNotForbiddenFromViewingNodeInPublicGroup` — AC-4 kernel half (node-level view
     gate inside private groups; content hidden from streams/search).
   - `testJoinPolicyForRegressionAcrossAllThreeVisibilityValues` — AC-6 regression: #121's
     `GroupMembershipManager::joinPolicyFor()` unchanged for `open`/`moderated`/`invite_only`.
   - `testAccessResultDoesNotLeakStaleCacheAcrossMembershipChange` — A advisory #2: fresh
     `access('view')` after `addMember()` returns allowed, no stale-cache leak.
   - Tier: kernel — these assert on real installed `field_group_privacy` config + real
     `GroupInterface`/`NodeInterface` entity-access results, cheaper and more precise than a full
     HTTP round-trip, and don't need a rendered page.

2. **`docs/groups/modules/do_group_extras/tests/src/Functional/PrivacyDirectoryTest.php`** (NEW,
   functional/BrowserTestBase)
   - `testAnonymousGetsAccessDeniedOnPrivateGroupCanonical` — AC-2 (403 on `/group/{id}`).
   - `testAnonymousAllGroupsOmitsSecurityTeamLiterally` — AC-3 (literal string absence, A advisory #3).
   - `testAnonymousStillSeesPublicGroup` — negative/regression baseline.
   - `testMemberSeesPrivateGroupInDirectoryAndCanonical` — AC-5 (Elena sees it, 200 on canonical).
   - `testMemberSeesPrivacyBadgeOnPrivateGroupCanonical`,
     `testMemberSeesNoPrivacyBadgeOnPublicGroupCanonical` — AC-9 (badge DOM presence/absence).
   - Tier: functional — these require the real route/access-control-handler pipeline and rendered
     theme markup (`groups_chrome`), which a kernel test cannot exercise for the DOM-selector
     assertions.

3. **`docs/groups/modules/do_chrome/tests/src/Unit/HelpTextTest.php`** (APPENDED) —
   `testPrivacyKeys()` — AC-8 (4 privacy keys exist, non-empty, plain-text, >= 40 chars; teaching
   key names both axes; `privacy.unlisted` honestly flags non-enforcement). Tier: unit — `HelpText`
   is a static string source, no framework bootstrap needed.

4. **`tests/e2e/private-group.spec.ts`** (NEW, Playwright) — one `test.describe.serial` block:
   anonymous does not see "Security Team" in `/all-groups`; `elena_garcia` does, and clicking
   through lands on the canonical page. Tier: e2e — this is the one behavior that must be proven
   through real SPA-style navigation against a fully seeded site (persona-switcher demo, AC-5/AC-9
   taken together), not duplicating the functional test's narrower DOM assertions.

## RED confirmation

`bash scripts/ci/assemble-config.sh` (run from this worktree): **PASS** for the assembly step
itself — copied 95 config files (7 excluded per the pre-existing env-specific list) and 13 custom
modules into `web/modules/custom/`, confirming the new/edited test files land at the CI-assembled
path:
```
web/modules/custom/do_group_extras/tests/src/Kernel/PrivacyAccessTest.php
web/modules/custom/do_group_extras/tests/src/Functional/PrivacyDirectoryTest.php
web/modules/custom/do_chrome/tests/src/Unit/HelpTextTest.php
```
The script then halted (as designed) on its own composer-autoload guard:
```
ERROR: C:/Users/aange/Projects/_worktrees/groups-private/vendor/autoload.php missing — run
'composer install' before assemble-config
```

**PHPUnit could NOT be executed in this session** — this worktree has no `vendor/` and no
`php`/`composer` on PATH; DDEV is available on the host but already bound to the primary
checkout's project (`pl-groups-on-d11`), so it cannot serve this worktree/branch. This is recorded
honestly per instructions rather than fabricated.

**Static basis for RED validity** (in lieu of an executed run — see full detail in
`docs/planning/handoffs/134-private/decisions.md` §"Phase 4 — T (RED...)"):
- `DoGroupExtrasHooks.php` (read directly, current state) has no `group_access` hook and its
  `nodeAccess()` only gates `op === 'create'` — so every `access('view', ...)` kernel/functional
  assertion above currently resolves ALLOWED (not forbidden) for every account, failing the
  `isForbidden()` assertions for the right reason (missing hook), not a setup bug.
- `HelpText.php` has no `privacy.*` keys yet (confirmed via survey's append-only framing) — `get()`
  falls back to `''`, failing `assertNotSame('', ...)` for the right reason.
- No `.gc-privacy-badge` markup exists in `groups_chrome` yet (per the wireframe, this is new
  markup) — AC-9 `elementExists` assertions fail for the right reason (markup absent).
- `private-group.spec.ts` is un-runnable this session (no seeded site up, Step 795 doesn't exist
  yet) — expected/waived RED for E2E per task instructions; contract is locked for F.

## Ready for F

Confirmed RED is valid (static analysis against current production code + successful config/module
assembly). F may implement against these tests. T will execute PHPUnit and confirm GREEN at
Phase 6 in a properly provisioned DDEV/CI environment.
