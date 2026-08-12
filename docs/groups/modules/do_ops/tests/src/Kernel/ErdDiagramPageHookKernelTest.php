<?php

declare(strict_types=1);

namespace Drupal\Tests\do_ops\Kernel;

use Drupal\do_ops\Hook\ErdDiagramPageHook;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\Tests\do_tests\Kernel\GroupsKernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Verifies the /er-diagram page's live-render hook, not just the generator.
 *
 * Covers the two failure modes a manual smoke test caught during
 * development: (1) the hook must only fire for the ONE node State points at
 * (not every page node), and (2) the diagram must be delivered via
 * `#lazy_builder` — a plain child render array with `#cache max-age: 0` is
 * NOT enough to force re-execution once wrapped in the node view builder's
 * own per-entity render cache, so this asserts the actual `#lazy_builder`
 * shape rather than just the final rendered HTML (which a Kernel test, with
 * no full page/theme layer, cannot exercise anyway).
 *
 * @group do_ops
 * @group group
 */
#[RunTestsInSeparateProcesses]
class ErdDiagramPageHookKernelTest extends GroupsKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['group', 'gnode', 'options', 'node', 'do_ops'];

  private ErdDiagramPageHook $hook;

  protected function setUp(): void {
    parent::setUp();
    // GroupsKernelTestBase::setUp() already installs the node entity schema
    // and creates a 'page' node type as one of NODE_BUNDLES (for the
    // group_node:page relationship type) — reuse it rather than re-creating.
    $this->hook = $this->container->get('do_ops.erd_diagram_page_hook');
  }

  private function createPage(): NodeInterface {
    $node = Node::create([
      'type' => 'page',
      'title' => 'ER Diagram',
      'uid' => $this->getCurrentUser()->id(),
    ]);
    $node->save();
    return $node;
  }

  private function buildFor(NodeInterface $node, string $view_mode = 'full'): array {
    $build = [];
    $display = $this->container->get('entity_display.repository')->getViewDisplay('node', $node->bundle(), $view_mode);
    $this->hook->entityView($build, $node, $display, $view_mode);
    return $build;
  }

  /**
   * The hook is a no-op until State points at a real node.
   */
  public function testNoStateMeansNoInjection(): void {
    $node = $this->createPage();
    $build = $this->buildFor($node);
    $this->assertArrayNotHasKey('do_ops_erd_diagram', $build);
  }

  /**
   * Only the node id stored in State gets the diagram injected.
   */
  public function testOnlyTheConfiguredNodeGetsTheDiagram(): void {
    $target = $this->createPage();
    $other = $this->createPage();
    $this->container->get('state')->set('do_ops.erd_diagram_nid', (int) $target->id());

    $target_build = $this->buildFor($target);
    $this->assertArrayHasKey('do_ops_erd_diagram', $target_build);

    $other_build = $this->buildFor($other);
    $this->assertArrayNotHasKey('do_ops_erd_diagram', $other_build);
  }

  /**
   * Non-full view modes (teaser, etc.) never get the diagram, even on the
   * configured node — it belongs on the canonical page only.
   */
  public function testOnlyFullViewModeGetsTheDiagram(): void {
    $target = $this->createPage();
    $this->container->get('state')->set('do_ops.erd_diagram_nid', (int) $target->id());

    $build = $this->buildFor($target, 'teaser');
    $this->assertArrayNotHasKey('do_ops_erd_diagram', $build);
  }

  /**
   * The injected element is a `#lazy_builder` placeholder, not a plain child
   * array — see class docblock for why that distinction is load-bearing.
   */
  public function testInjectionIsALazyBuilder(): void {
    $target = $this->createPage();
    $this->container->get('state')->set('do_ops.erd_diagram_nid', (int) $target->id());

    $build = $this->buildFor($target);
    $this->assertSame(
      ['do_ops.erd_diagram_page_hook:buildDiagram', []],
      $build['do_ops_erd_diagram']['#lazy_builder'] ?? NULL,
    );
    $this->assertTrue($build['do_ops_erd_diagram']['#create_placeholder'] ?? FALSE);
  }

  /**
   * `buildDiagram()` — the lazy_builder callback itself — renders the
   * escaped diagram inside a `.mermaid` element and attaches the library.
   */
  public function testBuildDiagramRendersEscapedMermaidBlock(): void {
    $result = $this->hook->buildDiagram();

    $this->assertSame('container', $result['#type']);
    $this->assertSame(['do_ops/erd_diagram'], $result['#attached']['library']);
    $this->assertSame('html_tag', $result['diagram']['#type']);
    $this->assertSame('pre', $result['diagram']['#tag']);
    $this->assertSame(['mermaid'], $result['diagram']['#attributes']['class']);
    $this->assertStringStartsWith('erDiagram', $result['diagram']['#value']);
    // group_relationship's gid field always resolves to group, same
    // assertion ErdGeneratorKernelTest makes directly against the generator
    // — here proving the hook's own escaped #value carries it through.
    $this->assertMatchesRegularExpression('/group_relationship \}o--\|\| group : &quot;gid&quot;/', $result['diagram']['#value']);
  }

  /**
   * `buildDiagram()` is registered as a trusted callback — required for
   * `#lazy_builder` to actually execute it (an unregistered callback throws
   * at render time instead of silently no-op'ing, so this is a real
   * correctness assertion, not boilerplate).
   */
  public function testBuildDiagramIsATrustedCallback(): void {
    $this->assertContains('buildDiagram', ErdDiagramPageHook::trustedCallbacks());
  }

}
