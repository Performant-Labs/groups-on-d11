<?php

declare(strict_types=1);

namespace Drupal\Tests\do_discovery\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\do_discovery\Controller\IcalController;
use Drupal\flag\Entity\Flag;
use Drupal\group\PermissionScopeInterface;
use Drupal\Tests\do_tests\Kernel\GroupsKernelTestBase;
use Drupal\user\RoleInterface;

/**
 * Behavioral test for do_discovery's three iCal feeds (C2 / #40, epic #31).
 *
 * `do_discovery`'s {@see \Drupal\do_discovery\Controller\IcalController} exposes
 * three feed routes, each of which returns a `text/calendar` VCALENDAR:
 *  - `/upcoming-events/ical`      -> IcalController::siteEvents()  (site-wide)
 *  - `/group/{group}/events/ical` -> IcalController::groupEvents() (one group)
 *  - `/user/{user}/events/ical`   -> IcalController::userEvents()  (RSVP'd)
 *
 * The #40 / RA6 risk is that the *group* feed selects a group's events with a raw
 * `group_relationship_field_data` query filtered by `gr.type LIKE '%event%'`
 * (IcalController::loadGroupEvents()). Static analysis cannot confirm that the
 * heuristic LIKE matches the Group 4.x relationship-type bundle id for the
 * group_node:event relation — which the base fixture derives as
 * `community_group-group_node-event` — while NOT matching a non-event relation
 * such as `community_group-group_node-post`. So this test EXECUTES the real
 * controller against a live DB with genuine group relationships and asserts the
 * event/non-event filter boundary, plus the iCal shape of the output.
 *
 * Layer choice — KERNEL, invoking the controller directly rather than a
 * BrowserTestBase HTTP GET. The three routes only require the `access content`
 * permission (see do_discovery.routing.yml); there is no access-policy or theme
 * behavior under test. The behavior that #40 actually risks lives entirely in the
 * controller's DB queries and its VEVENT string-building, which a kernel test
 * exercises far more precisely and deterministically (the raw `gr.type LIKE`
 * query, the datetime field join, the VCALENDAR/VEVENT bytes) than scraping an
 * HTTP response body. The controller uses only `\Drupal::` statics, so
 * {@see IcalController::create()} instantiates it from the test container and the
 * feed methods run their real query + iCal path unchanged. No UI is faked — every
 * assertion is against the actual Response the controller returns.
 *
 * Fixtures beyond the base:
 *  - `field_date_of_event` (a `datetime` field on the `event` node type) is NOT
 *    shipped as config in this repo, yet the controller sorts/reads it and emits
 *    it as DTSTART. It is created here as the RUNBOOK-resolved `datetime` field so
 *    the real query path (entity-query sort + `node__field_date_of_event` join +
 *    VEVENT DTSTART) runs.
 *  - the shipped `rsvp_event` flag (docs/groups/config/flag.flag.rsvp_event.yml,
 *    a per-user `entity:node` flag on `event`) drives the user feed's
 *    loadUserEvents() flagging query; it is installed byte-identically here.
 *
 * @group do_discovery
 * @group do_tests
 */
class IcalFeedsTest extends GroupsKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'do_discovery',
    'flag',
    'views',
    'taxonomy',
    'field',
    'text',
    'filter',
    'datetime',
  ];

  /**
   * The rsvp_event flag entity (per-user, flags event nodes).
   *
   * @var \Drupal\flag\FlagInterface
   */
  protected $rsvpFlag;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // The flagging content entity storage + flag's counts table (used by
    // FlagService on (un)flag) are not part of the base fixture; the user feed's
    // loadUserEvents() reads flagging rows for the rsvp_event flag.
    $this->installEntitySchema('flagging');
    $this->installSchema('flag', ['flag_counts']);

    // Create the `field_date_of_event` datetime field on the event node type.
    // The controller sorts the site feed by it (entity query), joins
    // node__field_date_of_event in the group feed, and emits it as DTSTART. It is
    // NOT shipped as config in this repo (only field_event_type is), so it is
    // created here as the RUNBOOK-resolved single-value `datetime` field.
    FieldStorageConfig::create([
      'field_name' => 'field_date_of_event',
      'entity_type' => 'node',
      'type' => 'datetime',
      'settings' => ['datetime_type' => 'datetime'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_date_of_event',
      'entity_type' => 'node',
      'bundle' => 'event',
      'label' => 'Date of event',
    ])->save();

    // Install the shipped rsvp_event flag as a fixture (byte-identical to
    // docs/groups/config/flag.flag.rsvp_event.yml). It flags event nodes per
    // user and is what loadUserEvents() queries.
    $this->rsvpFlag = Flag::create([
      'id' => 'rsvp_event',
      'label' => 'RSVP for event',
      'entity_type' => 'node',
      'bundles' => ['event'],
      'global' => FALSE,
      'flag_type' => 'entity:node',
      'link_type' => 'reload',
      'flagTypeConfig' => [
        'show_in_links' => [],
        'show_as_field' => TRUE,
        'show_on_form' => FALSE,
        'show_contextual_link' => FALSE,
        'extra_permissions' => [],
      ],
      'linkTypeConfig' => [],
    ]);
    $this->rsvpFlag->save();
    $this->assertNotNull(Flag::load('rsvp_event'), 'The rsvp_event flag fixture is installed.');

    // The group feed's raw query does not gate on Group access (it selects
    // group_relationship_field_data directly), but the site feed's entity query
    // uses accessCheck(TRUE). Grant the base fixture's non-member current user
    // view access to every group_node:* entity so published events are visible to
    // the site-wide entity query; this isolates the test on the iCal/filter
    // behavior rather than on Group's access policy (that is B1 / #35's surface).
    $permissions = [];
    foreach (static::NODE_BUNDLES as $node_type) {
      $permissions[] = "view group_node:$node_type relationship";
      $permissions[] = "view group_node:$node_type entity";
    }
    $this->createGroupRole([
      'group_type' => static::GROUP_TYPE_ID,
      'scope' => PermissionScopeInterface::OUTSIDER_ID,
      'global_role' => RoleInterface::AUTHENTICATED_ID,
      'permissions' => $permissions,
    ]);
  }

  /**
   * Instantiates the controller from the test container.
   *
   * @return \Drupal\do_discovery\Controller\IcalController
   *   The controller under test.
   */
  protected function controller(): IcalController {
    return IcalController::create($this->container);
  }

  /**
   * Creates a published event node in a group with a date, via addNode().
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The group to relate the event to.
   * @param string $title
   *   The event title.
   * @param string $date
   *   The datetime value (Y-m-d\TH:i:s), as the demo data writes it.
   *
   * @return \Drupal\node\NodeInterface
   *   The saved event node (its group_node:event relationship is also saved).
   */
  protected function addEvent($group, string $title, string $date) {
    return $this->addNode($group, 'event', [
      'title' => $title,
      'status' => 1,
      'field_date_of_event' => $date,
    ]);
  }

  /**
   * The group feed lists a group's event and EXCLUDES a non-event node.
   *
   * This is the #40 / RA6 filter-boundary assertion. A group holds one `event`
   * node (related via group_node:event -> relationship-type bundle id
   * `community_group-group_node-event`, which the raw `gr.type LIKE '%event%'`
   * matches) and one `post` node (bundle id `community_group-group_node-post`,
   * which the LIKE must NOT match). The group feed must return the event and only
   * the event, proving the LIKE filter boundary holds on the real 4.x bundle ids.
   */
  public function testGroupFeedListsEventsAndExcludesNonEvents(): void {
    $group = $this->createGroup();

    // Sanity: confirm the two relationship-type bundle ids the LIKE filter must
    // discriminate between actually are what the fixture derives them as, so the
    // boundary below is meaningful and not accidentally trivially true/false.
    $this->assertSame(
      'community_group-group_node-event',
      $this->relationshipTypeId('event'),
      "The event relation's bundle id contains 'event' (the LIKE must match it)."
    );
    $this->assertSame(
      'community_group-group_node-post',
      $this->relationshipTypeId('post'),
      "The post relation's bundle id does NOT contain 'event' (the LIKE must skip it)."
    );

    $event = $this->addEvent($group, 'Community Meetup', '2030-01-15T18:00:00');
    // A non-event node in the SAME group: must be excluded by the filter.
    $post = $this->addNode($group, 'post', ['title' => 'A Post', 'status' => 1]);

    $response = $this->controller()->groupEvents($group);
    $body = $response->getContent();

    // The event appears; the non-event does not.
    $this->assertStringContainsString(
      'SUMMARY:Community Meetup',
      $body,
      'The group feed lists the event node.'
    );
    $this->assertStringNotContainsString(
      'A Post',
      $body,
      'The group feed excludes the non-event (post) node — the gr.type LIKE %event% boundary holds.'
    );
    // Exactly one VEVENT: the single event, nothing else.
    $this->assertSame(
      1,
      substr_count($body, 'BEGIN:VEVENT'),
      'The group feed emits exactly one VEVENT (only the event, not the post).'
    );
    // The VEVENT carries the event's own UID (uuid) and a well-formed DTSTART
    // derived from field_date_of_event. NB the controller formats the date with
    // strtotime()+gmdate(), so the emitted Z time is the field value shifted from
    // the runtime timezone to UTC; we assert the RFC 5545 UTC shape (not a
    // timezone-pinned absolute) plus that DTEND is exactly the +1h default after
    // DTSTART — which verifies the DTSTART/DTEND pair without a TZ-fragile literal.
    $this->assertStringContainsString('UID:' . $event->uuid(), $body, 'The VEVENT UID is the event uuid.');
    $this->assertDtstartDtend($body, '2030-01-15T18:00:00');
  }

  /**
   * Asserts the VEVENT DTSTART/DTEND match the controller's own date derivation.
   *
   * The controller emits DTSTART via strtotime($value) + gmdate('Ymd\THis\Z'),
   * and DTEND as that timestamp + 3600s (the 1h default when there is no
   * daterange end_value). This recomputes the expectation the same way rather
   * than hard-coding a timezone-dependent literal, and asserts the RFC 5545 UTC
   * `Ymd\THis\Z` shape of both.
   *
   * @param string $body
   *   The iCal response body.
   * @param string $date_value
   *   The field_date_of_event value that was stored on the event.
   */
  protected function assertDtstartDtend(string $body, string $date_value): void {
    $ts = strtotime($date_value);
    $expected_dtstart = gmdate('Ymd\THis\Z', $ts);
    $expected_dtend = gmdate('Ymd\THis\Z', $ts + 3600);
    $this->assertMatchesRegularExpression('/DTSTART:\d{8}T\d{6}Z\r\n/', $body, 'DTSTART has the RFC 5545 UTC form.');
    $this->assertStringContainsString('DTSTART:' . $expected_dtstart, $body, 'DTSTART is the event date rendered to UTC.');
    $this->assertStringContainsString('DTEND:' . $expected_dtend, $body, 'DTEND is DTSTART + 1h (the default duration).');
  }

  /**
   * The group feed response is well-formed text/calendar with a valid VEVENT.
   *
   * The iCal-shape assertion for #40: the Content-Type is `text/calendar`, the
   * body opens/closes a single VCALENDAR carrying VERSION:2.0, and the event's
   * VEVENT is complete and correctly delimited (BEGIN/END matched, SUMMARY +
   * DTSTART + DTEND present, CRLF line endings per RFC 5545).
   */
  public function testGroupFeedIsWellFormedIcal(): void {
    $group = $this->createGroup();
    $this->addEvent($group, 'Launch Party', '2030-06-01T09:30:00');

    $response = $this->controller()->groupEvents($group);

    // Content-Type is text/calendar.
    $this->assertStringStartsWith(
      'text/calendar',
      $response->headers->get('Content-Type'),
      'The group feed is served as text/calendar.'
    );

    $body = $response->getContent();

    // A single, properly delimited VCALENDAR envelope.
    $this->assertSame(1, substr_count($body, 'BEGIN:VCALENDAR'), 'Exactly one VCALENDAR begin.');
    $this->assertSame(1, substr_count($body, 'END:VCALENDAR'), 'Exactly one VCALENDAR end.');
    $this->assertStringStartsWith('BEGIN:VCALENDAR', $body, 'The body opens with the VCALENDAR envelope.');
    $this->assertStringContainsString("VERSION:2.0\r\n", $body, 'VCALENDAR declares VERSION:2.0.');

    // A single, complete VEVENT with matched BEGIN/END and the core properties.
    $this->assertSame(substr_count($body, 'BEGIN:VEVENT'), substr_count($body, 'END:VEVENT'), 'Every VEVENT is closed.');
    $this->assertSame(1, substr_count($body, 'BEGIN:VEVENT'), 'One VEVENT for the one event.');
    $this->assertStringContainsString('SUMMARY:Launch Party', $body, 'The VEVENT carries the event title as SUMMARY.');
    $this->assertDtstartDtend($body, '2030-06-01T09:30:00');

    // RFC 5545 line folding uses CRLF; the builder emits \r\n line endings.
    $this->assertStringContainsString("\r\n", $body, 'iCal lines are CRLF-terminated.');
  }

  /**
   * The site feed returns 200 + a valid VCALENDAR listing published events.
   *
   * `/upcoming-events/ical` (siteEvents()) selects all published `event` nodes
   * site-wide via an entity query sorted by field_date_of_event. It must return a
   * text/calendar VCALENDAR containing an event created in any group, and must not
   * list a non-event node.
   */
  public function testSiteFeedReturnsValidCalendarOfEvents(): void {
    $group = $this->createGroup();
    $event = $this->addEvent($group, 'Global Summit', '2030-03-20T12:00:00');
    // A non-event node must never appear in the events-only site feed.
    $this->addNode($group, 'post', ['title' => 'Not An Event', 'status' => 1]);

    $response = $this->controller()->siteEvents();

    $this->assertSame(200, $response->getStatusCode(), 'The site feed returns 200.');
    $this->assertStringStartsWith('text/calendar', $response->headers->get('Content-Type'), 'The site feed is text/calendar.');

    $body = $response->getContent();
    $this->assertStringStartsWith('BEGIN:VCALENDAR', $body, 'The site feed is a VCALENDAR.');
    $this->assertStringContainsString('END:VCALENDAR', $body, 'The VCALENDAR is closed.');
    $this->assertStringContainsString('SUMMARY:Global Summit', $body, 'The site feed lists the published event.');
    $this->assertStringContainsString('UID:' . $event->uuid(), $body, 'The event VEVENT UID is present.');
    $this->assertStringNotContainsString('Not An Event', $body, 'The site feed lists events only, not other node types.');
  }

  /**
   * The user feed returns 200 + a VCALENDAR of the user's RSVP'd events only.
   *
   * `/user/{user}/events/ical` (userEvents()) derives "the user's events" from the
   * `rsvp_event` flag: loadUserEvents() loads that user's flaggings and keeps the
   * published event nodes. This exercises the module's real user-events model —
   * the RSVP flag path — headlessly: the user RSVPs one event and not another, and
   * the feed must list exactly the RSVP'd event.
   */
  public function testUserFeedListsOnlyRsvpdEvents(): void {
    $group = $this->createGroup();
    $rsvpd = $this->addEvent($group, 'I Am Going', '2030-09-10T17:00:00');
    $notRsvpd = $this->addEvent($group, 'I Am Not Going', '2030-09-11T17:00:00');

    $user = $this->createUser();
    // RSVP to exactly one event via the flag service (the real model path).
    $this->container->get('flag')->flag($this->rsvpFlag, $rsvpd, $user);

    $response = $this->controller()->userEvents($user);

    $this->assertSame(200, $response->getStatusCode(), 'The user feed returns 200.');
    $this->assertStringStartsWith('text/calendar', $response->headers->get('Content-Type'), 'The user feed is text/calendar.');

    $body = $response->getContent();
    $this->assertStringStartsWith('BEGIN:VCALENDAR', $body, 'The user feed is a VCALENDAR.');
    $this->assertStringContainsString('SUMMARY:I Am Going', $body, 'The user feed lists the RSVP\'d event.');
    $this->assertStringNotContainsString('I Am Not Going', $body, 'The user feed lists only events the user RSVP\'d to.');
    $this->assertSame(1, substr_count($body, 'BEGIN:VEVENT'), 'Exactly one VEVENT: the single RSVP\'d event.');
    $this->assertStringContainsString('UID:' . $rsvpd->uuid(), $body, 'The RSVP\'d event VEVENT UID is present.');
    $this->assertStringNotContainsString('UID:' . $notRsvpd->uuid(), $body, 'The non-RSVP\'d event is absent.');
  }

  /**
   * The user feed is a valid, empty VCALENDAR when the user has RSVP'd nothing.
   *
   * loadUserEvents() returns an empty array when there are no flaggings; the feed
   * must still be a well-formed, event-free VCALENDAR (200), not an error.
   */
  public function testUserFeedIsEmptyValidCalendarWithoutRsvps(): void {
    $user = $this->createUser();

    $response = $this->controller()->userEvents($user);

    $this->assertSame(200, $response->getStatusCode(), 'The empty user feed still returns 200.');
    $body = $response->getContent();
    $this->assertStringStartsWith('BEGIN:VCALENDAR', $body, 'The empty user feed is still a VCALENDAR.');
    $this->assertStringContainsString('END:VCALENDAR', $body, 'The empty VCALENDAR is closed.');
    $this->assertSame(0, substr_count($body, 'BEGIN:VEVENT'), 'No VEVENTs when the user has no RSVPs.');
  }

}
