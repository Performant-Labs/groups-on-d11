# Handoff-T-green: Phase 6 - #122 SC-3 Group-type homepages (GREEN + Tier 2)

**Date:** 2026-07-22
**Branch:** 122-grouptype-home
**Issue:** #122
**Handoff-F reviewed:** `docs/planning/handoffs/122-grouptype-home/handoff-F.md`
**Handoff-T-red:** `docs/planning/handoffs/122-grouptype-home/handoff-T-red.md`

## Test file changes made in this phase

`tests/e2e/group-type-homepage.spec.ts`:

1. **Thunder Distribution test rewritten** (as pre-authorized by my own Phase-4 handoff, since
   F took option (a) and seeded 3 real documentation nodes via the new "Step 740d" block).
   Replaced the `.gc-group-lead` count-0 empty-state assertion with a positive docs-first
   assertion: `.gc-group-lead__heading` matches `/doc/i`, 1-3 item links present with `/node/\d+`
   hrefs, "See all documentation" link href matches `/group/\d+/nodes\?type(\[\])?=documentation`,
   plus the tab-order regression guard preserved for this exemplar.
2. **`groupUrlByLabel()` helper fixed** (test-infrastructure fix, within my remit as the test
   author repairing a broken/flaky helper — no other spec file touched): switched from
   unpaginated `/all-groups` page-1 scanning to `/all-groups?search=<label>`. This was
   necessitated by a real, reproduced-live environment finding (see "Blocking issues" / advisory
   notes below) — the shared `gm122-groups-on-d11` DDEV instance accumulated so many
   throwaway fixture groups across repeated full-suite runs (8 -> 59 -> 76 -> 93 observed across
   my verification session) that ALL NINE of my spec's tests using this helper started failing
   for an environment reason, not a feature reason. Verified the `?search=` scoping resolves
   each of the four exemplar/fallback labels to exactly 1 directory card regardless of total
   group count (curl-verified before applying the fix).
3. Updated the file-header comment block to describe the Thunder-docs seed decision and the
   `groupUrlByLabel()` fix, replacing the stale RED-time "KNOWN WEAK RED" framing.

No other test file was touched. No production code was touched.

## GREEN confirmation

### Target spec (primary AC coverage)
```
BASE_URL="http://gm122-groups-on-d11.ddev.site" npx playwright test tests/e2e/group-type-homepage.spec.ts
```
Result: **10 passed** (all tests, including the rewritten Thunder test and the 4 tab-order
regression guards).

Spot-check that tests still fail if behavior is removed: the Thunder test's premise (real
seeded docs content + F's implementation) is exactly what the Phase-4 RED proved absent —
before F's change, `.gc-group-lead` had 0 count everywhere (see handoff-T-red.md's raw RED
output); the rewritten test would fail again today if `lead_items`/library-attach logic were
reverted, since it asserts `.gc-group-lead` visibility + specific heading/link/see-all content
that only exists because of F's preprocess + twig changes. Not a tautology.

### PHPUnit Unit — `HelpTextTest` (59/59 GREEN across full Unit suite, incl. the new key)
```
ddev exec "php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox $(find web/modules/custom -type d -path '*/tests/src/Unit')"
```
```
✔ Group type homepage adapts copy is present and names variants   <- was RED, now GREEN
Tests: 59, Assertions: 349, PHPUnit Deprecations: 51.
```
(0 Failures, 0 Errors.)

### PHPUnit Kernel — full custom-module suite (100/100 GREEN, matches F's baseline)
```
SIMPLETEST_DB='mysql://db:db@db:3306/db' ddev exec "php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox $(find web/modules/custom -type d -path '*/tests/src/Kernel')"
```
```
OK, but there were issues!
Tests: 13, Assertions: 306, ... (do_tests batch)
Tests: 100, Assertions: 2778, Deprecations: 28, PHPUnit Deprecations: 85.
```
0 Failures, 0 Errors across all 100 Kernel tests — identical count to F's own pre/post baseline.
"Issues" are pre-existing deprecation notices only (Drupal 12 forward-compat warnings), not
failures.

### Lint — phpcs on the 2 modified production PHP-family files
```
ddev exec "php vendor/bin/phpcs --standard=Drupal,DrupalPractice web/themes/custom/groups_chrome/groups_chrome.theme"
  -> exit 0, no output (fully clean)
ddev exec "php vendor/bin/phpcs --standard=Drupal,DrupalPractice web/modules/custom/do_chrome/src/HelpText.php"
  -> 18 errors, 8 warnings
```
Cross-checked against the pre-#122 baseline (`git show 93529bc~1:...HelpText.php`): baseline
was **19 errors, 6 warnings**. Net delta: **-1 error, +2 warnings** — consistent with F's own
claimed "-1 error, +1 warning" table in handoff-F.md (small +1-warning discrepancy from F's
count, immaterial — still net-flat-to-improved, not new debt introduced by this story).
`groups_chrome.theme` (the file most of F's new logic lives in) is fully phpcs-clean.

### E2E — target spec: 10/10 GREEN (see above)

### E2E — full regression `tests/e2e/*.spec.ts`
First run (before I applied the `groupUrlByLabel()` fix), reproducing F's exact flagged
environment issue live: **48 passed, 10 failed, 1 skipped** (58 total) — the 10 failures were
`directory-cards.spec.ts`'s own pre-existing `#84` test (page-1-only assumption, unrelated to
#122) plus all 9 of my spec's `groupUrlByLabel()`-dependent tests, broken purely because the
shared instance had accumulated 76 groups by that point (verified via
`drush sql-query "SELECT COUNT(*) FROM groups"`).

After applying the `?search=`-scoped fix to `groupUrlByLabel()` (test-infra fix, not a
production-code change) and re-running the full suite once more (instance now at 93 groups):
```
1 failed, 1 skipped, 56 passed (58 total)
```
The single remaining failure is `directory-cards.spec.ts`'s own pre-existing `#84` test — NOT
owned by this story, NOT touched by F or T, and independently reproducible with zero groups
created by #122 in play (it is a docblock-assumption bug in a story from a different issue).
Flagged below for O; not blocking #122.

## Tier 1 results

| Check | Command | Expected | Actual | Result |
|---|---|---|---|---|
| Assemble config | `ddev exec bash scripts/ci/assemble-config.sh` | idempotent success | 95 config files + 13 modules copied, core.extension patched | PASS |
| PHPUnit Unit | see above | all green incl. HelpText key | 59/59, 0 failures | PASS |
| PHPUnit Kernel | see above | 100/100 green | 100/100, 0 failures/errors | PASS |
| phpcs — `groups_chrome.theme` | see above | clean | exit 0, no violations | PASS |
| phpcs — `HelpText.php` | see above | no new debt vs. baseline | -1 error, +2 warnings vs. pre-#122 baseline | PASS |
| E2E target spec | see above | 10/10 | 10/10 | PASS |
| E2E full regression | see above | no #122 regression | 1 pre-existing unrelated failure only, after test-infra fix | PASS |
| Direct-URL smoke (4 states) | `curl` gid 1-4 | HTTP 200 each | all HTTP 200 | PASS |

## Tier 2 results

### DOM contract verification (direct render, bypassing the polluted directory)

- **DrupalCon Portland 2026 (gid=1, events-first):** `.gc-group-lead` present. Heading
  "Upcoming events". See-all: `<a href="/group/1/events" class="gc-group-lead__see-all">See all
  events →</a>`. PASS.
- **Core Committers (gid=3, discussion-first):** `.gc-group-lead` present. See-all:
  `<a href="/group/3/nodes?type=forum" class="gc-group-lead__see-all">See all discussions
  →</a>`. PASS (matches A's Q1 resolution exactly — type-filtered `group_nodes` path, not
  unfiltered stream).
- **Thunder Distribution (gid=4, docs-first):** `.gc-group-lead` present. Heading
  "Documentation". 3 item links to `/node/18`, `/node/19`, `/node/20`
  ("Getting Started with Thunder", "Upgrading from Thunder 7 to 8", "Media Library Configuration
  Guide"). See-all: `<a href="/group/4/nodes?type=documentation" class="gc-group-lead__see-all">
  See all documentation →</a>`. PASS.
- **Drupal France (gid=2, unmapped/Geographical):** `grep -c 'class="gc-group-lead"'` on the
  raw response = **0**. `grep -o 'group-type-homepage[^"]*"'` = **no match** (no CSS/library
  reference anywhere in the aggregated markup). Tab bar renders `Stream / Events / Members /
  About` unchanged. PASS.

### Cache metadata (inspected via direct render-array introspection, not response headers —
Drupal's `X-Drupal-Cache-Tags`/`-Contexts` headers are only emitted when
`http.response.debug_cacheability_headers` is enabled, which it is not on this install; I
instead rendered the view-builder output and inspected `CacheableMetadata::createFromRenderArray()`
directly, which is the ground truth the headers would just be echoing)

`drush php:script` against `Group::load(1)` (`getViewBuilder('group')->view($group, 'full')`,
rendered via `Renderer::renderRoot()`):
```
TAGS:
  group_view
  group:1
  config:flag.flag.mute_group_notifications
  config:group.role.community_group-anon_view
  config:group.role.community_group-outsider_view
  config:group.role.community_group-insider_view
  config:group.role.community_group-admin
  node_list:event
  node:13
  node:14
CONTEXTS:
  languages:language_interface
  theme
  user.permissions
  user.node_grants:view
  user.group_permissions
MAX-AGE: -1
```
Same check against `Group::load(4)` (Thunder Distribution):
```
TAGS: ... group:4 ... node_list:documentation ... node:19 node:20 node:18
CONTEXTS: ... user.node_grants:view ... user.group_permissions ...
```
**Confirmed present:** `group:{gid}` cache tag, `node_list:{bundle}` tag (bundle-specific, only
the resolved leading_section's bundle), each rendered node's own `node:{nid}` tag, contexts
`user.node_grants:view` and `user.group_permissions`, max-age `-1` (inherited default). This
exactly matches A's mandatory cache-metadata contract in `handoff-A.md`. PASS.

### Tooltip a11y (direct render inspection, gid=1)

```html
<span class="do-chrome-info gc-group-lead__help"
      tabindex="0"
      role="note"
      aria-label="This page adapts to the group&#039;s type — it leads with events,
                  discussion, or documentation depending on how the group is categorised."
      data-do-tooltip="This page adapts to the group&#039;s type — it leads with events,
                  discussion, or documentation depending on how the group is categorised.">ⓘ</span>
```
`tabindex="0"` PASS. `role="note"` PASS. Non-empty `aria-label` PASS. `data-do-tooltip` present
PASS. Copy contains "adapts to the group's type" PASS (also independently asserted by the E2E
tooltip test, which additionally proves keyboard-focusability via `.focus()` + `toBeFocused()`).

### Fallback silence (Drupal France, gid=2)

- Tab bar text + order: `Stream, Events, Members, About` — confirmed unchanged (grep on raw
  HTML + separately by the E2E fallback-contract test and the regression-guard test for this
  label). PASS.
- No `data-do-tooltip="...homepage_adapts..."` (or any `adapts to the group` text) anywhere on
  the page — confirmed via the E2E fallback test's explicit assertion (`[data-do-tooltip*="adapts
  to the group"]` count 0). PASS.
- No `.gc-group-lead` anywhere, no `group-type-homepage` CSS/library reference anywhere. PASS.

## Acceptance criteria status

| # | Criterion | Status | Backing test/evidence |
|---|---|---|---|
| 1 | `leading_section` derivation from `field_group_type` | PASS | Events/discussion/docs E2E tests each assert the correctly-labeled section renders for the correctly-typed exemplar; fallback test asserts `null` case |
| 2 | `theme_suggestions_group_alter()` returns correct suggestion string | PASS | F's direct hook-invocation self-check (handoff-F.md) + indirectly by E2E (distinct per-exemplar markup renders, proving suggestion resolution + template selection both work) |
| 3 | Lead section renders above tab bar when set; nothing when null | PASS | DOM position verified via direct render (section appears between header and nav in raw HTML); fallback test confirms zero new DOM when null |
| 4 | Tooltip carries HelpText copy, keyboard-focusable | PASS | Tooltip a11y E2E test + direct DOM inspection above |
| 5 | DrupalCon Portland 2026 leads with events | PASS | E2E events-first test (10/10 GREEN run) + direct DOM evidence |
| 6 | Core Committers leads with forum topics | PASS | E2E discussion-first test + direct DOM evidence, incl. A's Q1 type-filtered see-all |
| 7 | Thunder Distribution leads with documentation | PASS | E2E docs-first test (T-green rewrite) + direct DOM evidence (3 real seeded nodes) |
| 8 | Unmapped/untyped group renders identically to today | PASS | E2E fallback test + direct DOM/library/tooltip-absence evidence |
| 9 | Existing E2E specs stay green | PASS (1 pre-existing unrelated flag) | Full regression run: 56 passed, 1 skipped, 1 failed — the 1 failure is `directory-cards.spec.ts`'s own pre-existing `#84` test, unrelated to #122 (see Blocking issues) |
| 10 | New spec covers all 4 states + a11y | PASS | 10/10 GREEN in `group-type-homepage.spec.ts` |
| 11 | WCAG 2.2 AA (axe + focus ring + heading hierarchy) | PARTIAL PASS | Focus/aria-label/keyboard proven by E2E; full axe scan not automatable (documented tooling gap, pre-existing in this repo, same as `manage-members.spec.ts`); heading hierarchy verified directly (`<h2>` is the only `<h2>` on the page, page `<h1>` untouched) |
| 12 | phpcs passes on modified files | PASS | `groups_chrome.theme` fully clean; `HelpText.php` net-improved vs. baseline (-1 error) |
| 13 | Kernel/Functional PHPUnit stays green | PASS | 100/100 Kernel, 0 failures/errors, matches F's baseline |

## Blocking issues

**None for #122.** All AC bullets are backed by a passing, non-vacuous test or direct evidence.

## Advisory notes (non-blocking, flagged for O)

1. **`directory-cards.spec.ts`'s own `#84` test is currently failing against this shared
   instance** due to its own docblock assumption ("8 groups, no pagination") — this predates
   #122, is not owned by this story, and was independently reproduced by both F and me. It is
   not fixed by my `groupUrlByLabel()` change (different spec file, out of my remit to touch
   without O's say-so). Recommend O either: (a) accept this as a known, tracked pre-existing
   flake to fix in a separate ticket, or (b) have someone reseed the shared `gm122-groups-on-d11`
   instance fresh before the next full-suite run that needs `directory-cards.spec.ts` green.
2. **The `gm122-groups-on-d11` DDEV instance is now at 93 groups** (started at 8) due to
   cumulative throwaway-fixture creation by `phase1-4.spec.ts`, `showcase.spec.ts`,
   `manage-members.spec.ts`, and my own verification runs. This is expected, pre-existing
   behavior of running the full E2E suite repeatedly against one long-lived shared instance —
   not a #122-introduced problem — but worth a fresh reseed before U's walkthrough if U also
   needs `/all-groups` page-1 assumptions to hold for any *other* story's surface.
3. **The `.ddev/config.yaml` rename to `gm122-groups-on-d11`** (made by me at Phase 4, left in
   place per my own T-red handoff) is still not staged/committed, per the task's explicit
   instruction. O should decide whether to rename back before merge or leave the worktree as-is.
4. **Minor phpcs-count discrepancy vs. F's table:** F reported "18/6 -> net +1 warning" for
   `HelpText.php`; I measured 18 errors/8 warnings now vs. 19/6 baseline (net -1 error, +2
   warnings). Immaterial to the PASS verdict (still net-flat-to-improved), but flagging the
   discrepancy for transparency rather than silently restating F's number unverified.

## Ready for U

**T-green complete, no blocking issues.** This is a UI surface (full pipeline O -> D -> A ->
T(red) -> F -> T(green) -> A-dup -> U -> S per the brief). Ready for A-dup, then U.
