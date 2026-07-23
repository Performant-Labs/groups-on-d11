# Handoff-F-r2: Phase 5 (rework) - #121 SC-2 AC-2 discoverability fix

**Date:** 2026-07-22
**Branch:** 121-req2join (worktree `~/Projects/_worktrees/groups-req2join`)
**Issue:** #121
**Blocking finding addressed:** `docs/planning/handoffs/121-req2join/handoff-T-green.md` §"One real,
unresolved production gap found" / §"Blocking issues"

## What was found: where "Join group" actually renders from

The task's own hypothesis was that the module (`do_group_membership`/`do_chrome`) should own this
fix. Investigation showed the real render surface is **theme-owned**, not module-owned — this is a
course correction from the brief's initial framing, made after evidence, not before.

- **The stock `drupal/group` "Join group" mechanism exists but was never wired up in this project.**
  It runs through
  `\Drupal\group\Plugin\Group\RelationHandler\GroupMembershipOperationProvider::getGroupOperations()`
  (`web/modules/contrib/group/src/Plugin/Group/RelationHandler/GroupMembershipOperationProvider.php:26-55`),
  consumed ONLY by the `group_operations` block plugin
  (`web/modules/contrib/group/src/Plugin/Block/GroupOperationsBlock.php`). That block's own
  **optional config** placement
  (`web/modules/contrib/group/config/optional/block.block.group_operations.yml`) is scoped to
  `theme: bartik` — this project's active theme is `groups_chrome` (`config/sync/system.theme.yml`:
  `default: groups_chrome`), so Drupal's optional-config install machinery never activated that
  placement. Confirmed empirically: zero `block.block.group_operations*` config anywhere in
  `config/sync/`.
- **The REAL, already-live render surface** is
  `web/themes/custom/groups_chrome/templates/group/group--full.html.twig` (a `full`-view-mode
  override, story `#85`/CH-A3, predating #121), which renders a `gc_group.action` variable computed
  by `groups_chrome_preprocess_group()` in `web/themes/custom/groups_chrome/groups_chrome.theme`
  (also `#85`). That function already had a two-branch Join/Leave picker
  (`entity.group.join` for non-members, `entity.group.leave` for members), each gated by the
  route's own `->access($current_user)` — but **no third branch for
  `do_group_membership.request_join`** (a route that didn't exist when `#85` shipped). Once my own
  Phase-5 `RouteSubscriber` correctly narrowed `entity.group.join`'s access to `open`-visibility
  groups only, a non-member on a `moderated` group failed the `join` branch's access check
  (correctly) and had nothing to fall through to — so the header rendered nothing at all, exactly
  matching T-green's observation.

## The render pattern chosen, and why

**Extended the theme's existing `groups_chrome_preprocess_group()` action-picker with a third
`elseif` branch** — not a new module Hook, block plugin, or entity-extra-field.

I first implemented (and verified working, via a `drush eval` probe) a
`hook_entity_extra_field_info()` + `hook_ENTITY_TYPE_view()` pair on `group` in a new
`Drupal\do_group_membership\Hook\JoinAffordanceHook` class. I reverted this once template
investigation showed the theme's own pre-existing `gc_group.action` picker already occupies the
exact same header slot — shipping both would have created two competing render paths for the same
control, the precise anti-duplication failure mode the pipeline exists to catch. Extending the
theme's own picker reuses the exact slot, the exact route+access-check idiom the existing Join/Leave
branches already use, and needed zero new files/services/hooks.

`web/themes/custom/groups_chrome/` is genuinely-tracked, non-gitignored source (`git check-ignore -v`
exits 1 — NOT ignored; only `web/themes/contrib/` is), distinct from the gitignored, assembled
`web/modules/custom/`. Every chrome story (`#82`, `#84`-`#87`) commits directly to this path via
ordinary feature PRs. It is not on the task's forbidden-paths list (`web/modules/custom/*`,
`config/sync/*`), and it is the architecturally correct place once the surface is confirmed
theme-owned.

**New branch:**
```php
elseif (checks Url::fromRoute('do_group_membership.request_join', ['group' => $gid])->access($current_user))
```
renders a `#type => link`-shaped array identical in shape to the existing Join/Leave branches. No
new dependency, no new access mechanism — `ManageMembersController::requestJoinAccess()` (already
A-approved, unchanged, Phase-5) is the sole source of truth for whether the link shows, mirroring
exactly how the pre-existing branches defer entirely to `entity.group.join`/`entity.group.leave`'s
own route access. The pre-existing `try/catch (\Exception $e)` already wrapping the block absorbs a
missing-route case (`RouteNotFoundException extends \InvalidArgumentException extends \LogicException
extends \Exception`, verified by reading the class hierarchy) — no new defensive guard was added.

Rendered as a genuine `<a>` link (not a submit button), matching the shape of the existing "Join
group" branch and T's own `joinControl()` E2E locator precedent (`getByRole('link', ...)` tried
first). I confirmed this does not conflict with `requestToJoinControl()`'s button-role locator: that
locator is checked ONLY on the `/group/{id}/join-request` FORM page itself
(`tests/e2e/membership-models.spec.ts:152-154`), never against the canonical group page — my header
link's only job is getting the user there, which it does.

## Files created / edited

**Edited (source-tracked, non-build-artifact):**
- `docs/groups/modules/do_chrome/src/HelpText.php` — fixed the stale `permissions.panel.footnote`
  string per the task's optional advisory (contradicted shipped #121 behavior). A different
  HelpText key from the three `visibility.*` strings already corrected in Phase 5.
- `web/themes/custom/groups_chrome/groups_chrome.theme` — the actual fix: third `elseif` branch in
  `groups_chrome_preprocess_group()`'s action picker, plus expanded docblocks on the file header and
  the function explaining the #121 rework inline for future readers.

**Created then reverted (not part of the final diff):**
- `docs/groups/modules/do_group_membership/src/Hook/JoinAffordanceHook.php` — implemented, verified
  working, then deleted once the theme-extension approach was found to be the correct,
  non-duplicating fix. `do_group_membership.services.yml` and `.module` were reverted to their exact
  Phase-5 GREEN state (only `GroupAccessHook` + `RouteSubscriber` registered).

No test file touched. `git status --short docs/groups/ web/themes/` shows exactly the two edited
files above.

## Verification

**Functional — `JoinPolicyEnforcementTest` (mandated, must be 9/9):**
```
SIMPLETEST_DB='mysql://db:db@db:3306/db' SIMPLETEST_BASE_URL='http://127.0.0.1:8888' \
SYMFONY_DEPRECATIONS_HELPER=disabled BROWSERTEST_OUTPUT_DIRECTORY=/tmp/browsertest-output \
php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox \
web/modules/custom/do_group_membership/tests/src/Functional/JoinPolicyEnforcementTest.php

 ⚠ Non member sees join button on open group
 ⚠ Non member sees request to join on moderated group
 ⚠ Non member sees no join path on invite only group
 ⚠ Direct post to request join on invite only is 403
 ⚠ Organizer sees pending row in existing manage members
 ⚠ Anonymous get on manage members is 403
 ⚠ Plain member get on manage members is 403
 ⚠ Anonymous post to approve is 403
 ⚠ Plain member post to approve is 403
Tests: 9, Assertions: 54, Deprecations: 15, PHPUnit Deprecations: 10.
```
**9/9 GREEN** (⚠ = pre-existing deprecation noise, zero Errors/Failures, exit code 0 — matches
T-green's exact prior count). `testNonMemberSeesNoJoinPathOnInviteOnlyGroup` in particular confirms
AC-3 still holds: no join/request markup renders on an invite_only group's canonical page.

**Kernel — full CI-shaped command across all 11 custom modules (mandated, must be 107+/107+):**
```
SIMPLETEST_DB='mysql://db:db@db:3306/db' SYMFONY_DEPRECATIONS_HELPER=disabled \
php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox \
$(find web/modules/custom -type d -path '*/tests/src/Kernel')

OK, but there were issues!
Tests: 107, Assertions: 2947, Deprecations: 28, PHPUnit Deprecations: 93.
```
**107/107 GREEN**, exact match to F's Phase-5 and T's Phase-6 counts. (My theme change touches no
PHP module code the Kernel suite exercises, so this is a pure regression-absence confirmation.)

**Wider regression check — `do_group_membership` Functional (all 5 files) + `do_chrome` Unit +
Functional, combined:**
```
Tests: 35, Assertions: 329, Deprecations: 16, PHPUnit Deprecations: 43.
```
**35/35 GREEN**, zero `✘` markers. Confirms the `HelpText.php` footnote edit did not break
`HelpTextTest` (10/10 still GREEN) and every pre-existing Functional test in both modules is
unaffected.

**Live smoke — three independent verifications, all agreeing:**

1. **In-process `drush php:script` probe** (`account_switcher`-based, bypasses HTTP/BigPipe/`php -S`
   entirely) directly invoking `groups_chrome_preprocess_group()`:
   - `ravi_patel` (zero relationship) on Leadership Council (gid=5, moderated):
     `action: {"label":"Request to join","url":"/group/5/join-request","variant":"primary"}`
   - `sophie_mueller` (seeded PENDING) on the same group: `action: {"label":"Leave
     group",...}` — pending correctly treated as "already related" (A-R2-N1 symmetry).
   - `admin` (active member) on the same group: same "Leave group" result.
   - `ravi_patel` on Drupal France (gid=2, open): `action: {"label":"Join group",
     "url":"/group/2/join",...}` — unchanged #95 behavior.
   - `ravi_patel` and `sophie_mueller` on Core Committers (gid=3, invite_only): `action: NULL` for
     both — AC-3 preserved.

2. **Live HTTP request**, identity double-verified via `/user` → `/user/{uid}` redirect BEFORE
   trusting any result (an earlier attempt was corrupted by a `drush uli <username>` positional-arg
   bug in this drush version — it silently logs in as UID 1, not the named user; `drush uli
   --name=<username>` is required — flagging this as a CLI-usage pitfall for future sessions, not a
   Drupal/application defect). With a genuinely-verified `ravi_patel` session:
   ```
   GET /group/5 (Leadership Council, moderated) → 200
   <div class="gc-group-header__action">
     <a href="/group/5/join-request" class="gc-button gc-button--primary" data-gc-group-action>
       Request to join
     </a>
   </div>
   ```

3. **Same verified session, the other two visibility branches:**
   ```
   GET /group/2 (Drupal France, open) → 200
   <a href="/group/2/join" class="gc-button gc-button--primary">Join group</a>

   GET /group/3 (Core Committers, invite_only) → 200
   [zero "gc-group-header__action" elements; zero "Join group"/"Request to join" text anywhere]
   ```

**phpcs — zero new debt (before/after baseline diff on both touched files):**
- `HelpText.php`: baseline (pre-rework, `HEAD`) 19 errors + 6 warnings → mine 18 errors + 6
  warnings — one FEWER error, zero new debt.
- `groups_chrome.theme` (run with the required `--extensions=theme` flag, since phpcs does not scan
  `.theme` by default): baseline 4 errors + 7 warnings → mine 4 errors + 6 warnings — one fewer
  warning, same error count (all 4 pre-existing errors are in the OTHER two functions in this file
  I didn't touch), zero new debt.

## Confirmation: AC-3 (invite_only shows no join path) still passes

Confirmed at every layer:
- Functional: `testNonMemberSeesNoJoinPathOnInviteOnlyGroup` GREEN (asserts `elementNotExists` on
  both `input[type=submit][value*=Join]` and `input[type=submit][value*=Request]`, plus
  `pageTextNotContains('Request to join')`).
- Kernel: `RequestJoinFlowTest::testRequestJoinOnInviteOnlyGroupIsForbidden` GREEN (unaffected by
  this rework — no Kernel/manager/access code was touched).
- Live: `drush eval` probe and live HTTP request both show `action: NULL` / zero action markup for
  Core Committers (invite_only), for both `ravi_patel` and `sophie_mueller`.

My new `elseif` branch only ever fires when the preceding `entity.group.join` branch's access check
already failed AND the new branch's own `requestJoinAccess()` check (gated to `moderated` only)
passes — an invite_only group fails both, so nothing renders, exactly as before this fix.

## Advisory notes for T (not acted on by me — outside my mandate)

- **T-green's E2E workaround can likely be removed now.** `membership-models.spec.ts` line 152
  (`page.goto(`/group/${groupId}/join-request`)`, explicitly flagged in that file's own comment as a
  stand-in) can probably be replaced with clicking the new header link + `page.waitForURL(...)`,
  mirroring the open-group test's own two-hop pattern, for full parity and so a future accidental
  removal of the header link would be caught by the test again.
- **The `/all-groups` directory-card affordance** (`groups_chrome_preprocess_views_view_fields__all_groups()`,
  a separate function in the same theme file) still only branches on `is_open` and has no "Request
  to join" state — a genuine, pre-existing, SEPARATE gap from the canonical-page one this rework
  targeted. Flagged inline in the theme file's docblock as a follow-up; not folded into this fix to
  keep the diff surgical, per the task's own "narrow scope, targeted fix" framing.

## Files changed (final)

- `docs/groups/modules/do_chrome/src/HelpText.php`
- `web/themes/custom/groups_chrome/groups_chrome.theme`

`decisions.md` appended: `## Phase 5 (rework) — Feature Implementor: AC-2 discoverability fix
(2026-07-22)`.
