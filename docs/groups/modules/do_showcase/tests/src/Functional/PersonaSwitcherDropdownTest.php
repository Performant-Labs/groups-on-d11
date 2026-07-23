<?php

declare(strict_types=1);

namespace Drupal\Tests\do_showcase\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * #120 SC-1 Persona Switcher — the header dropdown widget, on the wire.
 *
 * wireframe.md §1: a `<label for="persona-switcher-select">Browse as</label>`
 * associated `<select id="persona-switcher-select" name="persona">` with 4
 * `<option>` entries (each carrying a non-empty `title=`), plus a real
 * `<button type="submit">` no-JS fallback (never `#type => submit`, which
 * Drupal renders as `<input type="submit">` — PROJECT_CONTEXT.md gotcha).
 *
 * BrowserTestBase self-installs a clean site with NO demo seed, so this test
 * asserts only the ANONYMOUS-visitor render contract, which requires no
 * additional user/group provisioning.
 *
 * RED reason: `do_showcase.persona_switch` route / block / hook wiring does
 * not exist yet, so `<front>` renders with none of this markup — every
 * assertion fails on a missing locator, not a malformed one.
 *
 * @group do_showcase
 */
final class PersonaSwitcherDropdownTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['do_showcase'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Anonymous visitor sees the persona-switcher widget on the front page:
   * the labeled `<select>` with 4 options, each carrying a non-empty
   * `title=`.
   */
  public function testAnonymousSeesWidgetWithFourOptions(): void {
    $this->drupalGet('<front>');
    $this->assertSession()->statusCodeEquals(200);

    $this->assertSession()->elementExists('css', 'select[name="persona"]');
    $options = $this->getSession()->getPage()->findAll('css', 'select[name="persona"] option');
    $this->assertCount(4, $options, 'The persona <select> must have exactly 4 <option> elements.');
    foreach ($options as $option) {
      $title = $option->getAttribute('title');
      $this->assertNotEmpty($title, 'Every <option> must carry a non-empty title attribute.');
    }
  }

  /**
   * The `<label>` "Browse as" is programmatically associated with the
   * `<select>` via `for`/`id` (WCAG 2.2 AA 1.3.1 / 2.5.3 — wireframe.md §5).
   */
  public function testLabelAssociatedWithSelectViaForId(): void {
    $this->drupalGet('<front>');
    $this->assertSession()->elementExists('css', 'label[for="persona-switcher-select"]');
    $this->assertSession()->elementTextContains('css', 'label[for="persona-switcher-select"]', 'Browse as');
    $this->assertSession()->elementAttributeExists('css', '#persona-switcher-select', 'id');
  }

  /**
   * A real `<button type="submit">` no-JS fallback is present (never
   * `#type => submit`, which renders `<input type="submit">` — PROJECT_
   * CONTEXT.md gotcha; wireframe.md §1: "a visually-hidden 'Go' <button
   * type='submit'> — real <button>, not #type=>submit — covers the no-JS
   * case").
   */
  public function testRealSubmitButtonFallbackPresent(): void {
    $this->drupalGet('<front>');
    $this->assertSession()->elementExists('css', 'form button[type="submit"]');
  }

}
