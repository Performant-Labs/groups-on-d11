// u-drive.mjs — canonical U (UI Walkthrough) driver helper.
//
// Purpose: give the U agent CORRECT HTMX-aware waits and a one-shot per-page
// evidence bundle, so it never re-derives timing or re-runs debug scripts.
// The #1 baseline time-sink was `waitForLoadState('networkidle')` resolving
// before `hx-push-url` finished — every wait here keys on the real HTMX
// lifecycle event or the URL, never networkidle.
//
// Usage (from a throwaway script INSIDE the target repo so `playwright`
// resolves from its node_modules):
//   import * as U from './.u-drive.mjs';
//   const { browser, page } = await U.launch({ base: 'http://127.0.0.1:3100' });
//   await U.login(page, { email, pass });
//   await U.spaNav(page, '/admin/users');
//   const ev = await U.collectEvidence(page, { label: 'admin-users-desktop' });
//   // ev = { alpine:[{factory,alive}], deadComponents:[], consoleErrors:[], screenshot }
//   await U.clickAndWaitUrl(page, 'a[href*="status=pending"]', /status=pending/);
//   await browser.close();

import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';

export async function launch({ headless = true, base } = {}) {
  const browser = await chromium.launch({ headless });
  const ctx = await browser.newContext({ baseURL: base });
  const page = await ctx.newPage();
  const errors = [];
  page.on('console', m => { if (m.type() === 'error') errors.push(m.text()); });
  page.on('pageerror', e => errors.push('pageerror: ' + e.message));
  page._uErrors = errors;
  return { browser, page };
}

export async function login(page, { email, pass, loginPath = '/login' }) {
  await page.goto(loginPath);
  await page.fill('input[name="email"]', email);
  await page.fill('input[name="password"]', pass);
  await Promise.all([
    page.waitForURL(u => !u.toString().includes('/login'), { timeout: 10000 }),
    page.click('button[type="submit"], input[type="submit"]'),
  ]);
}

// SPA navigation via HTMX swap. Resolves on htmx:afterSettle (DOM swapped AND
// settle/transition complete) — the correct signal, not networkidle.
export async function spaNav(page, path, { target = '#main-content' } = {}) {
  await page.evaluate(({ path, target }) => new Promise((resolve, reject) => {
    const timer = setTimeout(() => reject(new Error('htmx swap timeout: ' + path)), 8000);
    document.body.addEventListener('htmx:afterSettle', function h() {
      clearTimeout(timer);
      document.body.removeEventListener('htmx:afterSettle', h);
      resolve();
    }, { once: true });
    window.htmx.ajax('GET', path, { target, swap: 'innerHTML' });
  }), { path, target });
}

// Click a control whose HTMX request updates the URL (hx-push-url). Waits on the
// URL change, which only fires after pushState — the thing networkidle races.
export async function clickAndWaitUrl(page, selector, urlRe, { timeout = 8000 } = {}) {
  await Promise.all([
    page.waitForURL(urlRe, { timeout }),
    page.click(selector),
  ]);
}

// One-shot evidence bundle for the current DOM state. Captures, in a single pass,
// the three behavioral failure classes T/e2e miss on the SPA-swap path:
//   1. Dead Alpine components   (deadComponents — the #342/#345 check)
//   2. Console errors           (consoleErrors)
//   3. Blocking overlays        (blockingOverlays — the #347 class: a [role=dialog]
//      left visible after a swap, covering the page and intercepting clicks)
// A non-empty deadComponents / consoleErrors / blockingOverlays IS a finding — the
// agent should report it directly, NOT re-run scripts to re-characterize it.
export async function collectEvidence(page, { label, target = '#main-content', shotDir = '/tmp/lb-u-screenshots' } = {}) {
  mkdirSync(shotDir, { recursive: true });
  const bundle = await page.evaluate((sel) => {
    const visible = el => (el.checkVisibility ? el.checkVisibility() : getComputedStyle(el).display !== 'none');
    const alpine = [...document.querySelectorAll(`${sel} [x-data]`)].map(el => ({
      factory: (el.getAttribute('x-data') || '').slice(0, 40),
      alive: Array.isArray(el._x_dataStack) && el._x_dataStack.length > 0,
    }));
    // Blocking-overlay detection: any visible dialog, OR whatever sits at viewport
    // center being a modal/backdrop, means the page is covered.
    const cx = Math.floor(innerWidth / 2), cy = Math.floor(innerHeight / 2);
    const top = document.elementFromPoint(cx, cy);
    const inModal = top && (top.closest?.('[role="dialog"]') || top.closest?.('[aria-modal="true"]'));
    const blockingOverlays = [...document.querySelectorAll('[role="dialog"], [aria-modal="true"]')]
      .filter(visible)
      .map(el => {
        const r = el.getBoundingClientRect();
        return {
          id: el.id || null,
          label: el.getAttribute('aria-label') || null,
          coversViewport: r.width >= innerWidth * 0.6 && r.height >= innerHeight * 0.6,
          // The controlling Alpine scope says closed, yet the dialog is visible:
          shouldBeClosed: (() => { try { const c = el.closest('[x-data]'); return c?._x_dataStack?.[0]?.open === false; } catch { return null; } })(),
        };
      });
    return {
      alpine,
      blockingOverlays,
      centerHitIsModal: !!inModal,
      centerHitEl: top ? (top.id || top.getAttribute?.('role') || top.tagName) : null,
    };
  }, target);
  const screenshot = `${shotDir}/${label}.png`;
  await page.screenshot({ path: screenshot, fullPage: true }).catch(() => {});
  return {
    label,
    alpine: bundle.alpine,
    deadComponents: bundle.alpine.filter(a => !a.alive),
    blockingOverlays: bundle.blockingOverlays,
    centerHitIsModal: bundle.centerHitIsModal,
    centerHitEl: bundle.centerHitEl,
    consoleErrors: [...(page._uErrors || [])],
    screenshot,
  };
}
