<?php

declare(strict_types=1);

namespace Drupal\do_chrome\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;

/**
 * Provides a plain-markup search form for the site header (#252).
 *
 * Core's `search_form_block` (Drupal\search\Plugin\Block\SearchBlock) renders
 * a real Form-API form. Building that form emits a CSRF `form_token` +
 * `form_build_id`, and — as a side effect of Form API's build process —
 * lazy-starts a PHP session for every anonymous visitor. That defeats the
 * whole-page cache: every anonymous response gets `Set-Cookie: SESS*` and
 * `X-Drupal-Cache: UNCACHEABLE (response policy)`.
 *
 * This block renders a plain HTML `<form method="get" action="/search/node">`
 * instead — no Form API, no CSRF token, no session. It is placed in both
 * `groups_chrome` header search regions in place of `search_form_block` (see
 * config/sync/block.block.groups_chrome_search_form_wide.yml and
 * ..._narrow.yml).
 *
 * Deliberately CRITICAL constraints (do not "fix" these back to Form API):
 *   - No \Drupal::formBuilder() call.
 *   - No FormBase subclass.
 *   - No '#type' => 'form' render element.
 * Any of those re-introduces the session/CSRF behaviour this block exists to
 * avoid.
 *
 * @Block(
 *   id = "do_chrome_plain_search_form",
 *   admin_label = @Translation("Plain search form (no session)"),
 *   category = @Translation("DO Chrome")
 * )
 */
class PlainSearchFormBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build() {
    return [
      '#type' => 'inline_template',
      '#template' => '<form method="get" action="/search/node" role="search" class="search-block-form"><input type="search" name="keys" placeholder="{{ placeholder }}" aria-label="{{ label }}" class="form-search"><button type="submit" class="button">{{ submit }}</button></form>',
      '#context' => [
        'placeholder' => $this->t('Search'),
        'label' => $this->t('Search'),
        'submit' => $this->t('Search'),
      ],
      '#cache' => [
        'contexts' => [],
        'max-age' => Cache::PERMANENT,
      ],
    ];
  }

}
