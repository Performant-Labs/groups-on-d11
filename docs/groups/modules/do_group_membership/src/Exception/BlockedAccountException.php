<?php

declare(strict_types=1);

namespace Drupal\do_group_membership\Exception;

/**
 * Thrown when adding a Drupal user account that is blocked.
 *
 * Per AC-8: `$account->isBlocked()` accounts may not be added as a group
 * member.
 */
class BlockedAccountException extends \RuntimeException {}
