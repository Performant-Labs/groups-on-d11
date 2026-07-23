# #142 — Parked overnight: CI trigger anomaly

**Date:** 2026-07-23 (early)
**PR:** https://github.com/Performant-Labs/groups-on-d11/pull/165
**Head:** `213e343` on branch `142-directory-filters`
**Reason parked:** GitHub Actions did not trigger a CI run on PR#165 — same anomaly hit sibling PRs #132/#139/#127 tonight. This is **not** a story-side failure.

## Story work is complete and green locally

- **A r1 → BLOCK** (field-name collision with #125, plus 3 warns). Amended brief.
- **A r2 → PASS.**
- **T(RED):** 2/4 kernel + 5/5 e2e failing for the right reasons on real assembled DDEV.
- **F:** implemented; commit `8ae7358`. Beyond the brief F added two runtime-necessary pieces (evidenced): `language.entity.fr.yml` (core `LanguageFilter::access()` gates on `isMultilingual()`) and `do_group_language/src/Hook/DoGroupLanguageHooks.php::fieldViewsDataAlter()` (real Views handler resolution reads `filter.id` from Views-data, not stored `plugin_id:`; core has no path for bundle-attached language fields). Also flagged Views field-key `_value`-suffix gotcha — will recur.
- **T(GREEN):** commit `b889019`. **4/4 kernel; 141/141 full cross-module sweep; 5/5 e2e; phpcs clean.** Repaired two test-authorship bugs F flagged (bare `field:` name resolves to `Broken`; reset button is `<input type=submit>` role button not link) + a third T-found kernel-setup bug (needed to install `field_group_language` storage/instance + enable `do_group_language` + add ConfigurableLanguage `fr` in `setUp()`).
- **U:** commit `c0d4a83`. **PASS.** All 8 walkthrough tasks green on live `gm142-directory-filters.ddev.site`; form-scoped axe **0 violations**; no layout regressions. Non-blocking advisory: pre-existing `.gc-badge--success` color-contrast violation from `primitives.css:39` (stories #84/#121/#122) — out of #142 scope.
- **S:** commit `213e343`. **PASS.** 4/4 issue acceptance + 8/8 brief acceptance met. F's two additions ruled necessary production prerequisites (not scope creep). Open Assumption (`field_group_language` vs #139's `field_group_primary_language`) accepted for POC/overnight; #139's own issue text invites the reuse.
- **PR#165 opened:** https://github.com/Performant-Labs/groups-on-d11/pull/165.

## Blocker

GitHub Actions did not fire a workflow run on PR#165. Same class of anomaly seen on PR#132 / #139 / #127 tonight per coordinator. Not investigable inside this story.

Additionally, `main` itself is potentially still red from #141's phase3/phase4 regression, though status may have improved since #128's clean rebase-fix merge. Cannot ship on top of red main.

## Morning triage plan

1. Confirm/resolve `main` status. If red, first-priority is the phase3/phase4 fix.
2. Once `main` is green: from this worktree, `git fetch origin && git rebase origin/main` on `142-directory-filters` (no substantive conflicts expected — location field name `field_group_location_text` was chosen specifically to avoid #125 collision; view file edits are additive within `filters:` / `fields:` sections).
3. `git push --force-with-lease origin 142-directory-filters` to re-trigger CI.
4. If CI fires and greens → merge with `gh pr merge 165 --repo Performant-Labs/groups-on-d11 --merge --delete-branch`; then remove worktree + tear down `gm142-*` DDEV.
5. If Actions still doesn't trigger → close/reopen the PR (known workaround for this class of anomaly), or `gh pr create` a fresh PR from the same head.

## PR body notes to preserve (already in PR#165 description)

- Field rename rationale (`field_group_location_text` vs #125's geofield `field_group_location`).
- The #139 Open Assumption.
- The `_value`-suffixed Views-data field-key gotcha (recurring hazard).
- The pre-existing `.gc-badge--success` contrast advisory (informational, not blocking, no follow-up issue filed per POC posture).

## Local state (worktree)

- Branch: `142-directory-filters` at `213e343`, pushed and tracking `origin/142-directory-filters`.
- DDEV: `gm142-directory-filters` (running). Safe to tear down — `.ddev/config.yaml` correctly namespaced (`name: gm142-directory-filters`), not the shared default.
- All committed changes are source-only in `docs/…` + `tests/e2e/…`; no build artifacts committed. Unstaged local edits (config/sync/, web/, .ddev/config.yaml, .editorconfig) are gitignored build artifacts and stay uncommitted.
