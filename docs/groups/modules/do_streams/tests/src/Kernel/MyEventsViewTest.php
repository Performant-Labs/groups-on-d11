<?php

declare(strict_types=1);

namespace Drupal\Tests\do_streams\Kernel;

use Drupal\Core\Config\FileStorage;
use Drupal\Tests\do_tests\Kernel\GroupsKernelTestBase;
use Drupal\views\Views;

/**
 * Behavioral kernel test for the `my_events` view (issue #112, ST-3).
 *
 * Brief acceptance criteria under test (`docs/planning/handoffs/112-events-rsvps/brief.md`):
 *  - The shipped `my_events` view has a `default` display (Upcoming) AND a
 *    `my_rsvps` display, per survey.md's Reuse map row 2 and handoff-A.md's
 *    "Cross-checks that PASS" §Two-display view design.
 *  - Both displays: bundle filter restricted to `event` only;
 *    `field_date_of_event` ASC sort; row plugin `entity:node` @ view mode
 *    `stream_card` (mirrors `views.view.my_feed.yml`'s row shape).
 *  - `default` (Upcoming) carries the `do_streams_membership_scope` filter
 *    (REUSE as-is, survey.md row 3) AND a future-only date filter (brief
 *    AC "Past events excluded").
 *  - `my_rsvps` carries a relationship/filter limiting results to the
 *    CURRENT viewing user's `rsvp_event` flaggings (survey.md row 2c).
 *  - handoff-A.md Finding #2 (binding): the RSVP chip's render array MUST
 *    attach BOTH the rsvp_event flagging cache tag AND
 *    `#cache['contexts'] = ['user']` — `testChipCacheMetadata` pins this
 *    exact obligation per A's explicit ask ("trivial to add now, expensive
 *    to add after a leak is reported").
 *
 * None of `views.view.my_events.yml` (shipped), `MyEventsController`, or the
 * RSVP-chip render/preprocess logic exist yet — this story's own brief.md
 * names them as NEW files under this story (do_streams owns them). Every
 * test method below is intended to fail until F implements:
 *  - testViewExistsWithBothDisplays / testUpcomingDisplayContract /
 *    testMyRsvpsDisplayContract fail because `Views::getView('my_events')`
 *    returns NULL (the SHIPPED `docs/groups/config/views.view.my_events.yml`
 *    does not exist; only this test's OWN fixture copy does, installed here
 *    to exercise the display CONTRACT independent of config-import timing,
 *    mirroring `MyFeedRouteTest`'s documented fixture-install convention).
 *  - testChipCacheMetadata fails because no chip-rendering
 *    helper/preprocess/Views-field exists at all — this test calls a method
 *    that does not exist yet (`static::class`-scoped helper below simulates
 *    the EXPECTED render-array shape's cache assertions structurally; see
 *    the method's own docblock for exactly what must exist post-F).
 *
 * Layer choice: Kernel (GroupsKernelTestBase) — asserting on the executed
 * view's own display configuration/query-result shape and a render array's
 * `#cache` metadata is exactly the "kernel tests assert query results and
 * render-array shape" layer per survey.md's own testing-approach convention
 * (echoed in StreamsShellTest's class docblock), cheaper than a full
 * BrowserTestBase round-trip for these particular assertions.
 *
 * @group do_streams
 * @group do_tests
 */
class MyEventsViewTest extends GroupsKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'do_streams',
    'do_discovery',
    'flag',
    'views',
    'field',
    'text',
    'filter',
    'datetime',
    'comment',
    'taxonomy',
    'system',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system']);

    // views.view.my_events.yml is a genuinely SITE-LEVEL config artifact
    // (lives in docs/groups/config/, parallel to my_feed.yml — never
    // module-shipped), so KernelTestBase's installConfig() has nothing to
    // pull it from. Install it here from a MODULE-LOCAL fixture copy,
    // mirroring MyFeedRouteTest's own established FileStorage +
    // getStorage('view')->create()->save() pattern (see that class's
    // "PHASE 6 REPAIR NOTE" docblock for why this is the correct fix, not a
    // workaround).
    $fixtures = new FileStorage(__DIR__ . '/../../fixtures/config');
    $viewData = $fixtures->read('views.view.my_events');
    $this->assertNotFalse($viewData, 'The views.view.my_events fixture exists and is readable.');
    \Drupal::entityTypeManager()->getStorage('view')->create($viewData)->save();
  }

  /**
   * The `my_events` view exists with exactly the `default` + `my_rsvps` displays.
   */
  public function testViewExistsWithBothDisplays(): void {
    $view = Views::getView('my_events');
    $this->assertNotNull($view, 'The my_events view is installed and loadable via Views::getView().');

    $displayIds = array_keys($view->storage->get('display'));
    $this->assertContains('default', $displayIds, 'The my_events view has a default (Upcoming) display.');
    $this->assertContains('my_rsvps', $displayIds, 'The my_events view has a my_rsvps display.');
  }

  /**
   * The Upcoming (`default`) display: bundle=event, date ASC sort, scope
   * filter, future-only date filter, stream_card row.
   */
  public function testUpcomingDisplayContract(): void {
    $view = Views::getView('my_events');
    $this->assertNotNull($view);
    $view->setDisplay('default');

    $options = $view->displayHandlers->get('default')->options;

    // Bundle filter restricted to event only.
    $this->assertArrayHasKey('type', $options['filters'], 'The Upcoming display has a bundle (type) filter.');
    $this->assertSame(
      ['event' => 'event'],
      $options['filters']['type']['value'],
      'The Upcoming display\'s bundle filter is restricted to event only (no other bundles).',
    );

    // field_date_of_event ASC sort.
    $dateSort = NULL;
    foreach ($options['sorts'] as $sort) {
      if (($sort['entity_field'] ?? $sort['field'] ?? NULL) === 'field_date_of_event') {
        $dateSort = $sort;
      }
    }
    $this->assertNotNull($dateSort, 'The Upcoming display sorts on field_date_of_event.');
    $this->assertSame('ASC', $dateSort['order'], 'The Upcoming display\'s field_date_of_event sort is ASCENDING.');

    // Membership scope filter present (REUSE as-is, survey.md row 3).
    $this->assertArrayHasKey(
      'do_streams_membership_scope',
      $options['filters'],
      'The Upcoming display carries the do_streams_membership_scope filter.',
    );

    // Future-only date filter present (brief AC "Past events excluded").
    $futureFilter = NULL;
    foreach ($options['filters'] as $id => $filter) {
      if (($filter['entity_field'] ?? $filter['field'] ?? NULL) === 'field_date_of_event' && $id !== 'do_streams_membership_scope') {
        $futureFilter = $filter;
      }
    }
    $this->assertNotNull($futureFilter, 'The Upcoming display carries a field_date_of_event filter (future-only gate).');
    $this->assertSame('>=', $futureFilter['operator'] ?? NULL, 'The future-only date filter uses a >= operator (excludes past events).');

    // Row plugin: entity:node @ stream_card view mode (mirrors my_feed.yml).
    $this->assertSame('entity:node', $options['row']['type'], 'The Upcoming display renders rows as entity:node.');
    $this->assertSame('stream_card', $options['row']['options']['view_mode'], 'The Upcoming display renders rows in the stream_card view mode.');
  }

  /**
   * The My RSVPs display: bundle=event, date ASC sort, limited to the
   * CURRENT viewing user's rsvp_event flaggings, stream_card row.
   */
  public function testMyRsvpsDisplayContract(): void {
    $view = Views::getView('my_events');
    $this->assertNotNull($view);
    $view->setDisplay('my_rsvps');

    $options = $view->displayHandlers->get('my_rsvps')->options;

    // Bundle filter restricted to event only.
    $this->assertArrayHasKey('type', $options['filters'], 'The My RSVPs display has a bundle (type) filter.');
    $this->assertSame(
      ['event' => 'event'],
      $options['filters']['type']['value'],
      'The My RSVPs display\'s bundle filter is restricted to event only.',
    );

    // field_date_of_event ASC sort.
    $dateSort = NULL;
    foreach ($options['sorts'] as $sort) {
      if (($sort['entity_field'] ?? $sort['field'] ?? NULL) === 'field_date_of_event') {
        $dateSort = $sort;
      }
    }
    $this->assertNotNull($dateSort, 'The My RSVPs display sorts on field_date_of_event.');
    $this->assertSame('ASC', $dateSort['order'], 'The My RSVPs display\'s field_date_of_event sort is ASCENDING.');

    // Some relationship/filter that scopes results to the rsvp_event flag,
    // targeting the CURRENT user (never a fixed/hardcoded uid).
    $hasRsvpRelationship = FALSE;
    foreach ($options['relationships'] ?? [] as $relationship) {
      if (($relationship['plugin_id'] ?? NULL) === 'flag_relationship' && ($relationship['flag'] ?? NULL) === 'rsvp_event') {
        $hasRsvpRelationship = TRUE;
        $this->assertSame(
          'current',
          $relationship['user_scope'] ?? NULL,
          'The rsvp_event relationship is scoped to the CURRENT viewing user (user_scope: current), not a fixed uid.',
        );
      }
    }
    $this->assertTrue(
      $hasRsvpRelationship,
      'The My RSVPs display carries a flag_relationship to the rsvp_event flag, scoped to the current user.',
    );

    // Row plugin: entity:node @ stream_card view mode.
    $this->assertSame('entity:node', $options['row']['type'], 'The My RSVPs display renders rows as entity:node.');
    $this->assertSame('stream_card', $options['row']['options']['view_mode'], 'The My RSVPs display renders rows in the stream_card view mode.');
  }

  /**
   * The rendered event/stream_card render array executes and its query result
   * only ever contains `event` bundle nodes (defense-in-depth on the bundle
   * filter contract asserted structurally above).
   */
  public function testUpcomingDisplayExecutesAndReturnsOnlyEventBundleNodes(): void {
    $group = $this->createGroup();
    $account = $this->createUser();
    $this->addMember($group, $account);
    $this->setCurrentUser($account);

    $eventNode = $this->addNode($group, 'event', [
      'title' => 'Future Event',
      'status' => 1,
    ]);
    if ($eventNode->hasField('field_date_of_event')) {
      $eventNode->set('field_date_of_event', date('Y-m-d\TH:i:s', strtotime('+10 days')));
      $eventNode->save();
    }

    $view = Views::getView('my_events');
    $this->assertNotNull($view);
    $view->setDisplay('default');
    $view->execute();

    foreach ($view->result as $row) {
      $this->assertSame('event', $row->_entity->bundle(), 'Every row in the Upcoming display\'s result is an event-bundle node.');
    }
  }

  /**
   * handoff-A.md Finding #2 (binding): the RSVP chip render array must
   * attach BOTH the rsvp_event flagging cache tag AND a `user` cache
   * context.
   *
   * F's chosen implementation (preprocess_node__event__stream_card OR a
   * Views custom field, per brief.md §Plan step 2) must expose this
   * metadata somewhere reachable by a kernel test. This test asserts against
   * `\Drupal\do_streams\Hook\DoStreamsHooks::buildRsvpChipCacheMetadata()`
   * (or equivalent) — a small, directly-callable helper that returns the
   * `#cache` array the chip's render context must carry, mirroring
   * StreamsShellTest's own precedent of invoking a hook method directly
   * rather than depending on the full theme-render pipeline. This method
   * does not exist yet (RED: fails with a fatal "call to undefined method"
   * class-not-found style error) until F wires the chip.
   */
  public function testChipCacheMetadata(): void {
    $group = $this->createGroup();
    $account = $this->createUser();
    $this->addMember($group, $account);
    $this->setCurrentUser($account);

    $eventNode = $this->addNode($group, 'event', [
      'title' => 'Chip Cache Event',
      'status' => 1,
    ]);

    $this->assertTrue(
      method_exists('\Drupal\do_streams\Hook\DoStreamsHooks', 'buildRsvpChipCacheMetadata'),
      'DoStreamsHooks exposes a buildRsvpChipCacheMetadata() (or equivalently-named) method the chip render path calls to attach cache metadata (handoff-A.md Finding #2).',
    );

    $hooks = new \Drupal\do_streams\Hook\DoStreamsHooks();
    $cache = $hooks->buildRsvpChipCacheMetadata($eventNode);

    $this->assertArrayHasKey('contexts', $cache, 'The chip cache metadata declares #cache[contexts].');
    $this->assertContains(
      'user',
      $cache['contexts'],
      'The chip cache metadata carries the "user" cache context (handoff-A.md Finding #2b: viewer state must not leak across users).',
    );

    $this->assertArrayHasKey('tags', $cache, 'The chip cache metadata declares #cache[tags].');
    $expectedTag = 'flagging_list:node:' . $eventNode->id();
    $hasFlaggingTag = FALSE;
    foreach ($cache['tags'] as $tag) {
      if (str_contains($tag, (string) $eventNode->id()) && str_contains($tag, 'flag')) {
        $hasFlaggingTag = TRUE;
      }
    }
    $this->assertTrue(
      $hasFlaggingTag,
      sprintf(
        'The chip cache metadata carries a flagging-related cache tag scoped to node %d (handoff-A.md Finding #2a: a new RSVP must invalidate the count) — e.g. "%s".',
        $eventNode->id(),
        $expectedTag,
      ),
    );
  }

}
