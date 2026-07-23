# Handoff-T-red: Phase 4 - #140 MC-1 Links & Resources

**Date:** 2026-07-22
**Branch:** 140-links
**Brief / wireframe reviewed:** `docs/planning/handoffs/140-links/brief.md`,
`docs/planning/handoffs/140-links/survey.md`, `docs/planning/handoffs/140-links/decisions.md`,
`docs/planning/handoffs/140-links/handoff-A-plan.md` (no wireframe — D skipped per decisions.md,
confirmed convention-bound render, no novel UI).

## A precondition

Confirmed: A returned PASS (with 3 non-blocking warns) on the plan (Phase 3), recorded in
`handoff-A-plan.md`. All three warns are encoded as observable-behavior test requirements below
(see "A-warn coverage").

## Tests authored

### Kernel: `docs/groups/modules/do_group_extras/tests/src/Kernel/GroupLinksFieldTest.php`

Extends `Drupal\Tests\do_tests\Kernel\GroupsKernelTestBase` (mirrors `GroupExtrasBehaviorTest`'s
pattern). `$modules = ['group', 'gnode', 'options', 'node', 'field', 'text', 'link', 'user',
'do_group_extras']`. Tagged `#[RunTestsInSeparateProcesses]` (required by a Drupal 11.3+
deprecation on `KernelTestBase` subclasses that don't declare it).

| Test | Criterion / behavior pinned | Tier | Why this tier |
|---|---|---|---|
| `testStorageExists` | `field_group_links` storage exists on `group` entity type, type `link`, cardinality `-1` | Kernel | Config-shape assertion; no HTTP/UI needed — cheapest sufficient tier |
| `testInstanceExists` | Field instanced on `community_group`, label "Links & Resources", not required | Kernel | Same — config-entity assertion |
| `testFullDisplayShowsField` | Group Full display (`group.community_group.default`) has a non-hidden `field_group_links` component | Kernel | Display-config assertion; no rendering needed |
| `testFormDisplayShowsField` | Form display exposes `field_group_links` with the `link_default` widget, non-hidden | Kernel | Display-config assertion |
| `testRendersExternalLinkWithRelNoopener` | An external link (`https://external.example.com`) renders an `<a>` with `rel="noopener"` (or `"noopener noreferrer"`) and `target="_blank"` | Kernel | Asserts OBSERVABLE RENDERED HTML (view builder + renderer), not formatter config shape — per A's warn #5, mechanism-agnostic so F can choose formatter settings or a preprocess fallback |
| `testInternalLinkRendered` | An internal link (`internal:/node/1`) renders an `<a>` carrying the title text | Kernel | Confirms internal links are NOT forced into the external-only `rel`/`target` treatment |
| `testEmptyStateRendersNothing` | A group with no links set renders no "Links & Resources" text and no bare `<h2>`/`<label>` wrapper | Kernel | Per A's warn #6 — pins the OBSERVABLE empty-state behavior regardless of whether it's achieved via the field's own `label: above` (suppressed wrapper on 0 deltas) or an explicit template guard |

**E2E:** `tests/e2e/group-links.spec.ts` (Playwright, 2 tests)

| Test | Criterion / behavior pinned | Tier | Why this tier |
|---|---|---|---|
| "anonymous sees a Links & Resources section with a known seeded link" | Anonymous visitor to a known seeded group (`DrupalCon Portland 2026`, found via `/all-groups` directory card, not a hardcoded gid) sees visible "Links & Resources" text and at least one seeded link title as a real `<a>` | E2E | Only tier that proves the full install->config-import->seed->render path actually surfaces the feature to a real anonymous visitor |
| "every external link on the group page carries rel=\"noopener\"" | Every `href^="http"` anchor not matching the site origin carries `rel` containing `noopener` | E2E | Confirms the safety attribute survives the full rendering pipeline on a live page, complementing the kernel-level unit assertion |

Kernel tests cover config shape + isolated rendering cheaply; E2E covers the one behavior that
requires the full seeded-install path (a real anonymous page view) and is not duplicated at the
kernel tier (kernel tests build ad hoc groups in-memory, not the seeded demo groups).

## A-warn coverage

All three of A's warns (handoff-A-plan.md #4, #5, #6) are closed:

- **#4** (reserved-weight / merge-safety scheme) — not testable in kernel/E2E (it's a source-tree
  YAML-authoring convention for F to follow when creating
  `core.entity_view_display.group.community_group.default.yml`); recorded here as an F
  implementation note, not a test gap. No test can pin a comment convention.
- **#5** (rel="noopener" mechanism-agnostic) — `testRendersExternalLinkWithRelNoopener` asserts
  the rendered HTML only, never formatter config shape.
- **#6** (empty-state wrapper mechanism-agnostic) — `testEmptyStateRendersNothing` asserts
  absence of "Links & Resources" text / `<h2>`/`<label>` markup, regardless of whether F achieves
  it via field label suppression or an explicit guard.

Zero A-warns were left unencoded as test requirements.

## RED confirmation

**Environment:** worktree has no vendor/`web/core` checked in; local PHP is not on PATH. Set up
a namespaced DDEV project (`gm140-groups-links`, renamed from the worktree's default
`pl-groups-on-d11` in `.ddev/config.yaml` to avoid colliding with the already-running
`pl-groups-on-d11` project — **this rename is local-only and NOT staged for commit**), ran
`ddev composer install`, then assembled and ran tests via `ddev exec` (CI runs `php` directly on
a runner with PHP preinstalled; DDEV is the local equivalent). No sibling DDEV project was
touched.

```
cd ~/Projects/_worktrees/groups-links
ddev start
ddev composer install
ddev exec bash scripts/ci/assemble-config.sh
ddev exec 'SIMPLETEST_DB="mysql://db:db@db:3306/db" php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox web/modules/custom/do_group_extras/tests/src/Kernel/GroupLinksFieldTest.php'
```

Result: **6 of 7 FAIL for the right reason**, 1 passes (empty-state, expected — see note below).

```
PHPUnit 11.5.56 by Sebastian Bergmann and contributors.

Runtime:       PHP 8.4.22
Configuration: /var/www/html/web/core/phpunit.xml.dist

FFFFFFD                                                             7 / 7 (100%)

Group Links Field (Drupal\Tests\do_group_extras\Kernel\GroupLinksField)
 ✘ Storage exists
   │ field_group_links storage config exists on the group entity type.
   │ Failed asserting that null is not null.
   │ .../GroupLinksFieldTest.php:85

 ✘ Instance exists
   │ field_group_links is instanced on the community_group bundle.
   │ Failed asserting that null is not null.
   │ .../GroupLinksFieldTest.php:97

 ✘ Full display shows field
   │ The group community_group "default" (Full) view display exists.
   │ Failed asserting that null is not null.
   │ .../GroupLinksFieldTest.php:108

 ✘ Form display shows field
   │ The group community_group "default" form display exists.
   │ Failed asserting that null is not null.
   │ .../GroupLinksFieldTest.php:120

 ✘ Renders external link with rel noopener
   │ The external link anchor carries rel="noopener" (optionally "noopener noreferrer").
   │ Failed asserting that '<div class="group group--default group--community-group">...
   │ (rendered group markup has NO field_group_links output at all — field doesn't exist)
   │ matches PCRE pattern "#<a[^>]+href=\"https://external\.example\.com\"[^>]*rel=\"noopener[^\"]*\"[^>]*>#".
   │ .../GroupLinksFieldTest.php:142

 ✘ Internal link rendered
   │ The internal link title text is rendered.
   │ Failed asserting that '<div class="group group--default group--community-group">...' contains "Internal Page".
   │ .../GroupLinksFieldTest.php:169

 ⚠ Empty state renders nothing   <- passes today (see note)

Tests: 7, Assertions: 156, Failures: 6, Deprecations: 2.
```

**Every failure is the RIGHT reason**: the field storage/instance/display config genuinely does
not exist yet (`FieldStorageConfig::loadByName()` / `FieldConfig::loadByName()` /
`EntityViewDisplay::load()` / `EntityFormDisplay::load()` all return `NULL`), and the rendered
group markup contains no `field_group_links` output at all — not an import/setup/typo error. This
is a valid RED.

**`testEmptyStateRendersNothing` note:** this test passes today because there is currently no
field at all (trivially "renders nothing"). This is intentional and NOT an invalid RED — the test
does not assert on the field's existence, only on the absence of "Links & Resources" markup, which
is true both before AND after F's change (the acceptance criterion is "empty state renders
nothing", which must hold in both the zero-links-configured case pre- and post-implementation).
T-green will re-verify this test still holds once `field_group_links` exists but a seeded/test
group has 0 deltas, which is the real proof this test is pinned to behavior, not vacuously true
forever — the assertion will begin exercising `EntityViewDisplay`'s hide-empty-field logic for the
first time once the field is added, so it remains a meaningful regression guard.

## Ready for F

Confirmed RED is valid (6/7 failing for the right reason; the 7th is a correct, mechanism-agnostic
empty-state assertion that will begin exercising real logic once the field exists). F may
implement against these tests.

## Seed link titles (E2E coordination — F must seed exactly these)

Recorded for F to add in `docs/groups/scripts/step_700_demo_data.php` (append-only "Step 735:
seed group links" section per survey.md):

| Group | Link title | URL |
|---|---|---|
| DrupalCon Portland 2026 | Conference schedule | https://events.drupal.org/portland2026/schedule |
| DrupalCon Portland 2026 | Sponsorship info | https://events.drupal.org/portland2026/sponsors |
| Core Committers | Core Gitlab | https://git.drupalcode.org/project/drupal |
| Core Committers | Core issue queue | https://drupal.org/project/issues/drupal |
| Thunder Distribution | Thunder homepage | https://thunder.org |
| Thunder Distribution | Thunder repo | https://github.com/thunder/thunder-distribution |

`tests/e2e/group-links.spec.ts` currently asserts against the first group
("DrupalCon Portland 2026") and its two titles ("Conference schedule", "Sponsorship info"). The
other 4 (Core Committers, Thunder Distribution) satisfy the brief's "≥3 seeded groups show ≥2
links each" acceptance criterion even though this E2E spec only directly asserts on one of them
(kernel tests already cover the general link-rendering mechanism in isolation, so re-asserting the
same mechanism against 3 different E2E pages would be redundant per the proportionate-suite
principle).

## E2E RED — not gating (documented per instructions)

`npx playwright test tests/e2e/group-links.spec.ts` was **not run** in this session:
`node_modules` is not installed in this worktree and no fully-seeded site (assemble ->
site:install -> cim -> seed step_700_demo_data.php -> runserver) is currently running here. Per
the task instructions, the kernel RED is the gate and this is recorded, not blocking. F/T-green
should run this spec against the seeded site once F has added the field + seeded the 6 links
above; expected RED today would be: "Links & Resources" text absent (field doesn't exist) and/or
`ERR_CONNECTION_REFUSED` if no server is running.

## Environment notes for O/F

- DDEV project for this worktree was renamed `pl-groups-on-d11` -> `gm140-groups-links` in
  `.ddev/config.yaml` to avoid colliding with the currently-running `pl-groups-on-d11` DDEV
  project (same repo, different worktree). **This change is intentionally left unstaged** — it
  is local dev-environment config, not part of the test-authorship diff. F should either keep
  using `gm140-groups-links` (already started, composer-installed) or rename back if preferred;
  either way, no sibling DDEV containers were touched or stopped.
- `ddev composer install` was required (vendor/ absent in this worktree) before
  `scripts/ci/assemble-config.sh` could run (`php` is not on the host PATH; used `ddev exec`
  throughout to mirror what CI's runner does with a native `php`).
- `web/modules/custom/` (assembled by `assemble-config.sh`) is untracked/build-artifact — not
  staged.

## Files to stage (F/O — explicit paths, no `git add .`)

```
git add docs/groups/modules/do_group_extras/tests/src/Kernel/GroupLinksFieldTest.php
git add tests/e2e/group-links.spec.ts
```
