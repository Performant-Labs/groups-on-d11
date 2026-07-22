/**
 * @file
 * POC demo ribbon dismiss + client-side dismissal persistence.
 *
 * The ribbon always renders server-side (identical markup for anonymous and
 * authenticated visitors — no session-dependent branching). This behavior:
 *  - hides it immediately on page load if the client-side dismiss flag is
 *    already set (localStorage), so a dismissed visitor does not see it
 *    flash before removal,
 *  - removes it on a real click of the dismiss `<button>`, and persists the
 *    dismissal (localStorage) — NO server session write (Brief-gate B-2:
 *    keeps the anonymous page cache warm).
 */
((Drupal, once) => {
  'use strict';

  const STORAGE_KEY = 'doShowcase.ribbonDismissed';

  Drupal.behaviors.doShowcaseRibbon = {
    attach(context) {
      once('do-showcase-ribbon', '#do-showcase-ribbon[data-do-showcase-ribbon]', context).forEach((ribbon) => {
        let dismissed = false;
        try {
          dismissed = window.localStorage.getItem(STORAGE_KEY) === '1';
        }
        catch (e) {
          // localStorage unavailable — the ribbon simply always shows.
        }
        if (dismissed) {
          ribbon.remove();
          return;
        }

        const dismissButton = ribbon.querySelector('[data-do-showcase-dismiss]');
        if (dismissButton) {
          dismissButton.addEventListener('click', () => {
            try {
              window.localStorage.setItem(STORAGE_KEY, '1');
            }
            catch (e) {
              // localStorage unavailable — dismissal only lasts this page
              // view.
            }
            ribbon.remove();
          });
        }
      });
    },
  };
})(Drupal, once);
