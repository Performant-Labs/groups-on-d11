#!/usr/bin/env node
/**
 * PERF-5 Asset audit (issue #245).
 *
 * For each core anonymous page: catalog every subresource (URL, type, size,
 * status, content-encoding, cache headers, minified guess), count requests
 * by type, and dump a per-page JSON blob. Also runs CSS + JS coverage
 * (Chrome DevTools Protocol) to estimate unused bytes per page.
 *
 * Usage:
 *   BASE_URL=https://groups.performantlabs.com node scripts/perf/asset-audit.mjs
 * Writes:
 *   docs/planning/perf/asset-audit-2026-07-27.raw.json  (machine)
 *   (report authored separately, referencing this JSON)
 */
import { chromium } from '@playwright/test';
import { writeFileSync } from 'node:fs';

const BASE_URL = process.env.BASE_URL || 'https://groups.performantlabs.com';
const OUT = process.env.OUT || 'docs/planning/perf/asset-audit-2026-07-27.raw.json';

const PAGES = [
  { name: 'front',        path: '/' },
  { name: 'all-groups',   path: '/all-groups' },
  { name: 'group-detail', path: '/group/1' },
  { name: 'stream',       path: '/stream' },
  { name: 'groups',       path: '/groups' },
];

function looksMinified(body, url) {
  if (!body) return null;
  if (/\.min\.(js|css)(\?|$)/i.test(url)) return true;
  const s = body.toString('utf8', 0, Math.min(body.length, 4096));
  const lines = s.split('\n');
  if (lines.length < 3 && s.length > 500) return true;
  const avg = s.length / Math.max(lines.length, 1);
  return avg > 200;
}

function classify(url, ctype) {
  ctype = (ctype || '').toLowerCase();
  if (ctype.includes('css')) return 'css';
  if (ctype.includes('javascript')) return 'js';
  if (ctype.includes('font') || /\.(woff2?|ttf|otf|eot)(\?|$)/i.test(url)) return 'font';
  if (ctype.startsWith('image/') || /\.(png|jpe?g|gif|webp|svg|ico|avif)(\?|$)/i.test(url)) return 'image';
  if (ctype.startsWith('text/html')) return 'html';
  if (ctype.includes('json')) return 'json';
  return 'other';
}

async function auditPage(browser, { name, path }) {
  const context = await browser.newContext({ ignoreHTTPSErrors: true });
  const page = await context.newPage();
  const url = BASE_URL + path;
  const assets = [];

  // Start coverage.
  await page.coverage.startJSCoverage({ resetOnNavigation: false });
  await page.coverage.startCSSCoverage({ resetOnNavigation: false });

  const cdnHits = [];
  page.on('response', async (resp) => {
    try {
      const u = resp.url();
      const h = resp.headers();
      const ctype = h['content-type'] || '';
      let size = null;
      const cl = h['content-length'];
      if (cl) size = Number(cl);
      let body = null;
      if (resp.status() >= 200 && resp.status() < 400) {
        body = await resp.body().catch(() => null);
        if (body && size == null) size = body.length;
      }
      const kind = classify(u, ctype);
      const asset = {
        url: u,
        status: resp.status(),
        type: kind,
        contentType: ctype,
        contentEncoding: h['content-encoding'] || null,
        cacheControl: h['cache-control'] || null,
        size,
        minified: (kind === 'js' || kind === 'css') ? looksMinified(body, u) : null,
      };
      assets.push(asset);
      if (/unpkg\.com|cdn\.jsdelivr\.net|cdnjs\.cloudflare\.com|ajax\.googleapis\.com/i.test(u)) {
        cdnHits.push(u);
      }
    } catch { /* ignore */ }
  });

  const resp = await page.goto(url, { waitUntil: 'load', timeout: 30000 });
  const status = resp ? resp.status() : 0;
  await page.waitForTimeout(2500);

  // Coverage stop.
  const jsCov = await page.coverage.stopJSCoverage();
  const cssCov = await page.coverage.stopCSSCoverage();

  const coverage = { js: [], css: [] };
  for (const e of jsCov) {
    const total = e.source ? e.source.length : 0;
    let used = 0;
    for (const f of e.functions) for (const r of f.ranges) if (r.count > 0) used += (r.endOffset - r.startOffset);
    coverage.js.push({ url: e.url, total, used, unused: total - used });
  }
  for (const e of cssCov) {
    const total = e.text ? e.text.length : 0;
    let used = 0;
    for (const r of e.ranges) used += (r.end - r.start);
    coverage.css.push({ url: e.url, total, used, unused: total - used });
  }

  // Summary rollup by type.
  const byType = {};
  for (const a of assets) {
    const t = a.type;
    byType[t] = byType[t] || { count: 0, bytes: 0 };
    byType[t].count += 1;
    byType[t].bytes += a.size || 0;
  }

  await context.close();
  return { name, path, url, status, requestCount: assets.length, byType, assets, coverage, cdnHits };
}

async function main() {
  console.error(`[asset-audit] BASE_URL=${BASE_URL}`);
  const browser = await chromium.launch();
  const results = [];
  for (const p of PAGES) {
    console.error(`[asset-audit] ${p.path} ...`);
    try {
      results.push(await auditPage(browser, p));
    } catch (e) {
      console.error(`[asset-audit] FAILED ${p.path}: ${e.message}`);
      results.push({ name: p.name, path: p.path, error: e.message });
    }
  }
  await browser.close();
  const out = { baseUrl: BASE_URL, timestamp: new Date().toISOString(), pages: results };
  writeFileSync(OUT, JSON.stringify(out, null, 2));
  console.error(`[asset-audit] wrote ${OUT}`);
}

main().catch(e => { console.error(e); process.exit(1); });
