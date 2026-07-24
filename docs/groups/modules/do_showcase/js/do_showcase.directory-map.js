/**
 * @file
 * #125 SC-6: Leaflet map presentation for /all-groups' "Map" variant.
 *
 * Attached (unconditionally, alongside `do_showcase/switcher` and
 * `do_showcase/directory-compact`) by `DoShowcaseHooks::viewsPreRender()`
 * whenever the `all_groups.page_1` view renders — same precedent as
 * `directory-compact.css`: this behavior no-ops harmlessly whenever the
 * wrapper's `data-do-directory-variant` isn't `"map"`, so Cards/Compact mode
 * pays zero cost for this library being attached.
 *
 * No tile layer (brief.md "Design note — tiles": zero external network
 * requests for map assets is a hard AC; even OpenStreetMap tiles are
 * external). The map renders with Leaflet's stock default marker sprites
 * plotted via Leaflet's own Mercator projection over the plain grey
 * `.leaflet-container` background `directory-map.css` supplies — no
 * `L.tileLayer(...)` call anywhere in this file.
 *
 * Data source (survey.md's resolved seam): each `.views-row` wrapper inside
 * `.view-content` carries `data-do-location-lat` / `data-do-location-lng` /
 * `data-do-location-url` / `data-do-location-name` attributes, written by
 * `DoShowcaseHooks::preprocessViewsViewUnformatted()` (a
 * `preprocess_views_view_unformatted` hook, per handoff-A-plan.md's "simpler
 * alternative": one hook, one loop, one place). Rows without a location
 * simply carry none of these attributes and are silently skipped — no dead
 * marker, no placeholder pin at (0,0) (wireframe.md Surface 1).
 *
 * `.view-content` is hidden via `directory-map.css` in map mode, but the ROWS
 * THEMSELVES stay in the DOM (display:none only, never removed) so this
 * behavior can still read their data attributes — hiding via CSS, not
 * removing via JS, is what keeps that data-read possible (wireframe.md
 * Surface 3).
 *
 * Two renderings share the ONE data source read here so they cannot drift
 * out of sync with each other (wireframe.md Surface 2):
 *  - a Leaflet marker per row with resolvable coordinates, direct-click-
 *    navigates to that group's canonical page (brief AC-2 — no popup step,
 *    a deliberate deviation from Leaflet's popup-on-click default), and
 *    carries a native `title` attribute for a zero-cost hover preview
 *    (wireframe.md Open Question 1, D-gate APPROVED);
 *  - the SR-only keyboard fallback `<ul class="do-showcase-map-fallback-
 *    list">` (wireframe.md Surface 2) — the keyboard path for a population
 *    Leaflet's own marker DOM does not natively support tabbing to.
 *
 * Live client-side toggling (wireframe.md / directory-toggle.spec.ts's
 * existing "no reload" contract, extended to the Map variant): the
 * switcher's own JS (`do_showcase.switcher.js`) flips
 * `data-do-directory-variant` on the SAME wrapper element via a plain
 * attribute mutation — it does NOT insert/remove any DOM, so Drupal's
 * `once()`-gated `Drupal.behaviors.attach()` never re-fires for an
 * already-attached wrapper. A `MutationObserver` on that one attribute is
 * therefore the mechanism that (re)builds/shows the map + fallback list the
 * moment the value becomes `"map"`, independent of any full behavior
 * re-attach — deliberately NOT a drive-by edit to the shared
 * `do_showcase.switcher.js` (that file stays data-driven/agnostic to what
 * its selection means, per its own docblock); this file owns its own
 * re-check entirely.
 *
 * `L.Icon.Default.imagePath` is set explicitly to `/libraries/leaflet/images/`
 * before any marker is created (survey.md Assumption #2) — Leaflet's default
 * auto-detection guesses the image path from the currently-executing
 * script's own URL, which is wrong once this file is aggregated/minified by
 * Drupal's asset pipeline.
 */
((Drupal, once) => {
  'use strict';

  /**
   * The wrapper selector this behavior watches (Surface 3 "Contract" — the
   * SAME `.views-element-container[data-do-directory-variant]` element
   * `directory-compact.css`'s scoped selectors and the switcher's own
   * wrapper-mirror wiring already key off).
   */
  const WRAPPER_SELECTOR = '.views-element-container[data-do-directory-variant]';

  /** The attribute this behavior watches for the value `"map"`. */
  const VARIANT_ATTRIBUTE = 'data-do-directory-variant';

  /**
   * Reads every row's location data attributes into a plain plotting list.
   *
   * Rows without `data-do-location-lat`/`-lng` are silently skipped — no
   * dead marker, no placeholder pin (wireframe.md Surface 1 "truthful
   * partial-coverage").
   *
   * @param {HTMLElement} wrapper
   *   The `.views-element-container` wrapper.
   *
   * @return {Array<{lat: number, lng: number, url: string, name: string}>}
   *   One entry per row with resolvable coordinates, in row (plot) order.
   */
  function collectLocations(wrapper) {
    const rows = Array.from(wrapper.querySelectorAll('.view-content .views-row'));
    const locations = [];
    rows.forEach((row) => {
      const latRaw = row.getAttribute('data-do-location-lat');
      const lngRaw = row.getAttribute('data-do-location-lng');
      if (latRaw === null || lngRaw === null) {
        return;
      }
      const lat = parseFloat(latRaw);
      const lng = parseFloat(lngRaw);
      if (Number.isNaN(lat) || Number.isNaN(lng)) {
        return;
      }
      const url = row.getAttribute('data-do-location-url') || '';
      const name = row.getAttribute('data-do-location-name') || '';
      if (!url) {
        return;
      }
      locations.push({ lat, lng, url, name });
    });
    return locations;
  }

  /**
   * Counts the total number of rows currently rendered (whether or not they
   * carry a resolvable location) — the "M" in the wireframe's "Showing N of
   * M groups with a location" truthful partial-coverage caption.
   *
   * @param {HTMLElement} wrapper
   *   The `.views-element-container` wrapper.
   *
   * @return {number}
   *   The total row count.
   */
  function countTotalRows(wrapper) {
    return wrapper.querySelectorAll('.view-content .views-row').length;
  }

  /**
   * Builds (or returns the already-built) `.do-showcase-map` container,
   * inserted immediately before `.view-content` — created once per wrapper,
   * never re-created on a subsequent map-mode re-entry (wireframe.md
   * Surface 1: map region REPLACES, does not duplicate, the row grid).
   *
   * @param {HTMLElement} wrapper
   *   The `.views-element-container` wrapper.
   *
   * @return {HTMLElement}
   *   The `.do-showcase-map` container element.
   */
  function ensureMapContainer(wrapper) {
    let container = wrapper.querySelector('.do-showcase-map');
    if (container) {
      return container;
    }
    const viewContent = wrapper.querySelector('.view-content');

    container = document.createElement('div');
    container.className = 'do-showcase-map';
    // wireframe.md Surface 2 D-gate resolution: `role="application"` was
    // DROPPED (would intercept a screen reader's own virtual-cursor arrow-key
    // navigation for a widget that does not warrant it) in favor of a plain
    // `<div>` + `aria-label` — Leaflet's own container markup ships no
    // conflicting `role`/`aria-*` by default, confirmed against the vendored
    // 1.9.4 output (a bare unstyled `<div>`), so no role is set here at all.
    container.setAttribute('aria-label', Drupal.t('Map of groups with a location.'));

    if (viewContent && viewContent.parentNode) {
      viewContent.parentNode.insertBefore(container, viewContent);
    }
    else {
      wrapper.appendChild(container);
    }
    return container;
  }

  /**
   * Builds (or returns the already-built) caption `<p>`, inserted
   * immediately before the map container (wireframe.md Surface 1: "Caption
   * line ... sits between the filter row and the map container").
   *
   * @param {HTMLElement} wrapper
   *   The `.views-element-container` wrapper.
   * @param {HTMLElement} mapContainer
   *   The `.do-showcase-map` container (caption is inserted before this).
   *
   * @return {HTMLElement}
   *   The caption `<p>` element.
   */
  function ensureCaption(wrapper, mapContainer) {
    let caption = wrapper.querySelector('.do-showcase-map-caption');
    if (caption) {
      return caption;
    }
    caption = document.createElement('p');
    caption.className = 'do-showcase-map-caption';
    mapContainer.parentNode.insertBefore(caption, mapContainer);
    return caption;
  }

  /**
   * Sets the caption's truthful count text (wireframe.md Surface 1).
   *
   * "Showing N of M groups with a location" when N < M; drops the "of M"
   * clause when N === M (no redundant "of 4" when there's no gap to
   * disclose) — the exact two forms the wireframe and
   * `directory-map.spec.ts` both pin.
   *
   * @param {HTMLElement} caption
   *   The caption element.
   * @param {number} plotted
   *   The number of groups actually plotted (N).
   * @param {number} total
   *   The total number of groups currently rendered/filtered (M).
   */
  function setCaptionText(caption, plotted, total) {
    if (plotted === total) {
      caption.textContent = Drupal.formatPlural(
        plotted,
        'Showing 1 group with a location.',
        'Showing @count groups with a location.',
      );
      return;
    }
    caption.textContent = Drupal.t('Showing @plotted of @total groups with a location.', {
      '@plotted': plotted,
      '@total': total,
    });
  }

  /**
   * Builds (or returns the already-built) SR-only fallback `<ul>`, inserted
   * immediately after the map container (wireframe.md Surface 2: "Rendered
   * immediately after the map container").
   *
   * @param {HTMLElement} mapContainer
   *   The `.do-showcase-map` container (fallback list is inserted after
   *   this).
   *
   * @return {HTMLElement}
   *   The `<ul class="do-showcase-map-fallback-list">` element.
   */
  function ensureFallbackList(mapContainer) {
    let list = mapContainer.parentNode.querySelector(':scope > .do-showcase-map-fallback-list');
    if (list) {
      return list;
    }
    list = document.createElement('ul');
    list.className = 'do-showcase-map-fallback-list visually-hidden';
    if (mapContainer.nextSibling) {
      mapContainer.parentNode.insertBefore(list, mapContainer.nextSibling);
    }
    else {
      mapContainer.parentNode.appendChild(list);
    }
    return list;
  }

  /**
   * (Re)populates the SR-only fallback list to match the currently-plotted
   * locations, in the same plot order (wireframe.md Surface 2: "Order
   * matches marker plot order").
   *
   * @param {HTMLElement} list
   *   The `<ul class="do-showcase-map-fallback-list">` element.
   * @param {Array<{lat: number, lng: number, url: string, name: string}>} locations
   *   The plotted-location list, in plot order.
   */
  function populateFallbackList(list, locations) {
    list.textContent = '';
    locations.forEach((location) => {
      const li = document.createElement('li');
      const anchor = document.createElement('a');
      anchor.href = location.url;
      // "Group Name — City" (wireframe.md Surface 2) — location.name already
      // carries the full "Name — City" text (built server-side in
      // DoShowcaseHooks, which concatenates the group label and
      // field_group_location_text), so no further string assembly happens
      // here; this keeps the exact copy in one place.
      anchor.textContent = location.name;
      li.appendChild(anchor);
      list.appendChild(li);
    });
  }

  /**
   * Plots one marker per resolvable location, direct-click-navigates
   * (brief AC-2, wireframe.md Surface 1 "Marker behavior" — no popup step),
   * and fits the map's view to the plotted markers.
   *
   * @param {L.Map} map
   *   The Leaflet map instance.
   * @param {Array<{lat: number, lng: number, url: string, name: string}>} locations
   *   The plotted-location list.
   */
  function plotMarkers(map, locations) {
    map.eachLayer((layer) => {
      if (layer instanceof L.Marker) {
        map.removeLayer(layer);
      }
    });

    const markers = [];
    locations.forEach((location) => {
      const marker = L.marker([location.lat, location.lng], {
        // Native `title` attribute (browser-native hover tooltip, zero extra
        // library/DOM cost) — wireframe.md Open Question 1, D-gate
        // APPROVED: "F adds a native title attribute on each marker".
        title: location.name,
      });
      marker.on('click', () => {
        window.location.assign(location.url);
      });
      marker.addTo(map);
      markers.push(marker);
    });

    if (markers.length === 0) {
      // No groups to fit — a sensible default center/zoom rather than a
      // Leaflet exception from fitBounds() on an empty set (wireframe.md
      // "Map selected — zero groups have a location", D-gate DEFERRED to a
      // caption-only simplification; the map itself still needs *some*
      // valid view).
      map.setView([20, 0], 2);
      return;
    }

    if (markers.length === 1) {
      map.setView(markers[0].getLatLng(), 6);
      return;
    }

    const group = L.featureGroup(markers);
    map.fitBounds(group.getBounds(), { padding: [24, 24] });
  }

  /**
   * Initializes (once per wrapper) or re-renders the map for the current
   * set of rows — called both on first attach (page already in map mode)
   * and on every subsequent live client-side toggle INTO map mode (the
   * MutationObserver callback below).
   *
   * @param {HTMLElement} wrapper
   *   The `.views-element-container` wrapper, already resolved to map mode.
   */
  function renderMap(wrapper) {
    const mapContainer = ensureMapContainer(wrapper);
    const caption = ensureCaption(wrapper, mapContainer);
    const fallbackList = ensureFallbackList(mapContainer);

    const locations = collectLocations(wrapper);
    const total = countTotalRows(wrapper);
    setCaptionText(caption, locations.length, total);
    populateFallbackList(fallbackList, locations);

    let map = mapContainer.doShowcaseLeafletMap;
    if (!map) {
      L.Icon.Default.imagePath = '/libraries/leaflet/images/';
      map = new L.Map(mapContainer, {
        zoomControl: true,
        // No tile provider to attribute to (brief's "no tile layer" design
        // note) — attribution control would otherwise render an empty/
        // misleading credit strip.
        attributionControl: false,
        // Accessibility: no accidental zoom-trap on page-scroll for a
        // keyboard/trackpad user scrolling past the map region.
        scrollWheelZoom: false,
      });
      mapContainer.doShowcaseLeafletMap = map;
    }
    else {
      // The container may just have been un-hidden (display:none -> block)
      // by a live client-side toggle back into map mode — Leaflet computes
      // its internal size at construction time, so a container that was
      // zero-size while hidden needs an explicit recompute before
      // fitBounds()/setView() below produce a sane viewport.
      map.invalidateSize();
    }

    plotMarkers(map, locations);
  }

  /**
   * Whether the given wrapper is CURRENTLY resolved to the map variant.
   *
   * @param {HTMLElement} wrapper
   *   The `.views-element-container` wrapper.
   *
   * @return {boolean}
   *   TRUE if `data-do-directory-variant="map"`.
   */
  function isMapVariant(wrapper) {
    return wrapper.getAttribute(VARIANT_ATTRIBUTE) === 'map';
  }

  Drupal.behaviors.doShowcaseDirectoryMap = {
    attach(context) {
      once('do-showcase-directory-map', WRAPPER_SELECTOR, context).forEach((wrapper) => {
        if (isMapVariant(wrapper)) {
          renderMap(wrapper);
        }

        // Live client-side toggling (see file docblock): the switcher's own
        // JS flips this ONE attribute via a plain mutation, which does not
        // re-trigger Drupal.behaviors.attach() for an already-`once()`'d
        // element — this observer is what reacts to that mutation
        // independently, deliberately without touching
        // do_showcase.switcher.js (which stays agnostic to what its
        // selection means to any particular caller).
        const observer = new MutationObserver((mutations) => {
          mutations.forEach((mutation) => {
            if (mutation.attributeName === VARIANT_ATTRIBUTE && isMapVariant(wrapper)) {
              renderMap(wrapper);
            }
          });
        });
        observer.observe(wrapper, { attributes: true, attributeFilter: [VARIANT_ATTRIBUTE] });
      });
    },
  };
})(Drupal, once);
