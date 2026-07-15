// adapter.template.mjs — SCAFFOLD for a per-surface project adapter.
//
// Copy this into the project under test (next to its e2e specs, e.g.
// `<project>/e2e/adapters/<surface-slug>.adapter.mjs`) and fill in the four blanks. The
// adapter is the ONLY project-specific coupling in the test-case pipeline — cases stay
// portable and selector-free; this file holds the app's DOM/DB facts.
//
// Authoring step 0 (once per surface, reused by every case for that surface):
//   1. Open the real template/route. Copy the ACTUAL selectors — do not guess from a plan.
//   2. Find the DB table + columns the UI count reflects (the "data" oracle's truth).
//   3. Note any backend rules that make the UI number ≠ a naive COUNT (filtering, skips).
//   4. Verify the SQL against a seeded DB with a tiny known fixture (see the worked example).
//
// Consumed by:
//   ../lib/run-case.mjs    --adapter <this>   (live interpret; calls async sql())
//   ../lib/compile-case.mjs --adapter <this>  (emit *.gen.spec.ts; calls sync sqlText())
//
// Worked example to copy from:
//   language-buddy/e2e/adapters/admin-audio-backfill.adapter.mjs

export default {
  // ── Where the app runs + how to authenticate (match the project's e2e serve script) ──
  base: `http://127.0.0.1:${process.env.E2E_PORT || '3100'}`,
  login: {
    email: process.env.E2E_ADMIN_EMAIL || 'CHANGE-ME@example.com',
    pass: process.env.E2E_ADMIN_PASSWORD || 'CHANGE-ME',
  },

  // ── The seeded DB the data oracle queries (SQLITE_PATH wins for CI) ──────────────────
  dbPath: process.env.SQLITE_PATH || '/tmp/CHANGE-ME.db',

  // ── (1) CONTROLS — the REAL interactive elements, copied from the live DOM ───────────
  // One entry per control a case references by name. kind: input | select | radio | button
  // | region. `mode` (a radio group) may carry a values map: { 'fill-missing': 'fill', … }.
  controls: {
    // exampleInput:  { selector: '#real-id',                         kind: 'input'  },
    // exampleSelect: { selector: 'select[name="real"]',              kind: 'select' },
    // submitBtn:     { selector: 'form#real button[type="submit"]',  kind: 'button' },
    // resultRegion:  { selector: '#real-result-container',           kind: 'region' }, // for the content oracle
  },

  // Optional: assert the app is up before driving it (fail fast with a clear message).
  async serveCheck() {
    const res = await fetch(`${this.base}/login`).catch(() => null);
    if (!res || !res.ok) throw new Error(`e2e server not reachable at ${this.base} — start the project's e2e serve`);
  },

  // ── (2) READ — resolve a case `read.from` description to a value ─────────────────────
  // Locate the rendered number/text by its on-screen phrase, return a value. Keep this
  // matching the actual partial the UI swaps in.
  async read(page, fromDescription) {
    // const li = page.locator('#real-result-container li', { hasText: 'matching phrase' });
    // await li.first().waitFor({ timeout: 8000 });
    // return Number(((await li.locator('strong').first().textContent()) || '').trim());
    throw new Error(`adapter.read not implemented for "${fromDescription}"`);
  },

  // ── (3) DATA-ORACLE SQL — the UI claim's backend truth ──────────────────────────────
  // async sql() for the live runner; sync sqlText() for the compiler. Share one builder so
  // they can never drift. Encode any backend rule that makes the count non-naive.
  async sql(name, captured) {
    return buildSql(name, captured);
  },
  sqlText(name, captured) {
    return buildSql(name, captured);
  },

  // Count locator the compiler bakes into the generated spec's readCount().
  countLocator: {
    container: '#real-result-container',
    hasText: 'matching phrase',
  },

  // ── (4) SEED PRECONDITION — the named DB state (hand off the matrix to seed control) ──
  // The compiler runs this in test.beforeAll to guarantee a meaningful state. Keep it a
  // single statement here; the seed-control work owns the full state matrix.
  precondition: `-- CHANGE-ME: SQL that establishes the precondition this surface needs`,
};

// One builder, used by both sql() and sqlText(). Map each data-oracle var name to its SQL.
function buildSql(name, captured = {}) {
  switch (name) {
    // case 'in_scope': return `SELECT count(*) FROM real_table WHERE <condition>`;
    default:
      throw new Error(`adapter: unknown data-oracle var "${name}"`);
  }
}
