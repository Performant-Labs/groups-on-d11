# Survey — #182

## Files inspected
- `docs/groups/scripts/step_700_demo_data.php` (lines 205–237: Step 740c comment seed)
- `docs/groups/config/node.type.forum.yml`
- `docs/groups/config/field.field.node.forum.body.yml`
- `docs/groups/config/field.field.node.forum.field_group_tags.yml`
- `docs/groups/config/group.relationship_type.community_group-group_node-forum.yml`
- `config/sync/core.extension.yml` — `comment` module enabled (weight 0)
- `config/sync/comment.type.comment.yml` — default comments type exists
- `config/sync/comment.settings.yml`
- `config/sync/field.storage.node.comment.yml` — comment field storage exists
- `config/sync/field.field.node.article.comment.yml` — analogous instance (template)
- `config/sync/field.storage.comment.comment_body.yml`
- `config/sync/core.entity_form_display.node.article.default.yml` — includes `comment` field
- `docs/groups/modules/do_discovery/src/Hook/DoDiscoveryHooks.php` — hot_score formula: `(comment_count × 3) + (view_count × 0.5)`; reads `COALESCE(ces.comment_count, 0)` from `comment_entity_statistics`
- `docs/groups/modules/do_discovery/do_discovery.install` — `do_discovery_hot_score` schema
- `scripts/ci/assemble-config.sh` — copies `docs/groups/config/*.yml` → `config/sync/`

## Key findings
1. **95% of the infrastructure already exists.** Comment module enabled; `field.storage.node.comment` present; `comment.type.comment` present; seed script already loops through 6 planned comments. Only the field-instance attachment to the `forum` bundle is missing.
2. **`field.field.node.article.comment.yml` is a directly reusable template.** Change `bundle: article` → `bundle: forum` and update dependency `node.type.article` → `node.type.forum`. Regenerate `uuid`.
3. **No `core.entity_form_display.node.forum.default.yml` exists** — Drupal auto-generates it. Adding the comment field via config import will attach it to the auto-generated display. Views display same.
4. **`do_discovery_hot_score`** is populated via `comment_entity_statistics` join. Once forum nodes accept comments, `ces.comment_count` will be non-zero for commented nodes, so `hot_score = comment_count * 3 > 0`.
5. **Assemble script** simply copies `docs/groups/config/*.yml` → `config/sync/` (excluding 7 env-specific entries, none of which are comment-related). Adding one YAML is sufficient.
6. **The seed script's defensive `if (!$comment_field)` branch** short-circuits cleanly today. Once the field is attached, control flows into the `else` and seeds 6 comments.

## Forward-compat check
- **#113 Trending** — this story unblocks the deleted `test 2` assertion. Story #113 explicitly notes it does NOT restore that test in this PR.
- Downstream consumers of forum nodes (streams, activity feed) do not read comment_count and are unaffected.

## Reuse & Analogous-Feature map
See brief §"Reuse & Analogous-Feature map".
