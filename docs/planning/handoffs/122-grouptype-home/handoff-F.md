# Handoff-F: Phase 5 - SC-3 Group-type homepages (GREEN)

**Date:** 2026-07-22
**Branch:** 122-grouptype-home
**Issue:** #122

## What was done

- **`web/themes/custom/groups_chrome/groups_chrome.theme`** — extended
  `groups_chrome_preprocess_group()` to derive `gc_group.leading_section` (`events` |
  `discussion` | `docs` | `null`) from `field_group_type`'s term label, query the group's
  top-3 qualifying nodes via `$group->getRelationships('group_node:{bundle}')` (mirroring the
  existing last-activity block), sort them (events → soonest `field_date_of_event`,
  discussion/docs → newest `created`), build `gc_group.lead_items` / `lead_label` /
  `lead_help_key` / `lead_help_copy` / `lead_see_all_url` / `lead_see_all_text`, attach
  `groups_chrome/group_type_homepage` ONLY inside the non-empty-`lead_items` branch, and
  merge cache metadata (group tags, `node_list:{bundle}`, per-node tags, contexts
  `user.node_grants:view` + `user.group_permissions`). Added the new
  `groups_chrome_theme_suggestions_group_alter()` hook and a private
  `_groups_chrome_leading_section_for_type()` helper (the single term→behavior mapping
  point). Added a file-level `GROUPS_CHROME_LEAD_ITEM_LIMIT` constant (value 3).
- **`web/themes/custom/groups_chrome/groups_chrome.libraries.yml`** — added ONE new entry,
  `group_type_homepage`, depending on `groups_chrome/global`.
- **`web/themes/custom/groups_chrome/templates/group/group--full.html.twig`** — inserted the
  `.gc-group-lead` `<section>` as a sibling BETWEEN `.gc-group-header` and `.gc-group-tabs`
  (moved the tabs `<nav>` out from inside `<header>` to achieve true sibling positioning per
  wireframe §2 — CSS is layout-independent of that nesting, verified no regression), gated on
  `gc_group.lead_items is not empty`. Reuses the exact `GroupTypeContentHelp::infoTrigger()`
  tooltip markup (span + `do-chrome-info` + `tabindex="0"` + `role="note"` + `aria-label` +
  `data-do-tooltip`).
- **NEW** `web/themes/custom/groups_chrome/templates/group/group--community-group--events-first.html.twig`,
  `…--discussion-first.html.twig`, `…--docs-first.html.twig` — near-empty
  `{% include '@groups_chrome/group/group--full.html.twig' %}` passthroughs (wireframe §4,
  option (b)).
- **NEW** `web/themes/custom/groups_chrome/css/group-type-homepage.css` — BEM-scoped under
  `.gc-group-lead`, entirely token-driven (`--gc-space-*`, `--gc-color-*`, `--gc-font-*`), no
  color override on `.gc-group-lead__help` (margin/padding only, per A's Q2 resolution).
- **`docs/groups/modules/do_chrome/src/HelpText.php`** — appended ONE key,
  `group_type.homepage_adapts`, with the exact wireframe-approved copy naming all three
  variants.
- **`docs/groups/scripts/step_700_demo_data.php`** — added a new "Step 740d: Documentation
  pages" block seeding 3 documentation nodes into Thunder Distribution (see "Thunder-docs
  decision" below for full rationale and the Group-4.0.x relationship-type quirk this
  uncovered and worked around).

## Design decisions

- **Tabs moved out of `<header>` to be true siblings of the new lead section.** The wireframe
  (§2) explicitly requires the lead section to be a sibling of BOTH `.gc-group-header` and
  `.gc-group-tabs`, never nested inside either. The pre-#122 markup nested the tabs `<nav>`
  inside `<header class="gc-group-header">`. I moved the `<nav>` to be a direct sibling of
  `<header>` (both still inside the same `{% if gc_group %}` branch). Verified safe: `.gc-group-tabs`'s CSS (`group-page.css`) uses only `margin`/`border-top`, no
  parent-relative selector, so visual layout is unaffected — confirmed by direct-URL render
  inspection on all 4 exemplar/fallback pages (tab bar renders identically, same classes,
  same order, same active-state logic).
- **`field_date_of_event`, not `field_event_date`.** A's own concrete-shape guidance
  (handoff-A.md, "Sort key for events") hedged the field name as `field_event_date` and asked
  F to confirm at implementation time. I read
  `docs/groups/config/field.field.node.event.field_date_of_event.yml` directly (required
  `datetime` field) and used the real name. Sort: soonest-`field_date_of_event`-first;
  undated events (defensive fallback, none exist in seed) sort after dated ones, then by
  `created` DESC among themselves.
- **`class_exists(HelpText::class)` guard on the theme→module call.** The wireframe/A both
  confirm "theme calling a module service class is the established cross-boundary pattern,"
  but I added a defensive `class_exists()` check (falling back to `''` copy, matching
  `HelpText::get()`'s own unknown-key contract) since nothing else in this theme file
  currently has a hard runtime dependency on a specific module being enabled — this keeps the
  preprocess's existing "fail safe, never fatal" posture (every other block in this function
  uses `try/catch`) consistent for this one new cross-boundary call too.
- **`GROUPS_CHROME_LEAD_ITEM_LIMIT` as a `define()`, not an in-function `const`.** My first
  draft used `const LEAD_ITEM_LIMIT = 3;` inside the `if` block inside
  `groups_chrome_preprocess_group()` — this is **illegal PHP** (bare `const` cannot appear
  inside a function body; caught immediately via `php -l`, before ever reaching PHPUnit/E2E).
  Fixed by hoisting to a file-level `define()` guarded by `!defined()` (idempotent if the
  `.theme` file is ever re-included, matching how Drupal loads `.theme` files once per
  request but being defensive regardless).

## Reuse / extend-vs-new

Extended `groups_chrome_preprocess_group()` in place — no parallel preprocess function, no
parallel `$variables['gc_lead']` twin (per A's enforced expectations). Extended
`group--full.html.twig` in place — no `group--full--adaptive.html.twig`. The ONE new object,
`groups_chrome_theme_suggestions_group_alter()`, is exactly the one the brief/survey/A all
explicitly pre-authorized as the sole justified addition (no existing suggestion hook existed
in this theme to extend). No parallel-path risk anywhere in this change.

## Architecture notes for A

- **Layers touched:** theme preprocess (`.theme`), twig templates (4 files: 1 extended, 3 new
  passthroughs), theme CSS (1 new file), theme library registry (1 new entry), one module's
  copy-source class (`do_chrome/src/HelpText.php`, append-only), one seed script
  (`docs/groups/scripts/step_700_demo_data.php`).
- **New dependencies:** none (no new composer packages, no new Drupal modules enabled).
- **Schema/contract changes:** none — no new fields, no new routes, no new config schema. The
  `gc_group` render-array contract gained 6 new keys (`leading_section`, `lead_label`,
  `lead_items`, `lead_help_key`, `lead_help_copy`, `lead_see_all_url`, `lead_see_all_text` —
  7 actually), all additive, none removed or renamed.
- **Cache metadata:** implemented per A's mandatory contract — `$group->getCacheTags()`,
  `node_list:{bundle}`, each rendered node's own tags (via
  `CacheableMetadata::addCacheableDependency()`), contexts `user.node_grants:view` +
  `user.group_permissions`, applied via `CacheableMetadata::applyTo($variables)` merged into
  the render array's `#cache`. Verified this is a genuinely NEW cache-metadata attachment (the
  neighboring last-activity block still has none — a pre-existing gap I did not touch, per
  A's explicit instruction not to fix it drive-by).
- **A Group-4.0.x contrib quirk surfaced and worked around (flagging for A's awareness):**
  `Group::addRelationship($node, 'group_node:documentation')` throws an uncaught
  `AssertionError` (not `\Exception`) for this specific plugin on `community_group`, because
  `GroupRelationshipTypeStorage::getRelationshipTypeId()` always re-derives the "preferred"
  bundle id as `{group_type}-{plugin_id with : -> -}` (here, the 40-char
  `community_group-group_node-documentation`), but Drupal bundle ids cap at
  `EntityTypeInterface::BUNDLE_MAX_LENGTH` (32). The entity actually provisioned at
  group-type-creation time got a different, silently-truncated id
  (`community_group-group_node-doc`), and the re-derivation never consults the DB for that
  fallback — it just always recomputes the "would-be" id fresh. `forum` and `event` both
  happen to compute to exactly 32 chars (fits); `documentation` computes to 40 (doesn't). Fix:
  resolve the actual relationship-type entity by matching `getPluginId()` (not by
  recomputing the id string), then create the `group_relationship` entity directly — the same
  two steps `createForEntityInGroup()` performs internally, minus its broken id
  recomputation. Fully documented inline in the seed script. This is a real,
  reproducible contrib-library gap on Group 4.0.x that any future `group_node:{longbundle}`
  relationship on `community_group` would hit again — worth a note in the project's own
  Group-4.0.x gotcha list (the override doc already tracks a few: `grequest` unusable,
  optional-views collision, `#type=>submit` renders `<input>`).

## Deviations from spec / wireframe

- **Tab `<nav>` moved from inside `<header>` to a sibling position** — see "Design decisions"
  above. This is required BY the wireframe's own explicit sibling-positioning spec (§2), not a
  deviation from it, but flagging since it changes existing DOM structure (verified
  non-regressing via direct render inspection of all 4 states' tab bars).
- **No other deviations from the wireframe or A's binding guidance.** Both of A's Q1 (see-all
  URL = `/group/{gid}/nodes?type=forum|documentation`) and Q2 (no color override on
  `.gc-group-lead__help`) resolutions were implemented exactly as specified.

## Thunder-docs seed decision (T's flag #1)

**Took option (a):** added 3 documentation nodes to Thunder Distribution via a new "Step
740d" block in `docs/groups/scripts/step_700_demo_data.php`, following T's explicit
recommendation ("Prefer (a) — it makes the assertion meaningful"). Rationale: the seed
script is plain PHP under `docs/groups/scripts/` — not a config file, not a module, not
listed in either this story's "owns" or "does NOT touch" sections in the brief (silent on
seed scripts specifically) — and T flagged this as the only way to get a genuine (non-
vacuous) GREEN assertion for the third exemplar. Verified via direct render: Thunder
Distribution's page now shows a real "Documentation" lead section with all 3 seeded nodes,
sorted newest-first, "See all documentation" linking to `/group/4/nodes?type=documentation`.

**Node-query approach in detail (for the record):** identical mechanism to events/discussion
— `$group->getRelationships('group_node:documentation')`, filtered by `isPublished()` +
`$node->access('view', $current_user)`, sorted `created` DESC (documentation has no natural
"soonest" ordering per the wireframe), sliced to top 3.

**Seed-script relationship-creation gotcha (see "Architecture notes for A" above) required a
workaround**, not just a plain `addRelationship()` call like the forum/event blocks use. Also
made the block's node-existence + relationship-existence checks INDEPENDENT (rather than a
single "exists → skip" guard) so a partially-completed prior run (e.g. my own first attempt,
which created the node but failed on the relationship) self-heals on re-run instead of
leaving a permanently orphaned node — verified by running the seed script 3 times in a row:
first run created node 1 + failed on relationship (before my fix); second run (after the fix)
healed the orphan and created 2 fresh nodes+relationships; third run reported all 3
"Exists"/no new relationship attempts (fully idempotent).

## Tier 1 self-check (incl. tests now GREEN)

### PHP lint
```
php -l web/themes/custom/groups_chrome/groups_chrome.theme
  No syntax errors detected
php -l docs/groups/modules/do_chrome/src/HelpText.php
  No syntax errors detected
php -l docs/groups/scripts/step_700_demo_data.php
  No syntax errors detected
```

### PHPUnit Unit — HelpTextTest (11/11 GREEN, incl. the new key)
```
php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox \
  web/modules/custom/do_chrome/tests/src/Unit/HelpTextTest.php
...........                                                       11 / 11 (100%)
 ✔ Group type homepage adapts copy is present and names variants   <- NEW, was RED
 ✔ (10 other pre-existing tests, all still passing)
Tests: 11, Assertions: 100, PHPUnit Deprecations: 12.
```

### PHPUnit Kernel — full custom-module suite (100/100 GREEN, no regression)
```
SIMPLETEST_DB='mysql://db:db@db:3306/db' php vendor/bin/phpunit \
  -c web/core/phpunit.xml.dist --testdox \
  $(find web/modules/custom -type d -path '*/tests/src/Kernel')
Tests: 100, Assertions: 2778, Deprecations: 28, PHPUnit Deprecations: 85.
(0 Failures, 0 Errors — identical count to the pre-implementation baseline I captured
before touching any production file)
```

### phpcs — Drupal standard, delta-vs-baseline analysis
I compared each modified file against its ORIGINAL (pre-#122) `git show HEAD:<path>` version
rather than accepting a raw phpcs count at face value, since both files carry substantial
PRE-EXISTING violations unrelated to this story:

| File | Baseline (errors/warnings) | After my change | Net new |
|---|---|---|---|
| `groups_chrome.theme` | 4 / 7 | 4 / 8 | 0 errors, +1 warning (one unavoidable "hook comment format" flag on my new function — this file's OWN established house style tags every hook docblock `(#NN)` after the closing period, which this specific sniff rejects regardless of story; all 3 pre-existing docblocks in this file already carry the identical warning) |
| `HelpText.php` | 19 / 5 | 18 / 6 | **-1 error**, +1 warning (the +1 is the same pre-existing blank-line-after-comment style already used at every other block boundary in this file, applied consistently to my new block too) |
| `step_700_demo_data.php` | 204 / 3 | 211 / 3 | +7 errors (my `$docs` seed-data array uses the SAME single-line-per-row literal style as every sibling array — `$topics`, `$events`, `$group_defs` — already in this non-Drupal-standard demo/seed script; reformatting only my array would make it inconsistent with its immediate neighbors) |

I fixed the two trivially-fixable, no-tradeoff issues directly: a CRLF line-ending regression
(the `Write`/Python-patch tooling on this Windows host defaulted to CRLF on 2 of my edits;
`.gitattributes` mandates `eol=lf` for `.theme`/`.php` — converted both files to LF, confirmed
`git` no longer reports a normalization warning on them) and one genuinely-avoidable
80-character line wrap in a docblock I authored. I deliberately did NOT fight this file's own
established (non-canonical) conventions for the remaining warnings, since doing so would make
my new code inconsistent with its immediate siblings for zero functional benefit — flagging
this trade-off explicitly rather than silently claiming "phpcs clean."

`step_700_demo_data.php` is a demo-seed utility script (`docs/groups/scripts/`), not linted by
any CI job (confirmed: no `phpcs` invocation anywhere in `.github/workflows/*.yml`) — the
AC's phpcs requirement is satisfied by the two files it actually targets
(`groups_chrome.theme`, `HelpText.php`), both of which introduce net-zero-to-minimal new
debt relative to their own pre-existing baselines.

### Twig / template smoke test — all 4 states HTTP 200, zero Twig/fatal errors
```
gid=1 (DrupalCon Portland 2026, events-first)  HTTP 200, 0 error markers
gid=2 (Drupal France, fallback)                HTTP 200, 0 error markers
gid=3 (Core Committers, discussion-first)      HTTP 200, 0 error markers
gid=4 (Thunder Distribution, docs-first)       HTTP 200, 0 error markers
```

### Theme-suggestion mechanism — proven end-to-end, not just hook-string-correct
Direct hook invocation confirmed the correct suggestion string per exemplar:
```
DrupalCon Portland 2026 => group__community_group__events_first
Core Committers         => group__community_group__discussion_first
Thunder Distribution    => group__community_group__docs_first
Drupal France           => (none)
DrupalCon (view_mode=teaser) => (none, correctly scoped to 'full' only)
```
Since all 3 suggestion-target twigs are markup-identical passthroughs (wireframe §4), a
render-content check alone cannot distinguish "suggestion resolved correctly" from "silently
fell back to `group__full`." I closed this gap with a temporary, then-removed marker: added a
literal `<!-- F-VERIFY-MARKER-EVENTS-FIRST-TEMPORARY -->` to ONLY the events-first suggestion
twig, rebuilt cache, confirmed it appeared exactly once (on DrupalCon's page, zero times on
Core Committers' or Drupal France's pages), then removed it and rebuilt cache again
(confirmed 0 occurrences afterward, feature still renders correctly). This is definitive
proof the suggestion→filename resolution genuinely fires, not just that the alter hook
returns the right string.

### E2E — `tests/e2e/group-type-homepage.spec.ts`: 9/10 GREEN (1 known, T-flagged)
```
BASE_URL="http://gm122-groups-on-d11.ddev.site" npx playwright test tests/e2e/group-type-homepage.spec.ts
  9 passed, 1 failed (Thunder Distribution empty-state test — see "Test flagged for T" below)
```
All 6 genuinely new-feature assertions pass: events-first content+links+see-all, discussion-
first content+links+type-filtered-see-all, fallback contract (no DOM/library/tooltip leak),
tooltip accessibility (tabindex/role/aria-label/focus), and all 4 tab-order regression guards.

### E2E — full regression: 56/58 GREEN, 1 pre-existing skip, 1 known-flagged failure
First clean full-suite run (before repeated-run test-data accumulation, see below):
```
58 tests total: 56 passed, 1 skipped (pre-existing, manage-members.spec.ts's own
  `test.skip()` for a #138 story concern — unrelated to #122), 1 failed (the Thunder
  Distribution test — see below).
```

**IMPORTANT — environment-state finding from repeated full-suite runs (not a code defect):**
Running the FULL E2E suite multiple times against this one long-lived, shared
`gm122-groups-on-d11` DDEV instance (as I did for thorough verification) causes several specs
(`phase1-4.spec.ts`, `showcase.spec.ts`, `manage-members.spec.ts`) to each create their OWN
throwaway test-fixture groups as a side effect. By my 3rd full-suite run, 58 groups existed
(started at 8), and since `/all-groups` sorts `created DESC` with a 25-per-page pager, my 8
original seed groups were pushed to page 3. This broke:
- `directory-cards.spec.ts`'s own first test (asserts a type badge exists ANYWHERE on page 1 —
  its own docblock says "Runs against the seeded demo site (8 groups, one archived)," an
  assumption that was never going to survive indefinite repeated runs against one shared
  instance).
- `group-type-homepage.spec.ts`'s OWN `groupUrlByLabel()` helper, which navigates
  `/all-groups` (page 1 only, no pagination-awareness) to resolve each exemplar's gid.

**I verified my production code is fully unaffected by this**, via direct-URL access
(bypassing the polluted directory entirely):
```
gid=1 (DrupalCon)          HTTP 200, gc-group-lead__item count: 2 (both seeded events)
gid=2 (Drupal France)      HTTP 200, gc-group-lead__item count: 0 (correct fallback)
gid=3 (Core Committers)    HTTP 200, gc-group-lead__item count: 3
gid=4 (Thunder Distribution) HTTP 200, gc-group-lead__item count: 3
```
And confirmed the underlying `all_groups` view + `field_group_type` data are both completely
healthy: `field_group_type` is correctly set on all 4 exemplar groups (verified directly via
entity API); the type badge DOES render for all 7 published seed groups — just on page 3
(`/all-groups?page=2`), not page 1, confirmed via curl. A `?search=DrupalCon+Portland+2026`
query also resolves the group reliably regardless of pagination, proving the search/filter
infrastructure itself is unaffected.

**This is a pre-existing test-environment fragility (shared, never-reset DDEV instance +
tests that create their own fixture data + no-pagination-awareness helpers), not a #122 code
regression.** It would not occur in real CI, where every run gets a genuinely fresh
`site:install` + fresh seed (confirmed by reading `.github/workflows/test.yml`'s own install
steps — no shared state between runs). I stopped re-running the full suite once I identified
the root cause, to avoid compounding it further for whoever runs E2E against this instance
next (T-green / U). Flagging this explicitly for O/T/U rather than silently declaring "full
suite green" — see the flags section below for suggested remediation options.

## Tests that look wrong (for T)

**One test, `group-type-homepage.spec.ts` line ~168 ("Thunder Distribution... degrades to the
empty-state contract")** — T's own handoff flagged this as a "KNOWN WEAK RED" that would need
rewriting once real Thunder-docs content existed. I took option (a) (seeded real content) per
T's own preference ("Prefer (a) — it makes the assertion meaningful"), which means this
test's premise ("zero documentation nodes are seeded... this exemplar's page is
indistinguishable from the fallback case") is now FALSE — a docs-first section genuinely
renders for Thunder Distribution (verified above, 3 items). **I did not edit this test** (T
authors all tests; this is exactly the kind of pre-flagged trade-off T's own handoff called
out in advance). T needs to replace this test's assertions with a POSITIVE docs-first check
(mirroring the DrupalCon/Core Committers pattern: assert `.gc-group-lead` visible, heading
contains "Documentation", 3 item links to `/node/\d+`, "See all documentation" →
`/group/{gid}/nodes?type=documentation`) rather than the current `toHaveCount(0)` assertion.

**Separately (not a test defect, but T should know for T-green):** `groupUrlByLabel()`'s
reliance on `/all-groups` page 1 is fragile against the shared-instance test-data
accumulation documented above. If T-green re-runs this suite multiple times against the same
`gm122-groups-on-d11` instance, this helper will increasingly fail to locate exemplar groups
for reasons entirely unrelated to the feature under test. Suggested remediation (T's/O's
call, not mine to implement): either (a) have `groupUrlByLabel()` use the `?search=<label>`
query param instead of scanning page 1 (verified this resolves reliably regardless of
pagination), or (b) increase the exposed pager's items-per-page for test runs, or (c) start
T-green from a fresh seed rather than continuing to reuse this heavily-polluted instance.

## Known issues

None beyond the flagged test and the environment-state finding above. Every AC bullet is met
by the implementation itself (verified via direct render + direct hook invocation + cache
metadata code review); the one E2E gap is a stale test premise T's own handoff anticipated,
not a missing/incorrect feature.

## Files changed

- `web/themes/custom/groups_chrome/groups_chrome.theme` (modified — extended
  `groups_chrome_preprocess_group()`; added `groups_chrome_theme_suggestions_group_alter()`
  and `_groups_chrome_leading_section_for_type()`; added `GROUPS_CHROME_LEAD_ITEM_LIMIT`
  constant)
- `web/themes/custom/groups_chrome/groups_chrome.libraries.yml` (modified — added
  `group_type_homepage` entry)
- `web/themes/custom/groups_chrome/templates/group/group--full.html.twig` (modified — added
  the `.gc-group-lead` section; repositioned the tab `<nav>` to be a true sibling)
- `web/themes/custom/groups_chrome/templates/group/group--community-group--events-first.html.twig` (new)
- `web/themes/custom/groups_chrome/templates/group/group--community-group--discussion-first.html.twig` (new)
- `web/themes/custom/groups_chrome/templates/group/group--community-group--docs-first.html.twig` (new)
- `web/themes/custom/groups_chrome/css/group-type-homepage.css` (new)
- `docs/groups/modules/do_chrome/src/HelpText.php` (modified — appended
  `group_type.homepage_adapts` key)
- `docs/groups/scripts/step_700_demo_data.php` (modified — added "Step 740d: Documentation
  pages" seed block for Thunder Distribution)

**Not staged/committed by me (per task instructions):** `.ddev/config.yaml` (T's rename,
left as-is), `config/sync/*.yml` (gitignored assembled artifacts), `web/modules/custom/**`
(gitignored assembled artifacts).
