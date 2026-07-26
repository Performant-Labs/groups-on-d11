<?php

declare(strict_types=1);

namespace Drupal\Tests\do_chrome\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\block\Entity\Block;

/**
 * Functional coverage for #252 (anon header search must not start a session).
 *
 * Core's `search_form_block` renders a real Form-API form, which emits a CSRF
 * `form_token` + `form_build_id` and (as a side effect of Form API's build
 * process) lazy-starts a PHP session for anonymous visitors. That defeats the
 * whole-page cache: every anonymous response gets `Set-Cookie: SESS*` and
 * `X-Drupal-Cache: UNCACHEABLE (response policy)`.
 *
 * The fix replaces both `groups_chrome` header search block instances with a
 * new `do_chrome_plain_search_form` block plugin (PlainSearchFormBlock) that
 * renders a plain HTML `<form method="get" action="/search/node">` — no Form
 * API, no CSRF token, no session.
 *
 * This test self-provisions the two block placements programmatically (wide +
 * narrow, mirroring config/sync/block.block.groups_chrome_search_form_*.yml)
 * rather than importing the assembled block config, so it stays independent
 * of config-import timing and asserts purely on the plugin's rendered
 * behaviour. It proves three things on the `groups_chrome` theme:
 *
 *   1. The first anonymous GET does not start a session (no `Set-Cookie:
 *      SESS...` header).
 *   2. Because no session was started, the internal page cache can actually
 *      cache the response — the second anonymous GET is a `X-Drupal-Cache:
 *      HIT`.
 *   3. The header markup is the plain GET search form (method=get,
 *      action=/search/node, a `keys` search input) with NO Form-API
 *      artifacts (`form_token` / `form_build_id` hidden inputs).
 *
 * RED (before the fix): block config still places `search_form_block` (or,
 * once this test provisions `do_chrome_plain_search_form` directly, the
 * plugin id does not resolve because \Drupal\do_chrome\Plugin\Block\
 * PlainSearchFormBlock does not exist yet) — so assertion (3) fails first
 * (unknown plugin / block placement throws), and even if placement is
 * swapped to try the old plugin, assertions (1)-(2) fail because Form API's
 * CSRF token forces a session.
 *
 * @group do_chrome
 */
class AnonHeaderSearchNoSessionTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'do_chrome',
    'search',
    'block',
    'system',
    'user',
    'node',
    'page_cache',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'groups_chrome';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // The internal page cache is part of the standard profile but this test
    // installs a minimal profile, so ensure it's actually on — assertion (2)
    // (warm-cache HIT) is meaningless without it.
    /** @var \Drupal\Core\Extension\ModuleInstallerInterface $installer */
    $installer = \Drupal::service('module_installer');
    if (!\Drupal::moduleHandler()->moduleExists('page_cache')) {
      $installer->install(['page_cache']);
    }

    // Search needs an index/page to route `/search/node` to; the block only
    // needs to render the form markup for this test, so a minimal search
    // page config suffices. The `search.page.*` default config that ships
    // with the `search` module (node search) is enough — no content needed.
    // Place the two header search blocks exactly where the real config puts
    // them (secondary_menu = wide, primary_menu = narrow), but using the
    // NEW plugin id. Until \Drupal\do_chrome\Plugin\Block\
    // PlainSearchFormBlock exists, creating these blocks with this plugin id
    // throws a PluginNotFoundException — a valid RED.
    Block::create([
      'id' => 'groups_chrome_search_form_wide',
      'theme' => 'groups_chrome',
      'region' => 'secondary_menu',
      'weight' => -5,
      'plugin' => 'do_chrome_plain_search_form',
      'settings' => [
        'id' => 'do_chrome_plain_search_form',
        'label' => 'Search form (wide)',
        'label_display' => '0',
        'provider' => 'do_chrome',
      ],
      'visibility' => [],
    ])->save();

    Block::create([
      'id' => 'groups_chrome_search_form_narrow',
      'theme' => 'groups_chrome',
      'region' => 'primary_menu',
      'weight' => -4,
      'plugin' => 'do_chrome_plain_search_form',
      'settings' => [
        'id' => 'do_chrome_plain_search_form',
        'label' => 'Search form (narrow)',
        'label_display' => '0',
        'provider' => 'do_chrome',
      ],
      'visibility' => [],
    ])->save();
  }

  /**
   * The anon header search form does not start a session or break page cache.
   */
  public function testAnonHeaderSearchDoesNotStartSession(): void {
    $client = \Drupal::httpClient();
    $front_url = $this->getAbsoluteUrl('/');

    // --- Request 1: cold, primes the page cache. --------------------------
    $response1 = $client->request('GET', $front_url, [
      'cookies' => FALSE,
      'http_errors' => FALSE,
    ]);

    // (1) No session must be started for an anonymous visitor: no Set-Cookie
    // header naming a Drupal session cookie (SESS... / SSESS...).
    $set_cookie_headers = $response1->getHeader('Set-Cookie');
    $session_cookie_found = FALSE;
    foreach ($set_cookie_headers as $cookie_header) {
      if (preg_match('/^S?SESS[0-9a-zA-Z]{32,}=/', $cookie_header)) {
        $session_cookie_found = TRUE;
        break;
      }
    }
    $this->assertFalse(
      $session_cookie_found,
      'The first anonymous GET must not start a session (found: ' . implode('; ', $set_cookie_headers) . ').'
    );

    $body1 = (string) $response1->getBody();

    // (3) The header markup is the plain GET search form, with no Form-API
    // CSRF/build-id artifacts.
    $this->assertMatchesRegularExpression(
      '#<form[^>]+method="get"[^>]+action="[^"]*/search/node[^"]*"#i',
      $body1,
      'A plain GET form targeting /search/node must be present in the header.'
    );
    $this->assertMatchesRegularExpression(
      '#<input[^>]+type="search"[^>]+name="keys"#i',
      $body1,
      'The search form must contain a search input named "keys".'
    );
    $this->assertDoesNotMatchRegularExpression(
      '#<input[^>]+type="hidden"[^>]+name="form_token"#i',
      $body1,
      'The plain search form must NOT emit a Form API CSRF form_token.'
    );
    $this->assertDoesNotMatchRegularExpression(
      '#<input[^>]+type="hidden"[^>]+name="form_build_id"#i',
      $body1,
      'The plain search form must NOT emit a Form API form_build_id.'
    );

    // --- Request 2: warm, same anon client (no cookies carried). ----------
    $response2 = $client->request('GET', $front_url, [
      'cookies' => FALSE,
      'http_errors' => FALSE,
    ]);

    // (2) With no session forcing the response to be uncacheable, the
    // internal page cache must serve the second anonymous request as a HIT.
    $cache_header = $response2->getHeader('X-Drupal-Cache');
    $this->assertNotEmpty($cache_header, 'X-Drupal-Cache header must be present on the warm request.');
    $this->assertSame(
      'HIT',
      $cache_header[0] ?? NULL,
      'The second anonymous GET must be served from the internal page cache (HIT), proving the response is cacheable.'
    );
  }

}
