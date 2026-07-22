/**
 * @file
 * Variant-switcher interaction: click-to-select + client-side persistence.
 *
 * do_chrome/tooltips (already attached alongside this library wherever the
 * switcher renders) handles the ⓘ trigger — this behavior owns ONLY the
 * radiogroup's click/keyboard selection and persistence, never re-invents a
 * tooltip mechanism.
 *
 * Persistence is CLIENT-SIDE (localStorage, keyed per switcher instance id)
 * — Brief-gate B-2: a server session write (tempstore.private) would start a
 * session and bust the anonymous page cache. On load, this behavior reads
 * any stored choice for the instance and re-selects it (unless the page was
 * loaded with an explicit `?variant=` query param, which always wins, so the
 * no-JS fallback and a direct/shared link behave predictably).
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

  Drupal.behaviors.doShowcaseSwitcher = {
    attach(context) {
      once('do-showcase-switcher', '[role="radiogroup"][data-do-showcase-instance]', context).forEach((group) => {
        const instanceId = group.getAttribute('data-do-showcase-instance');
        const options = Array.from(group.querySelectorAll('[role="radio"]'));

        const select = (id, persist) => {
          options.forEach((option) => {
            setSelected(option, option.getAttribute('data-do-showcase-id') === id);
          });
          if (persist) {
            try {
              window.localStorage.setItem(STORAGE_PREFIX + instanceId, id);
            }
            catch (e) {
              // localStorage unavailable (private mode, disabled) — the
              // control still works for the current page load, it just
              // will not persist across navigation.
            }
          }
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
            const stored = window.localStorage.getItem(STORAGE_PREFIX + instanceId);
            const match = stored && options.find((option) => option.getAttribute('data-do-showcase-id') === stored && option.getAttribute('aria-disabled') !== 'true');
            if (match) {
              select(stored, false);
            }
          }
          catch (e) {
            // localStorage unavailable — fall back to the server-rendered
            // default selection.
          }
        }
      });
    },
  };
})(Drupal, once);
