# O's response to o4-mini diff-review — #140

## BLOCK findings — REJECTED (both false positives, verified)

### B-1: `#[Hook('preprocess_group')]` — REJECTED
o4-mini couldn't verify the attribute-hook mechanism from the diff alone. Verified against the codebase:

- `docs/groups/modules/do_chrome/src/Hook/ArchivePinHooks.php:54` uses the identical `#[Hook('preprocess_group')]` attribute on the identical hook, in shipped/merged code.
- The parent class `DoGroupExtrasHooks` already carries `#[Hook('form_alter')]`, `#[Hook('entity_presave')]`, `#[Hook('entity_insert')]`, and `#[Hook('node_access')]` — all proven-working in prior merged stories (#42, #143).
- `web/modules/contrib/group/src/Hook/ThemeHooks.php:48` defines the theme hook and its 'initial preprocess'; Drupal 11 core hook discovery picks up ProceduralCall-attribute wrappers on preprocess_* the same as any other hook.
- **Empirical evidence:** T's live E2E against the seeded DDEV site showed the `.field--name-field-group-links` styled correctly, which requires the library attach fired via this exact hook.

Not blocking.

### B-2: `docs/groups/config/` never reaches Drupal — REJECTED
o4-mini couldn't see the build pipeline. Verified:

- `scripts/ci/assemble-config.sh` explicitly copies every `docs/groups/config/*.yml` (minus a documented 7-file env-specific exclude set) into `config/sync/` on every CI run and every local RUNBOOK bring-up. Read lines 60–84 of the script.
- `core.extension.yml` is patched in the same script to register the custom `do_*` modules.
- **Empirical evidence:** T ran `assemble-config.sh`, saw all 4 new/edited files land in `config/sync/`, then ran a live seeded DDEV E2E that hit `/group/<gid>` and asserted the field renders.

Not blocking.

## WARN findings — ACCEPTED

### W-1: kernel test doesn't assert `title` setting = 2 (Required) — ACCEPTED
Route to T: add `$this->assertSame(2, $instance->getSetting('title'))` in `testInstanceExists()` so a drift to Optional fails the test. Low-cost regression pin on our own explicit deviation from the task-prompt literal.

### W-2: library attach not scoped to view mode + non-empty field — ACCEPTED
Route to F: change the `preprocessGroup()` attach condition to
```php
if ($variables['view_mode'] === 'default'
    && $group->hasField('field_group_links')
    && !$group->get('field_group_links')->isEmpty()) {
  $variables['#attached']['library'][] = 'do_group_extras/group-links';
}
```
Cleaner + avoids attaching CSS on empty-state pages. Cheap.

## NIT findings — SKIPPED (POC posture)

- **NIT-1:** `print`/`echo` in seed script matches every other `step_*.php`. Not a style outlier here.
- **NIT-2:** `region: content` on form displays is a Drupal-core standard export shape (present in every existing `core.entity_form_display.group.community_group.default.yml` component). Removing it would drift from what `drush cex` would produce. Not touching.

## Decision
- Both BLOCKs rejected with cited evidence.
- Both WARNs accepted → 1-line F edit (attach scope) + 1-line T assertion (title setting).
- Proceeding to A-dup (Phase 7) will fold in the two W edits before U/S.

**Cost:** o4-mini round 1 only. Round 2 not needed (BLOCKs are factually wrong; escalating won't change ground truth).
