// AUTO-GENERATED from LB-BULKAUDIO-001 by test-cases/lib/compile-case.mjs — do not hand-edit; regenerate from the case.
// Semantic source: LB-BULKAUDIO-001.case.yaml   |   trace: docs/handoffs/bulk-audio-backfill-U.md
import { test, expect } from '@playwright/test';
import Database from 'better-sqlite3';

const DB = process.env.SQLITE_PATH || '/tmp/lb-e2e.db';
function dbScalar(sql: string): number {
  const db = new Database(DB, { readonly: true });
  try { return Number(Object.values(db.prepare(sql).get() as Record<string, unknown>)[0]); } finally { db.close(); }
}
async function readCount(page: import('@playwright/test').Page): Promise<number> {
  const li = page.locator('#backfill-progress-container li', { hasText: 'would be generated' });
  await li.first().waitFor();
  return Number(((await li.locator('strong').first().textContent()) || '').trim());
}

// Bulk Audio preview count is truthful and mode-sensitive  (risk: high)
test.describe("LB-BULKAUDIO-001", () => {
  // seed.requires: es-MX deck with MIXED audio coverage — some phrases audio_url NULL, some set
  test.beforeAll(() => {
    const db = new Database(DB);
    try { db.prepare("UPDATE practice_items SET audio_url=NULL WHERE language_code='es-MX' AND rowid % 3 = 0").run(); } finally { db.close(); }
    expect(dbScalar("SELECT count(*) FROM practice_items WHERE language_code='es-MX' AND audio_url IS NULL")).toBeGreaterThan(0); // mixed coverage established
  });

  test("Bulk Audio preview count is truthful and mode-sensitive", async ({ page }) => {
    // auth: project storageState (admin); viewport: project viewport-projects
    await page.goto('/');
    // reach the surface via SPA navigation (HTMX swap, not a reload)
    await page.evaluate(() => new Promise<void>((res) => { document.body.addEventListener('htmx:afterSettle', () => res(), { once: true }); (window as unknown as { htmx: { ajax: (m: string, u: string, o: object) => void } }).htmx.ajax('GET', '/admin/audio/backfill', { target: '#main-content', swap: 'innerHTML' }); }));
    await expect(page.locator('#backfill-scope-form')).toBeVisible(); // structural
    await page.selectOption('#backfill-language-select', 'es-MX');
    await page.selectOption('#backfill-engine-select', 'kokoro');
    await page.click('label:has(input[name="mode"][value="missing"])');
    await page.click('button[name="dryRun"]');
    await page.waitForFunction(() => !document.querySelector('.htmx-request'));
    const fill_missing_count = await readCount(page);
    await page.click('label:has(input[name="mode"][value="all"])');
    await page.click('button[name="dryRun"]');
    await page.waitForFunction(() => !document.querySelector('.htmx-request'));
    const all_count = await readCount(page);
    await expect(page.locator('#backfill-result-container')).toContainText("would be generated"); // content oracle
    expect(fill_missing_count).toBe(dbScalar("SELECT count(*) FROM practice_items WHERE language_code='es-MX' AND audio_url IS NULL")); // data oracle: UI count == DB truth
    expect(all_count).toBeGreaterThan(fill_missing_count); // relational/metamorphic oracle
  });
});
