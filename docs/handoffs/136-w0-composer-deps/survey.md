# Survey — #136 W0: Dependency pre-story

## Objective

Add ALL program composer dependencies to `composer.json` in one PR, so the lock file is
touched once before Wave 1. Modules are **added, not enabled**. No config, no seed, no UI.

## Files this phase touches

- `composer.json` (add to `require`)
- `composer.lock` (regenerated)
- No other files. No `.info.yml` enables, no config, no seed script changes.

## Current state (read)

`composer.json` require block (as of `main` @ c18f417):

```
composer/installers: ^2.3
drupal/asset_injector: ^2.21
drupal/core-composer-scaffold: ^11.4
drupal/core-project-message: ^11.4
drupal/core-recipe-unpack: ^11.4
drupal/core-recommended: ^11.4
drupal/flag: ^4.0
drupal/group: 4.0.x-dev
drush/drush: ^13
```

`minimum-stability: dev`, `prefer-stable: true`. Repository already points at
`https://packages.drupal.org/8`. Drupal core pinned `^11.4`; Group pinned to the `4.0.x-dev`
branch (Group 4.x has no stable D11 release yet, hence `-dev`).

RUNBOOK.md (`docs/groups/RUNBOOK.md`) already sanctions `drupal/message` +
`drupal/message_notify` as "Phase 5 / optional" deps (lines 81-82, 3200-3201) — this PR makes
that concrete.

## Reuse & Analogous-Feature map

This is a dependency-manifest change, not a code feature — there is no "analogous object" to
extend in the sense of services/routes/plugins. The **analogous precedent** is how
`drupal/flag` and `drupal/group` were already added to this same `require` block: pinned to
the loosest constraint that resolves against D11 + the currently-locked `drupal/group` branch,
sorted alphabetically (composer.json has `"sort-packages": true` in `config`), no separate
`require-dev` entry, no scaffold/config changes bundled in.

**Extend-vs-new recommendation:** extend the existing `require` block in place (no new
composer.json structure, no `repositories` addition needed — packages.drupal.org already
covers all four projects). This is the only sane approach for a manifest; "new" is N/A here.

## Per-dependency research (verified against drupal.org release history + composer.json of
each candidate, 2026-07-22)

### 1. `drupal/masquerade` (for #120)
- Latest: `8.x-2.2`, `core_compatibility: ^10.3 || ^11.0 || ^12.0`. Composer name is
  `drupal/masquerade` even though the release branch is `8.x-2.x` (legacy D8-era branch
  naming retained by the maintainer; the composer constraint is what matters).
- **Constraint to add:** `drupal/masquerade: ^2.2`
- No Group-core dependency; standalone permission-based user-switcher. No compatibility risk
  found against Group 4.x.

### 2. Membership-request mechanism (for #121)
- **Finding: `drupal/grequest` is a separate contrib project (NOT bundled in Group core).**
  Checked Group 4.0.x core's repo tree at the `4.0.x` branch
  (`git.drupalcode.org/project/group`) — `modules/` contains only `gnode` and
  `group_support_revisions`. No membership-request submodule ships in Group 4.x core.
- **Finding: grequest has NO Group-4.x-compatible release or branch.** grequest's branches are
  `1.0.x`, `2.0.x`, `3.0.x` only. Latest release `3.2.4` (core_compatibility `^10.3 || ^11`)
  declares `"drupal/group": "^3.0"` in its own `composer.json` — hard-incompatible with this
  project's `drupal/group: 4.0.x-dev`. No open grequest issue proposes a Group-4.x port as of
  this check.
- **Decision: do NOT add `drupal/grequest` in this PR.** Adding it would either fail to
  resolve (composer would reject `drupal/group 4.0.x-dev` against grequest's `^3.0` require)
  or force a conflicting constraint override that breaks the existing `drupal/group` pin. This
  finding is recorded on #121 per the issue's acceptance criteria — #121 will need to
  either (a) build membership-request as bespoke Group-4.x logic (state-based group content
  + a custom route/form), or (b) wait for/contribute a grequest Group-4.x port, or (c)
  re-evaluate against whatever Group 4.x core ships by the time #121 is worked. Recorded as a
  comment on #121, not a package add here.

### 3. `drupal/message` + `drupal/message_notify` (for #116)
- `drupal/message` latest `8.x-1.8`, `core_compatibility: ^9.2 || ^10 || ^11`.
  **Constraint to add:** `drupal/message: ^1.8`
- `drupal/message_notify` latest `8.x-1.5`, `core_compatibility: ^9 || ^10 || ^11`.
  **Constraint to add:** `drupal/message_notify: ^1.5`
- Already pre-sanctioned in RUNBOOK.md as Phase 5 optional deps. No Group-core coupling; both
  are standalone entity/notification frameworks. No compatibility risk found.

### 4. `drupal/geofield` + map formatter (for #125)
- `drupal/geofield` latest `10.3.4`, `core_compatibility: ^10.3 || ^11 || ^12`.
  **Constraint to add:** `drupal/geofield: ^10.3`
- Map formatter choice: evaluated `drupal/geofield_map` (11.1.9, `core_compatibility: ^10.3 ||
  ^11`) vs a hypothetical "Views Leaflet formatter" package — **no standalone
  `leaflet_views`/`views_leaflet` project exists on drupal.org** (queried the project API;
  zero results). `drupal/geofield_map` is therefore the only viable contrib map-formatter
  choice, and it already bundles Leaflet-based map widgets/formatters/Views integration in one
  package — no companion package needed.
- `geofield_map`'s own `composer.json` requires only `drupal/core: ^10.3 || ^11` and
  `drupal/geofield: ^1.31 || ^10.3` — clean, no Group coupling, no PHP-side Leaflet package
  (Leaflet JS is a JS library `geofield_map` expects to be present via its own library
  definition / CDN — consistent with the issue's note that "leaflet assets get vendored
  locally in #125, not here").
  **Constraint to add:** `drupal/geofield_map: ^11.1`
- **Decision recorded on #125:** map formatter = `drupal/geofield_map` (not a bare "Leaflet
  Views formatter" package, which doesn't exist as a separate project).

## Compatibility risk summary

| Package | Constraint | D11 compat | Group 4.x coupling | Risk |
|---|---|---|---|---|
| drupal/masquerade | `^2.2` | Yes (`^10.3\|\|^11.0\|\|^12.0`) | None | Low |
| drupal/message | `^1.8` | Yes (`^9.2\|\|^10\|\|^11`) | None | Low |
| drupal/message_notify | `^1.5` | Yes (`^9\|\|^10\|\|^11`) | None | Low |
| drupal/geofield | `^10.3` | Yes (`^10.3\|\|^11\|\|^12`) | None | Low |
| drupal/geofield_map | `^11.1` | Yes (`^10.3\|\|^11`) | None (depends only on geofield+core) | Low |
| drupal/grequest | N/A — not added | N/A | **Hard-incompatible** (`^3.0` vs `4.0.x-dev`) | High — deferred to #121 |

Only open risk: whether `composer require` on the *actual* resolver (not just release-history
metadata) accepts all five packages simultaneously against `drupal/group: 4.0.x-dev` and
`drupal/core-recommended: ^11.4` with `minimum-stability: dev`. This is verified empirically in
Phase 4 (F) by running the real `composer require` inside the DDEV container — metadata review
alone is not sufficient proof of a clean resolve.

## Review-rigor dial

**Declared: `none`** (per the story's own "Review rigor: none" line and the operator's
explicit instruction). Mechanical, additive-only manifest change; CI build + `composer
install` clean is the check. No outside dual-review, no Designer phase (no UI surface, no code
surface at all beyond the manifest).
