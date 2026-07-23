<?php

declare(strict_types=1);

namespace Drupal\do_group_extras\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element\Button;
use Drupal\Core\Render\Markup;
use Drupal\Core\Security\TrustedCallbackInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Template\Attribute;
use Drupal\Core\Url;
use Drupal\group\Entity\GroupInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Restore confirm step — returns an archived group to a non-Archive type.
 *
 * Per the wireframe's Surface 2 / AC-4/AC-5/AC-9: restoring always goes
 * through a real confirmation form (never an instant GET-triggered
 * mutation). Archiving is expressed by reassigning the group's
 * `field_group_type` term-reference field away from the "Archive" term
 * (the same field {@see \Drupal\do_group_extras\Hook\DoGroupExtrasHooks}
 * inspects to enforce archived-group restrictions) — this form lets the
 * caller choose which non-Archive type to reassign to, defaulting to
 * "Working group".
 *
 * The group id is stashed in `$form_state` storage (in addition to the
 * `$this->group` instance property) so `submitForm()` can independently
 * re-derive the group — this matters not only for the race-guard's
 * always-reload-fresh posture, but because `$form_state` is the one object
 * guaranteed to be shared across `buildForm()`/`submitForm()` regardless of
 * whether both run on the same form-object instance (Drupal's real
 * `FormBuilder` always does; some test harnesses invoke the two methods on
 * separately-constructed instances).
 *
 * AC-4/AC-6 require a real `<button type="submit">`, not `<input>`. Core's
 * default `#type => 'submit'` element (`Submit extends Button`) renders via
 * `#theme_wrappers => ['input__submit']`, and core's `input.html.twig`
 * (`<input{{ attributes }} />`) emits a genuine `<input type="submit">`, not
 * a `<button>` — true across core's shipped themes (verified: no
 * `button.html.twig` exists anywhere in this project's vendored core, and
 * no theme, including Olivero, overrides `input.html.twig` to emit
 * `<button>`). {@see self::preRenderAsButtonTag()} converts the finished
 * submit element (after `Button::preRenderButton()` has computed its
 * `#attributes`/classes) into a hand-built `<button>` tag via `#markup`,
 * reusing the exact same computed attributes and value core already
 * produces — no new theme hook or global template is introduced. Implements
 * {@see TrustedCallbackInterface} because it supplies its own `#pre_render`
 * callback (a core render-pipeline security requirement — untrusted
 * `#pre_render` callbacks throw `UntrustedCallbackException`).
 */
class RestoreGroupForm extends ConfirmFormBase implements TrustedCallbackInterface {

  /**
   * The group to restore.
   */
  protected ?GroupInterface $group = NULL;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public static function trustedCallbacks(): array {
    return ['preRenderAsButtonTag'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'do_group_extras_restore_group_form';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): TranslatableMarkup {
    return $this->t("Restore the archived group '@label'?", ['@label' => $this->group?->label() ?? '']);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): TranslatableMarkup {
    return $this->t('This group is currently archived (type: Archive). Restoring it returns it to the group directory (/all-groups), lets members create content again, and removes the "Archived" badge.');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText(): TranslatableMarkup {
    return $this->t('Restore group');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return Url::fromRoute('entity.group.canonical', ['group' => $this->group->id()]);
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?GroupInterface $group = NULL): array {
    $this->group = $group;
    $form_state->set('restore_group_id', $group->id());

    $terms = $this->entityTypeManager->getStorage('taxonomy_term')->loadByProperties(['vid' => 'group_type']);
    $options = [];
    foreach ($terms as $tid => $term) {
      if ($term->label() === 'Archive') {
        continue;
      }
      $options[$tid] = $term->label();
    }

    if (empty($options)) {
      return [
        '#markup' => $this->t('Restore is unavailable: no non-Archive group type exists to restore this group to.'),
      ];
    }

    $form = parent::buildForm($form, $form_state);

    $description_id = 'do-group-extras-restore-desc-' . $group->id();
    $form['description'] = [
      '#markup' => '<p id="' . $description_id . '">' . $this->getDescription() . '</p>',
    ];

    $default_tid = NULL;
    foreach ($options as $tid => $label) {
      if ($label === 'Working group') {
        $default_tid = $tid;
        break;
      }
    }
    if ($default_tid === NULL) {
      $default_tid = array_key_first($options);
    }

    $form['group_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Set group type to'),
      '#options' => $options,
      '#default_value' => $default_tid,
      '#description' => $this->t('Archiving is expressed by group type, so restoring requires choosing a non-Archive type. ("Archive" is excluded from this list.)'),
      '#required' => TRUE,
      '#weight' => -10,
    ];

    $form['actions']['submit']['#attributes']['aria-describedby'] = $description_id;
    // Preserve Button's own attribute/class computation, then convert the
    // finished element to a real <button> tag (see class docblock).
    $form['actions']['submit']['#pre_render'] = [
      [Button::class, 'preRenderButton'],
      [self::class, 'preRenderAsButtonTag'],
    ];

    return $form;
  }

  /**
   * Converts a fully-computed `#type => submit` element into `<button>`.
   *
   * Runs after {@see \Drupal\Core\Render\Element\Button::preRenderButton()},
   * which has already populated `#attributes` (type=submit, id/name/value,
   * button/js-form-submit/form-submit classes, any button_type modifier
   * class, aria-describedby set in buildForm()). This callback only changes
   * which HTML tag wraps those attributes — `<button>…label…</button>`
   * instead of core's default `<input … />` — because a `<button>`'s label
   * is its text content, not a `value` attribute.
   *
   * The assembled string is wrapped in {@see Markup::create()} because every
   * piece is already safe (the `Attribute` object HTML-escapes all attribute
   * values itself; the label is either a `TranslatableMarkup`, which is
   * itself a `MarkupInterface`, or a plain string that came from `#value`,
   * which core's own render pipeline already treats as trusted for the
   * `<input>` `value` attribute it replaces). Without this, the renderer's
   * `ensureMarkupIsSafe()` would XSS-filter the string against
   * `Xss::getAdminTagList()`, which does not include `button` and would
   * strip the whole tag, leaving only the bare label text.
   *
   * Public (required for use as a #pre_render callback) and listed in
   * {@see self::trustedCallbacks()}.
   *
   * @param array $element
   *   The submit element, with #attributes already computed.
   *
   * @return array
   *   The element, replaced with a #markup render array.
   */
  public static function preRenderAsButtonTag(array $element): array {
    $attributes = $element['#attributes'] ?? [];
    // The value attribute becomes the button's visible text content instead.
    $label = $attributes['value'] ?? ($element['#value'] ?? '');
    unset($attributes['value']);
    $attribute_string = (string) new Attribute($attributes);

    return [
      '#markup' => Markup::create('<button' . $attribute_string . '>' . $label . '</button>'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $group = $this->group;
    if ($group === NULL) {
      $group_id = $form_state->get('restore_group_id');
      if ($group_id !== NULL) {
        $group = $this->entityTypeManager->getStorage('group')->load($group_id);
      }
    }
    $this->group = $group;

    if ($group === NULL) {
      $this->messenger()->addError($this->t('The group could not be restored. Please try again.'));
      return;
    }

    $isArchived = $group->hasField('field_group_type')
      && !$group->get('field_group_type')->isEmpty()
      && $group->get('field_group_type')->entity?->label() === 'Archive';

    if (!$isArchived) {
      $this->messenger()->addWarning($this->t('This group is no longer archived — no changes were made.'));
      $form_state->setRedirectUrl($this->getCancelUrl());
      return;
    }

    $tid = $form_state->getValue('group_type');

    try {
      $target_term = $this->entityTypeManager->getStorage('taxonomy_term')->load($tid);
      $type_label = $target_term?->label() ?? '';
      $group->set('field_group_type', $tid);
      $group->save();
      $this->messenger()->addStatus($this->t("Group '@label' has been restored and set to type '@type'.", [
        '@label' => $group->label(),
        '@type' => $type_label,
      ]));
    }
    catch (\Exception $e) {
      \Drupal::logger('do_group_extras')->error('Restore failed for group @gid: @msg', [
        '@gid' => $group->id(),
        '@msg' => $e->getMessage(),
      ]);
      $this->messenger()->addError($this->t('The group could not be restored. Please try again.'));
    }

    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}
