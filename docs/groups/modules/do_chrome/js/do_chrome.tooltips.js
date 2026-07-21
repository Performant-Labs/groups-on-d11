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
    attach(context, settings) {
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

      // Selector-mapped control tooltips (#92): the flag module renders the
      // promote/follow links itself, so their copy arrives as a
      // `selector => text` map in drupalSettings.doChrome.controlTooltips rather
      // than as a `data-do-tooltip` attribute. Bind each once so the same links
      // get the same explanatory tooltip wherever the flag module places them.
      const controlTooltips =
        (settings && settings.doChrome && settings.doChrome.controlTooltips) ||
        {};
      Object.keys(controlTooltips).forEach((selector) => {
        const text = controlTooltips[selector];
        if (!text) {
          return;
        }
        once('do-chrome-tooltip', selector, context).forEach((el) => {
          window.tippy(el, {
            content: text,
            allowHTML: false,
            theme: 'do-chrome',
          });
        });
      });
    },
  };
})(Drupal, once);
