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
 * and re-selects it (unless the page was loaded with an explicit `?variant=`
 * query param, which always wins, so the no-JS fallback and a direct/shared
 * link behave predictably).
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

  Drupal.behaviors.doShowcaseSwitcher = {
    attach(context) {
      once('do-showcase-switcher', '[role="radiogroup"][data-do-showcase-instance]', context).forEach((group) => {
        const instanceId = group.getAttribute('data-do-showcase-instance');
        const options = Array.from(group.querySelectorAll('[role="radio"]'));
        const availableOptions = options.filter((option) => option.getAttribute('aria-disabled') !== 'true');

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
            event.preventDefault();
            select(option.getAttribute('data-do-showcase-id'), true);
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
            event.preventDefault();
            select(option.getAttribute('data-do-showcase-id'), true);
          });
        });

        // Restore a persisted choice unless the URL explicitly names one
        // (?variant= always wins — the no-JS fallback stays authoritative).
        const params = new URLSearchParams(window.location.search);
        if (!params.has('variant')) {
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
