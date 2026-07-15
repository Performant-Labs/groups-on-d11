// axe-check.cjs — Tier 2 WCAG/ARIA check via axe-core. PLATFORM-AGNOSTIC TOOL
// (Website Front-End Pipeline, used by T and F).
//
// Usage: AXE_BASE_URL=https://site.example node axe-check.cjs <path> [path ...]
// Env:   AXE_BASE_URL  required — the site base URL (from the project profile).
// Deps:  npm install --no-save playwright @axe-core/playwright   (if absent)
//
// Runs WCAG 2.0/2.1 A+AA rules at 375 (mobile-first) and 1280 viewports.
// Exit code 1 if any violation is found.

const { chromium } = require('playwright');
const { AxeBuilder } = require('@axe-core/playwright');

const BASE = process.env.AXE_BASE_URL;
if (!BASE) { console.error('Set AXE_BASE_URL to the site base URL (see project profile).'); process.exit(2); }
const paths = process.argv.slice(2).length ? process.argv.slice(2) : ['/'];

(async () => {
  const browser = await chromium.launch();
  let total = 0;
  for (const [name, width, height] of [['mobile-375', 375, 667], ['desktop-1280', 1280, 800]]) {
    for (const path of paths) {
      const ctx = await browser.newContext({ ignoreHTTPSErrors: true, viewport: { width, height } });
      const page = await ctx.newPage();
      await page.goto(BASE + path, { waitUntil: 'networkidle' });
      const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa']).analyze();
      console.log(`${name} ${path}: ${results.violations.length} violations`);
      for (const v of results.violations) {
        console.log(`  [${v.impact}] ${v.id}: ${v.help} (${v.nodes.length} nodes)`);
        for (const n of v.nodes.slice(0, 3)) console.log(`      ${n.target.join(' ')}`);
      }
      total += results.violations.length;
      await ctx.close();
    }
  }
  await browser.close();
  process.exit(total > 0 ? 1 : 0);
})();
