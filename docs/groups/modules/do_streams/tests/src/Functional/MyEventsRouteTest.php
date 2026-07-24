<?php

declare(strict_types=1);

namespace Drupal\Tests\do_streams\Functional;

use Drupal\Core\Config\FileStorage;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\Tests\BrowserTestBase;

/**
 * Behavioral Functional test for the new `/my-feed/events` route (issue #112, ST-3).
 *
 * Brief acceptance criteria under test (`docs/planning/handoffs/112-events-rsvps/brief.md`):
 *  - Anonymous `GET /my-feed/events` -> 403 (or a redirect to the login
 *    form), mirroring MyFeedRouteTest's own AC-1 assertion shape and
 *    `_user_is_logged_in: 'TRUE'` (ST-1's verified Drupal-11 pattern).
 *  - Authenticated `GET /my-feed/events` -> 200, rendering BOTH section
 *    testids (`upcoming-events-results`/`-empty` and
 *    `my-rsvps-results`/`-empty`) per handoff-A.md Finding #1's binding
 *    resolution ("controller pre-composes both sections into ONE #results
 *    render array with per-section testids baked in there").
 *  - The iCal `<a>` links (`/upcoming-events/ical`, `/user/<uid>/events/ical`)
 *    are present in the rendered page AND resolve to 200 with a
 *    `text/calendar` content-type — REUSE-only per the brief's non-goal
 *    "Do NOT reimplement iCal generation."
 *  - `?scope=global` widens the Upcoming display beyond the viewer's own
 *    memberships (handoff-A.md Finding #3's `overrideOption('filters', ...)`
 *    mechanism — this test asserts the OBSERVABLE outcome, not the
 *    implementation technique, per that finding's own guidance).
 *
 * None of `do_streams.routing.yml`'s /my-feed/events entry, `MyEventsController`,
 * or the shipped `views.view.my_events.yml` exist yet — this story's own
 * brief.md names them as NEW files under this story. Every test method below
 * is intended to fail with a route-not-found (404) until F wires the route +
 * controller + view, mirroring MyFeedRouteTest's own documented RED
 * reasoning for the sibling /my-feed route.
 *
 * Layer choice: Functional (BrowserTestBase) — a real HTTP request/response
 * is the only way to assert the route's access-control behavior end-to-end
 * and the two-section DOM composition, mirroring MyFeedRouteTest's own
 * precedent for the sibling /my-feed route.
 *
 * `field_date_of_event` is a site-level (non-module-shipped) field, exactly
 * like `views.view.my_feed.yml` — a fresh BrowserTestBase install never
 * config-imports it either, so setUp() below provisions the field storage +
 * field config programmatically on the `event` content type this test also
 * creates, mirroring the SAME "install from code, not config/sync" approach
 * MyFeedRouteTest/GroupsKernelTestBase use for their own site-level
 * fixtures.
 *
 * T-red self-correction (fixed before RED was reported valid): the
 * `my_rsvps` display's `flag_relationship` handler
 * (`\Drupal\flag\Plugin\views\relationship\FlagViewsRelationship::calculateDependencies()`)
 * calls `Flag::load('rsvp_event')->getConfigDependencyName()` the moment the
 * view ENTITY is saved (not merely executed) — a fresh BrowserTestBase
 * install never config-imports `flag.flag.rsvp_event.yml` either (same
 * site-level-config gap as views.view.my_feed.yml), so saving the my_events
 * view fixture fatals with "Call to a member function
 * getConfigDependencyName() on null" before ANY route/HTTP assertion runs —
 * an invalid RED masking the intended one. Fixed by installing the REAL
 * shipped `docs/groups/config/flag.flag.rsvp_event.yml` (via FileStorage,
 * same convention as the view fixture) before the my_events view is saved.
 *
 * @group do_streams
 * @group do_tests
 */
class MyEventsRouteTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'group',
    'gnode',
    'options',
    'node',
    'views',
    'flag',
    'field',
    'text',
    'filter',
    'datetime',
    'do_discovery',
    'do_streams',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    if (!\Drupal\node\Entity\NodeType::load('event')) {
      $this->drupalCreateContentType(['type' => 'event', 'name' => 'Event']);
    }

    // field_date_of_event is a site-level field (parallel to
    // views.view.my_feed.yml — never module-shipped); provision it
    // programmatically so the Upcoming display's date sort/future-filter
    // has a real field to bind to.
    if (!FieldStorageConfig::loadByName('node', 'field_date_of_event')) {
      FieldStorageConfig::create([
        'field_name' => 'field_date_of_event',
        'entity_type' => 'node',
        'type' => 'datetime',
        'settings' => ['datetime_type' => 'datetime'],
      ])->save();
    }
    if (!FieldConfig::loadByName('node', 'event', 'field_date_of_event')) {
      FieldConfig::create([
        'field_name' => 'field_date_of_event',
        'entity_type' => 'node',
        'bundle' => 'event',
        'label' => 'Event date',
      ])->save();
    }

    // flag.flag.rsvp_event.yml is a site-level config artifact (parallel to
    // views.view.my_feed.yml — never module-shipped). The my_rsvps display's
    // flag_relationship handler resolves this flag entity as soon as the
    // VIEW is saved (calculateDependencies(), not merely executed), so it
    // must exist before the view fixture below is installed. Installed here
    // from the REAL shipped config (not a fixture copy) — this is a
    // dependency the view consumes, not a contract this story's own test
    // pins.
    $siteConfig = new FileStorage(dirname(__DIR__, 7) . '/docs/groups/config');
    $flagData = $siteConfig->read('flag.flag.rsvp_event');
    $this->assertNotFalse($flagData, 'The shipped flag.flag.rsvp_event config exists and is readable.');
    if (!\Drupal::entityTypeManager()->getStorage('flag')->load('rsvp_event')) {
      \Drupal::entityTypeManager()->getStorage('flag')->create($flagData)->save();
    }

    // views.view.my_events.yml is a site-level config artifact (parallel to
    // my_feed.yml — never module-shipped), so BrowserTestBase's fresh
    // per-run install never picks it up via config:import. Install it here
    // from a module-local fixture copy, mirroring MyFeedRouteTest's own
    // established pattern for the sibling /my-feed route (see that class's
    // "PHASE 6 REPAIR NOTE" docblock).
    $fixtures = new FileStorage(__DIR__ . '/../../fixtures/config');
    $viewData = $fixtures->read('views.view.my_events');
    $this->assertNotFalse($viewData, 'The views.view.my_events fixture exists and is readable.');
    \Drupal::entityTypeManager()->getStorage('view')->create($viewData)->save();
  }

  /**
   * Anonymous `GET /my-feed/events` is denied (403 or login redirect).
   */
  public function testAnonymousGetsDeniedOrRedirectedToLogin(): void {
    $this->drupalGet('/my-feed/events');
    $status = $this->getSession()->getStatusCode();
    $currentUrl = $this->getSession()->getCurrentUrl();

    $isDenied = $status === 403;
    $isLoginRedirect = $status === 200 && str_contains($currentUrl, '/user/login');

    $this->assertTrue(
      $isDenied || $isLoginRedirect,
      sprintf(
        'Anonymous GET /my-feed/events must be denied (403) or redirected to the login form; got status %d at %s.',
        $status,
        $currentUrl,
      ),
    );
  }

  /**
   * Authenticated GET renders 200 with both section testids present
   * (either the results OR the empty variant for each section, per
   * handoff-D.md's "two genuinely distinct, independently-empty sections").
   */
  public function testAuthenticatedUserSeesBothSections(): void {
    $account = $this->drupalCreateUser();
    $this->drupalLogin($account);

    $this->drupalGet('/my-feed/events');
    $this->assertSession()->statusCodeEquals(200);

    $page = $this->getSession()->getPage()->getContent();

    $hasUpcomingSection = str_contains($page, 'data-testid="upcoming-events-results"')
      || str_contains($page, 'data-testid="upcoming-events-empty"');
    $this->assertTrue(
      $hasUpcomingSection,
      'The response renders either the upcoming-events-results or upcoming-events-empty section (handoff-A.md Finding #1: controller-composed sections, per-section testids).',
    );

    $hasRsvpsSection = str_contains($page, 'data-testid="my-rsvps-results"')
      || str_contains($page, 'data-testid="my-rsvps-empty"');
    $this->assertTrue(
      $hasRsvpsSection,
      'The response renders either the my-rsvps-results or my-rsvps-empty section (handoff-A.md Finding #1).',
    );
  }

  /**
   * The iCal links are present in the rendered page and resolve to 200 with
   * a text/calendar content-type (REUSE-only, never reimplemented).
   */
  public function testIcalLinksPresentAndResolve(): void {
    $account = $this->drupalCreateUser();
    $this->drupalLogin($account);

    $this->drupalGet('/my-feed/events');
    $this->assertSession()->statusCodeEquals(200);

    $page = $this->getSession()->getPage()->getContent();

    $this->assertStringContainsString(
      'href="/upcoming-events/ical"',
      $page,
      'The page links to the site-wide iCal feed (do_discovery.ical_site), never reimplemented.',
    );

    $userIcalHref = '/user/' . $account->id() . '/events/ical';
    $this->assertStringContainsString(
      'href="' . $userIcalHref . '"',
      $page,
      'The page links to the CURRENT user\'s iCal feed (do_discovery.ical_user), built from the viewing user\'s own uid, never hardcoded.',
    );

    $this->drupalGet('/upcoming-events/ical');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertStringContainsString(
      'text/calendar',
      $this->getSession()->getResponseHeader('Content-Type') ?? '',
      'The site-wide iCal feed resolves to 200 with a text/calendar content type.',
    );

    $this->drupalGet($userIcalHref);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertStringContainsString(
      'text/calendar',
      $this->getSession()->getResponseHeader('Content-Type') ?? '',
      'The user iCal feed resolves to 200 with a text/calendar content type.',
    );
  }

  /**
   * `?scope=global` widens the Upcoming display beyond the viewer's own
   * memberships (handoff-A.md Finding #3): a user in a group with ONE
   * upcoming event sees only that event under the default (My Groups)
   * scope, but sees BOTH that event and a separate non-member group's event
   * under `?scope=global`.
   */
  public function testGlobalScopeWidensUpcomingBeyondMemberships(): void {
    $groupType = $this->createGroupTypeForEvents();

    $account = $this->drupalCreateUser();

    $memberGroup = $this->createGroupForEvents($groupType, 'My Group');
    $memberGroup->addMember($account);
    $memberEvent = $this->addPublishedEvent($memberGroup, 'In My Groups Scope', 10);

    $otherGroup = $this->createGroupForEvents($groupType, 'Other Group');
    $otherEvent = $this->addPublishedEvent($otherGroup, 'Only In Global Scope', 20);

    $this->drupalLogin($account);

    $this->drupalGet('/my-feed/events');
    $this->assertSession()->statusCodeEquals(200);
    $defaultScopePage = $this->getSession()->getPage()->getContent();
    $this->assertStringContainsString($memberEvent->label(), $defaultScopePage, 'The member group\'s event appears under the default (My Groups) scope.');
    $this->assertStringNotContainsString($otherEvent->label(), $defaultScopePage, 'A non-member group\'s event does NOT appear under the default (My Groups) scope.');

    $this->drupalGet('/my-feed/events', ['query' => ['scope' => 'global']]);
    $this->assertSession()->statusCodeEquals(200);
    $globalScopePage = $this->getSession()->getPage()->getContent();
    $this->assertStringContainsString($memberEvent->label(), $globalScopePage, 'The member group\'s event still appears under ?scope=global.');
    $this->assertStringContainsString(
      $otherEvent->label(),
      $globalScopePage,
      '?scope=global widens the Upcoming display to include a non-member group\'s event (handoff-A.md Finding #3).',
    );
  }

  /**
   * Creates a group type usable for event relationships, matching
   * MyFeedRouteTest's own setup shape for the analogous scope test.
   */
  protected function createGroupTypeForEvents() {
    $groupType = \Drupal::entityTypeManager()
      ->getStorage('group_type')
      ->create(['id' => 'community_group', 'label' => 'Community Group', 'creator_membership' => TRUE]);
    $groupType->save();

    /** @var \Drupal\group\Entity\Storage\GroupRelationshipTypeStorageInterface $rt_storage */
    $rt_storage = \Drupal::entityTypeManager()->getStorage('group_relationship_type');
    $rt_storage->save($rt_storage->createFromPlugin(
      $groupType,
      'group_node:event',
      ['entity_cardinality' => 0],
    ));

    $permissions = ['view group_node:event relationship', 'view group_node:event entity'];
    /** @var \Drupal\group\Entity\Storage\GroupRoleStorageInterface $role_storage */
    $role_storage = \Drupal::entityTypeManager()->getStorage('group_role');
    $role_storage->create([
      'id' => 'community_group-outsider',
      'label' => 'Outsider',
      'group_type' => 'community_group',
      'scope' => 'outsider',
      'global_role' => 'authenticated',
      'permissions' => $permissions,
    ])->save();
    $role_storage->create([
      'id' => 'community_group-insider',
      'label' => 'Insider',
      'group_type' => 'community_group',
      'scope' => 'insider',
      'global_role' => 'authenticated',
      'permissions' => $permissions,
    ])->save();

    return $groupType;
  }

  /**
   * Creates a group of the given type/label.
   */
  protected function createGroupForEvents($groupType, string $label) {
    $group = \Drupal::entityTypeManager()->getStorage('group')->create([
      'type' => $groupType->id(),
      'label' => $label,
    ]);
    $group->save();
    return $group;
  }

  /**
   * Adds a published, future-dated event node to a group.
   */
  protected function addPublishedEvent($group, string $title, int $daysFromNow) {
    $node = \Drupal::entityTypeManager()->getStorage('node')->create([
      'type' => 'event',
      'title' => $title,
      'status' => 1,
    ]);
    if ($node->hasField('field_date_of_event')) {
      $node->set('field_date_of_event', date('Y-m-d\TH:i:s', strtotime("+{$daysFromNow} days")));
    }
    $node->save();
    $group->addRelationship($node, 'group_node:event');
    return $node;
  }

}
