# Decision journal — #122 SC-3 Group-type-driven homepages

Append-only. One entry per phase. O writes the closing Chain Summary.

---

## O — Phase 1 (survey + brief)

**Decided:**
- Extend existing `groups_chrome_preprocess_group()` (theme, not module) instead of adding a
  parallel path. This preprocess already reads `field_group_type` and builds `$gc['tabs']`.
- Add ONE new function: `groups_chrome_theme_suggestions_group_alter()` — story explicitly
  asks for theme suggestions and no existing suggestion hook lives in the theme.
- Content sources: existing `views.view.group_events` for events; `node.type.forum` and
  `node.type.documentation` for discussion/docs (query via group relationships, mirroring
  the last-activity read at lines 456–470 of `groups_chrome.theme`).
- HelpText: append ONE key (`group_type.homepage_adapts`) to
  `docs/groups/modules/do_chrome/src/HelpText.php`.
- Review rigor: **none** per story spec.
- Source of truth for the theme layer = `web/themes/custom/groups_chrome/` (tracked in git,
  not gitignored). The "docs/groups/ only" rule applies to `web/modules/custom/` +
  `config/sync/` (assembled artifacts), not the theme.

**Assumed (needs verification in A / T):**
- `views.view.group_events` renders acceptable data for an "events-first" lead section, OR
  the preprocess can build its own render array of top-N events by querying the group's
  event nodes directly. A will judge which is cleaner.
- The three seeded exemplars still tag as expected on a fresh CI install (verified against
  `step_720_group_types.php` — the mapping is present; assumed the seed still runs in E2E CI).

**Hedged:**
- Whether the "leading section" should replace the tab bar's default view or render as a
  block above it. Wireframe (D) will decide. Prefer above-the-tabs block so tabs stay
  intact and un-mapped types get zero visual change.

**Evidence:**
- `web/themes/custom/groups_chrome/groups_chrome.theme` lines 204–356 (existing preprocess).
- `docs/groups/scripts/step_720_group_types.php` lines 68–102 (terms + exemplar tags).
- `docs/groups/config/node.type.{event,forum,documentation}.yml` exist.
- `docs/groups/config/views.view.group_events.yml` exists.

---
