<?php

declare(strict_types=1);

namespace Drupal\do_showcase\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\do_showcase\Persona\PersonaSwitcher;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides the "Browse as" persona-switcher block (#120 SC-1).
 *
 * Wireframe.md §1: placed in the site header, account-menu region (same nav
 * row as #110's account links) — append-only block placement. Visible to
 * ALL visitors (anonymous AND authenticated-as-persona).
 *
 * Delegates entirely to `\Drupal\do_showcase\Persona\PersonaSwitcher::build()`
 * (the `do_showcase.persona_switcher` service) — this plugin's only job is
 * the Block-plugin wiring (id, category, cache propagation); no rendering
 * logic lives here.
 *
 * @Block(
 *   id = "persona_switcher",
 *   admin_label = @Translation("Persona switcher (Browse as)"),
 *   category = @Translation("Do Showcase")
 * )
 */
final class PersonaSwitcherBlock extends BlockBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly PersonaSwitcher $personaSwitcher,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('do_showcase.persona_switcher'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    return $this->personaSwitcher->build();
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts() {
    // PersonaSwitcher::build() itself declares #cache[contexts]=>[user] on
    // its returned render array (Amendment 6), which Drupal's renderer
    // bubbles up automatically when this block is placed — declaring it
    // here too keeps the block-plugin-level cache metadata contract
    // explicit/self-documenting for any code that inspects the block
    // plugin directly (e.g. a Kernel test) without a full render pass.
    return Cache::mergeContexts(parent::getCacheContexts(), ['user']);
  }

}
