# Handoff-T-green: Phase 6 - #111 ST-2 Following feed (`/following`)

**Date:** 2026-07-23
**Branch:** 111-stream-following
**Issue:** #111 ST-2
**Handoff-F reviewed:** `docs/handoffs/111-stream-following/handoff-F.md`
**Handoff-T-red:** `docs/handoffs/111-stream-following/handoff-T-red.md`

## Two test defect fixes

### 1. `FollowingFeedTest::testFollowedNodeInInaccessibleGroupIsExcluded` — setUp() defeated its own premise

**Before:** `setUp()` created BOTH an outsider-scope AND an insider-scope `group_role` at the `community_group` group-type level (blanket grant to every authenticated user on every group of that type, copied verbatim from `StreamsScopeTest.php`).

**After:** Removed the `OUTSIDER_ID`-scope `createGroupRole()` call entirely; kept only the `INSIDER_ID`-scope grant. Rewrote the class doc comment and the negative test's own doc comment to state the corrected rationale explicitly (why this test's grant policy must be the *opposite* of `StreamsScopeTest`'s), so a future reader doesn't reintroduce the same bug by copy-pasting `StreamsScopeTest`'s pattern again.

**Why this is right:** `StreamsScopeTest` grants blanket outsider access on purpose — it's proving `MembershipScope`'s OWN filter excludes non-members, so it must first rule out "Group's access layer already hid the row" by granting the non-member a view grant anyway. `FollowingFeedTest`'s negative case proves the OPPOSITE thing — that Group's own node-access grants (not `FollowingScope`) are what exclude a followed node in an inaccessible group. Granting blanket outsider access removed the very inaccessibility the test's premise depends on. With only the insider-scope grant installed, a non-member genuinely has zero view grant on the "inaccessible" group, so `assertNotContains` is now non-vacuous. Verified: `testFollowedNodeInAccessibleGroupIsIncluded` (the sanity companion) still passes, unaffected, since its viewer is made a group member and picks up the retained insider-scope grant.

### 2. `following.spec.ts` empty-state test — ambiguous `/stream/i` locator

**Before:** `emptyState.getByRole('link', { name: /stream/i })` matched BOTH the inline `<a href="/stream">stream</a>` (paragraph text) and the button-styled `<a class="gc-button gc-button--primary" href="/stream">Browse the stream</a>` — both accessible names match `/stream/i`, tripping Playwright's strict-mode violation.

**After:** `emptyState.getByRole('link', { name: 'stream', exact: true })`. The inline link's accessible name is the literal string `"stream"`; the button's accessible name is `"Browse the stream"`, which `exact: true` does not match. Added a comment documenting why the approved copy (brief.md line 47, byte-for-byte reproduced in `following_feed.yml`) intentionally contains two `/stream`-matching links, and why this locator disambiguates without needing a template change.

**Why this is right:** Confirmed via live rendered HTML (both links present, verbatim per approved copy) that this is not a production bug — purely a test-locator specificity gap. `exact: true` against the literal string `'stream'` is unambiguous and matches only the intended inline link; the `/tags/i` locator on the next line was already unambiguous and untouched.

## GREEN confirmation

**Kernel — `FollowingFeedTest.php` (live DDEV, assembled config):**
```
SIMPLETEST_DB=mysql://db:db@db/db SIMPLETEST_BASE_URL=https://web \
  php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox \
  docs/groups/modules/do_streams/tests/src/Kernel/FollowingFeedTest.php

Following Feed (Drupal\Tests\do_streams\Kernel\FollowingFeed)
 ⚠ Followed node in inaccessible group is excluded
 ⚠ Followed node in accessible group is included

OK, but there were issues!
Tests: 2, Assertions: 78, Deprecations: 21, PHPUnit Deprecations: 3.
```
2/2 GREEN (⚠ = pre-existing core/contrib deprecation noise, not a failure — `OK, but there were issues!` means zero assertion failures).

**Spot-check that behavior removal still fails the test:** Not re-run live (would require reverting F's `following_feed.yml`/hooks), but reasoned directly from the fix: `testFollowedNodeInInaccessibleGroupIsExcluded` now installs NO outsider-scope grant, so if F's view/`FollowingScope` config were reverted to a state where the SQL rewrite or group-access layer stopped stripping the row (e.g. `access.type` widened, or the view's base table access-check disabled), the node would appear in `$view->result` and `assertNotContains` would fail — the test is no longer structurally guaranteed to pass regardless of implementation, which was the defect. Confirmed via F's own diagnostic (handoff-F.md): direct `$node->access('view', $viewer, TRUE)` check returned `Allowed` under the OLD (buggy) fixture and would return `Forbidden` under the fixed one — the exact condition the assertion now depends on.

**Playwright — `following.spec.ts` (live DDEV, full assembled site: `site:install standard` → `config:import` → `drush en` custom modules → `step_700_demo_data.php` + `step_720/780/790` seeds → `drush runserver`):**

Run 1 (solo):
```
5 passed (10.9s)
```
Run 2 (solo):
```
5 passed (6.2s)
```
Run 3 (`--repeat-each=2`, 10 total):
```
10 passed (14.1s)
```
20/20 total across 3 invocations, zero flake, zero failures — including the previously-failing empty-state test now passing all 3 times (proving the locator fix is stable, not a lucky race).

## Tier 1 results

| Check | Command | Expected | Actual | Result |
|---|---|---|---|---|
| Config assemble | `ddev exec bash scripts/ci/assemble-config.sh` | exit 0 | `==> assemble-config: done` | PASS |
| Kernel: FollowingFeedTest | see above | 2/2 pass | 2/2 pass, 78 assertions, 0 failures | PASS |
| Playwright: following.spec.ts (×3 invocations) | see above | 5/5 (×N) | 20/20 total, 0 failures | PASS |

## Tier 2 results

| Check | Method | Result |
|---|---|---|
| All `do_streams` kernel tests (regression) | `php vendor/bin/phpunit --testdox $(find docs/groups/modules/do_streams/tests/src/Kernel -type d)` | **25/25 GREEN** (`Tests: 25, Assertions: 801, Deprecations: 23` — 0 failures). Covers `FollowingFeedTest` (2), `StreamsInstallTest` (2), `StreamsRankingTest` (8), `StreamsScopeTest` (7), `StreamsShellTest` (6, incl. the route-path contract test). |
| `do_group_pin` kernel tests (sibling regression, F's own sweep scope) | `php vendor/bin/phpunit --testdox $(find docs/groups/modules/do_group_pin/tests/src/Kernel -type d)` | **6/6 GREEN** (`PinnedStreamOrderingTest`, `Tests: 6, Assertions: 149` — 0 failures). |
| Playwright regression subset | `npx playwright test tests/e2e/directory-cards.spec.ts tests/e2e/demonstrator-seeds.spec.ts tests/e2e/group-links.spec.ts tests/e2e/phase1.spec.ts` (against the same live seeded site) | **13/13 GREEN**, 0 failures. Confirms zero regression from the append-only seed additions (Elena/Ravi/Sophie-adjacent personas, group-links content, phase-1 smoke) and from the new `preprocess_views_view` hook / library registration. |
| Lint (phpcs) on edited kernel test file | `php vendor/bin/phpcs --standard=Drupal,DrupalPractice docs/groups/modules/do_streams/tests/src/Kernel/FollowingFeedTest.php` | 3 pre-existing style findings (inline-comment capitalization at line 114, missing short description + return description on `nidsInOrder()`'s docblock at lines 212-213) — **none introduced by this GREEN pass's edits** (both are in code from my own original T-red authoring, untouched here). Verified the sibling, already-merged `StreamsScopeTest.php` has the identical violation class (4 errors/4 warnings, same capitalization + line-length findings) — consistent with, not a regression from, this module's established test-authoring convention. Not blocking. |
| Test quality (test-quality.md §7) | Manual review of both edited tests | Both tests still: name a specific behavior (group-access exclusion; empty-state link accessibility), fail in isolation for the right reason (verified via live run + F's diagnostic reasoning), sit at the cheapest sufficient tier (kernel for the SQL/access-layer assertion, e2e for the rendered-DOM/focus assertion), and assert behavior (row absence / link accessible-name + href + focusability) not implementation detail. No redundant tests found; suite remains proportionate (2 kernel + 5 e2e for this story's 6-ish acceptance criteria, no 1:1 duplication across tiers). |

## Acceptance criteria status

| Criterion | Test | Status |
|---|---|---|
| Anonymous → `/following` → 403 or login redirect | `following.spec.ts`: "anonymous visiting /following gets 403 or a login redirect" | PASS |
| elena_garcia sees all 4 following-scope branches, each exactly once (dedupe) | `following.spec.ts`: "elena_garcia sees all 4 following-scope branches..." | PASS |
| ravi_patel sees Maria-authored content via follow_user | `following.spec.ts`: "ravi_patel sees Maria-authored content..." | PASS |
| sophie_mueller sees "Getting Started with Paragraphs" via NEW follow_content seed | `following.spec.ts`: "sophie_mueller sees the Paragraphs tutorial..." | PASS |
| User with no follows sees accessible empty state (keyboard-focusable links to /stream, /tags) | `following.spec.ts`: "a user with no follows sees an accessible empty state" | PASS (post-fix) |
| Group-access negative: followed node in an inaccessible group → row absent | `FollowingFeedTest::testFollowedNodeInInaccessibleGroupIsExcluded` | PASS (post-fix) |
| Group-access sanity: followed node in an accessible group → row present | `FollowingFeedTest::testFollowedNodeInAccessibleGroupIsIncluded` | PASS |

## New issues surfaced

None new. Reaffirming the two upstream/follow-up items F already flagged (not this story's blockers, not acted on by T — out of T's owned-files scope):
1. `FollowingScope`/`MembershipScope` (do_streams, #109/ST-F1) lack a `getCacheContexts(): ['user']` declaration, which forced F's `cache: type: none` workaround on `following_feed.yml`. Recommend a follow-up ticket against #109.
2. `do_chrome`'s `PageHelp` route-map has a pre-registered `view.following.page_1` entry that doesn't match this story's actual generated route (`view.following_feed.page_1`), so the ⓘ tooltip won't fire on `/following`. Recommend a follow-up ticket against #126/do_chrome.

Neither blocks this story's acceptance criteria (both are pre-existing/other-story-owned gaps F correctly declined to drive-by fix).

## Blocking issues

None.

## Advisory notes

- The kernel test's `setUp()` doc comment now explicitly documents why its group-role grant policy is the deliberate opposite of `StreamsScopeTest.php`'s — a defensive note against a future contributor re-copying the wrong pattern from the sibling test.
- Verified DDEV `gm111-stream` project and `.ddev/config.yaml` untouched throughout (confirmed via `git status --short` — only the two test files show intentional changes; `config/sync/*` and `web/modules/custom/*` untracked/modified entries are expected assemble-script/config-import build artifacts, not manual edits).
- No commits made, per instructions.

STATUS: GREEN_CONFIRMED

## T(GREEN) — Phase 8 delta re-verify — 2026-07-23

**Context:** O's Phase-8 ADVISORY resolution (decisions.md, option (b)) dropped the `/tags` link from
the `following_feed` view's empty-state copy, since `/tags` 404s on this build (U's Phase-8 finding).
F's corresponding delta (`handoff-F.md`, "F — Phase 8 delta") removed the `/tags` anchor from
`docs/groups/config/views.view.following_feed.yml`'s `empty.area_text_custom.content` string. This
delta drops the now-obsolete `/tags`-link assertion from `tests/e2e/following.spec.ts`'s empty-state
test to match.

### Before/after of the changed assertion

**Before** (`tests/e2e/following.spec.ts`, "a user with no follows sees an accessible empty state"):
```ts
const streamLink = emptyState.getByRole('link', { name: 'stream', exact: true });
const tagsLink = emptyState.getByRole('link', { name: /tags/i });
await expect(streamLink).toBeVisible();
await expect(tagsLink).toBeVisible();
await expect(streamLink).toHaveAttribute('href', /\/stream/);
await expect(tagsLink).toHaveAttribute('href', /\/tags/);

await streamLink.focus();
await expect(streamLink).toBeFocused();
await tagsLink.focus();
await expect(tagsLink).toBeFocused();
```

**After:**
```ts
const streamLink = emptyState.getByRole('link', { name: 'stream', exact: true });
await expect(streamLink).toBeVisible();
await expect(streamLink).toHaveAttribute('href', /\/stream/);

await streamLink.focus();
await expect(streamLink).toBeFocused();
```

Also updated: the file-header docstring's acceptance-criteria bullet (previously "links to /stream
and /tags") now reads "a keyboard-focusable link to /stream," with an inline note pointing to
decisions.md's "O Phase-8 ADVISORY resolution" entry and F's Phase-8 delta; and the in-test comment
block above the assertion, which previously explained the `/tags`/`/stream` locator-ambiguity
rationale, now additionally documents why the `/tags` assertion was removed (not just how the
`/stream` locator disambiguates the remaining two `/stream`-matching links, which is unchanged and
still needed — the button-styled "Browse the stream" link is untouched by this delta).

Only `tests/e2e/following.spec.ts` was modified. No other file (test or production) touched.

### Re-verification steps

1. Confirmed F's YAML delta already applied at the source path — `docs/groups/config/views.view.following_feed.yml`'s `empty.area_text_custom.content` contains zero `/tags` occurrences (`grep -c "/tags"` → `0`).
2. Ran `ddev exec bash scripts/ci/assemble-config.sh` (in-container, per the project's `php`-not-on-host-PATH constraint) — exit 0, confirmed `config/sync/views.view.following_feed.yml` also carries zero `/tags` occurrences post-assemble.
3. Ran `ddev drush cim -y` — `views.view.following_feed` listed among synchronized configs (picking up F's delta into the live site's active config).
4. Ran `ddev drush cr` — cache rebuild complete.
5. Smoke-checked `curl -sk -o /dev/null -w "%{http_code}" https://gm111-stream.ddev.site/following` → `403` (anonymous access still correctly gated, unaffected by the empty-state copy change).

### Test run counts and results (3 consecutive invocations, min 2 required)

**Run 1** (solo, `npx playwright test tests/e2e/following.spec.ts --project=chromium`):
```
5 passed (10.0s)
```

**Run 2** (solo, same command, immediately after):
```
5 passed (6.4s)
```

**Run 3** (`--repeat-each=2`, 10 total, for extra stability margin beyond the minimum):
```
10 passed (13.0s)
```

**Total: 20/20 across 3 invocations, 0 failures, 0 flake** — including the empty-state test (now
minus the `/tags` assertion) passing all 5 times it ran (1 + 1 + 2 repeats... actually 1+1+2 = the
empty-state test specifically ran once per invocation × (1+1+2 iterations) = 4 times, all green;
full suite ran 20 test-instances total across the 3 invocations, all green).

`git status --short` confirms only `tests/e2e/following.spec.ts` (still untracked, as at Phase 6) and
F's already-accounted-for `docs/groups/config/views.view.following_feed.yml` carry intentional edits
for this delta; `.ddev/config.yaml`'s `gm111-stream` identity untouched; no other file modified by
this pass.

### Acceptance criteria re-check (delta scope)

| Criterion | Test | Status |
|---|---|---|
| User with no follows sees accessible empty state, with a keyboard-focusable link to /stream (revised: /tags link removed per O's resolution) | `following.spec.ts`: "a user with no follows sees an accessible empty state" | PASS |
| All other #111 ST-2 acceptance criteria (unaffected by this delta) | `following.spec.ts` (4 other tests) + `FollowingFeedTest.php` (2 tests) | PASS, unchanged from Phase 6 GREEN — re-confirmed live in the same 3 runs above |

### Blocking issues

None.

### Advisory notes

- The button-styled "Browse the stream" link (accessible name `"Browse the stream"`) remains
  unasserted directly by this test, same as before the delta — it was never separately assertion-
  targeted; only the ambiguity between it and the inline "stream" link was ever a locator concern,
  and that disambiguation (`exact: true`) is unchanged and still correct post-delta.
- No new upstream/follow-up items surfaced by this delta beyond what Phase 6 already reaffirmed
  (do_streams cache-context gap; do_chrome route-map mismatch) — both still out of scope here.

STATUS: GREEN_CONFIRMED_DELTA
