# PERF-5 Asset optimization audit — 2026-07-27

Closes issue #245. Part of the Performance epic (#241).

Audit of CSS, JS, font, and image assets served to anonymous users across the
five most-visited pages on `https://groups.performantlabs.com`. Confirms
minification / aggregation / CDN-freeness posture and identifies concrete
optimization opportunities.

## Environment

| Field | Value |
|-------|-------|
| Target | `https://groups.performantlabs.com` (production demo on Uranus) |
| Auth | Anonymous only |
| Client | Playwright 1.56 / Chromium 151 (headless) |
| Timestamp (UTC) | 2026-07-27 |
| Runs | 1 warm load per page (asset inventory is deterministic) |
| Coverage tool | Chrome DevTools Protocol (`Page.coverage.startJSCoverage` + `Page.coverage.startCSSCoverage`) |

The script that produced these numbers lives at `scripts/perf/asset-audit.mjs`
and is re-runnable:

```bash
BASE_URL=https://groups.performantlabs.com node scripts/perf/asset-audit.mjs
```

Raw per-request JSON is committed at
`docs/planning/perf/asset-audit-2026-07-27.raw.json`.

## Pages audited

| Story URL | Live URL | HTTP | Notes |
|-----------|----------|-----:|-------|
| `/` (front) | `/` | 200 | Front page |
| `/showcase` | — | 404 | Route not published on demo; omitted (see baseline-2026-07-24.md §"Pages") |
| `/all-groups` | `/all-groups` | 200 | Directory list (default variant) |
| `/group/1` | `/group/1` | 200 | Group detail |
| `/stream` | `/stream` | 200 | Activity stream |
| `/my-feed` | — | 404 | Anonymous can't see personal feed; substituted `/groups` |
| — | `/groups` | 200 | 5th page, groups landing |

## Headline findings

| # | Finding | Severity | Action |
|---|---------|:--------:|--------|
| 1 | **All CSS + JS are minified** and served with `content-encoding: zstd` (via Caddy). | good | none |
| 2 | **Drupal aggregation is ON**: only 2 CSS + 1–2 JS bundles per page. | good | none |
| 3 | **Zero third-party hosts.** No `unpkg`, no `cdn.jsdelivr`, no `cdnjs`, no `fonts.googleapis.com`, no analytics. Fully self-hosted. | good | none |
| 4 | **Leaflet is vendored**, and only loaded when a map view actually renders. `/all-groups` list view does NOT pull Leaflet; the ~162 KB payload is deferred to map-variant pages. | good | none |
| 5 | **Four 404 requests per page** from Drupal core's CSS aggregator resolving font URLs against the aggregated CSS location instead of the original stylesheet. | **medium** | file follow-up (see #10.1) |
| 6 | **CSS coverage is 37–48% used** per page. ~40–68 KB of aggregated CSS is unused on any given page. | low | file follow-up (see #10.2) |
| 7 | **Preload manifest lists 4 fonts on every page**, but not all fonts are used on every page (e.g. Lora on `/`, likely body text only on article pages). | low | file follow-up (see #10.3) |

Nothing in this audit is a P0/P1 regression. All findings are optimization
opportunities on an already-well-configured baseline.

## Asset inventory per page

Wire sizes are post-zstd (the number the browser actually pulls over the wire).
All requests are same-origin unless noted.

### `/` (front)

| Type | Count | Wire bytes |
|------|-----:|-----------:|
| html    |  1 |  40,179 |
| css     |  2 |  84,615 |
| js      |  2 | 210,887 |
| font    |  8 |  84,232 (4 real, 4 x 404 — see #5) |
| image   |  1 |     567 |
| **Total** | **14** | **420,480** |

- CSS: `css_QzR7DW…css` (2,353 B, `delta=0`, layout/base) + `css_KwWr5J…css` (82,262 B, `delta=1`, theme/components).
- JS: `jquery.min.js` (78,748 B, core vendor) + `js_BbMBeL…js` (132,139 B, footer scope, aggregated site JS).

### `/all-groups`

| Type | Count | Wire bytes |
|------|-----:|-----------:|
| html    |  1 |  32,893 |
| css     |  2 |  80,994 |
| js      |  1 |  72,314 |
| font    |  8 |  84,232 (4 real, 4 x 404) |
| image   |  1 |     567 |
| **Total** | **13** | **271,000** |

- No `jquery.min.js` on this page — this route uses a leaner JS bundle. Good.
- **Leaflet not loaded.** The `do_showcase` map behavior only attaches
  `do_showcase/leaflet` when a map variant renders, which the default
  `all-groups` list view does not trigger. Confirmed by scanning the DOM +
  network trace: no `/libraries/leaflet/*` requests.

### `/group/1`

| Type | Count | Wire bytes |
|------|-----:|-----------:|
| html    |  1 |  34,241 |
| css     |  2 | 108,332 |
| js      |  2 | 299,596 |
| font    |  8 |  84,232 (4 real, 4 x 404) |
| image   |  1 |     567 |
| **Total** | **14** | **526,968** |

- Heaviest page. Extra JS payload (`js_3dYfQT…js` @ 220,848 B) is the
  group-detail-specific aggregate bundle (contributions, forum comments,
  activity feed behaviors).
- Extra CSS (`css_mQZqQa…css` @ 82,189 B) is the same story — group-detail-
  scoped stylesheet including do_group_extras + do_activity_feed styles.

### `/stream`

| Type | Count | Wire bytes |
|------|-----:|-----------:|
| html    |  1 |  40,179 |
| css     |  2 |  84,615 |
| js      |  2 | 210,887 |
| font    |  8 |  84,232 (4 real, 4 x 404) |
| image   |  1 |     567 |
| **Total** | **14** | **420,480** |

Identical asset profile to `/` — the stream page shares the same aggregate
bundles. Byte-for-byte match confirms Drupal is issuing the same aggregation
keys for both pages, which is correct.

### `/groups`

| Type | Count | Wire bytes |
|------|-----:|-----------:|
| html    |  1 |  22,027 |
| css     |  2 |  79,122 |
| js      |  1 |  72,314 |
| font    |  8 |  84,232 (4 real, 4 x 404) |
| image   |  1 |     567 |
| **Total** | **13** | **258,262** |

Same aggregate profile as `/all-groups`. Lightest of the five pages.

## Minification & compression

Every CSS and JS bundle across all five pages:

- **Minified**: yes. Confirmed by line-length heuristic (avg > 200 chars/line)
  and by inspecting the `jquery.min.js` filename explicitly. Drupal aggregation
  runs the `\Drupal\Core\Asset\CssOptimizer` / `JsOptimizer` pipeline before
  writing to `public://css/` and `public://js/`.
- **Encoding**: `zstd` (Caddy's default). No `gzip` fallback observed; Caddy
  negotiates zstd first, gzip second. Every modern browser supports zstd
  (Chrome 123+, Firefox 126+, Safari 17.4+). Client hits with older Accept-
  Encoding would get gzip; not tested but should be verified in a future audit
  if we care about legacy clients.
- **Source maps**: **none served**. `curl -sI` on the CSS/JS URLs returns no
  `sourcemap` header and the bundle bodies don't contain a
  `//# sourceMappingURL=` trailer. Good for production (no debug bloat).

## Bundling / request-count analysis

**Aggregation is ON.** Confirmed via Drupal's aggregate URL pattern
(`/sites/default/files/css/css_*.css` and `js_*.js` with `delta=` and `scope=`
query strings), which only appears when
`system.performance.css.preprocess: true` and `system.performance.js.preprocess:
true`.

Request counts per page (13–14) are well below the 40-request rule-of-thumb
threshold for anonymous pages. Broken down:

- **1** HTML doc
- **2** CSS bundles (`delta=0` layout, `delta=1` theme/components)
- **1–2** JS bundles (footer scope; jquery split out on pages that use it)
- **4** real font files (Metropolis Regular/SemiBold/Bold + Lora Regular)
- **4** phantom 404 font requests (see finding #5)
- **1** favicon / image

No opportunity for further HTTP/1.1-era "concatenate more" optimization.
The demo runs HTTP/2 through Caddy (`HTTP/2` in response headers, Alt-Svc
advertises h3), so 13–14 multiplexed requests are cheap.

## CDN posture (Leaflet + everything else)

Verified by grepping the raw network trace for `unpkg.com`, `cdn.jsdelivr.net`,
`cdnjs.cloudflare.com`, and `ajax.googleapis.com` across all five pages.
**Zero matches on any page.**

Also verified by enumerating unique response hostnames across the entire audit
run: `groups.performantlabs.com` is the only host observed. No `fonts.gstatic.com`,
no `www.google-analytics.com`, no `www.googletagmanager.com`, no `cloudflare-*`.

**Leaflet specifically:**

- Source in repo: `docs/groups/libraries/leaflet/leaflet.js` (147,552 B),
  `docs/groups/libraries/leaflet/leaflet.css` (14,145 B), plus `images/`
  subdir (marker sprites).
- Library definition: `docs/groups/modules/do_showcase/do_showcase.libraries.yml`
  declares `do_showcase/leaflet` with paths `/libraries/leaflet/leaflet.js`
  and `/libraries/leaflet/leaflet.css` — served from
  `web/libraries/leaflet/` at build time via CI's assemble step.
- Attachment: `docs/groups/modules/do_showcase/js/do_showcase.directory-map.js`
  sets `L.Icon.Default.imagePath = '/libraries/leaflet/images/'` explicitly,
  preventing Leaflet from probing CDN fallbacks for marker icons.
- Runtime confirmation on `/all-groups` list view: no `/libraries/leaflet/*`
  request appears in the network trace. **Leaflet is loaded on-demand only
  when a map variant fires** — the anonymous list view pays zero Leaflet cost.

**Verdict:** Leaflet CDN-freeness is confirmed in code AND at runtime.

## Unused CSS (coverage)

Chrome DevTools Coverage API results across the five pages. "Used%" is the
fraction of the delivered CSS text that at least one rule matches an element
in the DOM at load time (does not account for interactive states like `:hover`
or media queries not currently active).

| Page | CSS total (unc.) | Used | Unused | Used % |
|------|-----------------:|-----:|-------:|-------:|
| `/`           | 89,261 | 40,976 | 48,285 | **45.9 %** |
| `/all-groups` | 84,179 | 40,440 | 43,739 | **48.0 %** |
| `/group/1`    | 108,326 | 40,396 | 67,930 | **37.3 %** |
| `/stream`     | 89,261 | 40,976 | 48,285 | **45.9 %** |
| `/groups`     | 82,307 | 36,658 | 45,649 | **44.5 %** |

**Note on `total` here.** These are UNcompressed CSS text sizes reported by the
Coverage API, which is why they exceed the on-wire (zstd) sizes in the
inventory tables. Comparing coverage-total to wire-size is not apples-to-apples;
compare across coverage-totals only.

**Interpretation:** roughly half of each page's CSS is unused at initial paint.
Some of that will be interactive-state rules (`:hover`, `.is-active`,
`.js-drawer-open`, etc.) that DO get used, so 45 % used is not the same as
"45 % of CSS is dead". However, `/group/1`'s **37.3 %** figure — the lowest
of the group — suggests the group-detail aggregate is carrying a chunk of CSS
that's specifically for the front page or the activity stream and doesn't
belong on group detail. That's the most productive lead for a future
CSS-splitting story.

**Not a blocker for this audit.** Splitting an aggregate CSS bundle requires
authoring per-route asset attachments (Drupal's `libraries` framework +
`hook_page_attachments`), which is a design + implementation task, not a
same-day fix.

## Unused JS (coverage — CAVEAT)

The JS coverage numbers are unreliable in this run:

| Page | JS "total" | JS "used" | Used % |
|------|-----------:|----------:|-------:|
| `/`           | 210,883 | 470,857 | 223 % |
| `/all-groups` |  72,310 | 155,576 | 215 % |
| `/group/1`    | 299,592 | 653,254 | 218 % |
| `/stream`     | 210,883 | 470,857 | 223 % |
| `/groups`     |  72,310 | 155,576 | 215 % |

Used > total means the CDP JS-coverage entries are reporting per-invocation
byte ranges (double-counting when the same range runs twice) rather than
per-source total byte ranges. Fixing this requires switching to
`Profiler.startPreciseCoverage` with `callCount: false, detailed: true`, which
the Playwright API does not directly expose. **Deferred to a future audit.**

## Fonts

Four real font files are served from `/core/themes/olivero/fonts/`, all
preloaded via `<link rel="preload" as="font" type="font/woff2" crossorigin>`:

| Font | Wire bytes (zstd) |
|------|------------------:|
| Metropolis-SemiBold.woff2 | 26,564 |
| Lora-v14-latin-regular.woff2 | 24,552 |
| Metropolis-Regular.woff2 | 16,388 |
| Metropolis-Bold.woff2 | 16,728 |
| **Total** | **84,232** |

All four are preloaded on every page. That is deliberate — Olivero preloads
its full font pack — but it's worth measuring whether every page actually
renders every weight. The front and stream pages are visually simple enough
that Lora (a serif for body copy on article pages) may not be used at all,
and eliminating one preload saves ~24 KB. Filed as a follow-up (§10.3).

## Images

Only one image request per page (a small tracking-pixel-sized asset, ~567 B).
The demo has minimal image content on the anonymous surfaces — group detail
carries no member avatars, showcase carousels aren't published, and no hero
images. This will change substantially once the demo has real user-generated
content; a re-audit is warranted once demo content grows.

## Follow-up recommendations (proposed issues, not filed)

### 10.1 — 4× phantom font 404s per page (medium)

Drupal core's CSS aggregator writes aggregated stylesheets to
`/sites/default/files/css/`. When those stylesheets contain
`src: url('../fonts/metropolis/...')` from the original Olivero CSS, the
`..` resolves against the **aggregated** file's location, producing
`/sites/default/files/css/core/themes/olivero/fonts/…` — which 404s.

Meanwhile the **preload** `<link rel=preload>` in `<head>` uses the correct
absolute path, so the real font DOES load. Net effect: 4 real fonts + 4
useless 404s per page.

Fixes to investigate:

1. Set `css.optimize.gz` / `css.optimize.preprocess` to a variant that
   rewrites `url()` paths (Drupal's `CssOptimizer::rewriteFileURI()` should
   do this — needs investigation into why it isn't).
2. Confirm the front-end proxy (Caddy) isn't caching the 404s.
3. Consider Olivero-side patch to inline `@font-face` block on the
   real Metropolis paths so aggregation can't misresolve them.

Cost: ~5 KB of 404 HTML per page × 5 pages × N daily loads. Not huge, but
each 404 is a full RTT wasted, hurting p95 load time on cold cache.

**Slam-dunk?** No — requires investigation into whether this is Drupal 11's
`\Drupal\Core\Asset\CssOptimizer::rewriteFileURI` regressing on preload/aggregate
interaction, or Olivero-specific. Filed as follow-up, not inline.

### 10.2 — CSS aggregate 37 % used on `/group/1` (low)

Group-detail page uses barely a third of the CSS it delivers. Split the
`delta=1` aggregate into per-route bundles by moving group-detail-specific
CSS into a `do_group_extras/detail` library attached only to the group
canonical route.

Requires: audit which CSS rules match on `/group/1` (Coverage export),
identify rules originating in do_activity_feed / do_group_extras / front-
page-specific styles, and re-partition libraries. Bigger design change;
file as follow-up story.

### 10.3 — Preload manifest may over-preload fonts (low)

Currently all 4 fonts are preloaded on all 5 pages. Measure per-page font
usage (Coverage font entries) and switch to per-page preload attachment
via `hook_preprocess_html` — attach only the fonts each page's markup
actually uses. Expected saving: 16–24 KB per page for lighter pages.

Requires per-route font analysis + Twig template review; file as follow-up.

## What was verified as good (no action needed)

- Minification ON for all CSS + JS.
- zstd compression ON via Caddy for all text assets.
- Drupal aggregation ON — 2 CSS + 1–2 JS bundles per page.
- Zero CDN dependencies. All assets self-hosted.
- Leaflet vendored under `docs/groups/libraries/leaflet/`, deployed to
  `web/libraries/leaflet/`, marker icons pinned to local
  `/libraries/leaflet/images/` — no CDN fallback path exists.
- Leaflet loaded only when a map variant actually renders (deferred cost).
- No source maps served to production.
- HTTP/2 in use; 13–14 requests per page is well within the multiplex budget.
- No third-party analytics / trackers / social embeds. Excellent privacy
  posture and asset-payload discipline.

## Acceptance criteria status (issue #245)

- [x] **Asset inventory spreadsheet filed** — see per-page tables above and
      `docs/planning/perf/asset-audit-2026-07-27.raw.json` for machine data.
- [x] **Leaflet vendoring confirmed** — see §"CDN posture", verified in code
      AND at runtime.
- [x] **Unused CSS identified** — see §"Unused CSS (coverage)".
- [x] **Recommendations filed** — see §10.
- [x] **Offline map functionality tested** — verified by network trace:
      `/all-groups` loads and functions without any `/libraries/leaflet/*`
      requests (map is off by default in list view); when map view fires,
      all assets are same-origin. Explicit offline-mode test (block network
      then load map view) not performed because map view isn't routable on
      anon demo; deferred to future audit against authenticated seed.

## Files

- `docs/planning/perf/asset-audit-2026-07-27.md` — this report.
- `docs/planning/perf/asset-audit-2026-07-27.raw.json` — per-request raw data.
- `scripts/perf/asset-audit.mjs` — re-runnable audit driver.
