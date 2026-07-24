# TEST_PLAN — Groups-on-D11 behavioral test suite

> **Scope:** the coverage matrix and test conventions for the groups-on-d11 test-suite
> epic ([#31](https://github.com/Performant-Labs/groups-on-d11/issues/31)). This file is
> the A1 deliverable ([#32](https://github.com/Performant-Labs/groups-on-d11/issues/32));
> every Wave B–D sub-issue (#33–#44) maps to specific cells below, and no risk area is
> left unassigned.
>
> Target platform: **Drupal 11.4.4** + **`drupal/group` `4.0.x` (dev-4.0.x)**, the nine
> `do_*` custom modules assembled per [`RUNBOOK.md`](RUNBOOK.md).

---

## 1. Purpose & current state

### Why this plan exists

The migration from `drupal/group` 3.x → 4.x moved per-group permission *calculation* off
the contrib **Flexible Permissions** module and onto **Drupal core's Access Policy API**,
renamed `group_content` → `group_relationship`, changed creator auto-membership to
**form-only**, and turned "add an entity to a group" into a **cache-tag invalidation**
instead of an entity resave. These are exactly the behaviors that a static-analysis
migration audit (the `TODO(group4-VERIFY)` marker set, now resolved after 40+ green
story merges on D11.4 + Group 4.0.x-dev) **cannot** confirm — they need a running DB,
real entities, and real permission calculations.

### What exists today (the baseline this plan replaces)

| Layer | Location | Count | What it actually asserts |
| --- | --- | --- | --- |
| Kernel (`do_tests`) | `web/modules/custom/do_tests/tests/src/Kernel/Phase{1,2,3}Test.php` | **20** (7 + 5 + 8) | **Config *existence* only.** Each test extends `KernelTestBase`, opens `config/sync` as a `FileStorage`, and asserts a YAML file is present / has a key. It installs **no** modules and exercises **no** behavior. |
| E2E (Playwright) | `tests/e2e/phase1.spec.ts` | **4** | Phase-1 smoke (merged in [#30](https://github.com/Performant-Labs/groups-on-d11/issues/30)): group listing loads, add-form loads, create-one-group, anonymous → 403 on create. |
| CI | — | **0** | **No CI exists.** Nothing runs on a PR. |

So current *behavioral* kernel coverage of the seven risk areas is effectively **zero**
(`do_tests` reads YAML; it never installs Group or creates a `GroupRelationship`). This
plan defines the **target** and assigns every gap to a sub-issue.

### Test layers (from the epic)

- **Kernel — behavioral** (`KernelTestBase`, fast, real DB, no browser): install group
  config, create groups / members / relationships, assert entities and **permission
  calculations**. Target: ~60–70% of coverage. Owns raw-SQL / query / entity-API cells.
- **Functional** (`BrowserTestBase`, real request stack, no JS): permission *enforcement*
  across roles, node-access denials, hook side-effects, form-only creator membership.
- **E2E (Playwright)**: full journeys across the real theme (`bluecheese`), phases 1–5.

---

## 2. Coverage matrix

### 2.1 Legend

**Status**

- `none` — no test of any kind touches this behavior.
- `config-existence` — a `do_tests` Phase test asserts the backing config YAML exists,
  but nothing exercises the behavior (the baseline this epic converts).
- `E2E-smoke` — a Phase-1 Playwright test touches the surface but does not assert the
  risk-area behavior.
- `covered` — behavioral assertion exists (none yet at authoring time).

**Layer owner** — the layer that owns the *primary* assertion: `K` = kernel-behavioral,
`F` = functional (`BrowserTestBase`), `E` = E2E (Playwright).

**Risk areas** (columns, from epic #31)

1. **RA1** Access-policy enforcement (outsider / insider / individual)
2. **RA2** Creator auto-membership is form-only
3. **RA3** Add-to-group is a cache-tag event (not a resave)
4. **RA4** `do_group_pin` stream: pinned-first ordering + `DISTINCT`
5. **RA5** `do_multigroup` cardinality + `GroupRelationship::create()` v4 keys
6. **RA6** `do_discovery` iCal event filter
7. **RA7** `do_profile_stats` raw contribution query

### 2.2 Module × risk-area × layer

Cells read: **layer owner** · **what is asserted** · **sub-issue** · **status**.
`N/A` = the module has no exposure to that risk area.

| Module (real purpose / 4.x entry point) | RA1 access | RA2 creator | RA3 cache-tag | RA4 pin | RA5 multigroup | RA6 iCal | RA7 stats |
| --- | --- | --- | --- | --- | --- | --- | --- |
| **`do_discovery`** — hot-content scoring, promoted content, **three iCal feeds** (`/upcoming-events/ical`, `/group/{group}/events/ical`, `/user/{user}/events/ical`); `IcalController::loadGroupEvents()` runs a raw `group_relationship_field_data` select with `gr.type LIKE '%event%'`. | N/A | N/A | N/A | N/A | N/A | **K** · all three feeds emit only published `event` nodes; the `gr.type LIKE '%event%'` filter matches the v4 `community_group-group_node-event` bundle ID and excludes non-event relations; `VCALENDAR`/`VEVENT` shape. **#40 (C2)** · `none` | N/A |
| **`do_group_extras`** — moderation defaults + **content-access enforcement**: `entity_presave` forces a new non-admin group to `status=0`; `node_access` **denies `create` in an Archive-type group**; submission-guidelines `form_alter`; `preprocess_group` archived-CSS. **0 v4-API hits** (reads only `field_group_type` + core `status`). | **F** · Archive-group `node_access` forbidden vs. neutral in a live group across roles (this is the module's real access surface, distinct from Group's own policy). **#42 (C4)** · `none` | **F** · new non-admin group lands `status=0` (unpublished/pending) while `administer group[s]` bypasses. **#42 (C4)** · `none` | N/A | N/A | N/A | N/A | N/A |
| **`do_group_language`** — URL language negotiation: `LanguageNegotiationGroup` matches `^/group/(\d+)`, reads `field_group_language`, returns the langcode (skips `und`/`zxx`/empty). **0 v4-API hits** (entity load only). | N/A | N/A | N/A | N/A | N/A | N/A | N/A |
| **`do_group_mission`** — `GroupMissionBlock` renders truncated `field_group_description` (300-char word-boundary + "Read more"); group cache tags. **0 v4-API hits** (context/route group load only). | N/A | N/A | N/A | N/A | N/A | N/A | N/A |
| **`do_group_pin`** — pin-in-group stream: `DoGroupPinHooks` adds a `group_relationship` relationship + a `flagging` LEFT JOIN on the `pin_in_group` flag, sets **`distinct: true`**, and adds a `CASE WHEN pin_flagging.id IS NOT NULL` order-by (pinned-first). | N/A | N/A | N/A | **K/F** · after DISTINCT rewrite the `pin_flagging` alias survives and pinned nodes sort above unpinned in the group stream; no row duplication. **#38 (B4)** · `none` | N/A | N/A | N/A |
| **`do_multigroup`** — cross-post a node to multiple groups: `DoMultigroupHooks` `form_node_form_alter` offers a group-audience checkbox set; `nodeFormSubmit` calls `GroupRelationship::create(['type','gid','entity_id'])->save()` per selected group and deletes de-selected ones. Hard-codes `community_group-group_node-<bundle>` with `documentation→doc` (~L57) and `community_group-group_membership` (~L75). | N/A | N/A | (add-to-group semantics exercised via `create()` — the direct-create path is **unaffected** by the resave→cache-tag change; RA3's notification consequence is tested in `do_notifications`) | N/A | **K** · `create()` with v4 keys `type`/`gid`/`entity_id` (~L194) persists a working relationship; a node posted to N groups yields N relationships (cardinality); de-select deletes; `documentation→doc` id maps correctly. **#39 (C1)** · `none` | N/A | N/A |
| **`do_notifications`** — per-post opt-out, follow subscriptions, subscription page; `DoNotificationsHooks` records events off **core `node_insert`/`comment_insert`** (not Group hooks), uses the same `documentation→doc` map (~L231), and `getGroupIds()` does `loadByProperties(['type' => ...])` (a wrong type is swallowed by the `catch` → empty). | N/A | N/A | **F/K** · **the RA3 assertion**: `node_insert` on a node already in a group records a group-scoped event; a node added to a group **after** creation (v4 cache-tag path, no resave, no `node_update` hook) records **no** group-scoped event — pinning down whether that is correct or a gap. **#37 (B3)** · `none` | N/A | (consumes `do_multigroup`/Group relationships as input) | N/A | N/A |
| **`do_profile_stats`** — `ContributionStatsBlock` counts nodes/comments/groups; `countGroups()` (~L124) is a **raw** `group_relationship_field_data` select on `gr.uid` with `gr.type LIKE '%group_membership'` + `COUNT(DISTINCT gr.gid)`; `ProfileCompletenessBlock` scores profile fields. | N/A | N/A | N/A | N/A | N/A | N/A | **K** · `countGroups()` returns the correct DISTINCT membership count against the **v4** `group_relationship_field_data` schema (columns `gid`/`uid`/`type`); the `%group_membership` LIKE matches `community_group-group_membership` and excludes node relations; `catch` → 0 on error. **#41 (C3)** · `none` |
| **`do_tests`** — the test module itself. Today: 20 config-existence kernel tests. A3 (#34) converts it to a **behavioral** base (`GroupsKernelTestBase`) + fixtures that install Group and create real groups/members/nodes; existing Phase tests are re-pointed at installed config or retired. | (hosts RA1 base) | (hosts RA2 base) | (hosts RA3 base) | (hosts RA4 base) | (hosts RA5 base) | (hosts RA6 base) | (hosts RA7 base) |

### 2.3 Cross-cutting risk areas (RA1, RA2) — not owned by a single `do_*` module

RA1 and RA2 are **Group-core 4.x behaviors** that no single `do_*` module implements;
they are exercised against the assembled `community_group` type and its roles. They get
their own dedicated cells so the epic's "every risk area assigned" bar is met:

| Risk area | Layer owner | What is asserted | Sub-issue | Status |
| --- | --- | --- | --- | --- |
| **RA1 — Access-policy enforcement** | **K** primary, **F** enforcement | Using `PermissionScopeInterface::{OUTSIDER,INSIDER,INDIVIDUAL}_ID` scopes and the two core-registered `access_policy` services (`IndividualGroupRoleAccessPolicy` priority −100, `SynchronizedGroupRoleAccessPolicy` −50): an outsider, an insider, and an individually-assigned member each resolve the **calculated** permission set expected for a `community_group`; `getPermissions($include_plugins = FALSE)` and the `user.group_permissions` cache context still behave as in the original migration audit notes. F-layer asserts the *enforcement* (a member can act / a non-member is denied on a real request). Closes the outstanding functional items from #6. | **#35 (B1)** | `none` |
| **RA2 — Creator auto-membership is form-only** | **F** primary, **K** negative | `Group::create()->save()` (kernel/API path) adds **no** creator membership; creating the same group **through the add form** (`/group/add/community_group`, functional) **does** add the creator as a member. The silent-regression trap: API-created groups are memberless. | **#36 (B2)** | `E2E-smoke` (create-one-group in #30 touches the form but does not assert creator membership) |

### 2.4 Coverage summary by sub-issue

| Sub-issue | Wave | Delivers |
| --- | --- | --- |
| **#33 (A2)** | A | CI (GitHub Actions): kernel + Playwright on every PR. Enables *all* cells to actually run. |
| **#34 (A3)** | A | `GroupsKernelTestBase` + fixtures (`createGroup` / `addMember` / `addNode`); converts `do_tests` from config-existence to behavioral. Prerequisite for every K/F cell. |
| **#35 (B1)** | B | RA1 — access-policy enforcement (§2.3). |
| **#36 (B2)** | B | RA2 — creator auto-membership form-only (§2.3). |
| **#37 (B3)** | B | RA3 — `do_notifications` node_insert + add-to-group cache-tag event. |
| **#38 (B4)** | B | RA4 — `do_group_pin` pinned-first + DISTINCT. |
| **#39 (C1)** | C | RA5 — `do_multigroup` cardinality + `create()`. |
| **#40 (C2)** | C | RA6 — `do_discovery` iCal feeds. |
| **#41 (C3)** | C | RA7 — `do_profile_stats` contribution query. |
| **#42 (C4)** | C | `do_group_extras` (node_access + moderation default), `do_group_language`, `do_group_mission` — the "0 v4-API-hit" modules; concrete targets above. |
| **#43 (D1)** | D | Playwright E2E phases 2–5. |
| **#44 (D2)** | D | Fix missing `group.community_group` add-form display + assert fields render. |

> **Note on the three "0 v4-API-hit" modules (C4 / #42).** The migration audit found no
> `drupal/group` v4-API calls in `do_group_extras`, `do_group_language`, or
> `do_group_mission`, so none carries RA1–RA7 exposure. That does **not** make them
> untestable: `do_group_extras` in particular owns a **real content-access surface**
> (`node_access` Archive denial) and a **moderation default** (`entity_presave`
> `status=0`) that deserve behavioral tests; `do_group_language` owns a URL-negotiation
> plugin; `do_group_mission` owns a render-and-truncate block. #42 gives each a concrete
> target (see the module rows above).

---

## 3. Test conventions

### 3.1 Where tests live

- **Kernel / functional (PHPUnit):** `web/modules/custom/<module>/tests/src/Kernel/` and
  `.../tests/src/Functional/`, namespace `Drupal\Tests\<module>\{Kernel,Functional}`.
  Cross-module / integration behavioral tests live in **`do_tests`**
  (`web/modules/custom/do_tests/tests/src/Kernel/`), which A3 (#34) converts from
  config-existence into the behavioral home.
- **E2E (Playwright):** `tests/e2e/*.spec.ts` (repo root), one spec per phase
  (`phase1.spec.ts` merged in #30; phases 2–5 land in #43).

### 3.2 Running the suites

**PHPUnit requires `drupal/core-dev`** (PHPUnit + Drupal's test base classes) — already in
`require-dev` (`drupal/core-dev:^11.4`). A production `composer install` does not pull it;
provision once with `ddev composer install` (dev deps) if a clean checkout lacks it.

Canonical kernel/functional run command (the env DDEV needs; the `ddev exec phpunit` shim
is not guaranteed):

```bash
ddev exec 'SIMPLETEST_DB=mysql://db:db@db/db SIMPLETEST_BASE_URL=https://web \
  php vendor/bin/phpunit -c web/core/phpunit.xml.dist <path>'
```

- `<path>` = a directory (`web/modules/custom/do_tests/tests/src/Kernel/`) or a single
  file. Functional (`BrowserTestBase`) tests use the **same** command and env —
  `SIMPLETEST_BASE_URL=https://web` is what lets them boot a real site.
- Do **not** run `ddev drush config:import` as part of test setup — it reverts the
  assembled config. Behavioral tests install what they need via `$modules` +
  `installConfig()` / fixtures, not by importing `config/sync`.

**Playwright (E2E):**

```bash
npx playwright test tests/e2e/<file>.spec.ts
```

**Do not run the full 15-directory kernel aggregate locally** (issue
[#205](https://github.com/Performant-Labs/groups-on-d11/issues/205)). Handing
every module's Kernel dir to a single `phpunit` invocation — the way
`.github/workflows/test.yml` does per-job — reliably exhausts memory on a
developer workstation and exits 137. CI is unaffected (each module runs in
its own GitHub Actions job); local runs must be per-module.

Use the developer wrapper:

```bash
bash scripts/dev/run-kernel.sh                   # print usage + module list
bash scripts/dev/run-kernel.sh do_streams        # run one module
bash scripts/dev/run-kernel.sh all               # each module in its own process
```

The wrapper auto-detects DDEV and injects the `SIMPLETEST_*` env from above;
set `SKIP_DDEV=1` to bypass, `DRY_RUN=1` to print the phpunit command
without executing it. Regression test: `scripts/dev/tests/run-kernel-test.sh`.


### 3.3 Kernel base + fixtures (from A3 / #34)

A shared `GroupsKernelTestBase` (in `do_tests`) provides the behavioral foundation that
today's config-existence tests lack. Fixtures:

- `createGroup(array $values = []): GroupInterface` — a saved `community_group`.
- `addMember(GroupInterface $group, AccountInterface $account, array $roles = [])` — a
  membership `GroupRelationship` (the API path, so it can be contrasted with the
  form-only creator behavior in RA2).
- `addNode(GroupInterface $group, string $bundle, array $values = []): NodeInterface` — a
  node related to the group (the v4 create-relationship path, used by RA3/RA4/RA5 cells).

Enable Group + the relevant `do_*` module(s) via `protected static $modules` and install
their config with `installConfig()`; **never** rely on reading `config/sync` YAML (that is
the anti-pattern this epic retires).

### 3.4 E2E login

E2E logs in through the **real `/user/login` form** with `admin`/`admin`
(`ddev drush user:password admin 'admin'` per #30) — no drush/session injection at
runtime, so the suite is self-contained. The `ADMIN_USER`/`ADMIN_PASS` env vars override
the credentials. The group listing lives at **`/all-groups`** (Views page `all_groups`),
not the `/groups` path the runbook prose mentions.

### 3.5 Naming

- Every test class carries a `@group` annotation: `@group do_tests` for the integration
  suite, `@group <module>` for a module's own tests (so
  `phpunit --group do_group_pin` selects a slice).
- Test methods are `testCamelCaseBehavior()` naming the **behavior**, not the config
  (`testArchiveGroupDeniesNodeCreate()`, not `testFieldExists()`).

### 3.6 Config the test/CI setup must account for (RUNBOOK Step 190)

Seven `config/sync` entries carry drupal.org-environment dependencies and are **not**
imported on a standard-profile clean-room build (they are placed in the real environment
per Step 600h). Any clean-room `config:import` validation — and CI that stands up a fresh
site — must **exclude these seven**:

1. `block.block.do_*` — the three `do_*` blocks (depend on the `bluecheese` theme).
2. `pathauto.pattern.group_relationship` — needs `pathauto`.
3. `user.role.community` and its two `system.action.user_{add,remove}_role_action.community`
   actions — reference Contact / drupal.org-only node types.

CI kernel tests avoid this entirely by installing only the config they declare; the
exclusion matters for the full-site functional/E2E bring-up and the clean-room
`config:import` gate.
