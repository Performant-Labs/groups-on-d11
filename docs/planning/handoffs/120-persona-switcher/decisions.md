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
per option as the closest achievable reading of "each option carries a tooltip". Flagged as Open
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

## T — Phase 4 (RED)

**Decided:** Added one Unit test beyond the harness's original file list —
`GroupsModerateRoleConfigShapeTest.php` — reading the REAL on-disk
`group.role.community_group-groups_moderate.yml`, matching this repo's own
`do_group_membership/tests/src/Unit/GroupRoleConfigShapeTest.php` precedent. This is because
`PersonaAccessPositiveTest`/`PersonaAccessNegativeTest` (Functional, as originally scoped)
reconstruct group roles via the storage API with the AMENDED target shape, which made them pass
today (the underlying `do_group_membership` access-check code already exists and is correct) —
an invalid RED if left as the only Amendment-1 coverage. The Unit test reading the real shipped
YAML is the genuine RED anchor for Amendment 1's config edit.

**Decided:** Kept 2/19 Kernel assertions, 1/3 Unit assertions, and 8/17 Functional assertions as
explicit pre-existing-true regression guards (not RED) rather than deleting them — each is
individually documented in its test file's doc comment as to why it is expected to pass before F's
code exists, distinguishing "correctly asserting a pre-existing invariant" from "silently green
for the wrong reason."

**Assumed:** BrowserTestBase's install-time uid-1 account has SOME resolvable
`getAccountName()` value usable as a route parameter in `PersonaUidOneGuardTest`; flagged to F as
a design recommendation (compare by uid, not uname string, in `PersonaAccessCheck`) since a blank
uid-1 uname would be fragile either way.

**Evidence:** Full RED run logs in `handoff-T-red.md` — 17/19 Kernel, 2/3 Unit, 9/17 Functional
tests fail for the right reason (missing class/service/method/field/route/markup); E2E spec
`--list`s cleanly (4 tests); existing `do_showcase` Unit suite (29 pre-existing tests) stays green
with zero collateral breakage.

## F — Phase 5 (implement)

**Decided:** Compared uid-1 by resolved account **id** (`(int) $target_user->id() === 1`), not
uname string, in `PersonaAccessCheck` — per T's own recommendation in handoff-T-red.md. Belt-and-
suspenders: even if a future allowlist edit accidentally pointed a persona's `uname` at uid-1's own
account name, this still denies it (T's `testUidOneTargetIsAlwaysDenied` already passes because
`root_admin` isn't an allowlisted persona id at all — the uid-comparison is defense-in-depth on top
of the allowlist, not the only guard).

**Decided:** The "Browse as" widget renders via a THIRD sibling `#[Hook('page_top')]` method
(`personaSwitcherWidget()`), not solely via `PersonaSwitcherBlock` placement. Discovered via RED-
loop: `PersonaSwitcherDropdownTest` (BrowserTestBase, `$modules=['do_showcase']`, no config import)
requires the widget to render on `<front>` from module-enable alone — no module in this repo self-
installs `block.block.*` placement config via `config/install/`, so a Block-plugin-only path would
never satisfy that test (or a fresh production install without manual Block Layout placement).
`PersonaSwitcherBlock` still exists as an optional, explicitly-placeable alternative; both share
the ONE `do_showcase.persona_switcher` service render-array producer.

**Decided:** `DoShowcaseHooks` needed a constructor (`PersonaSwitcher $personaSwitcher`) to delegate
cleanly — required adding a class-name alias (`Drupal\do_showcase\Persona\PersonaSwitcher:
'@do_showcase.persona_switcher'`) in services.yml, because Drupal's `#[Hook]` attribute discovery
(`HookCollectorPass`) auto-registers ANY Hook-namespaced class as its own autowired service,
resolving constructor args by CLASS NAME — which fails for a custom class with no such alias
(matches core's own pattern, e.g. `EntityTypeManagerInterface: '@entity_type.manager'`).

**Decided:** `PersonaSwitcher::build()`'s assembled `<form>`/`<select>`/`<button>` HTML string is
wrapped in `Markup::create()` — `#markup` is XSS-filtered against `Xss::getAdminTagList()` by
default, which does not include `form`/`select`/`option`/`button`, silently stripping them (same
gotcha `RestoreGroupForm::preRenderAsButtonTag()` already documents for its own real `<button>`).
Every dynamic value is `htmlspecialchars()`-escaped before assembly, so the whole string is safe.

**Decided:** The persona banner's `<aside role="status">` wrapper is ALSO hand-assembled +
`Markup::create()`'d (children pre-rendered via `renderInIsolation()`, wrapped with an
`Attribute`-escaped `<aside>...</aside>`) — `'#type' => 'container'` always themes to a hardcoded
`<div>` (`container.html.twig`), which cannot satisfy `PersonaBannerTest`'s
`aside[role="status"].do-showcase-persona-banner` selector no matter what `#attributes` are set.

**Decided:** Added `do_chrome:do_chrome` to `do_showcase.info.yml` dependencies. Pre-existing gap
from #119 (`VariantSwitcher.php` already used `Drupal\do_chrome\HelpText` with no formal
dependency declared) — never surfaced before because no prior test exercised `do_showcase`-only-
enabled code that calls into `HelpText` inside an isolated BrowserTestBase install. My
`PersonaSwitcher::build()` was the first to do so on `<front>`, surfacing
`Class "Drupal\do_chrome\HelpText" not found` as a real 500 until fixed.

**Decided:** Dropped the redundant third "archive perm" bullet from Amendment 1's group-role edit
(per A's own non-blocking note, confirmed by reading `RestoreGroupAccess::access`: only `edit
group` or `administer group` gate restore — no separate archive-only permission exists in
`do_group_extras`). `group.role.community_group-groups_moderate.yml` ships exactly
`['administer members', 'edit group']`.

**Decided:** Left `composer.json`/`composer.lock`/`core.extension.yml` untouched per the brief's own
"simplest: leave alone" branch — verified `core.extension.yml` is a generated file (not tracked
under `docs/groups/config/`) and its assembled copy already carries no `masquerade` line, so both
of Amendment 3's file-touch items resolve to a true no-op.

**Found (route back to T):** `PersonaSpecTest::testEveryPersonaHasExpectedUname` has a real
test-authorship bug: `self::EXPECTED_UNAME[$persona['id']] ?? '__unexpected__'` — PHP's `??`
treats an EXISTING array key whose value is `NULL` (the `anonymous` persona's correct, spec'd
`uname`) as if the key were absent, always falling through to `'__unexpected__'`. My
`ShowcaseCatalog::personas()` correctly ships `'uname' => NULL` for `anonymous` per Amendment 2 —
confirmed via a direct source-string check. Fix (for T): index directly
(`self::EXPECTED_UNAME[$persona['id']]`) or use `array_key_exists()`, not `??`, since every real
persona id is a valid key in that map. Not edited (F does not edit tests).

**Found (route back to T):** Three Functional tests assert a 302/303 status via a code path that
cannot observe it, given Mink's `BrowserKitDriver` sets `followRedirects(true)` on its client by
default (verified in `vendor/behat/mink-browserkit-driver/src/BrowserKitDriver.php:64`):
`PersonaSwitchControllerMethodTest::testPostOnNonAnonymousPersonaRedirects`,
`::testGetOnAnonymousRedirects`, and `PersonaUidOneGuardTest::testPostToAllowlistedPersonaSucceeds`
(via its shared `postAndGetStatus()` helper) all call
`$client->request(...); $client->getInternalResponse()->getStatusCode()` WITHOUT first calling
`$client->followRedirects(false)` — so the driver silently follows the 302 to its destination
before `getInternalResponse()` is read, and the test observes the FINAL page's 200, not the
redirect's own 302. Verified my controller's actual behavior is correct: a scratch diagnostic
(not committed) confirmed raw `status=302` for both cases when `followRedirects(false)` is set
first. Fix (for T): add `$client->followRedirects(false);` before each such request. Not edited.

**Found (non-blocking, out of my edit scope):** `.github/workflows/test.yml`'s E2E job explicitly
enables only `do_tests do_group_extras do_group_language do_group_mission do_group_pin
do_multigroup do_notifications do_profile_stats do_discovery` via `drush en`, and calls only
`step_700_demo_data.php` / `step_720_group_types.php` / `step_780_nav_menu.php` by name —
`do_showcase`/`do_chrome` are NOT in that explicit `drush en` list (though they DO get enabled via
the earlier `config:import` step, since `assemble-config.sh` patches every `do_*` module into
`core.extension.yml`), and `step_790_persona_switcher.php` is not yet wired into that job at all.
The seed script itself is confirmed correct (see Tier-1 self-check below) — this is purely a CI
workflow-file wiring gap outside `docs/groups/`, flagged for O/T to add a `step_790` invocation
alongside the existing 3.

**Evidence:** Full verification logs below (Tier 1 self-check section) — 122/123 Kernel (full
custom-module suite), 31/31 Unit, 18/19 Kernel (do_showcase-scoped), 14/17 Functional
(do_showcase), all lint-clean except pre-existing debt in `HelpText.php` (append-only, zero lines
of my diff touch the flagged region) and `DoShowcaseHooks.php`'s existing raw-`t()`/`\Drupal::`
style (consistent with the untouched `pageTop()` method).

## T — Phase 6 (GREEN + Tier 2)

**Decided:** Fixed all 3 F-flagged test-authorship bugs (`PersonaSpecTest`'s `??`-vs-NULL bug;
`followRedirects(false)` missing in 3 Functional tests) exactly as F's own suggested fixes
specified. Also found and fixed a 4th, unflagged test-authorship bug: `do_group_membership`'s
pre-existing (#138) `GroupRoleConfigShapeTest::testGroupsModerateRoleConfigShape` asserted the
OLD `admin: true` shape that #120's own approved Amendment 1 deliberately supersedes — updated
its assertion to the new enumerated-permission shape (surgical 3-point edit, not a rewrite),
since this story's approved config change would otherwise regress that suite.

**Decided:** Fixed 2 test-authorship bugs in `tests/e2e/persona-switcher.spec.ts` (my own E2E
spec): `selectOption({label: /regex/})` is invalid Playwright API (label must be an exact string)
in 3 spots; a `form button[type="submit"]` locator collided with 2 unrelated search-form buttons
on the seeded page, fixed by scoping to `form.do-showcase-persona-switcher-form`.

**Found (escalated to O, NOT fixed by T):** A genuine production defect —
`DoShowcaseHooks::personaBanner()` renders the Groups-Moderate persona's banner as "You're
browsing as Moderator — switch back" instead of the wireframe-locked "You're browsing as
Groups-Moderate — switch back", because it trusts `ShowcaseCatalog::personas()`'s
`moderator.name` field (`'Moderator'`) directly, while `PersonaSwitcher::optionLabel()`
correctly hardcodes `'Groups-Moderate'` for the same persona id instead of trusting that same
field. This is E2E test 1's sole failure; 3/4 E2E tests pass. Full PHPUnit regression (Kernel
123/123, Unit 62/62, Functional 46/46) is 100% green — none of those suites assert this exact
string today (a coverage gap also flagged in the advisory notes).

**Evidence:** Full run logs in `handoff-T-green.md` — every PHPUnit tier at pre-story-plus-fixes
counts with zero collateral failures; E2E run against a genuinely fresh seeded install (drush
site:install -> config:set uuid -> cim -> 4 seed scripts incl. step_790 -> runserver ->
playwright), confirmed via `drush user:information groups_moderate_demo` that the seed applied;
`curl` against the live seeded page confirmed the dropdown option renders "Groups-Moderate"
correctly while the banner (once switched) renders "Moderator" — isolating the defect to
`personaBanner()`'s name-resolution path, not the dropdown's.

## F — Phase 5-fix (2026-07-23)

**Decided:** Chose Option B — added a fifth field, `label`, to each `ShowcaseCatalog::personas()`
entry (`'Anonymous'` / `'Elena Garcia — Member'` / `'Maria Chen — Organizer'` / `'Groups-Moderate'`)
rather than Option A (a new `personaLabel(string $id): string` method on `ShowcaseCatalog`).
Rejected Option A because `ShowcaseCatalogTest::testPersonaSwitcherEntryNamesAllFourPersonas()`
(a currently-GREEN, pre-existing Unit test) already pins `$p['name']` to the plain persona
name/title (`'Moderator'`, not `'Groups-Moderate'`) — and `ShowcaseController::build()`'s
`/showcase` tour listing (`@name — @description`) is a FOURTH consumer that genuinely needs the
plain `name`, not the display label, so `name` cannot be repurposed. A data-driven `label` field
is the minimal, additive fix matching this method's own existing per-field (`name`/`description`)
pattern — both `PersonaSwitcher::optionLabel()` (now a one-line `return $persona['label']`) and
`DoShowcaseHooks::personaBanner()` (now reads `$active_persona['label']` directly, in place of
the old `$active_persona['name']` + independent `$role_suffix` match) read from this ONE field.
Verified additive-safe against every existing consumer (`PersonaAccessCheck`,
`PersonaSwitchController` — neither reads `name` or `label` at all) before editing.

**Decided:** Also fixed a `phpcs` "avoid backslash escaping in translatable strings" warning I
introduced in `personaBanner()`'s new `$lead_text` construction — switched
`t('You\'re browsing as @label — ', ...)` (single-quoted, escaped apostrophe) to
`t("You're browsing as @label — ", ...)` (double-quoted, no escape), matching both `phpcs`'s own
recommendation and the pre-fix code's own quoting style.

**Found:** Confirmed via `git diff` against the pre-fix file (linted a scratch copy of the original
`DoShowcaseHooks.php` inside DDEV) that the class's remaining 9 `phpcs` warnings (raw `t()`/
`\Drupal::` calls, no DI/`StringTranslationTrait`) are 1-for-1 pre-existing — present in the
original file before this fix, in the SAME established style as the untouched `pageTop()` method.
Not new debt introduced by this fix.

**Evidence:** `ShowcaseCatalogTest.php:146-153` (`testPersonaSwitcherEntryNamesAllFourPersonas`,
asserts `$p['name']` is `'Moderator'`, not `'Groups-Moderate'` — still GREEN after the fix, proving
`label` is additive and `name` is genuinely untouched); `ShowcaseController.php:124-137` (4th
`name` consumer, the `/showcase` tour listing, unaffected by this fix); `tests/e2e/persona-
switcher.spec.ts:52,65,89,99` (the exact pinned banner/option strings this fix targets — read-only,
not edited); full re-verification below (Tier 1 self-check) — 50/50 (19 Kernel + 31 Unit), 17/17
Functional, 123/123 full custom-module Kernel regression, all unchanged from the pre-fix baseline;
E2E `--list` still parses 4/4 tests cleanly.

## T — Phase 6-followup

**Decided:** Re-ran full story-scoped PHPUnit (50/50 Kernel+Unit, 17/17 Functional — both
unchanged, deprecations-only) plus the actual E2E execution (`--reporter=list`, not just
`--list`) against F's `b02c3f6` label-unification fix; required `BASE_URL='http://gm120-groups-on-d11.ddev.site'`
override since `playwright.config.ts`'s default baseURL points at a different DDEV project.

**Found:** 4/4 E2E GREEN, including the previously-failing Groups-Moderate exact-copy assertion —
confirms the production defect is resolved and no collateral regression in the other 3 tests.

**Evidence:** `handoff-T-green.md` § "Phase 6-followup: post F-fix re-verify (2026-07-23)" —
assemble output, full PHPUnit testdox output, and the 4-row E2E pass table with per-test
browser/OS/duration.

## F — Phase 6.5 (2026-07-23)

**Decided:** Fixed all 3 of o4-mini's diff-review BLOCK findings, none else. B-1
(`PersonaSwitcher.php`): replaced both hardcoded `/persona-switch/` literals with
`Url::fromRoute('do_showcase.persona_switch', [...])`-derived values — the initial `<form action>`
directly, and the JS-usable base-path prefix via a sentinel-persona-id-then-`str_replace()`
technique (`__PERSONA_ID_SENTINEL__`, chosen because it cannot collide with any real allowlisted
persona id and survives URL generation/`rawurlencode()` unaltered). B-2
(`PersonaSwitchController.php`): `redirectBack()` now only follows the `Referer` header when a new
`isSameOriginReferer()` helper confirms its parsed scheme+host+port matches the current request's
own (component-by-component, not a string-prefix check, so a substring-matching attacker host like
`example.com.attacker.test` is correctly rejected) — an off-site Referer falls back to `<front>`.
B-3 (`DoShowcaseHooks.php` + `do_showcase.services.yml`): constructor-injected `ShowcaseCatalog`
into `DoShowcaseHooks` (matching how `PersonaSwitcher` is already injected, including the same
class-name-alias requirement for `#[Hook]` autowiring), and `personaBanner()` now reads
`$this->catalog->personas()` instead of `new ShowcaseCatalog()`-ing its own instance.
`personaSwitcherWidget()` was inspected per the task's instruction and already had no `new`
anywhere — no change needed there.

**Decided:** Left all 3 WARN findings (W-1 inline-onchange/CSP, W-2 deprecated `user_logout()`,
W-3 `\Drupal::service`/`\Drupal::currentUser()` statics in `personaBanner()`) untouched, per the
task's explicit "do NOT fix in this pass" instruction — W-3 in particular matches the pre-existing,
untouched `pageTop()` method's own established convention in the same class (confirmed via a
before/after lint diff: identical 9 warnings before and after this pass).

**Found:** None (no test-authorship bugs surfaced by this pass — all pre-existing tests continued
to pass unmodified once the 3 BLOCK fixes were applied; none of the story's tests assert the exact
`<form action>` string or the exact redirect `Location`, only status codes and the (already
`Url::fromRoute`-generated, unchanged-by-this-pass) banner switch-back `href`, so none needed
updating for the new URL-generation/referer-validation mechanism).

**Evidence:** Re-ran the full story-scoped PHPUnit suite (50/50 Kernel+Unit, 17/17 Functional) and
the full custom-module Kernel regression (123/123) — all three unchanged from the pre-Phase-6.5
baseline, zero failures/errors (confirmed via grep for "Failed asserting"/"FAILURES!"/"ERRORS!" —
0 occurrences in any run). `phpcs` on all 3 touched files: 0 errors; `DoShowcaseHooks.php`'s 9
pre-existing warnings confirmed unchanged via a before/after lint comparison (linted a temporary
renamed copy of the pre-Phase-6.5 file, then deleted it). A scratch `drush php:script` (deleted
after use) confirmed the sentinel-strip URL-generation technique produces byte-identical real
URLs to the old hardcoded literal on this site's config; `curl` against the live DDEV front page
confirmed the rendered `<form action>`/`onchange` markup is shape-identical to the pre-fix version.

## U - Phase 8 (UI walkthrough)

**Decided:** Ran the full 30-item checklist against the live DDEV site (gm120-groups-on-d11) via
raw Playwright, plus a re-run of T's own committed tests/e2e/persona-switcher.spec.ts with the
correct BASE_URL override. All 30 items PASS, including the previously-buggy Groups-Moderate
banner-copy case (confirmed fixed by F's Phase-5/6.5 label-unification work) and the uid-1/
HTTP-method access-check matrix (403/403/405/302 exactly per Amendment 4).

**Found (environment, not code):** the long-running gm120-groups-on-d11 container had a stale
compiled service container predating F's Phase-6.5 diff-gate fix, causing an ArgumentCountError
500 on every page at the start of this walkthrough. Resolved with a drush cache-rebuild - no
source file touched. Flagging for O/S in case another agent reuses this same container.

**Evidence:** handoff-U.md - full checklist table, curl status codes for the access-check matrix,
computed focus-outline styles (2px solid #4da3ff on select/Go-button/switch-back-link), zero
console errors and zero 500s across a full multi-persona round trip, 4/4 PASS on T's own E2E
spec re-run against the correct base URL.

**Verdict: PASS. Ready for S.**
