# Decision Journal — #136 W0: Dependency pre-story

## Phase 1 — O (survey + brief)

- **Decided:** Review-rigor = `none`. Source: issue #136 explicitly states "Review rigor:
  none" and the operator's launch instructions reiterate it. Minimal chain: O -> A -> F ->
  verify -> S -> O.
- **Decided:** `drupal/grequest` is NOT added in this PR. Evidence: grequest's own
  `composer.json` (checked at tag `3.2.4`, the latest release) requires `drupal/group: ^3.0`;
  this project pins `drupal/group: 4.0.x-dev`. grequest's git branches are `1.0.x`/`2.0.x`/
  `3.0.x` only — no 4.x work in progress found. Group 4.0.x core's own `modules/` tree
  (checked via git.drupalcode.org API) contains only `gnode` and `group_support_revisions` —
  no bundled membership-request submodule. This is a hard incompatibility, not a judgment
  call — adding it would break composer resolution against the already-pinned `drupal/group`
  constraint.
- **Decided:** Map formatter for #125 = `drupal/geofield_map` (11.1.9, constraint `^11.1`).
  Evidence: queried drupal.org's project API for a standalone "Leaflet Views formatter"
  project — zero results (no `leaflet_views`/`views_leaflet` project exists). geofield_map is
  the only viable contrib formatter and its composer.json has no Group coupling and depends
  only on `drupal/geofield` + D11 core.
- **Decided:** D and U phases = N/A (no UI surface — this is a composer-manifest-only story).
  T(red)/T(green) in the traditional PHPUnit sense = N/A (no new PHP behavior); F's own
  resolve/verify step stands in for it, per the brief's declared pipeline shape.
- **Evidence:** All package compat findings verified against `updates.drupal.org` release
  history XML + each package's own `composer.json`/`.info.yml` fetched from
  git.drupalcode.org, not assumed from memory. See `survey.md` for full detail and URLs
  checked.
- **Assumed:** Release-history metadata (`core_compatibility`) is a reliable proxy for
  resolvability, but the brief flags this as **not sufficient alone** — F must run the real
  `composer require` in the DDEV container and treat that as the actual gate.

## Phase 3 — A (up-front plan review)

- **Decided:** Verdict = PASS. The 5 proposed constraints (`drupal/masquerade: ^2.2`,
  `drupal/message: ^1.8`, `drupal/message_notify: ^1.5`, `drupal/geofield: ^10.3`,
  `drupal/geofield_map: ^11.1`) are internally consistent with the existing `require` block
  (D11 `^11.4` core pin, `drupal/group: 4.0.x-dev`, `minimum-stability: dev`,
  `prefer-stable: true`) and follow the same extend-in-place pattern set by the prior
  `drupal/flag`/`drupal/group` additions (alphabetical, `sort-packages: true` respected).
- **Decided:** The `drupal/grequest` exclusion is well-evidenced (grequest's own
  `composer.json` requires `drupal/group: ^3.0`; Group 4.0.x core's `modules/` tree has no
  bundled request submodule) and correctly scoped as a comment on #121, not a package add.
- **Decided:** Scope (composer.json/composer.lock only, no enables/config/seed changes) and
  pipeline shape (D/U N/A, T red/green replaced by F's real `composer require` +
  enable-state assert + regression suite, light S, no outside dual-review per declared
  rigor `none`) are appropriate for a manifest-only story with no new PHP.
- **Assumed:** Phase 7 A (anti-duplication gate) will be a no-op / trivially-skippable for
  this story since a manifest diff has no analogous object to duplicate against; flagged as
  a note for O to explicitly confirm/skip rather than silently drop.
- **Evidence:** Verified HEAD (`c18f417`) matches the survey's cited base commit; read
  `composer.json` directly to confirm the current `require` block, `minimum-stability`,
  `prefer-stable`, and `sort-packages` settings cited in the survey are accurate as of this
  review.

## Phase 4/5 — F (implement: composer require x5)

- **Decided:** Ran the real resolver via host `composer` (not `ddev composer`) with
  `--ignore-platform-req=php`. `ddev start` in this worktree failed because its
  `.ddev/config.yaml` project name (`pl-groups-on-d11`) collides with an already-registered
  DDEV project at a different local path (`~/Sites/pl-groups-on-d11`, a separate checkout of
  the same repo); fixing/unlisting that other project would touch shared local DDEV state
  outside this issue's scope. Host PHP is 8.3.31 vs. the project's target 8.4, which made
  `drupal/core-dev`'s dev-only transitive chain (`doctrine/instantiator` → `phpspec/prophecy`)
  reject without the flag; confirmed via `git stash` that this same PHP-8.3-vs-8.4 rejection
  reproduces identically on unmodified `main` (base commit `c18f417`), so it is a pre-existing
  host/target mismatch, not evidence of an incompatibility introduced by the 5 new packages.
- **Decided:** The real resolve succeeded cleanly — all 5 packages + their transitive deps
  resolved against `drupal/group: dev-4.0.x` and `drupal/core-recommended: 11.4.4` (unchanged
  core version) with zero conflicts. Resolved versions matched the survey's predictions
  exactly: `masquerade 2.2.0`, `message 1.8.0`, `message_notify 1.5.0`, `geofield 10.3.4`,
  `geofield_map 11.1.9` — no constraint needed loosening.
  `drupal/grequest` was not added, confirmed absent from both `composer.json` and
  `composer.lock` (`grep -c grequest` = 0 in both).
  This is the empirical resolver proof the survey flagged as the one open risk deferred to
  this phase.
- **Decided:** Reverted composer-scaffold's re-touch of `web/.htaccess`, `web/index.php`,
  `web/robots.txt`, `web/update.php`, `web/example.gitignore` (content drift vs. tracked
  versions despite `drupal/core` staying at the same 11.4.4, an expected composer-scaffold
  side effect of any `composer require`/`install` run) and deleted two newly-generated,
  previously-untracked scaffold files (`web/.gitignore`, `web/autoload_runtime.php`) so the
  final diff is exactly `composer.json` + `composer.lock`, per the brief's explicit scope
  constraint.
- **Assumed:** Enable-state-unchanged and existing-suite-green verification both had to fall
  back to proxies rather than direct checks, because no DB-backed site/DDEV instance for this
  specific worktree was available in this sandbox: (a) enable-state verified via
  `config/sync/core.extension.yml` grep (zero references to the 5 new packages) + full
  `git diff --name-only` (no config/`.info.yml` touched) rather than a live
  `drush pm:list` before/after; (b) the kernel/functional/e2e suites were not run locally
  (no MySQL, no assembled `web/modules/custom/`) — stated explicitly in the handoff as a gap
  CI will close, not silently skipped. Both fallbacks are the brief's own sanctioned proxies
  for exactly this "no live site/DB available" scenario.
- **Evidence:** `composer validate` clean (only the pre-existing `drupal/group` exact-version
  warning present on `main` too); `git diff --stat` shows only `composer.json`
  (+5/-0 lines, net +5) and `composer.lock` changed; `composer install
  --ignore-platform-req=php` against the final lock file reported "Nothing to install, update
  or remove" (internally consistent, reproducible lock).

## Phase 9 — S (spec audit, light)

- **Decided:** Verdict = PASS. Light audit appropriate given the story's declared
  `review rigor: none` and thin/mechanical nature (manifest-only diff, no PHP/behavior
  change, no UI surface).
- **Decided:** Independently re-verified (not just trusted from F's handoff): the 5
  composer.json entries directly in the file (alphabetical, correct constraints);
  `drupal/grequest` absence in both composer.json and composer.lock; composer.lock's 5 new
  package entries via direct JSON parse (real versions + plausible transitive deps, e.g.
  `itamair/geophp` for geofield, `message` as `message_notify`'s transitive dep) — not a
  hand-edited stub; `composer validate` re-run directly by S (clean, only the pre-existing
  `drupal/group` warning); enable-state-unchanged re-verified directly via
  `config/sync/core.extension.yml` grep (zero matches for any of the 5 new packages) rather
  than only trusting F's proxy reasoning; diff scope re-confirmed as composer.json +
  composer.lock only via `git diff --stat`/`--name-only`.
- **Decided:** F's inability to run the DB-backed kernel/functional/e2e suite is an
  honestly-disclosed sandbox limitation (no MySQL/DDEV site available), not a defect — S's
  own environment has the identical limitation, so S did not require F to somehow clear a
  bar S itself cannot clear either. F's substituted proxies (JSON validity, clean
  `composer install` reporting no-op against the new lock) are accepted as sufficient given
  CI is the declared actual gate for the DB-backed suites.
- **Decided:** No A-dup reconciliation needed — handoff-A's note 2 already flagged Phase 7
  as a no-op for a manifest-only diff with no analogous object to duplicate against; O
  confirmed this per the task's framing. Nothing for S to reconcile.
- **Evidence:** Ran `python3 -c "import json; ..."` directly against composer.lock to
  extract and inspect the 5 new package entries and confirm grequest's absence across a
  98-package lock; ran `composer validate` directly; ran `grep -c -iE
  "masquerade|geofield|message" config/sync/core.extension.yml` (0 matches) and `git diff
  --stat`/`--name-only` directly rather than relying solely on F's self-reported numbers.
- **Noted for O:** the #120/#121/#125 verification-note PR-description/issue-comment
  posting is O's Phase-11 responsibility per the brief's own division of labor, not a gap
  in F's or S's work — flagged as a non-blocking reminder in handoff-S, not a REWORK item.
