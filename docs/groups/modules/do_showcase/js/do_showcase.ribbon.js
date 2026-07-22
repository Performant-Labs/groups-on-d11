/**
 * @file
 * POC demo ribbon dismiss + client-side dismissal persistence.
 *
 * The ribbon always renders server-side (identical markup for anonymous and
 * authenticated visitors — no session-dependent branching). This behavior:
 *  - hides it immediately on page load if the client-side dismiss flag is
 *    already set (sessionStorage (per-session)), so a dismissed visitor does
 *    not see it flash before removal,
 *  - removes it on a real click of the dismiss `<button>`, and persists the
 *    dismissal (sessionStorage (per-session)) — NO server session write
 *    (Brief-gate B-2: keeps the anonymous page cache warm). sessionStorage
 *    (not localStorage) matches the brief/issue's "dismissible per session"
 *    contract exactly — the dismissal is forgotten once the browser
 *    tab/session ends, unlike localStorage which persists across restarts.
 */
((Drupal, once) => {
  'use strict';

  const STORAGE_KEY = 'doShowcase.ribbonDismissed';

  Drupal.behaviors.doShowcaseRibbon = {
    attach(context) {
      once('do-showcase-ribbon', '#do-showcase-ribbon[data-do-showcase-ribbon]', context).forEach((ribbon) => {
        let dismissed = false;
        try {
          dismissed = window.sessionStorage.getItem(STORAGE_KEY) === '1';
        }
        catch (e) {
          // sessionStorage unavailable — the ribbon simply always shows.
        }
        if (dismissed) {
          ribbon.remove();
          return;
        }

        const dismissButton = ribbon.querySelector('[data-do-showcase-dismiss]');
        if (dismissButton) {
          dismissButton.addEventListener('click', () => {
            try {
              window.sessionStorage.setItem(STORAGE_KEY, '1');
            }
            catch (e) {
              // sessionStorage unavailable — dismissal only lasts this page
              // view.
            }
            ribbon.remove();
          });
        }
      });
    },
  };
})(Drupal, once);
