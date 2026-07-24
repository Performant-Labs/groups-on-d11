# Brief — #182 Seed pipeline: forum bundle needs comment field

**Issue:** https://github.com/Performant-Labs/groups-on-d11/issues/182
**Branch:** `182-forum-comment-field` (base c89bf40)
**Worktree:** `C:/Users/aange/Projects/_worktrees/groups-seed-comments-182`
**Review-rigor:** none (data/config story, no UI surface, POC lean pipeline)

## Objective
Attach the `comment` field to the `forum` bundle so seeded forum threads accept comments, `do_discovery_hot_score` becomes non-zero for commented nodes, and `/trending` demonstrably orders commented threads above zero-comment nodes on the seeded demo.

## Reuse & Analogous-Feature map (extend-vs-new)
- **`field.storage.node.comment`** — ALREADY EXISTS at `config/sync/field.storage.node.comment.yml`. **Reuse as-is.**
- **`comment.type.comment`** — ALREADY EXISTS at `config/sync/comment.type.comment.yml`. **Reuse.**
- **`comment` module** — ALREADY ENABLED in `config/sync/core.extension.yml` (weight 0). **No change needed.**
- **`field.field.node.article.comment.yml`** — the closest analogous instance. **Extend by cloning** into `docs/groups/config/field.field.node.forum.comment.yml`, adjusting `bundle: forum` and dependencies. Keep `settings.anonymous: 0` (authenticated-only per the coordinator default).
- **Entity form/view display for forum** — no `core.entity_form_display.node.forum.default.yml` currently exists in either `config/sync/` or `docs/groups/config/`. Drupal auto-generates the display for the forum bundle at install time; attaching a new comment field via config-import will land it in the auto-created display. If we want deterministic placement, we'd need to add explicit form/view display YAMLs — decision deferred to A (default: don't add, let Drupal handle placement).
- **Seed script** — `docs/groups/scripts/step_700_demo_data.php` lines 205–237 ALREADY handles seeding (6 planned comments across forum threads). No change needed once the field exists — the `if (!$comment_field)` guard will fall through to the seed loop. **The 6 planned comments satisfy the "5-10" coordinator default.**

**Recommendation:** Extend existing analogous config. NO new modules, NO new field storage, NO new comment type. Just one new field-instance YAML and (optionally) form/view display YAMLs.

## Acceptance criteria
- [ ] `docs/groups/config/field.field.node.forum.comment.yml` exists, extends `field.storage.node.comment`, bundle `forum`, `settings.anonymous: 0`.
- [ ] After `bash scripts/ci/assemble-config.sh` + config-import, `\Drupal::service('entity_field.manager')->getFieldDefinitions('node', 'forum')` includes a `comment`-type field.
- [ ] Kernel test: create a forum node, post a comment, run `do_discovery_recalculate_hot_scores()` (or the equivalent function), assert `hot_score > 0` for that node's row in `do_discovery_hot_score`.
- [ ] Seed script Step 740c no longer emits `ERROR: No comment field on forum nodes`; instead emits at least 6 `Comment cid=… on "…"` lines.
- [ ] `phpcs` clean.
- [ ] No regressions in existing Kernel/Functional suites.

## Non-goals
- Not adding comment to `post`, `documentation`, `page`, `event` bundles.
- Not restoring the deleted assertion in #113's `tests/e2e/trending.spec.ts` (out of scope per issue body).
- Not adding comment displays to other bundles.

## Handoff locations
- Survey: `docs/planning/handoffs/182-forum-comment/survey.md`
- Decisions: `docs/planning/handoffs/182-forum-comment/decisions.md`
- Handoffs A/T-red/F/T-green/S: `docs/planning/handoffs/182-forum-comment/handoff-*.md`
