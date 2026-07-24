/**
 * @file
 * Variant-switcher interaction: click-to-select + client-side persistence.
 *
 * do_chrome/tooltips (already attached alongside this library wherever the
 * switcher renders) handles the ⓘ trigger — this behavior owns ONLY the
 * radiogroup's click/keyboard selection and persistence, never re-invents a
 * tooltip mechanism.
 *
 * Persistence is CLIENT-SIDE (sessionStorage (per-session), keyed per
 * switcher instance id) — Brief-gate B-2: a server session write
 * (tempstore.private) would start a session and bust the anonymous page
 * cache. sessionStorage (not localStorage) matches the brief/issue's
 * "remembers choice per session" contract exactly — it clears when the
 * browser tab/session ends, unlike localStorage which persists across
 * restarts. On load, this behavior reads any stored choice for the instance
 * and re-selects it (unless the page was loaded with an explicit query-string
 * value for THIS instance's own query key, which always wins, so the no-JS
 * fallback and a direct/shared link behave predictably).
 *
 * #124 SC-5 (O decision #1 / A-advisory #2): a generic, data-driven
 * "wrapper-mirror" addition — on selection, if the radiogroup wrapper
 * carries `data-do-showcase-mirror-attribute` +
 * `data-do-showcase-mirror-selector`, the newly-selected option's
 * `data-do-showcase-id` is mirrored onto the named attribute of the
 * document's FIRST element matching the named selector. This is deliberately
 * NOT a directory-specific branch (no `if (instanceId === 'directory.layout')`
 * anywhere in this file) — this codebase has zero existing CustomEvent/
 * dispatchEvent precedent (grepped, none found), so rather than inventing
 * one, any current/future switcher instance (SC-4/SC-6/ST-8) that needs its
 * selection mirrored onto some OTHER element's attribute gets it for free by
 * setting these two data-* attributes on ITS OWN wrapper at render time
 * (`DoShowcaseHooks::viewsPreRender()` does this for the `directory.layout`
 * instance on /all-groups, mirroring onto `.view-id-all_groups`'s
 * `data-do-directory-variant` attribute) — the switcher itself stays
 * single-responsibility (selection + persistence), agnostic to what its
 * selection MEANS to any particular caller, matching how
 * `VariantSwitcher::build()` is itself data-driven (caller supplies
 * instance_id/options; the widget doesn't know or care what they represent).
 *
 * #123 SC-4 (handoff-A-plan.md Spot-check finding #2): this behavior no
 * longer hard-codes the `variant` query-string parameter name when deciding
 * whether the URL should win over a persisted sessionStorage choice.
 * `VariantSwitcher::build()` now accepts a caller-supplied `$query_key`
 * (default `'variant'`), so a SECOND simultaneous instance on the same page
 * (e.g. `/showcase`'s `discovery.ranking` instance, keyed on `?discovery=`,
 * alongside the existing `directory.layout` stub keyed on `?variant=`) would
 * otherwise have its server-rendered `?discovery=hot` selection silently
 * overridden by a stale sessionStorage restore, because the URL-wins check
 * only ever looked for `variant` in the query string. `queryKeyForGroup()`
 * below reads the query key generically off one of THIS radiogroup's own
 * option anchors (`href="?<key>=<id>"`, written by `build()`'s 4th
 * parameter) rather than assuming any fixed name — every switcher instance,
 * present or future, gets correct URL-wins-over-storage behavior for free.
 */
((Drupal, once) => {
  'use strict';

  const STORAGE_PREFIX = 'doShowcase.variant.';

  /**
   * Marks a single radio option as selected/unselected in the DOM.
   *
   * @param {HTMLElement} option
   *   The `[role="radio"]` element.
   * @param {boolean} selected
   *   Whether this option should be marked selected.
   */
  function setSelected(option, selected) {
    option.setAttribute('aria-checked', selected ? 'true' : 'false');
    const label = option.querySelector('[data-do-showcase-label]');
    const plain = option.getAttribute('data-do-showcase-plain-label') || '';
    if (label) {
      label.textContent = selected ? '● ' + plain : plain;
    }
  }

  /**
   * Sets the roving tabindex: exactly one option (the given one) is
   * tabindex="0", every other option is tabindex="-1" (wireframe.md lines
   * 29-31, 271 — "one option in tab order at a time").
   *
   * @param {HTMLElement[]} options
   *   All `[role="radio"]` elements in the radiogroup (available and not).
   * @param {HTMLElement} target
   *   The option that should become the roving tabindex=0 target.
   */
  function setRovingTabindex(options, target) {
    options.forEach((option) => {
      option.setAttribute('tabindex', option === target ? '0' : '-1');
    });
  }

  /**
   * Whether this radiogroup opts into client-side content mirroring.
   *
   * #123 SC-4 (F-U-1 fix): the switcher supports two distinct update models,
   * selected per instance by whether the wrapper carries the mirror-attribute
   * pair (`data-do-showcase-mirror-attribute` + `data-do-showcase-mirror-
   * selector`, written at render time by the instance's caller):
   *
   *  - **Mirror-driven (client-side swap)** — the caller (e.g. `directory.
   *    layout` via `DoShowcaseHooks::preprocessViewsView()`) has wired the
   *    switcher's selection onto some OTHER element's attribute, and a CSS
   *    (or later JS) rule keys off that attribute to reveal the correct
   *    content variant WITHOUT reloading. In this model the click/Enter
   *    handlers MUST `preventDefault()` on the anchor (its `href` is only a
   *    no-JS fallback), then call `select()` which flips the mirrored
   *    attribute in-place. This is the pre-existing #124 SC-5 contract that
   *    `directory-toggle.spec.ts` pins ("no navigation occurred - same URL,
   *    same query params" — filters/pager must survive a toggle).
   *
   *  - **Navigation-driven (no-JS fallback IS the update path)** — the
   *    caller (e.g. `discovery.ranking` via `ShowcaseController::page()`)
   *    has NOT wired any mirror attributes because there is no client-side
   *    mechanism to swap the embedded view region; the server-rendered
   *    output for `?discovery=<id>` IS the correct content. In this model
   *    the handlers MUST let the anchor's `href` navigate naturally
   *    (no `preventDefault()`), producing a real navigation and re-render
   *    — matching the "no-JS fallback stays authoritative" contract this
   *    file's docblock already names.
   *
   * The two models are mutually exclusive per instance, distinguished
   * entirely by the presence/absence of the mirror-attribute pair on the
   * wrapper. No `instanceId === 'discovery.ranking'` branch is needed (and
   * none is added) — every current/future switcher instance picks its model
   * by wiring, matching how `mirrorSelectionToWrapperAttribute()` itself is
   * already data-driven.
   *
   * @param {HTMLElement} group
   *   The `[role="radiogroup"]` wrapper element.
   *
   * @return {boolean}
   *   TRUE if this instance uses the mirror-driven (client-swap) model,
   *   FALSE if it uses the navigation-driven (no-JS fallback) model.
   */
  function usesMirrorModel(group) {
    return group.hasAttribute('data-do-showcase-mirror-attribute')
      && group.hasAttribute('data-do-showcase-mirror-selector');
  }

  /**
   * Mirrors the selected option id onto a caller-named element's attribute.
   *
   * #124 SC-5: a generic, data-driven callback — reads
   * `data-do-showcase-mirror-attribute` + `data-do-showcase-mirror-selector`
   * off the radiogroup wrapper itself; no-ops (does nothing, throws nothing)
   * when either is absent, so every switcher instance that does NOT need
   * this behavior (the /showcase stub's OWN wrapper, ST-8, etc.) is
   * completely unaffected.
   *
   * @param {HTMLElement} group
   *   The `[role="radiogroup"]` wrapper element.
   * @param {string} id
   *   The newly-selected option's `data-do-showcase-id` value.
   */
  function mirrorSelectionToWrapperAttribute(group, id) {
    const targetAttribute = group.getAttribute('data-do-showcase-mirror-attribute');
    const targetSelector = group.getAttribute('data-do-showcase-mirror-selector');
    if (!targetAttribute || !targetSelector) {
      return;
    }
    const target = document.querySelector(targetSelector);
    if (!target) {
      return;
    }
    target.setAttribute(targetAttribute, id);
  }

  /**
   * Derives the query-string parameter name THIS radiogroup's own options
   * read/write, by reading it off one of the group's own option anchors.
   *
   * #123 SC-4 (handoff-A-plan.md Spot-check finding #2): `VariantSwitcher::
   * build()`'s 4th `$query_key` parameter means each switcher instance may
   * use a DIFFERENT query-string name (`directory.layout` -> `variant`,
   * `discovery.ranking` -> `discovery`). Every option's own no-JS fallback
   * `href` already carries `?<query_key>=<id>` (the template renders
   * `option.href` verbatim), so the group's query key can always be read
   * back off any one option's `href` rather than assumed to be a fixed
   * literal — this generalizes correctly for every current/future switcher
   * instance without each one needing its own extra data-* attribute.
   *
   * @param {HTMLElement[]} options
   *   All `[role="radio"]` elements in the radiogroup (available and not).
   *
   * @return {string|null}
   *   The query-string parameter name this group's options use, or NULL if
   *   it could not be determined (e.g. no options, or an href with no query
   *   string — should not happen given build()'s contract, but handled
   *   defensively rather than throwing).
   */
  function queryKeyForGroup(options) {
    for (let i = 0; i < options.length; i++) {
      const href = options[i].getAttribute('href') || '';
      const queryIndex = href.indexOf('?');
      if (queryIndex === -1) {
        continue;
      }
      const params = new URLSearchParams(href.slice(queryIndex));
      const keys = Array.from(params.keys());
      if (keys.length > 0) {
        return keys[0];
      }
    }
    return null;
  }

  Drupal.behaviors.doShowcaseSwitcher = {
    attach(context) {
      once('do-showcase-switcher', '[role="radiogroup"][data-do-showcase-instance]', context).forEach((group) => {
        const instanceId = group.getAttribute('data-do-showcase-instance');
        const options = Array.from(group.querySelectorAll('[role="radio"]'));
        const availableOptions = options.filter((option) => option.getAttribute('aria-disabled') !== 'true');
        // #123 SC-4 (F-U-1): pick the update model per instance from the
        // wrapper's mirror-attribute wiring (see usesMirrorModel() docblock
        // above for the full rationale). Cached once here because it can
        // only change if the wrapper is re-rendered, in which case
        // `once('do-showcase-switcher', ...)` would re-attach anyway.
        const isMirrorDriven = usesMirrorModel(group);

        const select = (id, persist) => {
          let target = null;
          options.forEach((option) => {
            const isMatch = option.getAttribute('data-do-showcase-id') === id;
            setSelected(option, isMatch);
            if (isMatch) {
              target = option;
            }
          });
          // Roving tabindex: only the newly-selected option stays in the Tab
          // order (wireframe.md lines 29-31, 271) — every other option
          // (available or not) is tabindex="-1".
          if (target) {
            setRovingTabindex(options, target);
          }
          // Mirror the selection onto a caller-named element's attribute
          // (#124 SC-5) — runs on EVERY select() call (both a user's live
          // click/keydown AND the page-load persisted-choice restore below),
          // so the wrapper attribute a CSS toggle keys off always reflects
          // the currently-displayed selection, not just the ones that get
          // persisted to sessionStorage.
          mirrorSelectionToWrapperAttribute(group, id);
          if (persist) {
            try {
              window.sessionStorage.setItem(STORAGE_PREFIX + instanceId, id);
            }
            catch (e) {
              // sessionStorage unavailable (private mode, disabled) — the
              // control still works for the current page load, it just
              // will not persist across navigation.
            }
          }
        };

        // Arrow-Left/Right moves selection + focus among AVAILABLE options
        // only, matching native radiogroup behavior (WAI-ARIA Authoring
        // Practices radiogroup pattern) — wireframe.md lines 29-31: "one
        // option in tab order at a time; Arrow-Left/Right moves selection,
        // matching native radiogroup behavior."
        const moveSelection = (fromOption, direction) => {
          const currentIndex = availableOptions.indexOf(fromOption);
          if (currentIndex === -1 || availableOptions.length === 0) {
            return;
          }
          const nextIndex = (currentIndex + direction + availableOptions.length) % availableOptions.length;
          const nextOption = availableOptions[nextIndex];
          const id = nextOption.getAttribute('data-do-showcase-id');
          select(id, true);
          nextOption.focus();
        };

        options.forEach((option) => {
          if (option.getAttribute('aria-disabled') === 'true') {
            return;
          }
          option.addEventListener('click', (event) => {
            // Mirror-driven instances (e.g. directory.layout) swap content
            // client-side by flipping a mirrored wrapper attribute — the
            // anchor's `href` is a no-JS fallback that MUST NOT navigate on
            // an interactive click, or filters/pager/URL query args are
            // lost. Navigation-driven instances (e.g. discovery.ranking)
            // have no client-side swap mechanism and MUST let the anchor's
            // `?<key>=<id>` fallback navigate — that navigation IS the
            // update. See usesMirrorModel() docblock (F-U-1 fix).
            if (isMirrorDriven) {
              event.preventDefault();
              select(option.getAttribute('data-do-showcase-id'), true);
              return;
            }
            // Navigation-driven: persist the choice first (so a same-page
            // history-back returns to a hydrated state), then let the
            // browser follow the anchor. Selection chrome (aria-checked,
            // bullet glyph, roving tabindex) will be re-derived from the
            // server-rendered markup on the next page load, and the
            // sessionStorage restore below is skipped because the URL now
            // carries an explicit value for THIS group's own query key —
            // so no chrome mutation is needed here.
            try {
              window.sessionStorage.setItem(STORAGE_PREFIX + instanceId, option.getAttribute('data-do-showcase-id'));
            }
            catch (e) {
              // sessionStorage unavailable — navigation still proceeds.
            }
          });
          option.addEventListener('keydown', (event) => {
            if (event.key === 'ArrowRight' || event.key === 'ArrowDown') {
              event.preventDefault();
              moveSelection(option, 1);
              return;
            }
            if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') {
              event.preventDefault();
              moveSelection(option, -1);
              return;
            }
            if (event.key !== 'Enter' && event.key !== ' ') {
              return;
            }
            // Same split as the click handler above — mirror-driven
            // instances swap in place; navigation-driven instances follow
            // the anchor. For the keyboard path we synthesize the
            // navigation explicitly (Enter/Space on a `role="radio"` does
            // not fire the anchor's default action the way a native click
            // would, even when the element is an `<a>`).
            if (isMirrorDriven) {
              event.preventDefault();
              select(option.getAttribute('data-do-showcase-id'), true);
              return;
            }
            event.preventDefault();
            try {
              window.sessionStorage.setItem(STORAGE_PREFIX + instanceId, option.getAttribute('data-do-showcase-id'));
            }
            catch (e) {
              // sessionStorage unavailable — navigation still proceeds.
            }
            const href = option.getAttribute('href');
            if (href) {
              window.location.assign(href);
            }
          });
        });

        // Restore a persisted choice unless the URL explicitly names one FOR
        // THIS GROUP'S OWN query key (that value always wins — the no-JS
        // fallback stays authoritative). #123 SC-4: reads the key generically
        // via queryKeyForGroup() rather than assuming 'variant', so a second
        // instance keyed on a different parameter (e.g. 'discovery') is not
        // silently overridden by a stale sessionStorage restore meant for a
        // co-resident instance's own query key.
        const queryKey = queryKeyForGroup(options);
        const params = new URLSearchParams(window.location.search);
        if (!queryKey || !params.has(queryKey)) {
          try {
            const stored = window.sessionStorage.getItem(STORAGE_PREFIX + instanceId);
            const match = stored && options.find((option) => option.getAttribute('data-do-showcase-id') === stored && option.getAttribute('aria-disabled') !== 'true');
            if (match) {
              select(stored, false);
            }
          }
          catch (e) {
            // sessionStorage unavailable — fall back to the server-rendered
            // default selection.
          }
        }
      });
    },
  };
})(Drupal, once);
