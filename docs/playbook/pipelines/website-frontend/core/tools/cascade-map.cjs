// cascade-map.cjs — Tier B over-reach / containment detector for the Website Front-End (build) Pipeline — per-change blast-radius gate.
// PLATFORM-AGNOSTIC engine; the component subtree anchor comes from the adapter.
//
// For every CSS rule on the rendered page it computes the *matched set*, maps each matched
// element to its nearest component (via the subtree anchor, default data-component-id), and
// flags CONTAINMENT VIOLATIONS: a rule whose selector is scoped to component C but that matches
// elements outside C — i.e. a component's styles leaking past its own boundary (over-reach).
//
// Usage: node cascade-map.cjs <URL> [config.json] > cascade-map.json
// config.json (optional):
//   { "componentAttr": "data-component-id",   // subtree anchor (from adapter)
//     "viewports": [["375",375,667],["1280",1280,800]] }
//
// Output: ranked findings (severity proxy from spread × specificity × !important × id).
// Tier C (perturb.cjs) later confirms active-vs-dormant for the top findings.

const { chromium } = require('playwright');
const fs = require('fs');

const cfg = process.argv[3] ? JSON.parse(fs.readFileSync(process.argv[3], 'utf8')) : {};
const COMP_ATTR = cfg.componentAttr || 'data-component-id';
const VIEWPORTS = cfg.viewports || [['375', 375, 667], ['1280', 1280, 800]];

(async () => {
  const url = process.argv[2];
  if (!url) { console.error('Usage: node cascade-map.cjs <URL> [config.json]'); process.exit(1); }

  const browser = await chromium.launch();
  const byViewport = {};

  for (const [label, width, height] of VIEWPORTS) {
    const ctx = await browser.newContext({ ignoreHTTPSErrors: true, viewport: { width, height } });
    const page = await ctx.newPage();
    await page.goto(url, { waitUntil: 'networkidle' });

    byViewport[label] = await page.evaluate((compAttr) => {
      // Layout/box/position/visibility props — over-reach here is high-risk (visible breakage)
      const HIGH_RISK = new Set([
        'display','position','top','right','bottom','left','inset','float','clear',
        'width','height','min-width','max-width','min-height','max-height',
        'margin','margin-top','margin-right','margin-bottom','margin-left',
        'padding','padding-top','padding-right','padding-bottom','padding-left',
        'flex','flex-direction','flex-wrap','grid','grid-template','gap',
        'overflow','overflow-x','overflow-y','visibility','z-index','transform',
      ]);

      const nameOf = (v) => (v && v.includes(':') ? v.split(':').pop() : v);
      // nearest component name for an element, e.g. "ns:article-card" -> "article-card"
      const compOf = (el) => {
        const host = el.closest(`[${compAttr}]`);
        return host ? nameOf(host.getAttribute(compAttr) || '') : null; // null = page/global
      };
      // is el anywhere inside an instance of component `comp` (subtree containment)?
      const inSubtree = (el, comp) => {
        for (let n = el; n; n = n.parentElement) {
          if (n.getAttribute && n.hasAttribute(compAttr) && nameOf(n.getAttribute(compAttr)) === comp) return true;
        }
        return false;
      };

      // component vocabulary present on this page
      const vocab = new Set();
      document.querySelectorAll(`[${compAttr}]`).forEach((el) => {
        const v = el.getAttribute(compAttr) || '';
        vocab.add(v.includes(':') ? v.split(':').pop() : v);
      });

      // BEM block of a class token: "article-card__title" / "article-card--x" -> "article-card"
      const blockOf = (cls) => cls.split(/__|--/)[0];

      // the component a selector is *scoped to*, if any class token's block is a known component
      const scopedComponent = (selectorText) => {
        const classes = (selectorText.match(/\.[A-Za-z0-9_-]+/g) || []).map((c) => c.slice(1));
        for (const c of classes) { const b = blockOf(c); if (vocab.has(b)) return b; }
        return null;
      };

      const specificity = (sel) => {
        // rough a-b-c: ids, classes/attrs/pseudo-classes, elements
        const a = (sel.match(/#[A-Za-z0-9_-]+/g) || []).length;
        const b = (sel.match(/\.[A-Za-z0-9_-]+|\[[^\]]+\]|:[A-Za-z-]+(?!:)/g) || []).length;
        const c = (sel.match(/(^|[\s>+~])[a-zA-Z][a-zA-Z0-9]*/g) || []).length;
        return a * 100 + b * 10 + c;
      };

      const findings = [];
      const seen = new Set();

      const eachRule = (rules) => {
        for (const rule of rules) {
          if (rule.type === 1) { // style rule
            const selectorText = rule.selectorText;
            if (!selectorText) continue;
            // split selector list; analyze each compound selector independently
            for (const sel of selectorText.split(',').map((s) => s.trim()).filter(Boolean)) {
              const scoped = scopedComponent(sel);
              if (!scoped) continue; // only component-scoped selectors can "leak"; bare/global handled by Tier A heuristics
              if (seen.has(sel)) continue; seen.add(sel);
              let matched;
              try { matched = Array.from(document.querySelectorAll(sel)); }
              catch (e) { continue; } // unsupported selector
              if (!matched.length) continue;
              // TRUE over-reach: matched element escapes the scoped component's subtree entirely.
              const escapes = matched.filter((el) => !inSubtree(el, scoped));
              // Lesser concern: inside the subtree but styling a NESTED CHILD component's element.
              const nestedChild = matched.filter((el) => inSubtree(el, scoped) && compOf(el) !== scoped);
              if (!escapes.length) {
                // not a boundary escape; record nested-child coupling as INFO only
                if (nestedChild.length) {
                  const props0 = Array.from(rule.style);
                  findings.push({
                    selector: sel, scopedTo: scoped, kind: 'nested-child-coupling',
                    matchedCount: matched.length, escapeCount: 0,
                    nestedChildCount: nestedChild.length,
                    nestedComponents: Array.from(new Set(nestedChild.map((el) => compOf(el)))),
                    declaredProps: props0, highRiskProps: props0.filter((p) => HIGH_RISK.has(p)),
                    important: rule.style.cssText.includes('!important'),
                    hasId: /#[A-Za-z0-9_-]+/.test(sel), specificity: specificity(sel),
                    href: (rule.parentStyleSheet && rule.parentStyleSheet.href) || '(inline)',
                    score: 1, severityProxy: 'info',
                  });
                }
                continue;
              }
              const props = Array.from(rule.style).map((p) => p);
              const highRisk = props.filter((p) => HIGH_RISK.has(p));
              const escapeComps = Array.from(new Set(escapes.map((el) => compOf(el) || '(page)')));
              findings.push({
                selector: sel,
                scopedTo: scoped,
                kind: 'boundary-escape',
                matchedCount: matched.length,
                escapeCount: escapes.length,
                escapeComponents: escapeComps,
                nestedChildCount: nestedChild.length,
                declaredProps: props,
                highRiskProps: highRisk,
                important: rule.style.cssText.includes('!important'),
                hasId: /#[A-Za-z0-9_-]+/.test(sel),
                specificity: specificity(sel),
                href: (rule.parentStyleSheet && rule.parentStyleSheet.href) || '(inline)',
                sampleEscaped: escapes.slice(0, 3).map((el) => ({
                  tag: el.tagName.toLowerCase(),
                  cls: (el.className || '').toString().slice(0, 60),
                  inComponent: compOf(el) || '(page)',
                })),
              });
            }
          } else if (rule.type === 4 || rule.type === 12) { // @media / @supports — recurse
            try { eachRule(rule.cssRules); } catch (e) { /* skip */ }
          }
        }
      };

      for (const sheet of Array.from(document.styleSheets)) {
        let rules; try { rules = sheet.cssRules; } catch (e) { continue; } // cross-origin
        if (rules) eachRule(rules);
      }

      // severity proxy for boundary-escape findings (nested-child already scored as info above)
      for (const f of findings) {
        if (f.kind !== 'boundary-escape') continue;
        let score = f.escapeCount + f.escapeComponents.length * 3;
        if (f.highRiskProps.length) score += 20;
        if (f.important) score += 10;
        if (f.hasId) score += 10;
        f.score = score;
        // '?' = static reach confirmed; Tier C perturbation confirms active (winning) vs dormant (masked)
        f.severityProxy = f.highRiskProps.length ? 'critical?' : 'warning?';
      }
      findings.sort((a, b) => b.score - a.score);
      const escapes = findings.filter((f) => f.kind === 'boundary-escape');
      const nested = findings.filter((f) => f.kind === 'nested-child-coupling');
      return { componentVocab: Array.from(vocab), boundaryEscapes: escapes, nestedChildCoupling: nested };
    }, COMP_ATTR);

    await ctx.close();
  }

  await browser.close();
  console.log(JSON.stringify({ url, tool: 'cascade-map.cjs', byViewport }, null, 2));
})();
