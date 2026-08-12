<?php

declare(strict_types=1);

namespace Drupal\do_ops\Erd;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;

/**
 * Walks entity/field metadata and renders a deterministic Mermaid erDiagram.
 *
 * Deliberately does NOT introspect the database schema. Drupal declares no
 * database-level foreign keys, so information_schema-based tools (SchemaSpy,
 * Atlas, etc.) render every table as a disconnected box. The relationships
 * that actually matter — Group to User via membership, Group to content via
 * a `group_relationship` — live in entity/field metadata instead: each
 * `group_relationship_type` bundle overrides its `entity_id` base field's
 * `target_type` setting to the plugin's target entity type (see
 * \Drupal\group\Plugin\Group\RelationHandlerDefault\EntityReference::configureField()).
 * Walking {@see EntityFieldManagerInterface::getFieldDefinitions()} per
 * bundle picks that override up the same way Drupal itself resolves it at
 * runtime, so it requires an actual site (real group_relationship_type
 * config) rather than static plugin definitions alone.
 *
 * Edges follow the standard entity-reference-field ER convention: the entity
 * that carries the reference field is drawn on the "many" side (an
 * unconstrained number of source entities may reference the same target),
 * and the target side is "one" unless the field itself is multi-valued (its
 * storage cardinality is not 1), in which case both sides are "many".
 */
final class ErdGenerator {

  /**
   * Seed entity types for the `group` scope diagram.
   *
   * The group/group_type/group_role/group_relationship_type/group_relationship
   * quintet the brief calls out by name. Entities discovered by walking their
   * reference fields (typically `user`, `node`) are added as leaf boxes, but
   * are not themselves walked — that is what keeps the `group` scope
   * readable instead of pulling in the rest of the site.
   *
   * @var string[]
   */
  public const GROUP_SCOPE_ENTITY_TYPES = [
    'group',
    'group_relationship',
    'group_relationship_type',
    'group_role',
    'group_type',
  ];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityTypeBundleInfoInterface $bundleInfo,
    private readonly EntityFieldManagerInterface $entityFieldManager,
  ) {}

  /**
   * Generates the Mermaid `erDiagram` body (no code fence, no title).
   *
   * @param string $scope
   *   Either 'group' (the seed set above, plus directly-referenced leaf
   *   entity types) or 'all' (every entity type on the site, fully walked).
   *
   * @return string
   *   The Mermaid diagram source, starting with `erDiagram`. Deterministic:
   *   calling this twice against the same site config produces byte-identical
   *   output — entity types, bundles, fields, and edges are all sorted.
   */
  public function generate(string $scope = 'group'): string {
    if (!in_array($scope, ['group', 'all'], TRUE)) {
      throw new \InvalidArgumentException(sprintf("Invalid scope '%s'; expected 'group' or 'all'.", $scope));
    }

    if ($scope === 'all') {
      $seeds = array_keys($this->entityTypeManager->getDefinitions());
      $expand_discovered = TRUE;
    }
    else {
      $seeds = self::GROUP_SCOPE_ENTITY_TYPES;
      $expand_discovered = FALSE;
    }
    sort($seeds);

    [$node_ids, $edges] = $this->walk($seeds, $expand_discovered);

    return $this->render($node_ids, $edges);
  }

  /**
   * Walks entity types breadth-first, collecting nodes and reference edges.
   *
   * @param string[] $seeds
   *   Entity type ids to start from.
   * @param bool $expand_discovered
   *   Whether entity types discovered via a reference field's target_type
   *   should themselves be walked (scope=all) or only added as leaf boxes
   *   (scope=group).
   *
   * @return array{0: string[], 1: array<string, array{from: string, to: string, field: string, many_target: bool}>}
   *   A tuple of [sorted node entity-type ids, edges keyed for de-duplication].
   */
  private function walk(array $seeds, bool $expand_discovered): array {
    $queue = $seeds;
    $visited = [];
    $node_ids = [];
    $edges = [];

    while ($queue) {
      $id = array_shift($queue);
      if (isset($visited[$id])) {
        continue;
      }
      $visited[$id] = TRUE;

      $definition = $this->entityTypeManager->getDefinitions()[$id] ?? NULL;
      if ($definition === NULL) {
        continue;
      }
      $node_ids[$id] = TRUE;

      // The bundle-of relationship (e.g. group_relationship ->
      // group_relationship_type, group -> group_type) is generic
      // entity-type metadata, not a Field API field, so it is derived
      // separately here rather than being found by the field walk below.
      $bundle_entity_type = $definition->getBundleEntityType();
      if ($bundle_entity_type !== NULL) {
        $edge_key = $id . '::' . $bundle_entity_type . '::type';
        $edges[$edge_key] ??= [
          'from' => $id,
          'to' => $bundle_entity_type,
          'field' => 'type',
          'many_target' => FALSE,
        ];
        if (!isset($visited[$bundle_entity_type])) {
          if ($expand_discovered) {
            $queue[] = $bundle_entity_type;
          }
          else {
            $node_ids[$bundle_entity_type] = TRUE;
          }
        }
      }

      if (!is_a($definition->getClass(), FieldableEntityInterface::class, TRUE)) {
        continue;
      }

      $bundles = array_keys($this->bundleInfo->getBundleInfo($id));
      sort($bundles);

      foreach ($bundles as $bundle) {
        $fields = $this->entityFieldManager->getFieldDefinitions($id, $bundle);
        ksort($fields);

        foreach ($fields as $field_name => $field) {
          $storage = $field->getFieldStorageDefinition();
          $target_type = $storage->getSetting('target_type');
          if (!$target_type) {
            continue;
          }

          // Config entities referenced through group_relationship's dynamic
          // target field are wrapped in a `group_config_wrapper`; unwrap to
          // the real config entity type so the diagram names it directly.
          if ($target_type === 'group_config_wrapper') {
            $handler_settings = $storage->getSetting('handler_settings') ?? [];
            $wrapped = $handler_settings['target_bundles'][0] ?? NULL;
            if (!$wrapped) {
              continue;
            }
            $target_type = $wrapped;
          }

          $many_target = $storage->getCardinality() !== 1;

          $edge_key = $id . '::' . $target_type . '::' . $field_name;
          if (isset($edges[$edge_key])) {
            // A field can repeat across bundles with differing cardinality
            // (e.g. one bundle override raises it to unlimited) — once any
            // bundle is many-valued, the edge is many-valued.
            $edges[$edge_key]['many_target'] = $edges[$edge_key]['many_target'] || $many_target;
            continue;
          }
          $edges[$edge_key] = [
            'from' => $id,
            'to' => $target_type,
            'field' => $field_name,
            'many_target' => $many_target,
          ];

          if (isset($visited[$target_type])) {
            continue;
          }
          if ($expand_discovered) {
            $queue[] = $target_type;
          }
          else {
            // Leaf box only: record the node without walking its fields.
            $node_ids[$target_type] = TRUE;
          }
        }
      }
    }

    $node_ids = array_keys($node_ids);
    sort($node_ids);

    return [$node_ids, $edges];
  }

  /**
   * Renders sorted nodes/edges as Mermaid `erDiagram` source.
   *
   * @param string[] $node_ids
   *   Sorted entity type ids to render as ER boxes.
   * @param array<string, array{from: string, to: string, field: string, many_target: bool}> $edges
   *   Edges keyed by `from::to::field`, as built by {@see self::walk()}.
   *
   * @return string
   *   The Mermaid diagram source.
   */
  private function render(array $node_ids, array $edges): string {
    $lines = ['erDiagram'];

    foreach ($node_ids as $id) {
      $definition = $this->entityTypeManager->getDefinitions()[$id] ?? NULL;
      $label = $definition ? (string) $definition->getLabel() : $id;
      $lines[] = sprintf('  %s {', $id);
      $lines[] = sprintf('    string label "%s"', $this->escapeLabel($label));
      $lines[] = '  }';
    }

    $edge_keys = array_keys($edges);
    sort($edge_keys);

    foreach ($edge_keys as $key) {
      $edge = $edges[$key];
      // Source side is always "many": an unbounded number of $from entities
      // may carry a reference to the same $to entity. Target side reflects
      // the field's own storage cardinality.
      $relationship = $edge['many_target'] ? '}o--o{' : '}o--||';
      $lines[] = sprintf('  %s %s %s : "%s"', $edge['from'], $relationship, $edge['to'], $edge['field']);
    }

    return implode("\n", $lines) . "\n";
  }

  /**
   * Escapes a label for safe use inside a Mermaid double-quoted string.
   */
  private function escapeLabel(string $label): string {
    return str_replace('"', '#quot;', $label);
  }

}
