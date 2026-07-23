# Handoff-F: Phase 5 - #127 SD-2 Card ⓘ tooltips

**Date:** 2026-07-23
**Branch:** 127-card-tooltips
**Issue:** #127

## What was done
- `docs/groups/modules/do_chrome/src/HelpText.php` — appended a new `card.*` section (32 lines) after `persona.moderator`, following the file's own comment-block-header-then-entries style. Adds exactly the 5 keys T's RED test pins: `card.directory.type`, `card.directory.members`, `card.stream.byline`, `card.stream.type`, `card.stream.comments`. No existing key touched.
- `web/themes/custom/groups_chrome/groups_chrome.theme` — extended both named preprocess functions with a data-passthrough `tooltips` sub-array (A's soft warning #1, adopted):
  - `groups_chrome_preprocess_node()` (stream_card branch): added `$variables['gc_stream']['tooltips'] = ['byline' => ..., 'type' => ..., 'comments' => ...]`, each value from `HelpText::get('card.stream.*')`.
  - `groups_chrome_preprocess_views_view_fields__all_groups()`: added `$gc['visibility_value']` (captures the raw `field_group_visibility` machine value, defaulted to `'open'` alongside the function's other defaults, set inside the existing visibility `if` block) and `$gc['tooltips'] = ['type' => ..., 'visibility' => HelpText::get('visibility.' . $gc['visibility_value']), 'members' => ...]` (A's soft warning #2 / AC-4 reuse path, adopted).
  - Both call `HelpText::` bare (not fully-qualified `\Drupal\do_chrome\HelpText::`) — the class is already imported at the top of the file and this matches the established call convention at line 665's pre-existing `group_type.homepage_adapts` usage (#122).
- `web/themes/custom/groups_chrome/templates/content/views-view-fields--all-groups.html.twig` — added 3 ⓘ triggers as immediate DOM siblings of the type badge, visibility badge, and members stat, each wrapped in its own `{% if gc_directory.tooltips.X %}` guard.
- `web/themes/custom/groups_chrome/templates/content/node--stream-card.html.twig` — added 3 ⓘ triggers as immediate DOM siblings of the byline row (`</div>` close), the type badge, and the comments anchor/span (`</a>`/`</span>` close) — the comments trigger is explicitly a sibling, never nested inside the `<a>`, so its own `aria-label="@count comments"` never merges with the ⓘ's accessible name.

## Design decisions
- **`tooltips` sub-array naming** (A's soft observation #2): adopted rather than flat `tooltip_type` / `tooltip_visibility` siblings — keeps the existing 12-13-key `gc_directory` / `gc_stream` shapes scannable, and reads naturally as `gc_directory.tooltips.type` in twig.
- **Visibility reuse mechanism**: added one new preprocess field, `visibility_value` (the raw machine value `open`/`moderated`/`invite_only`), defaulted to `'open'` alongside the existing `visibility_label`/`visibility_variant` defaults and set in the same `if` block that already resolves those two. This was necessary because the original code only kept a locally-scoped `$value` variable inside that `if` block — nothing captured the raw machine value into `$gc` for later reuse. Storing it as a new `$gc` key (rather than re-reading the field a second time, or passing `$value` out of scope) matches the function's existing pattern of storing every derived fact on `$gc` once.
- **`HelpText::` bare vs. fully-qualified call style**: the issue prompt suggested a fully-qualified `\Drupal\do_chrome\HelpText::get(...)` as an acceptable fallback if the class wasn't already imported. It is already imported (`use Drupal\do_chrome\HelpText;`, present since #122), and the established call site at that story's own `lead_help_copy` assignment uses the bare form. Followed the existing convention rather than introducing a second calling style in the same file.
- **Twig `{% if %}` guards on every trigger**: per the brief's own instruction ("wrap in `{% if %}` for safety"), each trigger is independently guarded on its own tooltip-copy variable being truthy, not on a single blanket condition — so a future story that empties one copy value (unlikely, since HelpText is append-only, but defensive) degrades that one trigger silently rather than breaking the whole badge cluster.

## Reuse / extend-vs-new
Extended, not new, on every axis named in the brief's Reuse map:
- **HelpText**: appended to the existing literal array (`HelpText::all()`), per the file's own documented "Extension pattern for a new surface" (HelpText.php:18-23). No parallel copy store.
- **Preprocess seams**: extended the two existing functions (`groups_chrome_preprocess_node()` and `groups_chrome_preprocess_views_view_fields__all_groups()`) rather than adding a new hook or a Twig extension — A's Phase-3 PASS explicitly confirmed this is the correct call (decisions.md Finding #1), matching the neighboring #122 precedent at `groups_chrome_preprocess_group()` -> `HelpText::get('group_type.homepage_adapts')`.
- **Visibility copy**: reused `visibility.open` / `visibility.moderated` / `visibility.invite_only` verbatim via a machine-value lookup — zero new visibility copy, single-sourced with the group-edit-form tooltip (AC-4). Verified in the rendered page: a card whose visibility badge reads "Open" carries a `data-do-tooltip` value byte-identical to the e2e spec's own hardcoded `VISIBILITY_OPEN_COPY` constant (confirmed by the e2e test itself passing, and independently by direct curl inspection of the rendered HTML).
- **ⓘ trigger DOM**: inlined the exact same `<span class="do-chrome-info gc-card-info" tabindex="0" role="note" aria-label="{{ copy }}" data-do-tooltip="{{ copy }}">ⓘ</span>` shape used by #89/#122/#126 — no new markup pattern, no new CSS class beyond the existing `gc-card-info` scoping hook the survey already named.
- No new object was created anywhere in this story; the brief's Reuse map called for extension throughout and every touch point followed it.

## Architecture notes for A
- Layers touched: theme preprocess (PHP, `groups_chrome.theme`) and theme templates (Twig) only. `docs/groups/modules/do_chrome` touched ONLY via the append-only `HelpText::all()` literal array — no new PHP class, no new hook, no `.libraries.yml` change (the `do_chrome/tooltips` library is already globally attached by `DoChromeHooks::pageAttachments()`, confirmed unchanged).
- No new dependencies. No schema/contract changes — every new preprocess key is a plain string or a string-keyed sub-array, read-only data derived from data already resolved in the same function (the group's own `field_group_visibility` value, already being read on the very next lines for the existing `visibility_label`/`visibility_variant` resolution).
- Shared components: `HelpText.php` is genuinely shared (both #126 in-flight and this story append to it); disjointness holds — #126 owns `page.*` keys and a new `Hook/PageHelp.php` file, this story owns `card.*` keys only, and both append after the same prior tail entry (`persona.moderator`) with no interleaving edits to any pre-existing line. Verified via `diff` against the pre-edit committed file: my HelpText.php change is 100% additive, zero lines before my insertion point were touched.
- No CSS was added. The survey's "prefer zero CSS if baseline ⓘ inherits reasonably" call held up — `.do-chrome-info { display: inline-block; margin-inline-start: 6px; ... }` renders correctly as a sibling of both `<span>` (badges/stat) and `<a>`/`<span>` (comments) elements without any visual break observable in the rendered HTML/DOM structure. (Full visual verification — screenshot diffing, actual browser rendering inspection — is outside my Tier-1 self-check scope; noting for S/U's Tier-3 pass in case a closer visual look surfaces a spacing nit.)

## Deviations from spec / wireframe
None. D was skipped per the brief (patterned append, no new visual pattern) — implementation matches the brief's exact copy strings, the survey's exact key names, and A's exact confirmed placement pattern.

## Tier 1 self-check (incl. tests now GREEN)

**Assemble:**
```
ddev exec bash scripts/ci/assemble-config.sh
==> assemble-config: repo root = /var/www/html
==> config: copied 95 file(s), excluded 7 env-specific file(s)
==> modules: copied 13 custom module(s) into web/modules/custom/
==> core.extension: registered custom do_* modules + flag as enabled
==> assemble-config: done
```
(Ran via `ddev exec` — the host shell has no `php` on PATH; only the DDEV container does. This matches how the DDEV `.ddev/config.local.yaml` override for this worktree's project is meant to be used; CI itself runs the bare host command since its runner has `php` on PATH directly.)

**Unit** (12/12 GREEN, up from T's RED 11/12):
```
ddev exec php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox web/modules/custom/do_chrome/tests/src/Unit/HelpTextTest.php
...
 ✔ Foundation demo copy is present
 ✔ Unknown key returns empty string
 ✔ Audience copy is present
 ✔ Visibility copy is present plain text and honest
 ✔ Archive pin control copy is present and plain text
 ✔ Report flag control copy is omitted
 ✔ Group type field copy names all types
 ✔ Content type field copy names all types
 ✔ Permission matrix panel copy is present
 ✔ Group type homepage adapts copy is present and names variants
 ✔ Card tooltip copy is present and plain text
 ✔ All returns string map

OK, but there were issues!
Tests: 12, Assertions: 163, PHPUnit Deprecations: 13.
```
(The "PHPUnit Deprecations: 13" and the leading "HTML output directory ... not writable" notice are pre-existing environment noise, unrelated to this story — present identically before my change, per T's RED baseline.)

**E2E — target spec** (7/7 GREEN, up from T's RED 0/7):
```
BASE_URL="https://gm127-card-tooltips.ddev.site" npx playwright test tests/e2e/element-tooltips.spec.ts
  ok 1 ... type, visibility, and member-count triggers carry the full tooltip contract
  ok 2 ... hovering a directory-card trigger shows a tippy tooltip
  ok 3 ... visibility ⓘ copy is single-sourced from the reused visibility.* HelpText key
  ok 4 ... no double-tooltip: card triggers are scoped inside .gc-card, not duplicated
  ok 5 ... byline, content-type, and comments triggers carry the full tooltip contract
  ok 6 ... hovering a stream-card trigger shows a tippy tooltip
  ok 7 ... no double-tooltip: stream-card triggers are scoped inside .gc-card, not duplicated

7 passed (8.8s)
```

**E2E — regression spec** (3/3 GREEN, unchanged from baseline):
```
BASE_URL="https://gm127-card-tooltips.ddev.site" npx playwright test tests/e2e/directory-cards.spec.ts
  ok 1 ... anonymous sees cards with type + visibility badges and member counts
  ok 2 ... anonymous gets a "View group" affordance, never a Join button
  ok 3 ... a logged-in member sees the "Member" note on groups they belong to

3 passed (10.6s)
```

**Both together, final confirmation** (10/10 GREEN):
```
BASE_URL="https://gm127-card-tooltips.ddev.site" npx playwright test tests/e2e/element-tooltips.spec.ts tests/e2e/directory-cards.spec.ts
10 passed (7.3s)
```

**Lint** (`phpcs --standard=Drupal,DrupalPractice` on the append-only file):
Reported 18 errors / 8 warnings — but **every single flagged line number (21, 43, 57, 78, 110, 114, 119-129, 134, 137-148, 178) falls strictly BEFORE my insertion point (line 210)**. I confirmed this by running the exact same `phpcs` invocation against the untouched, already-committed pre-edit copy of the file (renamed with a `.php` extension so phpcs would process it) and got the identical 19 errors / 8 warnings on the identical line numbers (the "19 vs 18" difference is a filename-vs-classname false positive that only exists because of the temporary rename, not a real difference). My own 32 appended lines (210-241) triggered **zero** new phpcs findings. This is pre-existing lint debt in the file predating this story — flagging it below rather than "while I'm here" fixing it, per the no-drive-by-refactor discipline (fixing 24 lines of unrelated pre-existing formatting is out of this story's scope and would inflate the diff A has to review).

## Tests that look wrong (for T)
None. All 2 authored test files (the e2e spec and the extended unit test) pinned exactly the contract the brief specified, and both are now GREEN with no test edits.

One environmental note, not a test-authorship issue: `element-tooltips.spec.ts`'s directory-card assertions use `.gc-directory-card.first()`, which is sensitive to the `all_groups` view's `created DESC` sort order. During my own regression verification I incidentally ran `phase1.spec.ts` and `phase2.spec.ts` (checking they still pass after my theme change), which create fixture groups with no `field_group_type` set — those fixture groups, being newest, sorted to "first" and caused a transient 2/7 failure (0 elements for the type-trigger locator; 2-not-3 trigger count) purely because `.first()` landed on a type-less card, not because of anything my implementation does. I deleted those artifact groups (IDs 9-12) and the suite returned to 7/7 clean. This is not a flaw in T's spec (the RED baseline was captured before any such artifact existed) — just a reminder that this spec's `.first()` locator is coupled to "whichever card sorts first," which any test suite that creates un-typed groups (including any future one) can transiently perturb. Not blocking; noting for awareness only, since T authored against a clean seed and the coupling is inherent to reusing `.first()` rather than filtering for a specific known seeded group (the same pattern the pre-existing `directory-cards.spec.ts` and my own diagnosis both rely on to identify a *specific* card by title when precision is needed).

## Known issues
None. All acceptance criteria from the brief are met:
1. Type/visibility/members ⓘ on `/all-groups` — confirmed rendered + hoverable.
2. Byline/type/comments ⓘ on `/stream` — confirmed rendered + hoverable.
3. No double-tooltip — card triggers exist only inside `.gc-directory-card` / `.gc-stream-card`; #126 (page-level) is a disjoint file set, not yet touching these templates.
4. Visibility reuse single-sourced — confirmed both by the e2e assertion (`data-do-tooltip` equals the exact `visibility.open` string) and by direct HTML inspection across multiple visibility states (Open, Invite Only both observed rendering their correct reused copy).
5. Existing suite green — `directory-cards.spec.ts` 3/3, `HelpTextTest.php` 12/12 (11 pre-existing + 1 newly-targeted).
6. `element-tooltips.spec.ts` 7/7 — 6-element contract (3+3) + tabindex/aria-label/tippy-hover all asserted and passing.
7. WCAG 2.2 AA — `tabindex="0"` + `role="note"` + non-empty `aria-label` on every trigger (verified by the e2e's `expectTooltipTrigger()` helper on all 6 elements); contrast inherits the established `.do-chrome-info` baseline (5.36:1, AA, from #122) since no new CSS was added.

## Files changed
- `docs/groups/modules/do_chrome/src/HelpText.php`
- `web/themes/custom/groups_chrome/groups_chrome.theme`
- `web/themes/custom/groups_chrome/templates/content/views-view-fields--all-groups.html.twig`
- `web/themes/custom/groups_chrome/templates/content/node--stream-card.html.twig`
