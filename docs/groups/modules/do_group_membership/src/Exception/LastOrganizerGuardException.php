<?php

declare(strict_types=1);

namespace Drupal\do_group_membership\Exception;

/**
 * Thrown when an action would remove or demote a group's last Organizer.
 *
 * Per AC-9: a group must always have at least one active Organizer. This is
 * the server-side backstop for the UI's disable-before-attempt guard (races
 * between two organizers acting concurrently are the reason this must be
 * enforced authoritatively at the service layer, not only in the UI).
 */
class LastOrganizerGuardException extends \RuntimeException {}
