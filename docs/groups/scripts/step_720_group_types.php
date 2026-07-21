<?php

/**
 * @file
 * step_720_group_types.php — provision group TYPES for the Groups demo.
 *
 * Single source of truth shared by the container entrypoint (deploy/entrypoint.sh)
 * and the CI E2E job (.github/workflows/test.yml). Creates the field_group_type
 * field (entity_reference -> taxonomy), the group_type vocabulary + 5 terms, tags
 * the 8 demo groups, and surfaces the Group Type widget on the community_group
 * form display. The field config is NOT in config/sync (step_200/step_220 are
 * never run on deploy), so without this the /all-groups type badges + Archive
 * enforcement (do_group_extras) + the #89 form tooltip have nothing to read.
 *
 * Demo-only: this adds a demo FIELD + terms via the entity API on an already
 * installed site; it introduces no change to the shipped module schema. Fully
 * idempotent — safe to re-run over a persistent volume or a fresh CI install.
 *
 * Must run as uid 1 (see the seed-as-admin wrapper the callers build) so the
 * group re-saves don't trip do_group_extras' unpublish-on-create presave hook.
 */

use Drupal\field\Entity\FieldStorageConfig;
use Drupal\field\Entity\FieldConfig;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\taxonomy\Entity\Term;

$etm = \Drupal::entityTypeManager();

// 1. group_type vocabulary.
if (!$etm->getStorage('taxonomy_vocabulary')->load('group_type')) {
  Vocabulary::create([
    'vid' => 'group_type',
    'name' => 'Group Type',
    'description' => 'Categorises groups (geographical, working group, distribution, etc.)',
  ])->save();
  echo "created vocabulary group_type\n";
}

// 2. field_group_type storage + instance on community_group.
if (!FieldStorageConfig::loadByName('group', 'field_group_type')) {
  FieldStorageConfig::create([
    'field_name' => 'field_group_type',
    'entity_type' => 'group',
    'type' => 'entity_reference',
    'cardinality' => 1,
    'settings' => ['target_type' => 'taxonomy_term'],
  ])->save();
  echo "created field storage field_group_type\n";
}
if (!FieldConfig::loadByName('group', 'community_group', 'field_group_type')) {
  FieldConfig::create([
    'field_name' => 'field_group_type',
    'entity_type' => 'group',
    'bundle' => 'community_group',
    'label' => 'Group Type',
    'required' => FALSE,
    'settings' => [
      'handler' => 'default:taxonomy_term',
      'handler_settings' => ['target_bundles' => ['group_type' => 'group_type']],
    ],
  ])->save();
  echo "created field instance field_group_type\n";
}

// 3. The 5 group_type terms.
$term_storage = $etm->getStorage('taxonomy_term');
$term_defs = [
  ['Geographical',  'Local user groups by city/region'],
  ['Working group', 'Module, feature, or initiative coordination'],
  ['Distribution',  'Drupal distribution projects'],
  ['Event planning','DrupalCon and camp organising'],
  ['Archive',       'Inactive groups (read-only)'],
];
$term_by_name = [];
foreach ($term_defs as [$name, $desc]) {
  $existing = $term_storage->loadByProperties(['vid' => 'group_type', 'name' => $name]);
  if ($existing) {
    $term_by_name[$name] = reset($existing);
    continue;
  }
  $term = Term::create([
    'vid' => 'group_type',
    'name' => $name,
    'description' => ['value' => $desc, 'format' => 'plain_text'],
  ]);
  $term->save();
  $term_by_name[$name] = $term;
  echo "created term $name (tid=" . $term->id() . ")\n";
}

// 4. Tag the 8 demo groups. Map by label -> group_type term name.
$group_type_map = [
  'DrupalCon Portland 2026' => 'Event planning',
  'Drupal France'           => 'Geographical',
  'Core Committers'         => 'Working group',
  'Thunder Distribution'    => 'Distribution',
  'Leadership Council'      => 'Working group',
  'Camp Organizers EMEA'    => 'Event planning',
  'Drupal Deutschland'      => 'Geographical',
  'Legacy Infrastructure'   => 'Archive',
];
$group_storage = $etm->getStorage('group');
foreach ($group_type_map as $label => $type_name) {
  $groups = $group_storage->loadByProperties(['label' => $label]);
  $group = reset($groups);
  if (!$group) { echo "SKIP tag: group not found: $label\n"; continue; }
  if (!$group->hasField('field_group_type')) { continue; }
  $term = $term_by_name[$type_name] ?? NULL;
  if (!$term) { continue; }
  $current = $group->get('field_group_type')->target_id;
  if ($current == $term->id()) { continue; }
  $group->set('field_group_type', $term->id());
  $group->save();
  echo "tagged '$label' -> $type_name (tid=" . $term->id() . ")\n";
}

// 5. Surface the Group Type widget on the community_group add/edit form (#89).
// The field was provisioned above but is HIDDEN on the default form display, so
// a group creator never sees / picks a type. do_chrome's #89 field-level "ⓘ"
// tooltip needs the <select> to exist on the form to anchor to it. This is a
// display-config change only (no schema change): it moves field_group_type out
// of the form display's `hidden` region into `content`. Done here (not in
// config/sync) because the field itself is provisioned at runtime, so a
// config:import that referenced it would fail on the missing field dependency.
// Idempotent — re-running just re-asserts the same component.
$form_display = $etm->getStorage('entity_form_display')->load('group.community_group.default');
if ($form_display && !$form_display->getComponent('field_group_type')) {
  $form_display->setComponent('field_group_type', [
    'type' => 'options_select',
    'weight' => 4,
    'region' => 'content',
  ])->save();
  echo "surfaced field_group_type on the community_group form display\n";
}

echo count($term_storage->loadByProperties(['vid' => 'group_type'])) . " group_type terms total\n";
