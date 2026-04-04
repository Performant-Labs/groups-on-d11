<?php
/**
 * Step 160b: Update attachment field limits (15 MB, expanded extensions).
 * Usage: ddev drush php:script docs/groups/do_runbook/scripts/step_160b.php
 */

foreach (["forum", "documentation", "event", "post", "page"] as $type) {
  $field = \Drupal\field\Entity\FieldConfig::loadByName("node", $type, "field_files");
  if ($field) {
    $field->setSetting("max_filesize", "15 MB");
    $field->setSetting("file_extensions", "pdf doc docx xls xlsx ppt pptx txt rtf odt ods odp zip gz tar png jpg jpeg gif svg");
    $field->save();
    echo "Updated $type field_files: 15MB + expanded extensions\n";
  }
}
