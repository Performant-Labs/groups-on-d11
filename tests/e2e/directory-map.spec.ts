import { test, expect, Page, Request } from '@playwright/test';

/**
 * E2E for #125 SC-6 — Directory map view on `/all-groups`.
 *
 * `docs/handoffs/0125-directory-map/brief.md` + `wireframe.md` flip the
 * SC-F1 (#119) variant switcher's third option — `Map` — from `(soon)` /
 * `aria-disabled="true"` to a live, selectable variant. Selecting it (or
 * loading `?variant=map` directly) replaces the row grid (`.view-content`)
 * with a Leaflet map plotting one marker per group carrying
 * `field_group_location` (four seeded groups: Drupal France/Paris, Drupal
 * Deutschland/Berlin, Camp Organizers EMEA/Brussels, DrupalCon Portland
 * 2026/Portland OR). Clicking a marker navigates directly to that group's
 * canonical page (no popup step — wireframe.md Surface 1 "Marker behavior").
 * All Leaflet assets (JS/CSS/marker sprites) are served from
 * `/libraries/leaflet/` — ZERO external network requests (brief.md AC,
 * epic #78's no-CDN posture).
 *
 * Nothing in this spec's assertions exist in the codebase yet at RED time
 * (Phase 4, before F implements): `VariantSwitcher::directoryLayoutOptionIds()`'s
 * `map` entry is still `available: FALSE` (falls back to Cards, per
 * `directory-toggle.spec.ts`'s own `'?variant=map (unavailable) falls back
 * gracefully to compact...'` test), the `do_showcase/directory-map` library
 * does not exist, `.do-showcase-map` renders nothing, and
 * `field_group_location` is not yet a field on `community_group`. This spec
 * is authored to RED (syntactically valid, `npx playwright test --list`
 * succeeds) and is NOT executed for real until T-GREEN, against a fully
 * seeded, running site (assemble -> site:install -> cim -> seed ->
 * runserver), per PROJECT_CONTEXT.md.
 *
 * Selector contract (per wireframe.md Surface 1/2/3):
 *   - Switcher wrapper: [role="radiogroup"][data-do-showcase-instance="directory.layout"]
 *     (SAME instance id `directory-toggle.spec.ts` already uses).
 *   - Wrapper variant attribute: .views-element-container[data-do-directory-variant="map"]
 *     (Surface 3 "Contract").
 *   - Map container: .do-showcase-map, nested inside the variant wrapper.
 *   - Markers: Leaflet 1.9.4's default DOM output — `.leaflet-marker-icon`
 *     (an <img> per marker, the standard Leaflet marker-pane element) —
 *     see `markerLocator()` below.
 *   - SR-only fallback list: ul.do-showcase-map-fallback-list (Surface 2),
 *     each <li><a> reading "Group Name — City".
 *   - Caption: visible text "Showing N groups with a location." /
 *     "Showing N of M groups with a location." (Surface 1).
 *
 * Auth/login helper mirrors directory-cards.spec.ts / directory-toggle.spec.ts's
 * existing convention (real /user/login form, admin/admin) — unused directly
 * in this spec (all assertions here are anonymous-accessible), kept for
 * parity/future extension.
 */

const ADMIN_USER = process.env.ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.ADMIN_PASS ?? 'admin';

async function login(page: Page, user: string, pass: string): Promise<void> {
  await page.goto('/user/login');
  await page.getByLabel('Username').fill(user);
  await page.getByLabel('Password', { exact: true }).fill(pass);
  await Promise.all([
    page.waitForURL(/\/user(\/\d+)?/),
    page.getByRole('button', { name: 'Log in' }).click(),
  ]);
  await expect(page.locator('body')).not.toContainText(
    'Unrecognized username or password',
  );
}

/** The switcher wrapper locator, scoped to the directory.layout instance. */
function switcherLocator(page: Page) {
  return page.locator(
    '[role="radiogroup"][data-do-showcase-instance="directory.layout"]',
  );
}

/** The view's variant-attributed content wrapper. */
function directoryWrapperLocator(page: Page) {
  return page.locator('.views-element-container[data-do-directory-variant]');
}

/** The Leaflet map container (Surface 1/3). */
function mapContainerLocator(page: Page) {
  return page.locator('.do-showcase-map');
}

/**
 * Leaflet 1.9.4's default marker DOM: each plotted marker is an `<img
 * class="leaflet-marker-icon">` inside `.leaflet-marker-pane` — this is the
 * stock output of `L.Marker` using `L.Icon.Default` (no custom icon, per
 * brief.md non-goal "No custom marker icons"). Scoping to the map container
 * avoids any accidental collision with an unrelated `.leaflet-marker-icon`
 * elsewhere on the page (there is none today, but this keeps the locator
 * self-contained regardless).
 */
function markerLocator(page: Page) {
  return mapContainerLocator(page).locator('.leaflet-marker-icon');
}

/** The SR-only keyboard fallback list (wireframe.md Surface 2). */
function fallbackListLocator(page: Page) {
  return page.locator('ul.do-showcase-map-fallback-list');
}

/**
 * Non-first-party hosts that must NEVER appear in the map page's network
 * log (brief.md AC: "Zero external network requests for map assets";
 * wireframe.md "No background tiles" design note). Deliberately broad
 * (includes tile/CDN hosts that would only be hit if a future regression
 * reintroduced a live tile layer or CDN-fetched Leaflet build).
 */
const FORBIDDEN_HOST_PATTERNS = [
  /unpkg\.com/i,
  /cdnjs\.cloudflare\.com/i,
  /tile\.openstreetmap\.org/i,
  /\.mapbox\.com/i,
  /googleapis\.com\/maps/i,
];

function isForbiddenRequest(request: Request): boolean {
  const url = request.url();
  if (url.startsWith('data:') || url.startsWith('blob:')) {
    return false;
  }
  let hostname: string;
  try {
    hostname = new URL(url).hostname;
  } catch {
    return false;
  }
  // First-party: same origin as the page itself.
  const isFirstParty = hostname === 'localhost' || hostname === '127.0.0.1' || hostname.endsWith('.ddev.site');
  if (!isFirstParty) {
    return true;
  }
  return FORBIDDEN_HOST_PATTERNS.some((pattern) => pattern.test(url));
}

test.describe('#125 SC-6 — Directory map view (/all-groups)', () => {
  test('the Map option in the switcher is live/selectable — no aria-disabled, no "(soon)" label', async ({
    page,
  }) => {
    await page.goto('/all-groups');
    const switcher = switcherLocator(page);
    const mapOption = switcher.getByRole('radio', { name: /^Map$/i });

    await expect(mapOption).toBeVisible();
    await expect(mapOption).not.toHaveAttribute('aria-disabled', 'true');
    await expect(switcher).not.toContainText('Map (soon)');
  });

  test('when Map is the selected variant, it carries the roving tabindex (tabindex="0"), per the WAI-ARIA radiogroup pattern', async ({
    page,
  }) => {
    // Positive form of the roving-tabindex check: on plain /all-groups
    // (Cards selected), Map is available-but-unselected and correctly
    // carries tabindex="-1" (VariantSwitcher::build() line 248: only the
    // SELECTED option gets tabindex="0" — pinned by the existing Unit tests
    // testExactlyOneAvailableOptionHasRovingTabindexZero() and
    // testUnavailableOptionIsNeverTheRovingTabindexTarget()). Once Map IS
    // the selected option (?variant=map), the roving-tabindex slot must
    // land on Map itself.
    //
    // Locator note (T-repair round 2, post-U): SC-F1 (#119) prepends a "● "
    // glyph to the SELECTED option's visible label, so the accessible name
    // for the checked Map radio becomes "● Map" and a `/^Map$/i` name-match
    // fails. Use the stable `data-do-showcase-id` attribute (shipped by
    // SC-F1 for exactly this reason — see do_showcase.switcher.js) which is
    // selection-glyph-independent.
    await page.goto('/all-groups?variant=map');
    const switcher = switcherLocator(page);
    const mapOption = switcher.locator('[data-do-showcase-id="map"]');

    await expect(mapOption).toHaveAttribute('aria-checked', 'true');
    await expect(mapOption).toHaveAttribute('tabindex', '0');
  });

  test('?variant=map renders a .do-showcase-map container inside the variant wrapper, replacing the row grid', async ({
    page,
  }) => {
    const res = await page.goto('/all-groups?variant=map');
    expect(res?.status()).toBe(200);

    const wrapper = directoryWrapperLocator(page);
    await expect(wrapper).toHaveAttribute('data-do-directory-variant', 'map');

    const mapContainer = wrapper.locator('.do-showcase-map');
    await expect(mapContainer).toBeVisible();

    // The row grid is hidden (Surface 3: ".view-content { display: none; }"
    // in map mode) — rows may still be present in the DOM (JS reads
    // data-do-location-lat/lng off them) but must not be VISIBLE.
    await expect(wrapper.locator('.view-content')).toBeHidden();

    // Pagination has no meaning for a single all-markers view — truthfully
    // hidden, not shown-but-inert (wireframe.md Surface 1).
    await expect(page.locator('.pager')).toBeHidden();
  });

  test('exactly 4 markers render for the seeded dataset (Portland, Paris, Brussels, Berlin)', async ({
    page,
  }) => {
    await page.goto('/all-groups?variant=map');
    const markers = markerLocator(page);
    await expect(markers).toHaveCount(4);
  });

  test('the caption states the truthful count: "Showing 4 groups with a location." (accepting either N==M or N<M wireframe form)', async ({
    page,
  }) => {
    // Wireframe Surface 1 permits both caption forms:
    //   - "Showing N groups with a location."         (when N == M total)
    //   - "Showing N of M groups with a location."    (when N < M total)
    // On the seeded site, persona-seed adds groups beyond the 4 with
    // coordinates, so the correct rendered form is "Showing 4 of 11 groups
    // with a location." A regex accepts either form while still pinning the
    // load-bearing "4 with a location" count. (T-repair round 2, post-U.)
    await page.goto('/all-groups?variant=map');
    const caption = page.getByText(/Showing 4( of \d+)? groups with a location\./);
    await expect(caption).toBeVisible();
  });

  test('clicking a marker navigates to a group canonical page', async ({ page }) => {
    await page.goto('/all-groups?variant=map');
    const markers = markerLocator(page);
    await expect(markers).toHaveCount(4);

    await Promise.all([
      page.waitForURL(/\/group\/\d+/),
      markers.first().click(),
    ]);
    expect(page.url()).toMatch(/\/group\/\d+/);
  });

  test('the SR-only fallback list has 4 items reading "Group Name — City", each linking to a group page', async ({
    page,
  }) => {
    await page.goto('/all-groups?variant=map');
    const list = fallbackListLocator(page);
    const items = list.locator('li a');
    await expect(items).toHaveCount(4);

    const expectedCities = ['Paris', 'Berlin', 'Brussels', 'Portland'];
    for (const city of expectedCities) {
      await expect(list).toContainText(new RegExp(`—\\s*${city}`));
    }

    // Every fallback link targets a real group canonical page.
    const hrefs = await items.evaluateAll((links) =>
      links.map((el) => (el as HTMLAnchorElement).getAttribute('href')),
    );
    for (const href of hrefs) {
      expect(href).toMatch(/\/group\/\d+/);
    }
  });

  test('the fallback list is visually hidden by default but reveals on focus-within (keyboard-visible-focus AC)', async ({
    page,
  }) => {
    await page.goto('/all-groups?variant=map');
    const list = fallbackListLocator(page);

    // Present in the accessibility tree (not display:none) — visually
    // hidden via a clip-based technique.
    await expect(list).toBeAttached();
    const displayBefore = await list.evaluate((el) => getComputedStyle(el).display);
    expect(displayBefore).not.toBe('none');

    const firstLink = list.locator('li a').first();
    await firstLink.focus();
    await expect(firstLink).toBeFocused();

    // On focus-within, the list must become visible to a sighted keyboard
    // user (wireframe.md Surface 2: "MUST become visible-on-focus... not
    // optional"). A reasonable structural proxy: the list's bounding box
    // has non-zero size once a descendant is focused (clip-based
    // visually-hidden patterns collapse the box to 1px until :focus-within).
    const box = await list.boundingBox();
    expect(box).not.toBeNull();
    expect((box?.width ?? 0)).toBeGreaterThan(1);
    expect((box?.height ?? 0)).toBeGreaterThan(1);
  });

  test('zero external network requests during the map page load — every Leaflet asset comes from /libraries/leaflet/', async ({
    page,
  }) => {
    const offenders: string[] = [];
    page.on('request', (request) => {
      if (isForbiddenRequest(request)) {
        offenders.push(request.url());
      }
    });

    await page.goto('/all-groups?variant=map');
    await expect(markerLocator(page)).toHaveCount(4);

    expect(offenders, `Forbidden (non-first-party) requests observed: ${offenders.join(', ')}`).toEqual([]);

    // Positive assertion: at least one asset from the local /libraries/leaflet/
    // path was actually requested — proves the "zero CDN" result isn't
    // trivially true because nothing loaded at all. Drupal's default JS
    // aggregation bundles leaflet.js into `/sites/default/files/js/js_*.js`,
    // so no request URL literally contains "leaflet.js" — but Leaflet's
    // marker sprite PNGs (marker-icon.png, marker-shadow.png) DO hit
    // `/libraries/leaflet/images/...` directly, which proves local-vendor
    // sourcing just as well. (T-repair round 2, post-U — was previously
    // asserting `/leaflet\.js/i` which never matches under aggregation.)
    const leafletRequests: string[] = [];
    page.removeAllListeners('request');
    page.on('request', (request) => {
      if (/\/libraries\/leaflet\//i.test(request.url())) {
        leafletRequests.push(request.url());
      }
    });
    await page.goto('/all-groups?variant=map');
    await expect(markerLocator(page)).toHaveCount(4);
    expect(leafletRequests.some((u) => u.includes('/libraries/leaflet/'))).toBe(true);
  });

  test('toggling Cards -> Map -> Cards works client-side (no reload); markers still show after returning to Map', async ({
    page,
  }) => {
    await page.goto('/all-groups');
    const switcher = switcherLocator(page);
    const wrapper = directoryWrapperLocator(page);
    const urlBefore = page.url();

    await switcher.getByRole('radio', { name: /^Map$/i }).click();
    await expect(wrapper).toHaveAttribute('data-do-directory-variant', 'map');
    expect(page.url()).toBe(urlBefore);
    await expect(markerLocator(page)).toHaveCount(4);

    await switcher.getByRole('radio', { name: /Cards/i }).click();
    await expect(wrapper).toHaveAttribute('data-do-directory-variant', 'cards');
    await expect(page.locator('.gc-directory-card').first()).toBeVisible();

    await switcher.getByRole('radio', { name: /^Map$/i }).click();
    await expect(wrapper).toHaveAttribute('data-do-directory-variant', 'map');
    await expect(markerLocator(page)).toHaveCount(4);
  });
});

test.describe('#125 SC-6 — existing suites stay green (non-regression)', () => {
  test('directory-toggle.spec.ts: switcher still renders three options, Cards default, wrapper starts in cards mode', async ({
    page,
  }) => {
    await page.goto('/all-groups');
    const switcher = switcherLocator(page);
    await expect(switcher).toBeVisible();
    await expect(switcher.locator('[role="radio"]')).toHaveCount(3);

    const cards = switcher.getByRole('radio', { name: /Cards/i });
    await expect(cards).toHaveAttribute('aria-checked', 'true');
    await expect(directoryWrapperLocator(page)).toHaveAttribute('data-do-directory-variant', 'cards');
  });

  test('directory-cards.spec.ts: default cards view still renders .gc-directory-card cards', async ({
    page,
  }) => {
    await page.goto('/all-groups');
    const cards = page.locator('.gc-directory-card');
    expect(await cards.count()).toBeGreaterThan(0);
    await expect(directoryWrapperLocator(page)).toHaveAttribute('data-do-directory-variant', 'cards');
  });

  test('directory-toggle.spec.ts: filters are preserved across a Map toggle too (extends the existing Compact/Cards guarantee)', async ({
    page,
  }) => {
    await page.goto('/all-groups');
    const searchInput = page.getByLabel(/search groups/i);
    await searchInput.fill('Drupal');
    await page.getByRole('button', { name: 'Filter' }).click();
    await page.waitForURL(/[?&]search=Drupal/i);
    const urlAfterFilter = page.url();

    const switcher = switcherLocator(page);
    await switcher.getByRole('radio', { name: /^Map$/i }).click();
    expect(page.url()).toBe(urlAfterFilter);
    await expect(page.getByLabel(/search groups/i)).toHaveValue('Drupal');
  });
});
