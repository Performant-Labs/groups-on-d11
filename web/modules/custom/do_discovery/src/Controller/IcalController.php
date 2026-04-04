<?php

namespace Drupal\do_discovery\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\group\Entity\GroupInterface;
use Drupal\user\UserInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Controller for iCal feed endpoints.
 *
 * Provides three endpoints:
 * - /upcoming-events/ical — site-wide upcoming events
 * - /group/{group}/events/ical — events for a specific group
 * - /user/{user}/events/ical — events the user has RSVP'd to
 */
class IcalController extends ControllerBase {

  /**
   * Site-wide upcoming events iCal feed.
   */
  public function siteEvents(): Response {
    $events = $this->loadEvents();
    return $this->buildIcalResponse($events, 'Upcoming Events');
  }

  /**
   * Group events iCal feed.
   */
  public function groupEvents(GroupInterface $group): Response {
    $events = $this->loadGroupEvents($group);
    return $this->buildIcalResponse($events, $group->label() . ' Events');
  }

  /**
   * User's RSVP'd events iCal feed.
   */
  public function userEvents(UserInterface $user): Response {
    $events = $this->loadUserEvents($user);
    return $this->buildIcalResponse($events, $user->getDisplayName() . ' Events');
  }

  /**
   * Loads upcoming published events, sorted by date.
   */
  protected function loadEvents(): array {
    $query = \Drupal::entityTypeManager()
      ->getStorage('node')
      ->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'event')
      ->condition('status', 1)
      ->sort('field_date_of_event', 'ASC')
      ->range(0, 100);

    $nids = $query->execute();
    return $nids ? \Drupal::entityTypeManager()->getStorage('node')->loadMultiple($nids) : [];
  }

  /**
   * Loads events belonging to a specific group.
   */
  protected function loadGroupEvents(GroupInterface $group): array {
    $database = \Drupal::database();

    // Query group_relationship_field_data for event nodes in this group.
    $query = $database->select('group_relationship_field_data', 'gr');
    $query->fields('gr', ['entity_id']);
    $query->condition('gr.gid', $group->id());
    $query->condition('gr.type', '%event%', 'LIKE');

    // Join node_field_data to filter published and sort by date.
    $query->join('node_field_data', 'n', 'n.nid = gr.entity_id');
    $query->condition('n.status', 1);
    $query->condition('n.type', 'event');

    // Join event date field for sorting.
    $query->leftJoin('node__field_date_of_event', 'ed', 'ed.entity_id = n.nid');
    $query->orderBy('ed.field_date_of_event_value', 'ASC');

    $nids = $query->execute()->fetchCol();

    if (empty($nids)) {
      return [];
    }

    return \Drupal::entityTypeManager()->getStorage('node')->loadMultiple($nids);
  }

  /**
   * Loads events the user has RSVP'd to via the rsvp_event flag.
   */
  protected function loadUserEvents(UserInterface $user): array {
    try {
      $flag_service = \Drupal::service('flag');
      $flag = $flag_service->getFlagById('rsvp_event');
    }
    catch (\Exception $e) {
      return [];
    }

    if (!$flag) {
      return [];
    }

    $flaggings = \Drupal::entityTypeManager()
      ->getStorage('flagging')
      ->loadByProperties([
        'flag_id' => 'rsvp_event',
        'uid' => $user->id(),
      ]);

    $events = [];
    foreach ($flaggings as $flagging) {
      $node = $flagging->getFlaggable();
      if ($node && $node->getType() === 'event' && $node->isPublished()) {
        $events[] = $node;
      }
    }

    return $events;
  }

  /**
   * Builds an iCal response from an array of event nodes.
   *
   * @param \Drupal\node\NodeInterface[] $events
   *   The event nodes.
   * @param string $calendar_name
   *   The calendar name for X-WR-CALNAME.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The iCal response.
   */
  protected function buildIcalResponse(array $events, string $calendar_name): Response {
    $site_name = \Drupal::config('system.site')->get('name') ?: 'Drupal';

    $ical = "BEGIN:VCALENDAR\r\n";
    $ical .= "VERSION:2.0\r\n";
    $ical .= "PRODID:-//do_discovery//EN\r\n";
    $ical .= "X-WR-CALNAME:" . $this->escapeIcal($calendar_name) . "\r\n";
    $ical .= "X-WR-CALDESC:" . $this->escapeIcal($site_name . ' — ' . $calendar_name) . "\r\n";
    $ical .= "CALSCALE:GREGORIAN\r\n";
    $ical .= "METHOD:PUBLISH\r\n";

    foreach ($events as $event) {
      $ical .= $this->buildVevent($event);
    }

    $ical .= "END:VCALENDAR\r\n";

    return new Response($ical, 200, [
      'Content-Type' => 'text/calendar; charset=utf-8',
      'Content-Disposition' => 'attachment; filename="events.ics"',
    ]);
  }

  /**
   * Builds a VEVENT block from an event node.
   */
  protected function buildVevent($event): string {
    $vevent = "BEGIN:VEVENT\r\n";

    // UID — unique per event.
    $vevent .= "UID:" . $event->uuid() . "\r\n";

    // SUMMARY — event title.
    $vevent .= "SUMMARY:" . $this->escapeIcal($event->label()) . "\r\n";

    // DTSTART — event date.
    if ($event->hasField('field_date_of_event') && !$event->get('field_date_of_event')->isEmpty()) {
      $start_value = $event->get('field_date_of_event')->value;
      $dtstart = $this->formatIcalDate($start_value);
      $vevent .= "DTSTART:" . $dtstart . "\r\n";

      // Try end_value for daterange fields; otherwise 1h default duration.
      $end_value = $event->get('field_date_of_event')->end_value ?? NULL;
      if ($end_value) {
        $vevent .= "DTEND:" . $this->formatIcalDate($end_value) . "\r\n";
      }
      else {
        // Default: 1 hour duration.
        $end_ts = strtotime($start_value) + 3600;
        $vevent .= "DTEND:" . gmdate('Ymd\THis\Z', $end_ts) . "\r\n";
      }
    }

    // DESCRIPTION — body field trimmed.
    if ($event->hasField('body') && !$event->get('body')->isEmpty()) {
      $summary = strip_tags($event->get('body')->value);
      $summary = mb_substr($summary, 0, 500);
      $vevent .= "DESCRIPTION:" . $this->escapeIcal($summary) . "\r\n";
    }

    // URL — canonical link.
    try {
      $url = $event->toUrl('canonical', ['absolute' => TRUE])->toString();
      $vevent .= "URL:" . $url . "\r\n";
    }
    catch (\Exception $e) {
      // Skip URL if generation fails.
    }

    // DTSTAMP — last modified.
    $vevent .= "DTSTAMP:" . gmdate('Ymd\THis\Z', $event->getChangedTime()) . "\r\n";

    $vevent .= "END:VEVENT\r\n";

    return $vevent;
  }

  /**
   * Formats a Drupal datetime string to iCal format.
   */
  protected function formatIcalDate(string $date_string): string {
    $timestamp = strtotime($date_string);
    if ($timestamp === FALSE) {
      return gmdate('Ymd\THis\Z');
    }
    return gmdate('Ymd\THis\Z', $timestamp);
  }

  /**
   * Escapes a string for iCal properties.
   */
  protected function escapeIcal(string $text): string {
    $text = str_replace(['\\', ';', ',', "\n"], ['\\\\', '\\;', '\\,', '\\n'], $text);
    return $text;
  }

}
