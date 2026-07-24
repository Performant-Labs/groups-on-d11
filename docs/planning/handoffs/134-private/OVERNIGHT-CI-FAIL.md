# #134 Overnight CI-fail park note

**Branch:** `134-private`
**PR:** https://github.com/Performant-Labs/groups-on-d11/pull/158
**Status:** PARKED per §6.5 contract (same tests failed twice; no third cycle attempted).

## Runs

| Cycle | Kernel | Functional | E2E | Kernel URL | Functional URL |
|---|---|---|---|---|---|
| 1 | FAIL (9 real fails) | FAIL (6 real fails, all mine) | PASS ✓ | https://github.com/Performant-Labs/groups-on-d11/actions/runs/30002555710/job/89190911249 | https://github.com/Performant-Labs/groups-on-d11/actions/runs/30002555710/job/89190911253 |
| 2 | FAIL (**2** real fails) | FAIL (5 fails + 1 err — **inherited**) | PASS ✓ | https://github.com/Performant-Labs/groups-on-d11/actions/runs/30003255475/job/89193167330 | https://github.com/Performant-Labs/groups-on-d11/actions/runs/30003255475/job/89193167435 |

## Cycle-1 fixes (commit `400ac06`) — what they did

1. **Added 4th DI arg (`entity_type.manager`) to 2 pre-existing kernel test helpers.** Cleared 7 `ArgumentCountError` cascades. ✓ Cycle 2 confirms.
2. **Swapped `'forum'` → `'post'` in `PrivacyAccessTest.php:243,257,271`** — matched the roles' granted content type. Cleared 1 of 3 forum-related fails (line 243's `testNonMemberForbiddenFromViewingNodeInPrivateGroup` now passes because forbid comes from my hook regardless of grant). But 262 and 275 still fail — see below.
3. **Added `'link'` to `PrivacyDirectoryTest::$modules`** — cleared all 6 PrivacyDirectoryTest fails cycle 2. ✓ Verified: no PrivacyDirectoryTest names appear in cycle-2 failure list.

## Cycle-2 remaining failures — categorized

### Category A — Still-#134-specific (2 kernel fails; ONE root cause)

Both at `PrivacyAccessTest.php`:
- `:262` `testMemberNotForbiddenFromViewingNodeInPrivateGroup` — asserts `!isForbidden()` for a MEMBER viewing a `post` node in a private group.
- `:275` `testNonMemberNotForbiddenFromViewingNodeInPublicGroup` — asserts `!isForbidden()` for a NON-MEMBER viewing a `post` node in a PUBLIC group.

Both return `isForbidden() == TRUE` in CI.

**Theory (best available; not verified because I can't run phpunit locally):**

Drupal Node's access system relies on `node_access` grants + `hook_node_access` hooks. In this kernel test:
- `hook_node_access` (my extended one) returns **neutral** for the two failing cases (verified by reading the code: `isPrivateForNonMember` returns FALSE for member-of-private and for public-privacy).
- The base `GroupsKernelTestBase` does NOT install `node_access` schema (no `installSchema('node', ['node_access'])` call) — verified.
- Without grants AND with all hooks neutral, Drupal's final resolution is FORBIDDEN (the `neutral -> forbidden` default).

If this theory is correct, the failure is a **test-fixture gap**, not a production bug: the two tests should either
- (a) install the node_access grants schema in setUp, then call `node_access_rebuild()` after `$group->addRelationship($node, 'group_node:post')`, so the group-node grant-based path can flip a member's `view` decision to allowed, **or**
- (b) grant `'access content'` on the anonymous + authenticated global user role so Drupal's own node access falls back to allowed for the neutral cases.

The runtime path is UNAFFECTED — E2E is green cycle 1 and cycle 2, meaning on a real DDEV site (with node_access grants live), everything works. U's live walkthrough with 7 screenshots also confirms this.

**Alternative theory to check:** maybe the two failing tests are actually correctly asserting a real production concern — that in a permissions-only-not-grants world, my hook should explicitly return `AccessResult::allowed()` for members (grant the view), not neutral. But that would over-reach: `hook_node_access` is a gate, not a grant provider; changing it to `allowed()` for members would break the archive-hook's own `create`-forbid pattern for consistency reasons, and it wouldn't match `#121`'s `GroupAccessHook` (which uses neutral for permitted cases).

### Category B — Inherited from broken main (5 functional fails + 1 error)

Per `gh run list --branch main --workflow test.yml`:
- Run 29999314914 (merge of PR #156 auto-organizer) = **failure** on main.

The 5 functional failures on my branch = `JoinPolicyEnforcementTest` (#121's test file). Root cause: same `PluginNotFoundException: "link" plugin does not exist` — #121's `$modules` list omits `'link'`, and #140's `field.storage.group.field_group_links.yml` cascades a setUp failure into #121's tests too. **This is NOT a #134 regression.** It's the state of main after #140 + #144 landed. My PR inherits it.

**Waiting on hotfix PR #160** (per coordinator's note) — once #160 lands, my branch rebase-and-CI-rerun should show category B failures clear automatically.

## What the morning human should decide

1. **If PR #160 hotfixes main (adds `'link'` to `#121` test's module list or similar):** rebase `134-private` onto new main; re-run CI. Category B clears. Then only category A remains → decide on that.
2. **For category A (2 kernel node-access fails):** two options —
   - **Option 1 (recommended):** Add `installSchema('node', ['node_access'])` + `node_access_rebuild()` calls to `PrivacyAccessTest::setUp()` OR to the 2 failing test methods locally. Zero production-code change. Explicit test-fixture fix; matches how core's own group-node tests provision.
   - **Option 2 (only if 1 doesn't work):** Delete the 2 failing test methods and rely on `PrivacyDirectoryTest`'s functional tests + E2E for AC-4 (node-view-forbid) coverage. Kernel tests would then not cover the node-hide gate — a coverage reduction I'd prefer to avoid.
3. **If neither fix is quick:** merge behind a `@group ci-broken-inherited` marker on the 2 tests (skip in CI, leave code untouched). Lowest-effort path to green if #145 or another late-wave story picks up test-fixture cleanup.

## What is NOT a concern

- **Runtime code is correct.** All 3 gates (`group_access`, extended `nodeAccess`, `views_query_alter`) are validated against a live seeded DDEV site by U with 7 screenshots. Anonymous 403, directory omission, badge markup, tooltip fire, WCAG a11y attrs — all confirmed live. E2E green in both CI cycles.
- **No F production code change is required by either category.** Category A is test-fixture; category B is inherited-from-main.
- **Assembly, config integrity, and pipeline handoffs are clean.** `bash scripts/ci/assemble-config.sh` copies successfully; no config-import errors on the live site.

## Files to review

- Cycle-1 fix commit: `400ac06`
- Full decisions journal (all phases including Phase 10 CI diagnosis): `docs/planning/handoffs/134-private/decisions.md`
- Landed hooks: `docs/groups/modules/do_group_extras/src/Hook/DoGroupExtrasHooks.php`
- U evidence screenshots: `docs/handoffs/134-private/evidence/*.png` (7 files)

## Local worktree state

- Worktree at `~/Projects/_worktrees/groups-private/` still checked out on `134-private`.
- DDEV `gm134-private` (renamed from `pl-groups-on-d11` in the worktree's own `.ddev/config.yaml` — LOCAL uncommitted change) may still be running with the seeded Security Team private group. Safe to tear down via `ddev poweroff` from the worktree.
- Primary checkout at `~/Projects/groups-on-d11` untouched, per the never-share-git-state rule.

**One-line status:** PR#158 waiting on (a) PR#160 hotfix to clear inherited link-module failures on 5 #121 tests, and (b) morning human decision on the 2 remaining kernel node-access fails (test-fixture gap, not production bug).
