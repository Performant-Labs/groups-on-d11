# Handoff-S: Phase 12 spec audit — #123 SC-4 Discovery three ways

Date: 2026-07-23
Branch: 123-discovery-three-ways
Auditor mode: read-only. No production or test code modified.

## Verdict

**PASS.** All 8 acceptance criteria met. No scope creep, no silent drops from
the brief. Convergent-correct artifacts (`discovery-compare.css`, its library
entry, `queryKeyForGroup()` fix in `do_showcase.switcher.js`) verified against
wireframe intent. U-obs-1 classified as **acceptable POC-level behavior**, not
a spec deviation — see reasoning below.

Route to commit + PR.

## Acceptance criteria — final table

| # | Criterion (from brief.md) | Evidence | Status |
|---|---|---|---|
| 1 | All three variants render non-empty from seed (Promoted shows 2 seeded nodes; Hot shows commented threads) | U-2 walk items 1-3; `DiscoveryRankingControllerTest` 8/8 GREEN; U-2 confirmed both "Getting Started with Paragraphs" + "Community Code of Conduct" present on Promoted | PASS |
| 2 | Switcher labels + single wrapper tooltip | Wireframe: three labels Recent/Hot/Promoted with ONE shared tooltip. Verified in U-2 (authored spec test 7, 415ms) + HelpText.php diff shows key `showcase.switcher.discovery.ranking` with the wireframe's exact copy | PASS |
| 3 | Deep links land pre-switched (`?discovery=recent\|hot\|promoted`) | U-2 walk item 15; `discovery-compare.spec.ts` 11/11 GREEN live | PASS |
| 4 | `/hot` and existing promoted views UNCHANGED | T-green Phase 6 + T-green-2 Phase 10: full non-regression sweep 357/357 tests, `DirectoryTogglePreRenderTest` 8/8 GREEN pins the 3-arg BC caller | PASS |
| 5 | Existing suite stays green | Grand total 357 tests, 0 failures across all custom modules (T Phase 10) | PASS |
| 6 | Playwright spec `tests/e2e/discovery-compare.spec.ts` cycles the three tabs | File exists (228 lines, 11 tests); live-run 11/11 GREEN under `BASE_URL=http://gm123-discovery.ddev.site` (U-2 and T-green-2) | PASS |
| 7 | HelpText entry `showcase.switcher.discovery.ranking` appended (do_chrome) | Staged diff of `HelpText.php` shows exactly this key added, append-only, with wireframe's copy | PASS |
| 8 | WCAG 2.2 AA — labels, keyboard, focus, contrast, non-color status | U-2 walk items 5-7: focus ring rgb(77,163,255) solid 2px; bullet glyph + aria-checked on selected; role="radiogroup" / aria-label="Viewing"; keyboard arrow + Enter path GREEN via authored spec test 9 | PASS |

## Scope conformance

**Nothing shipped outside brief.** Owned-files claim (brief.md lines 27-34, as
amended by A) matches actual staged changes exactly:

| Brief-owned file | Actual state | Notes |
|---|---|---|
| `docs/groups/modules/do_showcase/css/discovery-compare.css` (new) | Staged, +76 lines | Convergent-correct artifact (F Phase 5 flag) — verified against wireframe WCAG note by T Phase 6 |
| `tests/e2e/discovery-compare.spec.ts` (new) | Untracked, 228 lines, 11 tests | Must be staged for commit |
| `docs/groups/modules/do_showcase/src/VariantSwitcher.php` — 4th `$query_key` param | Staged, +41 lines; BC-safe default preserves existing 3-arg callers | Cross-story extension recorded in A-plan Risk 1 |
| `docs/groups/modules/do_showcase/src/Controller/ShowcaseController.php` — new render block | Staged, +151 lines | Adds `embedDiscoveryView()`, `?discovery=` cache context, discovery.ranking switcher render |
| `do_chrome` HelpText key append | Staged (see HelpText.php diff above) | Append-only, wireframe copy verbatim |

**Additional artifacts (all justified):**

- `do_showcase/do_showcase.libraries.yml` (staged, +17 lines) — registers
  `discovery-compare.css`. Not called out in the brief's owned-files list but
  is the only correct way to attach a new CSS file in Drupal; convergent-
  correct per F Phase 5.
- `do_showcase/js/do_showcase.switcher.js` (staged base +72, unstaged fix1
  +105 lines) — combined delivers A-plan advisory #2 (JS reads href verbatim
  via `queryKeyForGroup()`) plus the F-fix1 `usesMirrorModel()` discriminator
  for F-U-1. Staged/unstaged split is a workflow artifact; both must be in
  the final commit.
- Three new test files: `VariantSwitcherTest.php` (extended, unstaged +130),
  `DiscoveryRankingHelpTextTest.php` (untracked, 103 lines), and
  `DiscoveryRankingControllerTest.php` (untracked, 341 lines). All three are
  required by T Phase 4 RED plan.
- Three test fixtures: `flag.flag.promote_homepage.yml`,
  `views.view.hot_content.yml`, `views.view.promoted_content.yml` (all
  untracked, under `tests/fixtures/config/`) — required by
  `DiscoveryRankingControllerTest` per T Phase 4.
- `docs/handoffs/sc4-discovery-123/` (all 13 files untracked) — the story's
  handoff journal. Include in commit per house convention.

**Nothing silently dropped:** the brief's one dropped item
(`views.view.discovery_compare.yml`) was dropped explicitly by A Phase 3
Risk 3 with recorded justification ("would fork ranking"); the actual
implementation embeds the three existing views directly, honoring the
"do NOT fork ranking" constraint.

## U-obs-1 judgment call

**Classification: acceptable POC-level behavior. Not a spec deviation. Not a
blocker.**

Reasoning:

1. **Not in brief or wireframe.** Session persistence for `discovery.ranking`
   is not an acceptance criterion of #123. It is a self-imposed feature of
   the shared switcher library (inherited from #124 SC-5 `directory.layout`,
   where it works correctly because a mirrored wrapper attribute drives a
   CSS swap).
2. **Primary path is fully correct.** The wireframe-mandated behavior —
   click a tab → URL updates → embed updates — is fully green (11/11
   Playwright, live-verified in U-2 walk items 2-4). U-obs-1 only surfaces
   on a narrow off-path (bare `/showcase` revisit while sessionStorage
   holds a prior choice).
3. **POC pipeline convention applies.** Per `feedback_poc_no_follow_ups.md`
   in user's MEMORY.md, the project is a POC and the user has explicitly
   said not to file follow-ups for latent debt on merged stories. U-2
   surfaced this once as required; that is the correct disposition. Do not
   file a GH issue, do not expand this handoff's §10 to track it.
4. **Fix shapes are recorded** in `handoff-U-2.md` if this ever becomes a
   product priority; that record is sufficient.

## Git-state audit

Confirmed the actual working-tree state matches what the handoffs claim:

- Staged production files (6): `HelpText.php`, `discovery-compare.css`
  (new), `do_showcase.libraries.yml`, `do_showcase.switcher.js` (base),
  `ShowcaseController.php`, `VariantSwitcher.php`.
- Unstaged production/test files that are story-scope and MUST be included:
  - `do_showcase.switcher.js` (F-fix1 additions layered on top of staged
    base, verified via `git diff` — non-conflicting).
  - `tests/src/Unit/VariantSwitcherTest.php` (+130 lines).
- Untracked story-scope files that MUST be included:
  - `tests/e2e/discovery-compare.spec.ts`
  - `tests/src/Functional/DiscoveryRankingControllerTest.php`
  - `tests/src/Unit/DiscoveryRankingHelpTextTest.php`
  - `tests/fixtures/config/flag.flag.promote_homepage.yml`
  - `tests/fixtures/config/views.view.hot_content.yml`
  - `tests/fixtures/config/views.view.promoted_content.yml`
  - `docs/handoffs/sc4-discovery-123/` (all 13 files)
- **Out-of-story noise** (present in worktree but NOT part of #123 —
  DO NOT stage): the other 30+ modified/untracked files under `config/sync/`,
  `web/`, `.ddev/`, `.editorconfig`, `.gitattributes`, `web/modules/custom/`,
  `web/sites/simpletest/`, `web/autoload_runtime.php`. These are environmental
  detritus / assemble-config output / other work in this worktree. The
  commit stage must be surgical — see recommended commit shape below.

## Docs / config that should have been updated but weren't

None found. Story does not add a new module, new route, or new module info
change — everything is an extension of existing surfaces already listed in
the respective `.info.yml` and `.libraries.yml` files. Library entry for
`discovery-compare` is added correctly. No README, no `.services.yml`, no
`.routing.yml` change needed.

## Recommended commit shape

**Commit message:**

```
feat: #123 SC-4 Discovery three ways — Recent/Hot/Promoted switcher on /showcase

Adds a second VariantSwitcher instance ("discovery.ranking") to /showcase
below the existing directory.layout stub, presenting the three existing
views (activity_stream / hot_content / promoted_content) as Recent / Hot /
Promoted tabs. Deep-linkable via ?discovery=<id>, distinct from the
existing ?variant= key so both switchers coexist without collision.

- VariantSwitcher::build() gains BC-safe 4th param $query_key = 'variant';
  cache-context bubble also keyed off $query_key. Existing 3-arg callers
  (DoShowcaseHooks, ShowcaseController directory.layout instance) are
  untouched. Ref A-plan Risk 1.
- ShowcaseController::page() renders the new section via
  views_embed_view('activity_stream'|'hot_content'|'promoted_content',
  'default') — do NOT fork ranking. Adds url.query_args:discovery to page
  cache context alongside url.query_args:variant.
- do_showcase.switcher.js gains queryKeyForGroup() (reads href verbatim
  instead of hardcoding 'variant') and usesMirrorModel() discriminator so
  mirror-driven instances (directory.layout) preventDefault + client-swap
  while navigation-driven instances (discovery.ranking) let the anchor
  navigate. Preserves the existing directory-toggle contract.
- New CSS discovery-compare.css (WCAG-conformant meta line + focus ring
  matches existing switcher outline).
- HelpText.php appends the single wrapper tooltip key
  showcase.switcher.discovery.ranking (POC scope — no per-option tooltip).

Tests: VariantSwitcherTest extended (+6 new), new DiscoveryRankingHelpTextTest
(5 tests), new DiscoveryRankingControllerTest (8 tests, Functional), new
Playwright discovery-compare.spec.ts (11 tests). All green live at
gm123-discovery.ddev.site. Non-regression: 357/357 custom-module tests,
directory-toggle.spec.ts 11/11.
```

**PR title:** `feat: #123 SC-4 Discovery three ways — Recent/Hot/Promoted on /showcase`

**PR body shape:**

```
## Summary
- Adds discovery.ranking VariantSwitcher instance on /showcase — Recent/Hot/Promoted tabs, each embedding the existing view AS-IS (no ranking forked).
- BC-safe VariantSwitcher::build() extension (optional 4th $query_key param) so two switchers coexist with distinct query keys (?variant= + ?discovery=).
- do_showcase.switcher.js: mirror-model discriminator lets directory.layout keep its client-side swap while discovery.ranking uses real navigation.

## Acceptance criteria (8/8 PASS)
See docs/handoffs/sc4-discovery-123/handoff-S.md for the full table + evidence.

## Test plan
- [x] PHPUnit Unit + Kernel: 279 tests, 0 failures
- [x] PHPUnit Functional (do_showcase incl. new DiscoveryRankingControllerTest): 37/37
- [x] Playwright discovery-compare.spec.ts: 11/11 live
- [x] Playwright directory-toggle.spec.ts non-regression: 11/11 + 1 skipped
- [x] Full custom-module non-regression sweep: 357/357
- [x] Live UI walkthrough (mouse + keyboard + deep-link + mobile + WCAG focus/contrast): PASS

## Notes
- One informational observation (U-obs-1, non-blocking, not in brief or wireframe): bare /showcase revisit with sessionStorage-persisted discovery choice restores the switcher chrome but not the server-rendered embed. Recorded in handoff-U-2.md; POC convention → no follow-up filed.

🤖 Generated with [Claude Code](https://claude.com/claude-code)
```

**Files to stage for the commit (surgical):**

```
# Staged already:
git diff --cached --name-only   # 6 production files, as listed above

# Add remaining story-scope files:
git add docs/groups/modules/do_showcase/js/do_showcase.switcher.js
git add docs/groups/modules/do_showcase/tests/src/Unit/VariantSwitcherTest.php
git add docs/groups/modules/do_showcase/tests/src/Unit/DiscoveryRankingHelpTextTest.php
git add docs/groups/modules/do_showcase/tests/src/Functional/DiscoveryRankingControllerTest.php
git add docs/groups/modules/do_showcase/tests/fixtures/config/flag.flag.promote_homepage.yml
git add docs/groups/modules/do_showcase/tests/fixtures/config/views.view.hot_content.yml
git add docs/groups/modules/do_showcase/tests/fixtures/config/views.view.promoted_content.yml
git add tests/e2e/discovery-compare.spec.ts
git add docs/handoffs/sc4-discovery-123/
```

**Do NOT stage:** any of the `config/sync/*`, `web/*`, `.ddev/`, `.editorconfig`,
`.gitattributes`, `web/modules/custom/**`, `web/sites/simpletest/`,
`web/autoload_runtime.php` files. Those are worktree noise from
`assemble-config.sh` runs and other work; they belong to other stories or
are generated artifacts.

## Handoff to next role

Commit + PR agent. All eight acceptance criteria PASS; audit findings above;
recommended commit message and stage-list above. Self-merge on CI-green +
mergeable per `feedback_uranus_wider_autonomy.md` standing rule.
