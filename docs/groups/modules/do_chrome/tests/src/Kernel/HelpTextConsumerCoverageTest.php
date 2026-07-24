<?php

declare(strict_types=1);

namespace Drupal\Tests\do_chrome\Kernel;

use Drupal\do_chrome\HelpText;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Finder\Finder;

/**
 * #193 (SD-4 tooltip consumers): every HelpText::all() key must have at
 * least one PRODUCTION consumer wiring it into a `data-do-tooltip` trigger
 * somewhere in the codebase — either a direct `HelpText::get('<key>')` call
 * site, an indirect one (the key resolved via a variable, e.g. a class
 * constant lookup map — VisibilityTooltip::OPTION_COPY_KEYS — or a
 * concatenated/assigned key — VariantSwitcher::build()'s `$tooltip_key =
 * 'showcase.switcher.' . $instance_id; ... HelpText::get($tooltip_key)`),
 * or the literal copy string appearing in a rendered attribute value.
 *
 * Detection strategy: rather than pattern-matching every possible
 * indirection style used across the codebase (verified during authoring:
 * there are at least FIVE distinct styles — direct call,
 * concatenation-inline, concatenation-then-variable,
 * class-constant-map-then-variable, and a two-part-literal concatenation
 * where the namespace prefix and the per-instance id are each spelled out
 * as SEPARATE string literals in different files — and attempting to
 * enumerate them all via regex is an arms race with the code, not a stable
 * contract), this scans for the literal quoted key string (e.g.
 * `'chrome.stream_switcher'`) ANYWHERE in a production file. This catches
 * every style except the two-part-literal-concatenation one (where no
 * single file ever spells out the FULL key string — see
 * whitelistedKeys() for that category, verified manually).
 *
 * Two source locations are scanned (both verified during authoring):
 *  - docs/groups/ — the primary do_* custom-module source tree.
 *  - web/themes/custom/groups_chrome/ — the ONE consumer location that is
 *    NOT under docs/groups/: `groups_chrome` is a real, git-tracked theme
 *    (NOT a build artifact like web/modules/custom/, which IS gitignored —
 *    verified: `git ls-files web/themes/custom/groups_chrome` returns 19
 *    tracked files), and HelpText.php's own #127 docblock says the
 *    `card.*` keys are read via groups_chrome's preprocess functions
 *    (verified: `groups_chrome.theme` calls `HelpText::get('card.stream.
 *    byline')` / `HelpText::get('card.directory.type')` directly).
 *
 * Test files are excluded from the scan (see trackedFiles()) — a unit test
 * asserting `HelpText::get('some.key')` resolves to expected copy proves
 * the copy exists, not that any production code renders it as a tooltip
 * trigger; counting test-only references as "consumers" produced false
 * negatives during authoring (verified: `HelpTextTest.php` asserts
 * `HelpText::get('privacy.unlisted')` / `'privacy.vs_invite_only'`
 * directly, which are NOT actually wired into any `data-do-tooltip`
 * trigger in production code — a genuine pre-existing gap, whitelisted
 * below as out of #193's scope).
 *
 * Scope correction (triage, coordinator-approved "Option A"): the issue's
 * original list of 6 `stream.card.*` keys does not exist in HelpText.php at
 * all (verified directly against HelpText::all() before authoring this
 * test) — there is no `stream.card.*` namespace in the file, so asserting
 * consumer coverage for those would be asserting against a fiction. The
 * ONLY key in the real HelpText::all() with a documented, verified-absent
 * consumer that #193 is scoped to fix is `chrome.stream_switcher`
 * (HelpText.php:404-420) — its own docblock says so explicitly ("No
 * consuming markup is wired to this key YET"). This test pins that one
 * real gap plus a regression sweep so a future orphaned key doesn't
 * silently reappear.
 *
 * RED reason (today, before F implements): `chrome.stream_switcher` is the
 * literal string `'chrome.stream_switcher'` ONLY inside HelpText.php itself
 * and this test file — no production consumer anywhere else (verified via
 * full-repo search, test files and HelpText.php excluded). Both
 * testChromeStreamSwitcherHasConsumer() and
 * testEveryHelpTextKeyHasAConsumer() fail for this same, single reason.
 *
 * @group do_chrome
 */
#[RunTestsInSeparateProcesses]
final class HelpTextConsumerCoverageTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['do_chrome'];

  /**
   * Returns the absolute path to the repo root (the directory that
   * contains both docs/groups/ and web/).
   *
   * Walks up from this test file's directory looking for a `docs/groups`
   * subdirectory as the repo-root marker — works from both the source
   * tree (this file lives INSIDE docs/groups already) and the assembled
   * tree (this file lives under web/modules/custom/, a sibling of
   * docs/groups at the repo root).
   */
  private function repoRootPath(): string {
    $dir = __DIR__;
    for ($i = 0; $i < 12; $i++) {
      $candidate = $dir . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'groups';
      if (is_dir($candidate)) {
        return $dir;
      }
      // Also handle the case where __DIR__ is already inside docs/groups
      // (running directly from the source tree) — the repo root is then
      // two levels above 'docs/groups'.
      $normalized = str_replace('\\', '/', $dir);
      if (str_ends_with($normalized, '/docs/groups')) {
        return \dirname($dir, 2);
      }
      $parent = \dirname($dir);
      if ($parent === $dir) {
        break;
      }
      $dir = $parent;
    }
    $this->fail(sprintf('Could not locate the repo root (a docs/groups marker) by walking up from "%s".', __DIR__));
  }

  /**
   * Returns true if any PRODUCTION file (see trackedFiles()) contains the
   * key's literal quoted string (single- or double-quoted) — the most
   * robust single signal for "some code path wires this key", covering
   * every indirection style verified in this codebase except two-part
   * literal concatenation (direct call, concatenation, class-constant map)
   * without needing to enumerate each style's exact syntax — OR the
   * literal copy value (e.g. baked into a twig/theme template as a
   * rendered attribute value).
   *
   * "Production file" excludes both HelpText.php itself (the source of
   * truth for every key's literal copy — and, unavoidably, of the literal
   * key string too — so it can never count as its own consumer) and every
   * test file (see trackedFiles()).
   */
  private function keyHasConsumer(string $key, string $copy, string $repoRoot): bool {
    $singleQuoted = "'" . $key . "'";
    $doubleQuoted = '"' . $key . '"';

    foreach ($this->trackedFiles($repoRoot) as $file) {
      $contents = @file_get_contents($file);
      if ($contents === FALSE) {
        continue;
      }
      if (str_contains($contents, $singleQuoted) || str_contains($contents, $doubleQuoted)) {
        return TRUE;
      }
      // Literal copy match (e.g. baked into a twig/theme template as a
      // rendered attribute value) — only meaningful for non-empty copy.
      if ($copy !== '' && str_contains($contents, $copy)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Returns every PRODUCTION file path across the two verified consumer
   * source locations (docs/groups/ and web/themes/custom/groups_chrome/ —
   * see class docblock), restricted to the extensions a `data-do-tooltip`
   * consumer could plausibly live in.
   *
   * Uses Symfony Finder over the filesystem (no git dependency — the
   * assembled `web/modules/custom/` copy is a derived build artifact, and
   * in CI's assembled layout there is no `.git` directory reachable from
   * the container mount at all: verified `git rev-parse --show-toplevel`
   * fails inside the ddev web container — "not a git repository" — so
   * `git ls-files`/`git grep` are not viable here). Excludes every
   * module's `tests/` directory wholesale (not just this test file) — a
   * test file referencing a key literal to assert copy content is not a
   * rendered tooltip consumer, and counting it as one produces false
   * negatives (see class docblock).
   *
   * @return string[]
   */
  private function trackedFiles(string $repoRoot): array {
    $docsGroups = $repoRoot . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'groups';
    $groupsChromeTheme = $repoRoot . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . 'themes'
      . DIRECTORY_SEPARATOR . 'custom' . DIRECTORY_SEPARATOR . 'groups_chrome';

    $this->assertDirectoryExists($docsGroups, sprintf('Expected docs/groups at "%s".', $docsGroups));

    $searchDirs = [$docsGroups];
    if (is_dir($groupsChromeTheme)) {
      $searchDirs[] = $groupsChromeTheme;
    }

    $finder = new Finder();
    $finder->files()
      ->in($searchDirs)
      ->name(['*.twig', '*.php', '*.module', '*.theme', '*.yml', '*.install'])
      // Exclude every module's tests/ directory wholesale, and
      // HelpText.php itself (the source of truth for every key's literal
      // copy AND its literal key string, so it can never count as its own
      // consumer).
      ->exclude('tests')
      ->notPath('modules/do_chrome/src/HelpText.php');

    $files = [];
    foreach ($finder as $fileInfo) {
      $files[] = $fileInfo->getRealPath();
    }
    return $files;
  }

  /**
   * Keys this literal-string scanner cannot detect a PRODUCTION consumer
   * for, split into two categories — BOTH manually verified during
   * authoring and BOTH out of #193's scope (triage confirmed the ONLY
   * currently-orphaned key #193 must wire a NEW consumer for is
   * `chrome.stream_switcher`):
   *
   * 1. GENUINELY WIRED, via two-part-literal concatenation — the
   *    namespace prefix and the per-instance/per-entry id are each
   *    spelled out as separate string literals in DIFFERENT files, so no
   *    single file ever contains the full key as one literal string. This
   *    static scanner cannot follow that without hard-coding the specific
   *    concatenation convention, which is exactly the "arms race" the
   *    class docblock says isn't worth fighting. Verified by directly
   *    reading the consuming code for each entry below.
   * 2. GENUINELY NOT WIRED, pre-existing, out of scope — no production
   *    consumer exists anywhere (verified via full-repo search, tests and
   *    HelpText.php excluded). Documented here so this sweep doesn't
   *    silently paper over them, but also doesn't fail THIS story for a
   *    gap #193 wasn't asked to close.
   *
   * @return string[]
   */
  private function whitelistedKeys(): array {
    return [
      // --- Category 1: two-part-literal concatenation, genuinely wired --
      //
      // VariantSwitcher::build(string $instance_id, ...) does
      // `$tooltip_key = 'showcase.switcher.' . $instance_id;` then
      // `HelpText::get($tooltip_key)` (VariantSwitcher.php:260-261). Each
      // instance id is a SEPARATE literal, passed at the call site:
      //  - 'directory.layout'  — ShowcaseController.php:229,
      //    DoShowcaseHooks.php:510.
      //  - 'discovery.ranking' — ShowcaseController.php:359.
      //  - 'stream.model'      — ModelToggleHooks.php:215.
      'showcase.switcher.directory.layout',
      'showcase.switcher.discovery.ranking',
      'showcase.switcher.stream.model',
      //
      // ShowcaseController::page() does
      // `HelpText::get('showcase_help.' . $entry['id'])`
      // (ShowcaseController.php:285), where each `$entry['id']` is a
      // separate literal in ShowcaseCatalog::all() (ShowcaseCatalog.php):
      // 'discovery-ranking', 'directory-presentation',
      // 'membership-models', 'group-type-homepages', 'stream-model',
      // 'private-group-reveal', 'persona-switcher'. (showcase_help.map and
      // showcase_help.persona_banner are direct `HelpText::get()` call
      // sites elsewhere and are already detected without whitelisting.)
      'showcase_help.discovery-ranking',
      'showcase_help.directory-presentation',
      'showcase_help.membership-models',
      'showcase_help.group-type-homepages',
      'showcase_help.stream-model',
      'showcase_help.private-group-reveal',
      'showcase_help.persona-switcher',
      //
      // --- Category 2: genuinely NOT wired, pre-existing, out of scope --
      //
      // #131 SD-4 docblock (HelpText.php lines 329-346): 'stream.my_feed'
      // is explicitly "reserved for the shared do_streams_shell's own 'My
      // Feed' scope-tab tooltip ... read wherever a future story wires a
      // tooltip trigger onto that tab — this story ships only the copy
      // entry itself." Verified: no do_streams file references this key
      // at all. A genuine, pre-existing, documented gap — out of #193's
      // scope.
      'stream.my_feed',
      // #134 (SC-7) privacy axis keys — verified via full-repo search
      // (excluding tests and HelpText.php): the literal key string appears
      // NOWHERE else, and no `data-do-tooltip` trigger renders any of
      // their literal copy. do_group_extras (the privacy-axis owning
      // module) has no do_chrome dependency and wires no tooltip trigger
      // for this field today. This is a genuine, pre-existing gap — out of
      // #193's scope (#193 = do_chrome's own chrome.stream_switcher only)
      // — flagged here rather than silently passed, so a future story (or
      // an SD-6-style capstone sweep) has a documented pointer to it.
      'privacy.public',
      'privacy.private',
      'privacy.unlisted',
      'privacy.vs_invite_only',
      // #126 SD-1: 5 W2 pre-registered page.* keys — HelpText.php's own
      // docblock (lines 251-253) says these routes do not exist yet;
      // PageHelp::getRouteMap() intentionally has no entry for them until
      // their W2 story builds the route.
      'page.my_feed',
      'page.following',
      'page.trending',
      'page.my_feed_events',
      'page.profile_stream',
      // #131 SD-4 docblock (lines 358-377): these stream.* element-tooltip
      // keys are copy-only, wired by SIBLING wave stories (#112-#115, #129,
      // #130) into their own host templates — those stories are out of
      // #193's scope (#193 is do_chrome-only: chrome.stream_switcher).
      'stream.my_feed.empty',
      'stream.my_feed_events.rsvp_chip',
      'stream.activity_row.social',
      'stream.activity_row.aggregated',
      'stream.activity_row.comment',
      'stream.model_toggle',
      // #114 ST-5: profile_activity.section is wired by a sibling module
      // (do_activity_feed / user_activity view), out of #193's do_chrome
      // scope.
      'profile_activity.section',
      // #129 ST-7: page.activity's PageHelp::getRouteMap() wiring is
      // explicitly deferred to SD-6 (#133) per HelpText.php's own docblock
      // (lines 424-430) — not this story.
      'page.activity',
    ];
  }

  /**
   * `chrome.stream_switcher` (HelpText.php:420) must have at least one
   * real PRODUCTION consumer once #193 is implemented — either a
   * `HelpText::get('chrome.stream_switcher')` call site (direct or via an
   * indirection that still spells out the literal key string), or its
   * literal copy rendered as a `data-do-tooltip` attribute value.
   */
  public function testChromeStreamSwitcherHasConsumer(): void {
    $key = 'chrome.stream_switcher';
    $copy = HelpText::get($key);
    $this->assertNotSame('', $copy, 'chrome.stream_switcher copy must exist in HelpText::all().');

    $repoRoot = $this->repoRootPath();
    $this->assertTrue(
      $this->keyHasConsumer($key, $copy, $repoRoot),
      sprintf(
        '"%s" must have at least one PRODUCTION consumer — its literal key string must appear in a wiring call site (docs/groups/ or web/themes/custom/groups_chrome/), or its literal copy must be rendered as a data-do-tooltip value. None found (test files and HelpText.php excluded from the scan).',
        $key
      )
    );
  }

  /**
   * Regression sweep: every key in HelpText::all() must have at least one
   * detectable PRODUCTION consumer, except the keys in whitelistedKeys()
   * (documented, manually-verified genuinely-wired-via-two-part-literal
   * entries, and documented pre-existing/deferred gaps — both categories
   * verified out of #193's scope).
   *
   * Today this fails for exactly one key — `chrome.stream_switcher` — the
   * same root cause as testChromeStreamSwitcherHasConsumer(). Once F wires
   * a consumer for that key, this test also goes green, and continues to
   * guard against a future story adding a new HelpText key with no
   * consumer and no whitelist entry.
   */
  public function testEveryHelpTextKeyHasAConsumer(): void {
    $repoRoot = $this->repoRootPath();
    $whitelist = $this->whitelistedKeys();
    $orphaned = [];

    foreach (HelpText::all() as $key => $copy) {
      if (\in_array($key, $whitelist, TRUE)) {
        continue;
      }
      if (!$this->keyHasConsumer($key, $copy, $repoRoot)) {
        $orphaned[] = $key;
      }
    }

    $this->assertSame(
      [],
      $orphaned,
      sprintf(
        'The following HelpText::all() keys have no PRODUCTION consumer and are not in the whitelist: %s',
        implode(', ', $orphaned)
      )
    );
  }

}
