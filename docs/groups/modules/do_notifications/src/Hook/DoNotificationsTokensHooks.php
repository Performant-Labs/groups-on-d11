<?php

declare(strict_types=1);

namespace Drupal\do_notifications\Hook;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\message\MessageInterface;

/**
 * Token providers for do_activity's `[message:...]` Message fields (#232).
 *
 * The 6 `activity_*` Message templates (do_activity, #116) use
 * `[message:uid]`, `[message:field_group_id]`,
 * `[message:field_referenced_entity_type]`, and
 * `[message:field_referenced_entity_id]` in their stored text (see
 * do_activity's `message.template.activity_*.yml`). The Message module's own
 * `message.tokens.inc` implements `hook_tokens()` for type `message` but
 * ONLY for the single hardcoded `author` (BC) token — it does not resolve
 * arbitrary Message fields generically. Generic `[type:field_name]`
 * resolution is normally supplied by the CONTRIB Token module
 * (drupal/token), which is not a dependency of this project. Without either,
 * `\Drupal\Core\Utility\Token::replace(..., ['clear' => TRUE])` silently
 * collapses these four tokens to empty strings (Token::doReplace() only
 * fills `$replacements` via `hook_tokens()` dispatch, then `clear: TRUE`
 * backfills anything left unresolved with `''`) — discovered by
 * EmailRendererTest's failing assertions during #232 implementation, not by
 * the brief, which assumed the Message-module token boundary alone would be
 * sufficient.
 *
 * This class closes that gap the same way core modules do it (see e.g.
 * {@see \Drupal\node\Hook\NodeTokensHooks}): a normal `hook_token_info()` /
 * `hook_tokens()` pair, scoped to exactly the four tokens the `activity_*`
 * templates use. It does not re-implement or bypass Message's rendering
 * contract — it is the missing TOKEN PROVIDER that contract depends on.
 */
class DoNotificationsTokensHooks {

  use StringTranslationTrait;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Declares the four `[message:...]` tokens this class resolves.
   */
  #[Hook('token_info')]
  public function tokenInfo(): array {
    $message['uid'] = [
      'name' => $this->t('Author'),
      'description' => $this->t('The user who triggered this activity message.'),
    ];
    $message['field_group_id'] = [
      'name' => $this->t('Group'),
      'description' => $this->t('The label of the group this activity relates to.'),
    ];
    $message['field_referenced_entity_type'] = [
      'name' => $this->t('Referenced entity type'),
      'description' => $this->t('The entity type machine name of the entity this activity refers to.'),
    ];
    $message['field_referenced_entity_id'] = [
      'name' => $this->t('Referenced entity ID'),
      'description' => $this->t('The label (or ID, if unloadable) of the entity this activity refers to.'),
    ];

    return [
      'tokens' => ['message' => $message],
    ];
  }

  /**
   * Resolves `[message:uid]`, `field_group_id`, and `field_referenced_*`.
   *
   * `uid` and `field_group_id` resolve to the referenced entity's label
   * (mirroring core's `[node:author]` -> user label pattern); a missing
   * referenced user/group resolves to an empty string via `clear: TRUE`
   * rather than this hook (Message/group deletion `(removed)` handling for
   * the primary referenced entity is
   * {@see \Drupal\do_notifications\Email\EmailRenderer}'s job, not this
   * hook's — this hook only supplies the RAW token value, present entity or
   * not, matching core's own token-provider convention of returning nothing
   * when the referenced object cannot be loaded).
   *
   * `field_referenced_entity_type` / `field_referenced_entity_id` resolve to
   * their STORED raw values (a type machine name string and an integer ID)
   * regardless of whether the referenced entity still loads — by design:
   * EmailRenderer defensively re-checks the referenced entity itself and
   * substitutes `(removed)` in the RENDERED text, rather than this token
   * provider silently hiding a deletion.
   */
  #[Hook('tokens')]
  public function tokens(
    string $type,
    array $tokens,
    array $data,
    array $options,
    BubbleableMetadata $bubbleable_metadata,
  ): array {
    $replacements = [];

    if ($type !== 'message' || empty($data['message'])) {
      return $replacements;
    }

    /** @var \Drupal\message\MessageInterface $message */
    $message = $data['message'];

    foreach ($tokens as $name => $original) {
      switch ($name) {
        case 'uid':
          $account = $message->getOwner();
          if ($account) {
            $bubbleable_metadata->addCacheableDependency($account);
            $replacements[$original] = $account->label();
          }
          break;

        case 'field_group_id':
          $group = $this->loadReferencedFieldEntity($message, 'field_group_id');
          if ($group) {
            $bubbleable_metadata->addCacheableDependency($group);
            $replacements[$original] = $group->label();
          }
          break;

        case 'field_referenced_entity_type':
          if ($message->hasField('field_referenced_entity_type') && !$message->get('field_referenced_entity_type')->isEmpty()) {
            $replacements[$original] = (string) $message->get('field_referenced_entity_type')->value;
          }
          break;

        case 'field_referenced_entity_id':
          if ($message->hasField('field_referenced_entity_id') && !$message->get('field_referenced_entity_id')->isEmpty()) {
            $replacements[$original] = (string) $message->get('field_referenced_entity_id')->value;
          }
          break;
      }
    }

    return $replacements;
  }

  /**
   * Loads the entity a Message's entity_reference field points at, if any.
   */
  private function loadReferencedFieldEntity(MessageInterface $message, string $field_name): ?object {
    if (!$message->hasField($field_name) || $message->get($field_name)->isEmpty()) {
      return NULL;
    }
    return $message->get($field_name)->entity;
  }

}
