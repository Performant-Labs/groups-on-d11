/**
 * @file
 * Renders the `.mermaid` diagram block on the /er-diagram page.
 *
 * The diagram source is generated live server-side by ErdGeneratorPageHook
 * (do_ops) — this behavior only turns the plain-text Mermaid source already
 * in the page into an SVG, the same client-side rendering GitHub uses for
 * ```mermaid fenced blocks.
 */
((Drupal, once) => {
  'use strict';

  Drupal.behaviors.doOpsErdDiagram = {
    attach(context) {
      if (typeof window.mermaid === 'undefined') {
        return;
      }
      const elements = once('do-ops-erd-diagram', '#do-ops-erd-diagram .mermaid', context);
      if (!elements.length) {
        return;
      }
      window.mermaid.initialize({ startOnLoad: false, securityLevel: 'strict' });
      window.mermaid.run({ nodes: elements });
    },
  };
})(Drupal, once);
