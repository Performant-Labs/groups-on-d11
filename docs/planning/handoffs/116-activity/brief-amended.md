# Brief — #116 Activity foundation (A-amended)

## Story
`#116 ST-F2: Activity layer foundation — Message + message_notify, event logging, one-time backfill`. Full spec: `gh issue view 116 --repo Performant-Labs/groups-on-d11` + artifact §5.

## Amendments folded in after A-BLOCK (pass 1)
Six items required by A before PASS. Baked into constraints below.

## Reuse & Analogous-Feature map
- **Analog module:** `docs/groups/modules/do_notifications/` (attribute-based OOP hooks under `src/Hook/`, no UI beyond settings). NOT `do_streams` (that's a Views consumer).
- **Hook pattern:** attribute `#[Hook('event_name')]` methods on a class in `src/Hook/DoActivityHooks.php` — mirror `DoNotificationsHooks`.
- **Group-context event:** subscribe to `group_relationship_insert` filtered to bundle `group_node:*`, NOT `node_insert`. `DoNotificationsHooks` (lines 20–54, 165) documents this Group 4.x pitfall — `node_insert` cannot see the group at insert time.
- **Config path:** `docs/groups/config/message.template.<id>.yml` for shared config; module-internal templates under `docs/groups/modules/do_activity/config/install/`. `scripts/ci/assemble-config.sh` (lines 36–50) flat-copies `docs/groups/config/` into `config/sync/`.
- **Backfill idempotency cadence:** per-row existence check with `echo "Exists"; continue;` (mirror `docs/groups/scripts/step_700_demo_data.php` lines 20–29).
- **Flag confirmations:** `rsvp_event`, `follow_user`, `pin_in_group` all exist in `docs/groups/config/flag.flag.*.yml`. No cross-story dep.

## Six event log points (corrected to firing hooks)
1. **Post created in group** — `#[Hook('group_relationship_insert')]` filtered to `group_content->getPluginId() LIKE 'group_node:%'`. Actor = current user, refs = node + group.
2. **Comment created** — `#[Hook('comment_insert')]`. Refs = comment + commented entity + (if in group context via entity access relationship) group.
3. **Membership created** — `#[Hook('group_relationship_insert')]` filtered to `group_membership` bundle. Actor = new member, refs = user + group.
4. **Flagging created** — `#[Hook('flagging_insert')]`. Handle `rsvp_event`, `follow_user`, and other flag ids in one method; branch on `$flagging->getFlagId()`.
5. **Group created** — `#[Hook('group_insert')]`. Actor = owner, refs = group.
6. **Pin toggle** — `#[Hook('flagging_insert')]` branch for `pin_in_group` (or dedicated hook if a separate storage exists — read `do_group_pin` to confirm). Also `flagging_delete` for unpin.

## Backfill (`docs/groups/scripts/step_7xx_backfill_activity.php`)
- **Order:** groups → memberships → nodes-in-groups → comments → flaggings. Each iterates all existing entities.
- **Idempotency key:** before creating a Message, query for existing Message where `template = X AND field_referenced_entity_type = Y AND field_referenced_entity_id = Z AND created = <source_timestamp>`. Skip if found (with `echo "Exists: ..."; continue;`).
- **Timestamp:** `Message::create([...])->setCreatedTime($source->getCreatedTime())`. Never `\Drupal::time()->getRequestTime()`.
- **Choice — backfill-after-seed** (subscribers stay enabled always; backfill script is safe on already-logged data because of the idempotency key). Rationale: same script covers prod/staging where seed never runs. Recorded per A question #3.

## Deletion hygiene
- `#[Hook('node_delete')]`, `#[Hook('comment_delete')]`, `#[Hook('group_relationship_delete')]`, `#[Hook('flagging_delete')]`, `#[Hook('group_delete')]` — hard-delete Message rows keyed by `(field_referenced_entity_type, field_referenced_entity_id)`.

## Uninstall boundary
`do_activity` `hook_uninstall`: delete only the module's own Message templates and only Message rows whose `template` field points at those templates. **Do NOT uninstall `message` or `message_notify`** — shared infrastructure.

## Seed-pipeline wiring (marker-section convention this story ESTABLISHES)
- Add `# --- do_activity step_7xx BEGIN ---` / `# --- do_activity step_7xx END ---` markers around the new step in **both**:
  - `deploy/entrypoint.sh` (in the block ~lines 100–157)
  - `.github/workflows/test.yml` (`Seed full demo data` step, lines 480–523)
- Marker text is the append convention — #113 can rebase against these when it lands.

## Owns (disjoint files, per §5)
- `composer.json` / `composer.lock` — append-only additions of `drupal/message` + `drupal/message_notify`.
- `docs/groups/config/message.template.*.yml` (shared) OR `docs/groups/modules/do_activity/config/install/message.template.*.yml` (module-internal). Prefer module-internal for the template config so uninstall cleans up cleanly.
- `docs/groups/modules/do_activity/**` — entire new module.
  - `do_activity.info.yml`
  - `do_activity.services.yml` (if any services)
  - `src/Hook/DoActivityHooks.php` (all `#[Hook(...)]` methods)
  - `do_activity.install` (hook_uninstall + hook_schema if needed)
- `docs/groups/scripts/step_7xx_backfill_activity.php`
- `deploy/entrypoint.sh` + `.github/workflows/test.yml` — marker sections.
- Kernel test files under `docs/groups/modules/do_activity/tests/src/Kernel/`.

## Acceptance
- Six kernel tests, one per log point; each creates fixtures and asserts exactly one Message with correct template, actor, referenced entity, and timestamp.
- One kernel test for the backfill: seed fixtures with `Message::create` disabled/mocked, run the backfill script, assert Messages present; run backfill again, assert Message count unchanged.
- One kernel test for deletion hygiene: create entity → verify Message exists → delete entity → verify Message row removed.
- Module enable/uninstall clean: uninstall does not delete shared `message` module tables; does delete `do_activity`'s templates + logged rows.
- Existing suite green; full-seed E2E (CI) green with backfill in the pipeline.

## Review rigor
`second-opinion` (per issue label). Applies to the diff-gate stage.

## DDEV rename
`.ddev/config.yaml` `name: gm116-activity` — committed. Do not revert.

## Concurrency context
Sibling worktrees active on #111, #124, #145. `.ddev` isolated per worktree. Never git-op in the primary checkout.
