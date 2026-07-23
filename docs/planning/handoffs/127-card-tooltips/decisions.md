# Decisions — #127 SD-2 Card ⓘ tooltips

Append-only journal. Every phase writes its own entry.

## Phase 1 — O (Orchestrator)
- **Decided:** Skip D per lean POC pipeline — the ⓘ affordance is 100% patterned on #89/#122/#126 (same DOM, same class, same JS). No wireframe adds signal here.
- **Decided:** File set = 2 theme twigs + `groups_chrome.theme` preprocess extension + HelpText append + 1 new e2e spec. Disjoint from #126 (verified by reading `~/Projects/_worktrees/groups-page-tooltips/…/brief.md`).
- **Decided:** Namespace new keys under `card.*` (subnamespaces `card.directory.*` and `card.stream.*`) — clearly disjoint from #126's `page.*` namespace.
- **Decided:** REUSE `visibility.open|moderated|invite_only` for visibility badge ⓘ (issue explicitly requires reuse where copy exists). Add 5 new keys (issue estimated ~8; we're under because we honestly omitted keys for elements whose data doesn't exist yet — language chip, pinned badge, event-date chip).
- **Assumed:** Extending `groups_chrome.theme` preprocess to pass tooltip copy into twig is acceptable — theme file is NOT gitignored, and extending an existing preprocess to add data-passthrough variables is not the same as adding logic. The issue's "twig-template-only in Wave 1" language targets `.libraries.yml` (asset-pipeline coupling with #122), not preprocess data. **Flagged for A confirmation.**
- **Assumed:** Baseline `.do-chrome-info` CSS renders acceptably inside a card without new scoped rules — defer to F's Tier-1 visual check.
- **Evidence:** Read of `HelpText.php`, both twig templates, `groups_chrome.theme` lines 700-800, `DoChromeHooks::pageAttachments()`, #126 brief, WAVE-EXECUTION-HANDOFF §4/§6/§7, PROJECT_CONTEXT.md.

## Phase 3 — A (up-front architecture review)

**Verdict:** PASS — T may proceed to author RED.

### Summary
Plan is architecturally consistent with the established `do_chrome` tooltip pattern (#88/#89/#122/#126). Extend-vs-new posture is correct: the two existing preprocess functions in `groups_chrome.theme` are the right seams to extend, and appending to `HelpText::all()` matches the file's own documented "Extension pattern for a new surface" contract (HelpText.php:18-23). Disjointness with #126 verified. No blocks. Two soft observations for T/F.

### Findings

| # | Severity | Plan element | Drift dimension | Finding | Suggested guidance |
|---|---|---|---|---|---|
| 1 | soft | Wave-1 guardrail literal read (Option b: Twig extension) | dependency direction / abstraction level | Option (a) — extending existing preprocess to pass tooltip copy as `$variables['gc_directory']['tooltip_type']` etc. — is the correct call. No Twig extension (`Drupal\Core\Template\TwigExtension` subclass) exists anywhere in `docs/groups/modules/do_*` today (grep confirms zero hits). Introducing one now would invent a new abstraction layer solely for this story — the opposite of the reuse-first discipline. The issue's "no `.theme` edits" line is guarding the **`.libraries.yml` / asset-pipeline coupling with #122**, not preprocess data passthrough. Preprocess extension IS the neighboring pattern (see #122's own `groups_chrome_preprocess_group()` reading `HelpText::get('group_type.homepage_adapts')` — HelpText.php:180-193 documents this exact pattern). **Confirmed acceptable.** | Proceed with Option (a). T's RED can assert `$variables['gc_directory']['tooltip_type']` and `$variables['gc_stream']['tooltip_byline']` (or equivalent) are populated by the extended preprocess. |
| 2 | soft | Naming of new preprocess-array keys | naming | Brief shows `$variables['gc_directory']['tooltip_type']` as illustrative. Recommend a `tooltips` sub-array (e.g. `gc_directory.tooltips.type`, `gc_directory.tooltips.visibility`, `gc_directory.tooltips.members`) so the surface stays scannable and doesn't pollute the top-level `gc_directory` keyspace with 3 more flat siblings alongside `type_label`, `visibility_label`, etc. Same for `gc_stream.tooltips.{byline,type,comments}`. Not a block — flat naming works — but a grouped sub-array reads better next to the existing 12-key `gc_directory` shape. | F's call. If flat is chosen, use `tooltip_*` prefix consistently. |
| 3 | pass | Extend-vs-new (preprocess seams) | layering | The two existing preprocess functions (`groups_chrome_preprocess_views_view_fields__all_groups` at line 721 and `groups_chrome_preprocess_node` at line 76) are the correct objects to extend. Both already assemble `gc_directory` / `gc_stream` metadata by reading from HelpText's neighbors (group entity, term labels, allowed_values). Adding `HelpText::get()` reads to the same shape is a natural extension, not a parallel path. | — |
| 4 | pass | HelpText append-only invariant | pattern consistency | 5 new keys (`card.directory.type`, `card.directory.members`, `card.stream.byline`, `card.stream.type`, `card.stream.comments`) append after `persona.moderator` (HelpText.php:209). Namespace `card.*` is disjoint from every existing prefix (`demo.`, `audience.`, `visibility.`, `archive.`, `pin.`, `promote.`, `follow.`, `group_type.`, `content_type.`, `permissions.`, `showcase.`, `persona.`, and #126's `page.*`). Contract at HelpText.php:11-13 explicitly names this pattern. | — |
| 5 | pass | Disjointness with #126 | dependency direction | File sets disjoint: #126 owns `Hook/PageHelp.php` (NEW) + `page.*` keys; #127 owns 2 twigs + preprocess-extension in `groups_chrome.theme` + `card.*` keys. HelpText is the only shared file — append-only contract makes concurrent appends safe (different namespaces, both after `persona.moderator`). DOM disjoint: card ⓘ is inside `.gc-card`, page ⓘ is inside `page_title.html.twig`'s `title_suffix` slot. No double-tooltip risk. | — |
| 6 | pass | Behavior-changes guardrail (markup + copy only) | pattern consistency | Plan adds inline `<span>` triggers only; no route, entity, field, or schema changes. Language chip / pinned badge / event-date chip correctly omitted — their backing data does not exist in `gc_directory` / `gc_stream` preprocess arrays (verified against `groups_chrome.theme:734-749` for directory, and stream preprocess at line 76+). Adding them would require new preprocess variables and new field reads = scope creep. Honest omission is the right call. | If a follow-up story adds pinned/event/language data, append `card.stream.pinned` / `card.stream.event_date` / `card.directory.language` under the same `card.*` namespace. |
| 7 | pass | A11y DOM baseline | cross-cutting consistency | `tabindex="0"` + `role="note"` + `aria-label="{copy}"` + `data-do-tooltip="{copy}"` matches #89 `GroupTypeContentHelp::infoTrigger()` and #122's `groups_chrome_preprocess_group()` rendering and #126's `PageHelp::preprocessPageTitle()`. Baseline contrast 5.36:1 (AA) established by #122 carries over. | — |

### Notes for T
- RED can assert both preprocess extensions populate their tooltip copy from `HelpText::get('card.…')` (single-source assertion — no duplicated string literals in the spec). Additionally assert the visibility tooltip resolves via `HelpText::get('visibility.' . $variables['gc_directory']['visibility'])` to prove the reuse path.
- E2E should assert 6 elements carry `data-do-tooltip` (3 directory + 3 stream) plus `tabindex="0"`, `role="note"`, non-empty `aria-label`, and at least one tippy-visible on hover, per the brief's AC-6.

### Patterns referenced
- `docs/groups/modules/do_chrome/src/HelpText.php` (append-only contract at lines 11-13, 18-23; existing key catalog)
- `docs/groups/modules/do_chrome/src/Hook/GroupTypeContentHelp.php` (ⓘ trigger DOM pattern)
- `web/themes/custom/groups_chrome/groups_chrome.theme:76` (`preprocess_node` — `gc_stream` shape)
- `web/themes/custom/groups_chrome/groups_chrome.theme:721` (`preprocess_views_view_fields__all_groups` — `gc_directory` shape)
- `~/Projects/_worktrees/groups-page-tooltips/docs/planning/handoffs/126-page-tooltips/brief.md` (sibling story — disjointness verified)

## Phase 4 — T(RED)

**Verdict:** RED confirmed valid. F may implement.

### Tests authored
1. **`tests/e2e/element-tooltips.spec.ts`** (NEW, 7 tests):
   - Directory card (`/all-groups`, anonymous): type/visibility/members triggers carry `data-do-tooltip` + `tabindex="0"` + `role="note"` + non-empty `aria-label`, scoped adjacent to their badge/stat (not merged into it).
   - Directory card: hovering a trigger shows a tippy tooltip (`.tippy-box, [data-tippy-root]`).
   - Directory card: visibility ⓘ copy is single-sourced — equals the hardcoded `HelpText::get('visibility.open')` string (verbatim from HelpText.php) for a card whose visibility badge reads "Open" — proves the REUSE path, not a new key.
   - Directory card: exactly 3 triggers per card (no double-tooltip / no stray duplicate).
   - Stream card (`/stream`, anonymous): byline/type/comments triggers carry the same 3-attribute contract; comments trigger asserted to be a SIBLING of `.gc-stream-card__comments` (not nested inside it, since that anchor already carries its own `@count comments` aria-label — guards against accessible-name merging).
   - Stream card: hovering a trigger shows a tippy tooltip.
   - Stream card: exactly 3 triggers per card.
2. **`docs/groups/modules/do_chrome/tests/src/Unit/HelpTextTest.php`** (EXTENDED, +1 method `testCardTooltipCopyIsPresentAndPlainText`): asserts the 5 new `card.*` keys (`card.directory.type`, `card.directory.members`, `card.stream.byline`, `card.stream.type`, `card.stream.comments`) are literal keys in `HelpText::all()`, non-empty, plain text, and each names the vocabulary the brief specifies (group types / content types / "posted" + "group" / "replies|comment"). Visibility reuse is already covered by the pre-existing `testVisibilityCopyIsPresentPlainTextAndHonest` — not duplicated.

Tier: e2e for DOM/a11y/hover contract (cheapest sufficient tier that can observe rendered markup + tippy JS); unit for the copy-source contract (no Drupal bootstrap needed, matches the file's own existing per-surface pattern).

### Environment stood up for RED
No worktree DDEV project existed yet (fresh worktree). Built `gm127-card-tooltips` (untracked `.ddev/config.local.yaml` override — `pl-groups-on-d11` was already running against the primary checkout and would have collided). `ddev composer install`, assembled config via `ddev exec bash scripts/ci/assemble-config.sh`, installed **standard** profile (not minimal — matches `.github/workflows/test.yml`'s recipe), set matching site UUID, `config:import`, enabled the do_* modules, seeded via `docs/groups/scripts/step_700/720/780/790` (mirrors CI's seed step exactly), admin/admin. `web/sites/default/settings.php` got one local, gitignored line adding `$settings['config_sync_directory'] = '../config/sync'` before the `settings.ddev.php` include (DDEV's default sync dir is `sites/default/files/sync`, which is not where assemble-config.sh places config) — this file is gitignored, not committed, and not part of the test-authorship diff.

### RED confirmation
**Unit** — `ddev exec php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox web/modules/custom/do_chrome/tests/src/Unit/HelpTextTest.php`:
```
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
✘ Card tooltip copy is present and plain text
  "card.directory.type" must be a literal key in HelpText::all() (append-only contract).
  Failed asserting that an array has the key 'card.directory.type'.
  /var/www/html/web/modules/custom/do_chrome/tests/src/Unit/HelpTextTest.php:246
✔ All returns string map
Tests: 12, Assertions: 120, Failures: 1.
```
All 11 pre-existing tests unaffected (still green); only the new targeted test fails, on the first missing key — right reason (missing append), not a setup/typo error.

**E2E** — `BASE_URL="https://gm127-card-tooltips.ddev.site" npx playwright test tests/e2e/element-tooltips.spec.ts`: **7 failed, 0 passed.** Every failure is a `toHaveCount`/`toBeVisible`/`toHaveAttribute` assertion resolving to **0 elements** (`[data-do-tooltip]` does not exist anywhere inside `.gc-directory-card` / `.gc-stream-card` yet) — never a navigation/import/selector-typo error. Confirmed against a live, fully-seeded site (drush site:install standard → cim → step_700/720/780/790 seed), not an isolated fixture.

**Baseline sanity check** — `tests/e2e/directory-cards.spec.ts` (pre-existing, adjacent surface) run against the same seeded site: 3/3 pass (one transient `net::ERR_CONNECTION_CLOSED` on first attempt was a DDEV/Mutagen blip, not reproducible on retry — confirms the site itself is healthy and the RED is isolated to the new assertions).

### Ready for F
RED is valid on both tiers. F may implement against:
- 5 new `HelpText::all()` keys (`card.directory.type`, `card.directory.members`, `card.stream.byline`, `card.stream.type`, `card.stream.comments`) — append only, after `persona.moderator`.
- Extend `groups_chrome_preprocess_views_view_fields__all_groups()` + `groups_chrome_preprocess_node()` to pass tooltip copy into `gc_directory` / `gc_stream` (A's guidance: option (a), preprocess extension — confirmed acceptable).
- 3 inline `<span class="do-chrome-info gc-card-info" tabindex="0" role="note" aria-label="{{ copy }}" data-do-tooltip="{{ copy }}">ⓘ</span>` triggers per template, each placed as a DOM **sibling** immediately after its target element (type badge / visibility badge / members stat on the directory card; byline / type badge / comments footer on the stream card) — NOT nested inside the comments `<a>` (that element already owns an `aria-label`; nesting would merge accessible names, which the spec explicitly guards against).
- Visibility trigger's `data-do-tooltip` must resolve via `HelpText::get('visibility.' . $variables['gc_directory']['visibility'])` (reuse, not a new key) — the e2e spec pins the exact `visibility.open` string as a regression guard.

## Phase 5 — F (implementation)

**Verdict:** GREEN. 12/12 unit, 7/7 target e2e, 3/3 regression e2e. Commit `d42f716`.

### Decided
- Adopted A's soft warning #1 (`tooltips` sub-array, not flat `tooltip_*` siblings) in both preprocess functions — `gc_directory.tooltips.{type,visibility,members}` and `gc_stream.tooltips.{byline,type,comments}`.
- Adopted A's soft warning #2 (visibility reuse via machine-value lookup) — added one new `$gc['visibility_value']` field (default `'open'`, set alongside the pre-existing `visibility_label`/`visibility_variant` resolution in the same `if` block) purely to carry the raw machine value forward for `HelpText::get('visibility.' . $gc['visibility_value'])`. This is the one new field added to either preprocess function beyond the `tooltips` sub-array itself; every other value read is already computed by the existing function body.
- Called `HelpText::` bare (not fully-qualified), matching the file's own established convention at the pre-existing `group_type.homepage_adapts` (#122) call site, since `use Drupal\do_chrome\HelpText;` is already imported at the top of `groups_chrome.theme`.
- Placed all 6 ⓘ triggers as immediate DOM siblings (verified against rendered HTML, not just twig source) of their target elements, each independently `{% if %}`-guarded on its own tooltip-copy value.

### Assumed
- That running `bash scripts/ci/assemble-config.sh` from the host would work as the issue prompt states; the host shell in this environment has no `php` on PATH (only the DDEV container does), so every assemble/phpunit/phpcs invocation in this phase used `ddev exec` as a wrapper. This matches how T's own RED confirmation ran (`ddev exec php vendor/bin/phpunit ...`), so no deviation from the established local-verification pattern for this worktree — just naming it as an assumption since the raw host command as literally written in the prompt does not, on its own, succeed here.

### Evidence
- `diff` of the pre-edit committed `HelpText.php` against my edited version: confirmed 100% additive (32 new lines, zero lines before the insertion point touched).
- Direct HTML inspection (curl) of `/all-groups` and `/stream` on the seeded site: confirmed all 6 tooltip types render with correct, distinct copy per card (Event planning / Geographical / Working group / Distribution directory cards each got the correct `card.directory.type` copy; an Open-visibility card's `data-do-tooltip` matched `visibility.open` verbatim; an Invite-Only card correctly got `visibility.invite_only`).
- Ran `phpcs --standard=Drupal,DrupalPractice` against BOTH the edited file and a renamed copy of the untouched pre-edit committed file — identical violation set on identical line numbers (all strictly before line 210, my insertion point) confirms the 18 errors / 8 warnings reported are 100% pre-existing lint debt, not introduced by this story. Not fixed (out of scope — a spec'd extend-only change should not drive-by-refactor 24 lines of unrelated pre-existing formatting).
- One transient e2e failure diagnosed and resolved during self-check: running `phase1.spec.ts` / `phase2.spec.ts` for regression-adjacency verification incidentally created fixture groups with no `field_group_type`, which (being newest under the view's `created DESC` sort) sorted to "first" and broke `element-tooltips.spec.ts`'s `.first()`-based directory-card locator (0 elements for the type-trigger; 2-not-3 total triggers). Confirmed via `drush php:eval` that the specific groups (IDs 9-12) had `field_group_type` empty; deleted them; re-ran and got 7/7 clean. This was self-inflicted test-run pollution from my own verification activity, not a defect in T's spec or my implementation — documented in handoff-F.md "Tests that look wrong (for T)" as an FYI about the locator's inherent coupling to view sort order, not a blocking finding.

## Phase 6 — T(green)

**Verdict:** GREEN, no blocking issues. Ready for U (UI surface).

### Commands run + results
- `ddev exec bash scripts/ci/assemble-config.sh` — clean re-assembly, no drift (95 config files, 13 custom modules, identical to F's report).
- `ddev exec php vendor/bin/phpunit -c web/core/phpunit.xml.dist web/modules/custom/do_chrome/tests/src/Unit/HelpTextTest.php --testdox` — **12/12 GREEN** (163 assertions), matches F's reported count exactly.
- `BASE_URL=https://gm127-card-tooltips.ddev.site npx playwright test tests/e2e/element-tooltips.spec.ts` — **7/7 GREEN** (3.8s), matches F's reported count exactly.
- Full do_chrome Unit sweep (no Kernel dir exists for do_chrome — confirmed via `find`, so Unit-only is the correct full set): `ddev exec php vendor/bin/phpunit -c web/core/phpunit.xml.dist web/modules/custom/do_chrome/tests/src/Unit/ --testdox` — **16/16 GREEN** (HelpText 12 + PermissionMatrix 4), no regressions.
- Adjacent e2e regression sweep: `directory-cards.spec.ts` (3/3), `showcase.spec.ts` (20/20) — both fully GREEN, run together as 23/23.
- Cross-cutting sample: `nav.spec.ts` (6/6), `persona-switcher.spec.ts` (4/4), `manage-members.spec.ts` (3/4 + 1 pre-existing conditional `test.skip()` inside the test body, unrelated to this story — confirmed by reading the skip condition, not caused by card-tooltip changes). Total 13 passed, 1 skipped, 0 failed.

### Lint baseline comparison
- Current `HelpText.php` (assembled): 18 errors / 8 warnings, all on lines ≤178.
- `origin/main`'s `HelpText.php` (extracted via `git show origin/main:...`, linted as a renamed copy since phpcs needs a real path): 19 errors / 8 warnings, same lines ≤178 — the "19 vs 18" delta is a class-name-vs-filename false positive introduced solely by the rename needed to run phpcs on a detached blob (confirmed: extra error reads "Class name doesn't match filename," not present when linting the real in-place file).
- **Verdict: F's claim confirmed.** Zero new lint findings from the 32 appended lines (179-241 in the current file); all 18 errors / 8 warnings are pre-existing debt predating this story.

### Behavior-pinning spot-check
- Mutated `card.directory.type`'s value to `''` in the assembled `HelpText.php`, re-ran the unit suite: test correctly failed (`Failed asserting that two strings are not identical` at HelpTextTest.php:248) — proves the test pins the actual copy value, not just key existence. Restored the file immediately after; confirmed back to 12/12 GREEN and confirmed via `git status` that no source-tracked file was left dirty (the assembled copy is untracked; the tracked source under `docs/groups/modules/do_chrome/` was never touched).
- E2E assertions (`toHaveCount(1)` per adjacent trigger, `toHaveCount(3)` per card) are inherently behavior-pinning — RED baseline already proved 0 elements / 7 failures with no triggers present, so removal of any twig trigger reproduces that exact RED. No further mutation needed on the twig/theme side.

### Fixture stability check
- `drush eval "print count(\Drupal\group\Entity\Group::loadMultiple())"` before and after a full `element-tooltips.spec.ts` run: **8 groups both times** — stable, no zombie fixtures. F's reported deletion of stray IDs 9-12 (created by an unrelated `phase1`/`phase2` self-check run, not by this spec) held; `element-tooltips.spec.ts` itself creates no persistent fixtures.

### A11y spot check
- Independently re-verified (fresh one-off Playwright check, not committed) that a directory-card ⓘ trigger has `tabindex="0"`, `role="note"`, non-empty `aria-label`, AND is actually focusable via `.focus()` with `document.activeElement` confirming the landing — a stronger check than attribute presence alone. Passed. This corroborates (does not duplicate) the assertions already inside `expectTooltipTrigger()` in the committed spec.

### Cross-check against F's reported numbers
No discrepancies. F's handoff reported 12/12 unit, 7/7 target e2e, 3/3 regression e2e, 18 errors/8 warnings pre-existing lint, and a resolved fixture-pollution incident (IDs 9-12) — every number reproduced identically on independent re-run.

### Test-quality re-check (playbook §7)
- Each of the 7 e2e tests + 1 unit test names a distinct behavior (per-element contract, hover, reuse-sourcing, no-double-tooltip, per-surface repeat for stream) — no duplication between directory and stream describe blocks since the DOM/copy differs per surface.
- Tier placement still correct: e2e for anything requiring rendered DOM + tippy JS; unit for the copy-source contract. No test could be cheaper.
- Suite is proportionate: 8 new tests for 5 new keys + 6 new DOM triggers across 2 surfaces — no redundancy found, nothing flagged for deletion.

### Verdict
GREEN. No blocking issues. Handing off to U (UI surface — tippy hover/focus/visual behavior on live SPA nav warrants U's walkthrough beyond headless Playwright).

## Phase 8 - U

Verdict: PASS -- ready for S.

Drove the live DDEV site (gm127-card-tooltips.ddev.site) with a throwaway Playwright script (standard page.goto/hover/focus -- this is plain Drupal with no SPA/HTMX nav to special-case). All 19 checklist items from the U prompt confirmed PASS: correct tooltip copy on hover for all 6 elements (directory type/visibility/members, stream byline/type/comments) across /all-groups and /stream; visibility copy byte-identical to the reused visibility.open HelpText key; no double-tooltip (badge itself has no native title and does not fire tippy); full keyboard reachability with a visible 2px solid focus outline (rgb(0,103,184)) and tippy showing on focus; distinct non-empty accessible names confirmed via ariaSnapshot; contrast approximately 5.78:1, comfortably AA; zero console errors across the entire walkthrough; window.Drupal.behaviors.doChromeTooltips confirmed registered. Regression-checked /showcase (200 OK, no card templates rendered there by design, unrelated page-level info icons unaffected) and the authenticated Elena Garcia (member) persona on both surfaces (session confirmed via cookie persistence, cards and tooltips render identically to anonymous).

Independently assessed diff-gate W-3 (role=note passivity concern): judged as not a UI-behavior blocker from the live-browser angle -- the element is keyboard-reachable, carries a full accessible name, and behaves honestly (a passive annotation, not a misrepresented control). The semantic-correctness question of role=note versus an alternative ARIA pattern is forwarded to S formal WCAG audit rather than adjudicated here.

No behavioral defects found. Ready for S.
