#!/usr/bin/env node
/**
 * Baseline profiling for core pages under zero load (PERF-1, issue #242).
 *
 * Anonymous-only measurement. Runs each page N times (cold via fresh context,
 * warm via reuse of the same context). Records:
 *   - TTFB (responseStart - requestStart on the main document)
 *   - DOMContentLoaded (domContentLoadedEventEnd - navigationStart)
 *   - Load event  (loadEventEnd  - navigationStart)
 *   - LCP (PerformanceObserver 'largest-contentful-paint')
 *   - CLS (PerformanceObserver 'layout-shift', excluding hadRecentInput)
 *   - Total transferred bytes and request count (from Network events)
 *
 * FID/INP is anon-load-only and not measurable without real interaction —
 * reported as N/A. INP would require a scripted interaction sequence per page.
 *
 * Usage:  BASE_URL=https://groups.performantlabs.com node scripts/perf/baseline.mjs
 * Output: JSON to stdout; wrap with tee to capture.
 */
import { chromium } from '@playwright/test';

const BASE_URL = process.env.BASE_URL || 'https://groups.performantlabs.com';
const RUNS_COLD = Number(process.env.RUNS_COLD || 3);
const RUNS_WARM = Number(process.env.RUNS_WARM || 5);
const NAV_TIMEOUT_MS = 30000;
const SETTLE_MS = 2500; // let LCP / CLS observers accumulate after 'load'

const PAGES = [
  { name: 'front',         path: '/' },
  { name: 'showcase',      path: '/showcase' },
  { name: 'all-groups',    path: '/all-groups' },
  { name: 'group-detail',  path: '/group/1' },
  { name: 'stream',        path: '/stream' },
];

async function measureOnce(context, url) {
  const page = await context.newPage();
  let bytes = 0, requests = 0;
  page.on('response', async (resp) => {
    requests += 1;
    try {
      const len = resp.headers()['content-length'];
      if (len) bytes += Number(len);
      else {
        // Fallback: read body length. Skip on non-2xx to avoid throwing.
        if (resp.status() >= 200 && resp.status() < 400) {
          const buf = await resp.body().catch(() => null);
          if (buf) bytes += buf.length;
        }
      }
    } catch { /* ignore */ }
  });

  // Install web-vitals observers before navigation.
  await page.addInitScript(() => {
    window.__perf = { lcp: null, cls: 0, clsEntries: 0 };
    try {
      new PerformanceObserver((list) => {
        for (const e of list.getEntries()) window.__perf.lcp = e.startTime;
      }).observe({ type: 'largest-contentful-paint', buffered: true });
    } catch {}
    try {
      new PerformanceObserver((list) => {
        for (const e of list.getEntries()) {
          if (!e.hadRecentInput) {
            window.__perf.cls += e.value;
            window.__perf.clsEntries += 1;
          }
        }
      }).observe({ type: 'layout-shift', buffered: true });
    } catch {}
  });

  const t0 = Date.now();
  const resp = await page.goto(url, { waitUntil: 'load', timeout: NAV_TIMEOUT_MS });
  const httpStatus = resp ? resp.status() : 0;
  await page.waitForTimeout(SETTLE_MS);

  const nav = await page.evaluate(() => {
    const [e] = performance.getEntriesByType('navigation');
    if (!e) return null;
    return {
      ttfb: e.responseStart - e.requestStart,
      dcl:  e.domContentLoadedEventEnd - e.startTime,
      load: e.loadEventEnd - e.startTime,
      transferSize: e.transferSize,
      encodedBodySize: e.encodedBodySize,
    };
  });
  const perf = await page.evaluate(() => window.__perf || {});
  const wallMs = Date.now() - t0;

  await page.close();
  return {
    httpStatus, wallMs,
    ttfb: nav?.ttfb ?? null,
    dcl:  nav?.dcl  ?? null,
    load: nav?.load ?? null,
    lcp:  perf.lcp ?? null,
    cls:  perf.cls ?? null,
    clsEntries: perf.clsEntries ?? 0,
    requests,
    bytes,
  };
}

function pct(arr, p) {
  const xs = arr.filter((x) => typeof x === 'number' && Number.isFinite(x)).slice().sort((a, b) => a - b);
  if (!xs.length) return null;
  const idx = Math.min(xs.length - 1, Math.max(0, Math.ceil((p / 100) * xs.length) - 1));
  return xs[idx];
}
function summarize(runs, key) {
  const vals = runs.map((r) => r[key]);
  return {
    p50: pct(vals, 50),
    p95: pct(vals, 95),
    p99: pct(vals, 99),
    min: Math.min(...vals.filter(Number.isFinite)),
    max: Math.max(...vals.filter(Number.isFinite)),
    n: vals.filter(Number.isFinite).length,
  };
}

async function measurePage({ name, path }) {
  const url = new URL(path, BASE_URL).toString();
  const results = { name, path, url, cold: [], warm: [] };

  // Cold: fresh context per run (no HTTP cache, no cookies).
  for (let i = 0; i < RUNS_COLD; i++) {
    const browser = await chromium.launch();
    const ctx = await browser.newContext({ bypassCSP: false });
    try {
      const r = await measureOnce(ctx, url);
      results.cold.push(r);
      process.stderr.write(`[cold] ${name} run ${i + 1}/${RUNS_COLD}: status=${r.httpStatus} ttfb=${r.ttfb?.toFixed(0)}ms load=${r.load?.toFixed(0)}ms lcp=${r.lcp?.toFixed(0)}ms cls=${r.cls?.toFixed(3)}\n`);
    } catch (err) {
      process.stderr.write(`[cold] ${name} run ${i + 1}: ERROR ${err.message}\n`);
      results.cold.push({ error: err.message });
    } finally {
      await ctx.close(); await browser.close();
    }
  }

  // Warm: single browser + context, primed by one throwaway load.
  const browser = await chromium.launch();
  const ctx = await browser.newContext();
  try {
    // Prime cache once — don't record it.
    try { const p = await ctx.newPage(); await p.goto(url, { waitUntil: 'load', timeout: NAV_TIMEOUT_MS }); await p.close(); } catch {}
    for (let i = 0; i < RUNS_WARM; i++) {
      try {
        const r = await measureOnce(ctx, url);
        results.warm.push(r);
        process.stderr.write(`[warm] ${name} run ${i + 1}/${RUNS_WARM}: status=${r.httpStatus} ttfb=${r.ttfb?.toFixed(0)}ms load=${r.load?.toFixed(0)}ms lcp=${r.lcp?.toFixed(0)}ms cls=${r.cls?.toFixed(3)}\n`);
      } catch (err) {
        process.stderr.write(`[warm] ${name} run ${i + 1}: ERROR ${err.message}\n`);
        results.warm.push({ error: err.message });
      }
    }
  } finally {
    await ctx.close(); await browser.close();
  }

  const okCold = results.cold.filter((r) => !r.error);
  const okWarm = results.warm.filter((r) => !r.error);
  results.summary = {
    cold: {
      ttfb: summarize(okCold, 'ttfb'),
      dcl:  summarize(okCold, 'dcl'),
      load: summarize(okCold, 'load'),
      lcp:  summarize(okCold, 'lcp'),
      cls:  summarize(okCold, 'cls'),
      bytes: summarize(okCold, 'bytes'),
      requests: summarize(okCold, 'requests'),
    },
    warm: {
      ttfb: summarize(okWarm, 'ttfb'),
      dcl:  summarize(okWarm, 'dcl'),
      load: summarize(okWarm, 'load'),
      lcp:  summarize(okWarm, 'lcp'),
      cls:  summarize(okWarm, 'cls'),
      bytes: summarize(okWarm, 'bytes'),
      requests: summarize(okWarm, 'requests'),
    },
  };
  return results;
}

(async () => {
  const started = new Date().toISOString();
  const out = { started, baseUrl: BASE_URL, runsCold: RUNS_COLD, runsWarm: RUNS_WARM, pages: [] };
  for (const p of PAGES) {
    process.stderr.write(`\n=== ${p.name} (${p.path}) ===\n`);
    out.pages.push(await measurePage(p));
  }
  out.finished = new Date().toISOString();
  process.stdout.write(JSON.stringify(out, null, 2) + '\n');
})();
