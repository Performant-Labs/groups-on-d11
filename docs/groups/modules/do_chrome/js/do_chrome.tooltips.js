/**
 * @file
 * Initializes tippy.js tooltips for the Groups demo (do_chrome, #79).
 *
 * This behavior is the shared runtime for every tooltip surface in epic #78.
 * It attaches a tooltip to any element carrying a `data-do-tooltip` attribute
 * whose value is the tooltip text — so B-stories (#88-#92) only need to render
 * that attribute (server-side, from \Drupal\do_chrome\HelpText) and never touch
 * this file.
 */
((Drupal, once) => {
  'use strict';

  Drupal.behaviors.doChromeTooltips = {
    attach(context) {
      // `tippy` is provided by the locally-bundled UMD build (no CDN).
      if (typeof window.tippy !== 'function') {
        return;
      }
      const elements = once('do-chrome-tooltip', '[data-do-tooltip]', context);
      elements.forEach((el) => {
        window.tippy(el, {
          content: el.getAttribute('data-do-tooltip'),
          allowHTML: false,
          theme: 'do-chrome',
        });
      });
    },
  };
})(Drupal, once);
