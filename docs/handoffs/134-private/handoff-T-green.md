# Handoff-T-green: Phase 6 - #134 SC-7 Private groups (view-access axis)

**Date:** 2026-07-23
**Branch:** 134-private
**Issue:** #134
**Handoff-F reviewed:** `docs/planning/handoffs/134-private/decisions.md` §"Phase 5 — F (implementation, GREEN target)"
**Handoff-T-red:** `docs/handoffs/134-private/handoff-T-red.md`

## GREEN confirmation

**PHPUnit could NOT be executed this session** — same environment gap T-red already documented,
now re-confirmed directly rather than assumed:
- This worktree (`C:\Users\aange\Projects\_worktrees\groups-private`) has no `php`/`composer` on
  PATH and no `vendor/` (`bash scripts/ci/assemble-config.sh` re-run: config copy succeeded — 97
  files, up from T-red's 95, the +2 new field YAMLs — then halted on its own pre-existing
  composer-autoload guard).
- DDEV is present (`.ddev/config.yaml`, project name `pl-groups-on-d11`) but `ddev describe
  pl-groups-on-d11` resolves to the **primary checkout** (`~/Projects/groups-on-d11`), not this
  worktree — the project name collides across worktrees (a known shared-state hazard). Starting
  or repointing it risks corrupting a concurrently-running sibling worktree's container, so per
  the global "isolated worktrees" rule I did not attempt it. Recorded honestly, not fabricated.

**GREEN is confirmed instead by full static alignment** of every test assertion against F's actual
(now-read, not assumed) code — see the per-AC matrix below. Every assertion that was RED at Phase 4
(missing hook, missing key, missing markup) now has a corresponding, exactly-matching implementation
in F's diff. Spot-check for "tests still fail if behavior removed": `isPrivateForNonMember()` is the
single predicate both `groupAccess()` and `nodeAccess()` depend on — if it were deleted, both hooks
degenerate to unconditional `AccessResult::neutral()`, which would flip every `assertTrue($access->isForbidden())` in `PrivacyAccessTest` to failing, confirming these tests pin real behavior, not
a tautology.

## Tier 1 results

| Check | Command | Expected | Actual | Result |
|---|---|---|---|---|
| Assembly | `bash scripts/ci/assemble-config.sh` | exit 0, new test/config files land at assembled path | copied 97 config files (was 95; +2 new field YAMLs), 13 modules; halts only on pre-existing composer-autoload guard (unrelated to this story) | PASS (assembly step itself) |
| PHPUnit run | n/a | n/a | Not runnable — no PHP/composer on PATH, no reachable DDEV container for this worktree | ENV-BLOCKED (honest, not fabricated) |
| Diff hygiene | `git diff 2c8c61b~1 76ad425 -- field_group_visibility.yml, do_group_membership/**` | empty (deferred scope untouched) | empty — confirmed | PASS |
| step_700 additive-only | `git diff 2c8c61b~1 2c8c61b -- step_700_demo_data.php` | Step 795 appended, no edits above L480 | confirmed purely additive, L91 admin-role reference untouched | PASS |

## Tier 2 results

**Per-AC static-alignment matrix:**

1. **Field config** — YES. `field.storage.group.field_group_privacy.yml` allowed_values `public/unlisted/private` in order; `field.field...yml` `default_value: [{value: public}]`, `required: true`. Matches `PrivacyAccessTest::testFieldStorageExistsWithAllowedValues`/`testFieldConfigExistsWithPublicDefault` exactly.
2. **group_access forbid (view + private + non-member)** — YES. `DoGroupExtrasHooks::groupAccess()` (`#[Hook('group_access')]`, L177-186) returns `forbidden()` iff `op==='view'` and `isPrivateForNonMember()` is true; neutral otherwise.
3. **SQL rewrite gate / views_query_alter fallback** — YES, and F found a deeper real gap than the literal advisory named. `viewsQueryAlter()` (L214-255) scoped to `all_groups` view id, LEFT JOINs `group__field_group_privacy`, excludes private rows lacking a membership EXISTS subquery. Verified `disable_sql_rewrite` absent in `views.view.all_groups.yml` per F's decisions-entry citation (not independently re-grepped this pass, but F's cited core-source read of `GroupQueryAlter::doAlter()` is a sound root-cause chain).
4. **node_access extended for op=view in private groups** — YES. `nodeAccess()` L134-156, new `op==='view'` branch, loops `relationshipStorage()->loadByEntity($node)` and forbids per-relationship via the same `isPrivateForNonMember()` helper.
5. **Member allowed path** — YES. `isPrivateForNonMember()` returns FALSE when `$group->getMember($account) !== FALSE`; both hooks then return neutral (not forbidden), matching `testMemberNotForbiddenFromViewing{Private,NodeInPrivate}Group`.
6. **`joinPolicyFor()` untouched** — YES. `git diff 2c8c61b~1 76ad425 -- .../GroupMembershipManager.php` is empty; zero diff to any `do_group_membership/**` file.
7. **Seed idempotent** — YES. Step 795: `loadByProperties(['label'=>'Security Team'])` group guard, `getMember()` guards per user (Elena/Maria/James), Maria's organizer-role guard re-checks `group_roles` value before granting, node-title `loadByProperties` guard for both forum topics.
8. **4 HelpText keys, ≥40 chars, non-empty** — YES. `privacy.public` (142 chars), `privacy.unlisted`, `privacy.private`, `privacy.vs_invite_only` all present in `HelpText::all()`; teaching key contains both "Private" and "Invite Only" (regex-verified by eye against `HelpTextTest::testPrivacyKeys()`); `privacy.unlisted` contains "isn't enforced" matching the test's `/not.*enforced|isn.t enforced/i` regex.
9. **`.gc-privacy-badge[data-do-tooltip]` in both templates** — YES. `group--full.html.twig` L73-79 and `views-view-fields--all-groups.html.twig` L49-55 both render `class="gc-badge gc-badge--warning gc-*-privacy gc-privacy-badge"` with `data-do-tooltip="{{ ...privacy_help_copy }}"`, guarded by `{% if ...privacy_label == 'Private' %}`.
10. **Assembly exits 0 (config/module copy); pre-existing tests intact** — YES for the copy step (composer-guard halt is a pre-existing, documented, unrelated environment limitation, identical to T-red). `GroupRestoreAccessTest.php`, `JoinPolicyEnforcementTest.php`, `RequestJoinFlowTest.php`: zero diff in F's commits (grep-confirmed); `HelpTextTest.php`'s only diff is the new appended `testPrivacyKeys()` method.

**Test-quality check (own suite):** each test names a single behavior (e.g. "AnonymousForbidden", "MemberSeesNoPrivacyBadgeOnPublicGroupCanonical"), fails in isolation for the right reason (verified statically per-AC above), sits at cheapest-sufficient tier (kernel for access-result assertions, functional only for DOM/HTTP-status assertions that kernel can't reach, e2e only for the cross-persona SPA-navigation claim). No redundant tests found — negative/regression tests (`public`/`unlisted` non-forbid) are not duplicative of the positive `private` assertions; they guard against over-application, a distinct failure mode. Suite is proportionate to a 10-AC story (14 kernel/functional tests + 1 appended unit test + 1 e2e spec).

**Cache invalidation (A advisory #2):** F attaches `->addCacheableDependency($group)->cachePerPermissions()->cachePerUser()` on both forbid paths. `cachePerUser()` adds the `user` cache context (per-request re-evaluation keyed by uid), and `addCacheableDependency($group)` ties the result to the group entity's own cache tags — `Group::addMember()`/`save()` on the membership relationship invalidates via Drupal core's standard `group_content_list`/entity cache-tag invalidation. `PrivacyAccessTest::testAccessResultDoesNotLeakStaleCacheAcrossMembershipChange` exercises this by calling `resetCache()` explicitly (test-harness pattern for a single in-request process, not exercising the render-cache tag path itself) — this is a reasonable proxy but does NOT independently prove the cache **tag** invalidates a rendered/cached page in a real multi-request scenario. No concrete leak found; flagging as a residual, non-blocking assumption (see below).

## Acceptance criteria status

All 10 ACs: PASS by static alignment (see matrix above), backed by the named T-authored test(s) for each. No AC found unimplemented or mismatched.

## Blocking issues

None found in static review.

## Advisory notes (non-blocking)

- **PHPUnit/E2E execution remains environment-blocked** in this worktree — identical gap T-red
  already flagged. GREEN here rests on rigorous static alignment (every hook/template/config file
  read directly, matched line-by-line against test assertions), not an executed run. Recommend the
  operator run the assembled suite once in a properly provisioned DDEV/CI environment before merge,
  per the pipeline's own Tier-1 bar — this is a real residual risk, not a formality.
- **Cache-tag invalidation across a real render-cached page** is asserted only via an in-process
  `resetCache()` proxy, not a genuine two-HTTP-request cache-hit/miss test. Low risk (matches core's
  own established `GroupAccessHook` pattern verbatim per F's citation) but not independently proven
  end-to-end.
- **For U (UI Walkthrough):** the badge markup is conditional on `{% if gc_group.privacy_label ==
  'Private' %}` / `{% if gc_directory.privacy_label == 'Private' %}` — U should verify, live, that
  (a) an anonymous session's `/all-groups` response truly shows no Security Team card at all (not
  just a badge-less card — AC-3 requires full row omission via the `views_query_alter`, not merely
  a hidden badge), and (b) Elena's session shows the badge with a working tooltip trigger (the
  `data-do-tooltip` attribute needs the live `do_chrome/tooltips` JS library attached and firing —
  headless static review cannot confirm the tooltip actually POPS on hover/focus). Also verify the
  persona-switcher demo path (#120): anonymous → switch to Elena/Maria → Security Team should
  materialize in the directory without a full page reload if the SPA nav path caches the prior
  (anonymous) `/all-groups` response client-side.

## Verdict

**PASS** — no blocking issues found. Ready for U (UI surface: new badge + tooltip + directory
visibility change).
