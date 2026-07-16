# Group 3.x → 4.x Migration Notes

**Status:** Wave-0 reference for the Group-v4 upgrade epic. This is the shared source of
truth that Wave-1 issues (the runbook, issue #4, and each affected custom module) consume.
Facts that could not be pinned down from change records at write time are marked
**[VERIFY on build]** — resolve them against the real code/release, do not assert them.

**Scope of this doc:** the concrete API/config/composer deltas between Group **3.x** and
Group **4.0.0-alpha1** (released 2026-04-24) that our custom modules and site config must
handle. It is *not* a general Group tutorial.

## Source change records (cite these in code review)

| Delta | Change record / issue | Applies to |
|---|---|---|
| Flexible Permissions dependency replaced with core Access Policy API | https://www.drupal.org/node/3455789 (dependency-drop issue), core CR https://www.drupal.org/node/3385551 | 4.0.0-alpha1 |
| Core Access Policy API (the replacement) | https://www.drupal.org/docs/develop/drupal-apis/access-policy-api | Drupal core ≥ 10.3 |
| `GroupRelationshipType::$content_plugin` renamed to `$relation_type` | https://www.drupal.org/list-changes/group (CR dated 2026-06-19, targets 4.0.0-alpha2) | 4.0.0-alpha2 |
| Minimum Drupal version raised to 11.2 | https://www.drupal.org/list-changes/group (CR 2025-02-19) | 4.0.0-alpha1 |
| Two-step membership wizard removed | https://www.drupal.org/list-changes/group (CR 2026-04-24) | 4.0.0-alpha1 |
| Creator auto-membership is now form-only, not programmatic | https://www.drupal.org/list-changes/group (CR 2026-04-24) | 4.0.0-alpha1 |
| "Add entity to group" invalidates cache tags instead of resaving the entity | https://www.drupal.org/list-changes/group (CR 2025-05-23) | 4.0.0-alpha1 |
| Empty `.module` files removed; hooks converted to OOP hook classes | https://www.drupal.org/list-changes/group (CR 2025-05-14) | 4.0.0-alpha1 |
| `$roles` filter on membership-loading methods must always be an array | https://www.drupal.org/list-changes/group (CR 2025-02-19) | 4.0.0-alpha1 |
| Entity API (`entity`) module dependency removed (uses core revision UI) | https://www.drupal.org/list-changes/group (CR 2024-12-19) | 4.0.0-alpha1 |

Full change-record index: https://www.drupal.org/list-changes/group
Releases: https://www.drupal.org/project/group/releases

Note on ordering: two deltas below (the `$relation_type` rename, and any post-alpha1 API
churn) landed in **4.0.0-alpha2**. If the runbook pins **alpha1**, treat the alpha2 items as
forward-looking; if it pins alpha2, they apply now. **[VERIFY on build]** which alpha the
runbook targets.

---

## 1. Composer / platform

**What changes:**

- **Group constraint:** move from `drupal/group:^3` to `drupal/group:^4` (alpha — see note
  below). The direct upgrade path is **from v3 only**; there is no supported jump from v1/v2
  straight to v4. Sites on v2 must reach v3 first.
- **Drop transitive contrib deps.** Group 4.x no longer depends on:
  - `drupal/variationcache` — folded into Drupal core (VariationCache landed in core **10.2+**).
  - `drupal/flexible_permissions` — replaced by the core **Access Policy API** (core **10.3+**).
  - `drupal/entity` (Entity API) — Group 4.x uses core's revision UI instead
    (CR 2024-12-19). Remove it from `composer.json` **only if** no other module needs it.
  Remove `variationcache` and `flexible_permissions` from `composer.json` `require` and
  uninstall the modules; on Drupal 11 they are redundant and can conflict.
- **Drupal core:** Group 4.x requires **Drupal ^11.2** (CR 2025-02-19). The epic targets
  **Drupal 11.4** — confirm the site is on ≥ 11.2 before installing group ^4.
  **[VERIFY on build]** exact core version pinned in the site's `composer.json`.
- **Alpha stability.** 4.0.0-alpha1 is an alpha; `composer` will need
  `minimum-stability: alpha` (or a versioned `^4.0@alpha` constraint) to resolve it.
  **[VERIFY on build]** whether alpha2 (2026-06-19) or a later alpha/beta is the pin —
  prefer the newest alpha available since the `$relation_type` rename lands there.

**Uninstall order:** uninstall dependent contrib (`variationcache`, `flexible_permissions`)
**after** Group 4.x is installed and its update hooks have run, not before — Group 3.x still
references them at the moment of the version swap. **[VERIFY on build]** with a dry-run
`drush updatedb` on a DB copy.

---

## 2. Permissions — Access Policy API replaces Flexible Permissions

This is the highest-impact API delta. In Group 3.x, per-group permission calculation went
through the contrib **Flexible Permissions** module (`PermissionCalculatorInterface`,
the `permission_calculator` service, scope providers). In Group 4.x that machinery is gone;
the equivalent lives in **Drupal core's Access Policy API** (core ≥ 10.3; CR
https://www.drupal.org/node/3385551, docs
https://www.drupal.org/docs/develop/drupal-apis/access-policy-api).

**Core API shape (what custom code targets now):**

- A policy is a service tagged **`access_policy`** that extends
  `Drupal\Core\Session\AccessPolicyBase` (implements `AccessPolicyInterface`).
- Key methods a policy implements:
  - `calculatePermissions(AccountInterface $account, string $scope): RefinableCalculatedPermissionsInterface`
    — builds the permission set for an account in a given scope, adding
    `CalculatedPermissionsItem` objects (per-scope, per-identifier).
  - `alterPermissions(...)` — alter phase for adjusting already-calculated permissions.
  - `getPersistentCacheContexts()` — declares cache contexts that vary the calculation.
  - `applies(string $scope)` — restricts the policy to the scope(s) it handles.
- Results are aggregated into an **immutable** `CalculatedPermissions` object with
  cacheable metadata; nothing can mutate permissions mid-request.
- Group defines its own **scopes** (historically `group_outsider` / `group_insider` for
  membership-vs-group-type checks, and `group_individual` for per-membership grants). Group
  4.x registers these as core access policies rather than flexible-permissions calculators.
  **[VERIFY on build]** the exact scope constant names/values Group 4.x ships, by reading
  `group/src/Access/*` in the installed 4.x code — the 3.x-era names may have been renamed.

**What custom code / config using the old permission provider must change:**

1. **Any service tagged `permission_calculator`** (Flexible Permissions) must be
   re-implemented as an **`access_policy`**-tagged service extending `AccessPolicyBase`.
   Grep the modules for `permission_calculator`, `PermissionCalculatorInterface`,
   `CalculatedPermissions` imported from the `flexible_permissions` namespace, and
   `flexible_permissions` in any `*.services.yml`.
2. **`use Drupal\flexible_permissions\...`** imports must be repointed to the core
   equivalents under `Drupal\Core\Session\` (e.g. `CalculatedPermissions`,
   `CalculatedPermissionsItem`, `RefinableCalculatedPermissionsInterface`,
   `AccessPolicyInterface`, `AccessPolicyBase`). **[VERIFY on build]** the exact core FQCNs
   against the target core release — some class names carry over, some were adjusted when
   the contrib code was upstreamed.
3. **Permission *provider* plugins** on custom Group relation plugins (the
   `permission_provider` handler that declares group permissions) — confirm the handler
   interface/`getPermissions()` signature is unchanged in 4.x. The permission *declaration*
   side (what permissions a relation exposes) is distinct from the *calculation* side (Access
   Policy) and is more likely to survive intact. **[VERIFY on build]** against
   `group/src/Plugin/Group/RelationHandler/PermissionProvider*`.
4. **Cache contexts:** any code that read Flexible Permissions cache contexts
   (e.g. a `user.group_permissions`-style context) must switch to the context Group 4.x /
   core emits. **[VERIFY on build]** the context name.

---

## 3. Relationship API (`group_relationship` / GroupRelationship)

Terminology note: the `GroupContent` → `GroupRelationship` rename and
`Group::addContent()` → `Group::addRelationship()` change happened back in **v2.0**
(https://www.drupal.org/node/3292844) — so if our modules are already on v3, they should
**already** use the `Relationship` names. v4 does **not** re-do that rename.

**What is new in v4:**

- **`GroupRelationshipType::$content_plugin` → `$relation_type`** (CR 2026-06-19, targets
  **4.0.0-alpha2**). The config/entity property that stored the relation plugin ID was
  renamed. An **update hook ships to migrate stored config**, but:
  - Custom code that reads `$group_relationship_type->content_plugin` (or the array key
    `content_plugin` in exported YAML / `getContentPlugin()`-style accessors) must switch to
    `relation_type`. Grep for `content_plugin` across modules **and** config/sync YAML.
  - **Config YAML in `config/install` or `config/sync`** that hard-codes `content_plugin:`
    keys for a `group.relationship_type.*` entity must be updated to `relation_type:`.
    **[VERIFY on build]** whether the module ships such config exports.
- **Creator auto-membership is form-only** (CR 2026-04-24). Programmatically created groups
  (e.g. in migrations, tests, or a module that spins up groups via the API) **no longer get
  the creator auto-added as a member**. Any custom code relying on "create a group → creator
  is a member" must now add the membership explicitly via the API.
- **"Add entity to group" no longer resaves the entity** — it invalidates cache tags instead
  (CR 2025-05-23). Custom code/tests that assumed adding a relationship triggers a full
  entity `save()` (and its side effects: `hook_entity_update`, field recomputation) must not
  rely on that. Assert on cache-tag invalidation, not on a resave.
- **`$roles` filter must be an array** (CR 2025-02-19). Any call to membership-loading
  helpers that passed a single role ID as a string must pass `['role_id']`.

**[VERIFY on build]** whether the `GroupRelationship` entity/storage picked up any other
signature changes in 4.x (e.g. new required fields, changed `create()` array keys) by
diffing `group/src/Entity/GroupRelationship*` between the installed 3.x and 4.x.

---

## 4. Deprecations / removals to sweep for

Grep the custom modules (and tests, and config YAML) for each of these and fix every hit:

- `flexible_permissions` — module name in `.info.yml` `dependencies`, in `*.services.yml`,
  and `use Drupal\flexible_permissions\...` imports. **Remove / repoint to core.**
- `variationcache` — dependency references and `Drupal\variationcache\...` imports.
  **Remove** (now core).
- `permission_calculator` (service ID) and `PermissionCalculatorInterface`. **Migrate to
  Access Policy API** (§2).
- `content_plugin` (property, accessor, array key, config YAML key). **Rename to
  `relation_type`** (§3).
- `entity` (Entity API) in `.info.yml` dependencies, if present and unused elsewhere.
- Empty `.module` files / procedural hooks that Group itself moved to OOP hook classes —
  this is Group-internal, but **[VERIFY on build]** none of our modules call a Group
  procedural function that was deleted in the OOP conversion (CR 2025-05-14).
- Any `GroupContent`-era class names or `addContent()` calls still lingering — should already
  be gone at v3, but sweep anyway.
- The two-step membership wizard route/form (removed, CR 2026-04-24) — any module linking to
  or overriding that wizard route must drop the reference. **[VERIFY on build]** the exact
  route name that was removed.

Run `drush deprecation` / `phpstan` (drupal-check) against the modules **after** the composer
swap to catch deprecated-code hits the greps miss.

---

## 5. Per-consumer checklist

### Runbook (issue #4) — phase gates

- **Phase: pre-flight.** Confirm site core ≥ 11.2 (target 11.4); confirm current Group is on
  **3.x** (not 2.x) — direct v4 path is from v3 only. Snapshot DB.
- **Phase: composer.** Swap `group:^3`→`^4@alpha`; remove `variationcache`,
  `flexible_permissions`, and (if unused) `entity`; set `minimum-stability` as needed (§1).
- **Phase: update DB.** `drush updatedb` runs Group's update hooks, including the
  `content_plugin`→`relation_type` config migration. **[VERIFY on build]** it completes clean
  on a DB copy first.
- **Phase: uninstall redundant modules.** Uninstall `flexible_permissions` + `variationcache`
  **after** Group 4.x update hooks succeed, not before (§1).
- **Phase: config.** Re-export config; diff for `content_plugin:`→`relation_type:` churn and
  any Access-Policy-related service changes. Confirm no `flexible_permissions` config remains.
- **Phase: regression.** Membership create/join, permission checks per group type (insider/
  outsider/individual), programmatic group creation (creator-membership behavior change!),
  and access-controlled queries. Follow the inventory→baseline→regression discipline: capture
  the working inventory on 3.x first, re-verify the identical list on 4.x.

### `do_multigroup`

- Most likely to touch permission calculation and relation types. Grep for
  `permission_calculator`, `flexible_permissions`, `content_plugin`, custom scopes. Re-home
  any calculator to an `access_policy` service (§2). Verify multi-group permission
  aggregation still resolves under the immutable `CalculatedPermissions`.

### `do_discovery`

- Likely queries/lists groups & relationships. Check `$roles`-as-array (§3), any reliance on
  entity resave when adding relationships, and any `content_plugin` reads when filtering by
  relation type. Verify access-scoped queries still return the same result set.

### `do_group_pin`

- If it creates relationships or pins entities to groups, check the resave→cache-tag change
  (§3) and `content_plugin`→`relation_type` if it inspects relation types. **[VERIFY on
  build]** whether it programmatically creates groups (creator-membership change).

### `do_notifications`

- If it reacts to membership/relationship events or entity updates, the "add to group no
  longer resaves the entity" change (§3) may drop a `hook_entity_update` it depended on.
  Re-verify notification triggers fire on the new cache-tag path.

### `do_profile_stats`

- If it counts memberships or reads group permissions for stats, check membership-loading
  `$roles`-array calls and any permission-calculation reads. Lower risk, but sweep for the
  same tokens (§4).

### `do_tests`

- Highest churn surface for test code. Fix: `$roles` string→array; assertions that expected
  an entity resave on "add to group" (now cache-tag invalidation); test fixtures/programmatic
  group creation that assumed creator auto-membership; any `content_plugin` in test config or
  `create()` arrays; any Flexible-Permissions test doubles. Re-run the full suite RED→GREEN
  against 4.x. **[VERIFY on build]** kernel-test base classes from Group still exist under the
  same namespace.

---

## Open items to resolve at build (consolidated)

1. Which alpha the runbook pins (alpha1 vs alpha2) — decides whether `$relation_type` applies now.
2. Exact core version in the site `composer.json` (must be ≥ 11.2; epic target 11.4).
3. Exact core FQCNs for the upstreamed permission classes and the Group 4.x scope constants.
4. Whether any module ships `group.relationship_type.*` config YAML with `content_plugin:` keys.
5. The removed two-step-wizard route name, if any module references it.
6. Whether `entity` (Entity API) is safe to remove (no other consumer).
7. Dry-run `drush updatedb` on a DB copy to confirm the config-migration update hook is clean.
