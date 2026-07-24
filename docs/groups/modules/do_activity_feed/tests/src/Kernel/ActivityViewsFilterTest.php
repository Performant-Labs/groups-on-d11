<?php

declare(strict_types=1);

namespace Drupal\Tests\do_activity_feed\Kernel;

/**
 * `hook_views_data` registration for ActivityMembershipScope (#129 A-advisory #3).
 *
 * Small, standalone test per the brief: "The ActivityMembershipScope
 * synthetic filter field MUST be registered via hook_views_data on the
 * message_field_data base table ... Without this, the filter plugin cannot
 * be attached in the view config." This test pins ONLY the registration
 * contract (the views.views_data service exposes the synthetic field with
 * the right filter plugin id) — it deliberately does NOT execute a view or
 * assert scoping behavior; that behavior-level proof belongs to
 * ActivityFeedRenderTest's AC-3 (access scoping), which already covers it
 * end-to-end. Duplicating a full view-execution here would be redundant
 * coverage of the same acceptance criterion at a more expensive tier.
 *
 * Mirrors do_streams' own `DoStreamsHooks::viewsData()`
 * (docs/groups/modules/do_streams/src/Hook/DoStreamsHooks.php ~L368), which
 * registers `do_streams_membership_scope` on `node_field_data` the same way.
 *
 * RED reason: `do_activity_feed` does not exist yet — no `DoActivityFeedHooks`
 * class, no `#[Hook('views_data')]` implementation — so
 * `views.views_data`'s cached definition for `message_field_data` has no
 * `do_activity_feed_membership_scope` key at all. The assertion fails
 * because the key is genuinely absent, not because of a setup/typo error.
 *
 * @group do_activity_feed
 * @group do_tests
 */
class ActivityViewsFilterTest extends ActivityFeedKernelTestBase {

  /**
   * The message_field_data base table registers the synthetic scope field.
   */
  public function testActivityMembershipScopeIsRegisteredOnMessageFieldData(): void {
    /** @var \Drupal\views\ViewsData $viewsData */
    $viewsData = \Drupal::service('views.views_data');
    $tableData = $viewsData->get('message_field_data');

    $this->assertIsArray($tableData, 'message_field_data has Views data registered at all (message module\'s own base views.views.inc integration).');
    $this->assertArrayHasKey(
      'do_activity_feed_membership_scope',
      $tableData,
      'do_activity_feed registers its synthetic do_activity_feed_membership_scope field on message_field_data via hook_views_data.'
    );

    $fieldDefinition = $tableData['do_activity_feed_membership_scope'];
    $this->assertArrayHasKey('filter', $fieldDefinition, 'The synthetic field carries a filter plugin definition.');
    $this->assertSame(
      'do_activity_feed_membership_scope',
      $fieldDefinition['filter']['id'] ?? NULL,
      'The registered filter plugin id is do_activity_feed_membership_scope, matching the #[ViewsFilter(...)] attribute ActivityMembershipScope must declare.'
    );
  }

  /**
   * The Views filter plugin manager can actually instantiate the plugin.
   *
   * A views_data registration alone does not prove the plugin CLASS exists
   * and is discoverable by Views' own plugin manager — this second
   * assertion closes that gap, mirroring how a view config's filter
   * `plugin_id` is resolved at render time (per the brief's own
   * cross-reference to "the same discovery rule T's handoff-T-red.md
   * documents for the ranking argument," i.e. do_streams' MembershipScope
   * precedent).
   */
  public function testActivityMembershipScopeFilterPluginIsDiscoverable(): void {
    /** @var \Drupal\views\Plugin\ViewsHandlerManager $filterManager */
    $filterManager = \Drupal::service('plugin.manager.views.filter');
    $this->assertTrue(
      $filterManager->hasDefinition('do_activity_feed_membership_scope'),
      'The do_activity_feed_membership_scope filter plugin is discoverable by the Views filter plugin manager.'
    );
  }

}
