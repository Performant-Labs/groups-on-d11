# Group 3.x → 4.x Migration Playbook (for adopters)

**Audience:** maintainers of Drupal 10/11 sites currently running `drupal/group:^3` who
want to move to `drupal/group:^4`. This playbook distills the migration steps we performed
on the groups-on-d11 project into a checklist another site team can follow.

**Not in scope:** general Group module tutorials, or the internal architecture of this
repository. For the deep-dive on API deltas (Access Policy API, `$relation_type` rename,
etc.) see the change records linked from the Group project on drupal.org. For a change-log
style reference we used internally, this repo also keeps `docs/groups/GROUP_4X_MIGRATION.md`.

**Worked examples in this repo** — every step below cross-references the PR(s) where we
did the work, so you can see a real diff:

| Area                                        | PRs (this repo)   |
|---------------------------------------------|-------------------|
| Wave-0 migration reference notes            | #15               |
| Composer swap + foundation                  | #16, #24          |
| `do_multigroup` 4.x compatibility           | #19, #75          |
| `do_discovery` 4.x compatibility            | #18               |
| `do_group_pin` 4.x compatibility            | #20               |
| `do_notifications` 4.x compatibility        | #21               |
| `do_profile_stats` 4.x compatibility        | #17               |
| `do_tests` 4.x compatibility                | #22               |
| Runbook — phase gates                       | #23, #25          |
| Config-sync `relation_type` conversion      | #27, #28          |
| Playwright E2E smoke suite (D11.4 + G4.x)   | #30               |
| `core_version_requirement: ^11.2`           | commit `3692858`  |

---

## 0. Preflight checklist

Before touching `composer.json`, verify all of the following:

- [ ] **Drupal core ≥ 11.2.** Group 4.x requires it (CR 2025-02-19). Confirm with
      `composer show drupal/core`.
- [ ] **Currently on Group 3.x.** The supported upgrade path is v3 → v4 only. Sites on v1
      or v2 must reach v3 first.
- [ ] **PHP version** meets Drupal 11.2's own minimum (PHP 8.3 at time of writing —
      confirm against your target core release).
- [ ] **Composer ≥ 2.7** (needed for the platform overrides + patch resolution most Drupal
      projects use).
- [ ] **Full database + files backup.** The `content_plugin` → `relation_type` update hook
      rewrites `group.relationship_type.*` config in place; you want a rollback.
- [ ] **Dry-run environment.** Copy production DB to a scratch environment and perform the
      whole playbook there first. Do not skip this — the update hook interacts with any
      custom `group.relationship_type.*` config you may have.
- [ ] **Inventory of what "works" on 3.x.** Capture (and pin somewhere you can re-run):
      list of group types, list of relation plugins in use, sample of URLs for members
      pages, join/leave, permission-gated pages. You will re-run this exact list on 4.x
      in step 8 to confirm parity.

---

## 1. Composer changes

**What to change in `composer.json`:**

```jsonc
{
  "require": {
    "drupal/group": "^4.0@alpha"        // was: "^3"
    // REMOVE these — they are now folded into core:
    // "drupal/flexible_permissions": "^2",
    // "drupal/variationcache": "^1",
    // REMOVE if no other module depends on it:
    // "drupal/entity": "^1"
  },
  "minimum-stability": "alpha"          // only if not already looser than "stable"
}
```

Notes:

- **Alpha stability.** At time of writing the newest 4.x release line is `4.0.0-alpha*`.
  Either set `minimum-stability: alpha` or pin `^4.0@alpha`. Prefer the newest alpha
  available — the `$relation_type` rename lands in `4.0.0-alpha2`. In this repo we
  landed the alpha2 pin in **PR #24**.
- **Do not uninstall the dropped modules yet.** Group 3.x still references
  `flexible_permissions` and `variationcache` at the moment of the version swap. Run the
  composer update first, then update DB, *then* uninstall (§4).
- **Custom modules that declare `drupal/group:^3` in their `.info.yml` dependencies**
  will need the constraint bumped. Also set
  `core_version_requirement: ^11.2` on each custom `.info.yml` — see this repo's commit
  `3692858` for the pattern.

Run:

```sh
composer update drupal/group --with-all-dependencies
```

---

## 2. Config renames — `content_plugin` → `relation_type`

The `GroupRelationshipType` config entity's `content_plugin` property was renamed to
`relation_type` in `4.0.0-alpha2` (CR 2026-06-19).

**A Group-shipped update hook migrates stored config**, but:

- Any **exported YAML** in your own module's `config/install/` or your site's
  `config/sync/` directory that hard-codes `content_plugin:` for a
  `group.relationship_type.*` entity must be edited by hand. `drush cim` compares against
  your exported YAML — if the YAML still says `content_plugin`, the config diff will
  never converge.
- Any **custom PHP** that reads `$relationship_type->content_plugin` (or a
  `getContentPlugin()` accessor, or the `'content_plugin'` array key in `create()`
  arrays) must be repointed to `relation_type` / `getRelationType()`.

**How we did it** — PRs #27 (`config/sync` round-trip) and #28 (`config/install`
converter + test infra). Grep both `.php` and `.yml`:

```sh
grep -rn "content_plugin" web/modules/custom/ config/sync/
```

---

## 3. Permissions — Access Policy API replaces Flexible Permissions

Group 3.x calculated per-group permissions through the contrib **Flexible Permissions**
module. Group 4.x uses **Drupal core's Access Policy API** instead (core ≥ 10.3).

**If any of your custom modules do the following, they will need code changes:**

- Register a service tagged **`permission_calculator`** → re-implement as a service
  tagged **`access_policy`** extending `Drupal\Core\Session\AccessPolicyBase`.
- Import from **`Drupal\flexible_permissions\…`** → repoint the same class names under
  **`Drupal\Core\Session\`**. The five class names carry over unchanged:
  `CalculatedPermissions`, `CalculatedPermissionsItem`, `CalculatedPermissionsInterface`,
  `RefinableCalculatedPermissionsInterface`, `AccessPolicyInterface`, `AccessPolicyBase`.
- Reference Group's 3.x-era scope IDs like `group_outsider` / `group_insider` /
  `group_individual` → use the constants on
  `Drupal\group\PermissionScopeInterface`: `OUTSIDER_ID = 'outsider'`,
  `INSIDER_ID = 'insider'`, `INDIVIDUAL_ID = 'individual'` (the `group_` prefix was
  dropped).

**Unchanged and safe to leave alone:**

- The **`permission_provider`** handler on custom Group relation plugins (the side that
  *declares* permissions). Only the *calculation* side moved.
- The **`user.group_permissions`** cache context — still emitted in 4.x.

Grep for the migration surface:

```sh
grep -rEn "permission_calculator|PermissionCalculatorInterface|flexible_permissions" \
  web/modules/custom/
```

In this repo, all six of our custom modules were clean of these tokens — see the
per-module PRs #17–#22 for the concrete audit. Your site will vary.

---

## 4. Other API changes to sweep for

Group 4.x also introduced these smaller changes. Grep, and fix each hit:

- **`$roles` filter must be an array.** Membership-loading helpers (`getMember`,
  `loadByGroup`, etc.) reject a bare string role ID. `getMember($account, 'editor')` →
  `getMember($account, ['editor'])`.
- **Creator auto-membership is form-only.** Programmatically-created groups (via API,
  migrations, tests) no longer add the creator as a member automatically. If your code
  or fixtures depend on that, add the membership explicitly.
- **"Add entity to group" no longer resaves the entity.** It invalidates cache tags
  instead. Any code that hooked `hook_entity_update()` to react to relationships being
  added will stop firing — switch to reacting on the relationship entity itself.
- **Two-step membership wizard removed.** Any custom link to (or route override of) the
  wizard route must be dropped.
- **Empty `.module` files removed.** Group's own hooks are now OOP hook classes. If your
  code calls a Group procedural function that was inlined, you'll get an undefined-function
  error — the fix is to use the Group service instead.
- **`drupal/entity` (Entity API) module dependency dropped.** If nothing else on your site
  uses it, uninstall it after the swap.

---

## 5. Test-fixture updates

If you have Kernel or Functional tests exercising Group:

- **`assertRelationPlugin()` helper.** Assertions that previously read
  `$relationship_type->content_plugin` in test helpers need the same rename as
  production code (§2). This repo's `do_tests` helper (PR #22) is a worked example.
- **`core_version_requirement`.** Any test-only module's `.info.yml` that pinned
  `^10 || ^11` will need `^11.2` to install alongside Group 4.x under Drupal 11.2+.
- **Programmatic group creation in fixtures** that expected creator auto-membership
  must add the membership explicitly (§4).
- **`$roles` filter** — audit test helpers passing role IDs as strings.
- **Playwright / browser tests.** Any selector or route that touched the two-step
  membership wizard needs to be updated to the single-step form. Our
  Phase-1 E2E smoke suite (PR #30) is a template.

---

## 6. Uninstall order for redundant modules

**Only after** `drush updatedb` completes cleanly on 4.x:

```sh
drush pmu flexible_permissions variationcache
# and, if nothing else uses it:
drush pmu entity
```

Then remove them from `composer.json` and run `composer update --lock`.

Order matters: uninstalling *before* the 4.x update hooks run leaves Group 3.x pointing
at services that no longer exist.

---

## 7. Update DB and config

```sh
drush updatedb -y            # runs Group's content_plugin → relation_type update hook
drush cache:rebuild
drush config:export -y       # re-export; commit the diff
```

Review the config diff carefully. You should see:

- `content_plugin: foo` → `relation_type: foo` on every `group.relationship_type.*.yml`.
- No `flexible_permissions.*` config left over.
- Access-Policy-related service additions from Group core (informational only — don't
  hand-edit).

**Clean-room install note (no existing DB):** if you are installing 4.x from scratch and
have no 3.x DB to upgrade, you can skip the update-hook step entirely — the config ships
with the correct keys. This repo captured that carve-out in **PR #25** (dropped Step 115
from the runbook for the clean-room case).

---

## 8. Verification

Re-run the exact inventory you captured in step 0 preflight. At minimum:

- [ ] Every group type loads its "Members" page.
- [ ] Join / leave flows work (single-step form now — no more wizard).
- [ ] Permission-gated pages resolve the same insider/outsider/individual outcomes as
      they did on 3.x for a representative account per role.
- [ ] Programmatic group creation (from your migrations or seed scripts) still ends up
      with a valid group **and** an explicit creator membership if you rely on that.
- [ ] Any code path that reacted to `hook_entity_update` on entities being added to a
      group still fires (via the relationship entity, or via cache-tag invalidation).
- [ ] Your full test suite passes against 4.x.
- [ ] `drush deprecation` (`drupal-check`) is clean against your custom modules.

If anything drifts from the 3.x baseline: capture the specific case, and check §3–§5 for
the corresponding API change. Do **not** patch symptoms without knowing which delta
caused them.

---

## Rollback

Because the `content_plugin` → `relation_type` update hook rewrites config in place,
rollback is:

1. Restore the DB backup from step 0.
2. Revert the `composer.json` change and run `composer update drupal/group --with-all-dependencies`.
3. Re-install `flexible_permissions` / `variationcache` if you had already uninstalled
   them.
4. `drush cache:rebuild`.

Verify parity against your 3.x baseline before re-attempting the migration.
