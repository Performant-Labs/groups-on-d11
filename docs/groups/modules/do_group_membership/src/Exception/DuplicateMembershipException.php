<?php

declare(strict_types=1);

namespace Drupal\do_group_membership\Exception;

/**
 * Thrown when adding a user who already has a membership (any status).
 *
 * Per AC-8: a user with an existing `community_group-group_membership`
 * relationship to the group — active, pending, or blocked — must not be
 * added a second time.
 */
class DuplicateMembershipException extends \RuntimeException {}
