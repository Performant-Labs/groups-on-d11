<?php

declare(strict_types=1);

namespace Drupal\do_group_membership\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\do_group_membership\Exception\LastOrganizerGuardException;
use Drupal\do_group_membership\GroupMembershipManager;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupRelationshipInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Remove-member confirm step — a real confirmation, never instant-fire.
 *
 * Per the wireframe's Screen 5 / AC-7: removing a member always goes
 * through a confirm step. The last-Organizer guard (AC-9) is the
 * authoritative server-side backstop if this is attempted on the group's
 * sole active Organizer (e.g. a race between two organizer tabs).
 */
class RemoveMemberForm extends ConfirmFormBase {

  /**
   * The group the membership belongs to.
   */
  protected ?GroupInterface $group = NULL;

  /**
   * The membership relationship to remove.
   */
  protected ?GroupRelationshipInterface $relationship = NULL;

  public function __construct(
    protected GroupMembershipManager $manager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('do_group_membership.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'do_group_membership_remove_member_form';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): TranslatableMarkup {
    $name = $this->relationship?->getEntity()?->label() ?? $this->t('this member');
    $group_label = $this->group?->label() ?? '';
    return $this->t('Are you sure you want to remove @name from @group?', [
      '@name' => $name,
      '@group' => $group_label,
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): TranslatableMarkup {
    return $this->t('This deletes their membership. They will lose access to group content and will need to be re-added or re-request access to rejoin.');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText(): TranslatableMarkup {
    return $this->t('Remove member');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return Url::fromRoute('do_group_membership.manage_members', ['group' => $this->group->id()]);
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?GroupInterface $group = NULL, ?GroupRelationshipInterface $group_relationship = NULL): array {
    $this->group = $group;
    $this->relationship = $group_relationship;
    $form = parent::buildForm($form, $form_state);
    $form['actions']['submit']['#attributes']['class'][] = 'button--danger';
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $name = $this->relationship?->getEntity()?->label() ?? (string) $this->t('The member');

    try {
      $this->manager->removeMember($this->relationship);
      $this->messenger()->addStatus($this->t('@name has been removed from this group.', ['@name' => $name]));
    }
    catch (LastOrganizerGuardException $e) {
      $this->messenger()->addError($this->t('A group must have at least one Organizer.'));
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('The member could not be removed. Please try again.'));
    }

    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}
