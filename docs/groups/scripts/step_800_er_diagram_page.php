<?php

/**
 * @file
 * Step 800 (do_ops): the "ER Diagram" page at /er-diagram.
 *
 * Creates a standard `page` content-type node — not a Drupal Canvas page —
 * titled "ER Diagram", with a short intro body and the `/er-diagram` URL
 * alias. The diagram itself is NOT stored in the node body: it is injected
 * live at render time by \Drupal\do_ops\Hook\ErdDiagramPageHook, which reads
 * the node id this script stores in State (`do_ops.erd_diagram_nid`) to find
 * its page. That is what keeps the page's diagram always current — the
 * hook re-generates it from live entity/field metadata on every view, so it
 * can never drift the way a hand-pasted copy would.
 *
 * Idempotent: if State already points at a node that still exists, this is a
 * no-op (re-running a seed must never duplicate the page or its alias).
 */

use Drupal\node\Entity\Node;
use Drupal\path_alias\Entity\PathAlias;

echo "\n=== Step 800: ER Diagram page (/er-diagram) ===\n";

$state = \Drupal::state();
$node_storage = \Drupal::entityTypeManager()->getStorage('node');

$existing_nid = $state->get('do_ops.erd_diagram_nid');
if ($existing_nid && $node_storage->load($existing_nid)) {
  echo "Exists: ER Diagram page (nid={$existing_nid})\n";
  echo "=== Step 800 complete ===\n";
  return;
}

$node = Node::create([
  'type' => 'page',
  'title' => 'ER Diagram',
  'uid' => 1,
  'status' => 1,
  'body' => [
    'value' => '<p>This page shows the current Groups entity-relationship diagram, generated live from Drupal entity and field metadata (not hand-maintained). The same diagram, generated the same way, is committed at <code>docs/architecture/groups-erd.md</code> in the repository — run <code>drush groups:erd --scope=group</code> to regenerate it yourself.</p>',
    'format' => 'basic_html',
  ],
]);
$node->save();

$alias = PathAlias::create([
  'path' => '/node/' . $node->id(),
  'alias' => '/er-diagram',
]);
$alias->save();

$state->set('do_ops.erd_diagram_nid', (int) $node->id());

echo "Created: ER Diagram page (nid={$node->id()}) -> /er-diagram\n";
echo "=== Step 800 complete ===\n";
