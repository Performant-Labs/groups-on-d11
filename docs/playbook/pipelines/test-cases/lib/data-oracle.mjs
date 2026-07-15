// data-oracle.mjs — the "data" oracle: confirm a UI claim matches the backend truth by
// querying the app's seeded database directly. This is the check off-the-shelf UI test
// tools skip — they assert on the screen, never on the store.
//
// Standalone: no project deps. Uses better-sqlite3 if importable (it's in most of our
// Node projects), else falls back to the `sqlite3` CLI. SQLite-only for now (our e2e
// fixture DBs); a Postgres adapter slots in behind the same `scalar()` signature later.
//
// Usage:
//   import { scalar, assertEquals } from '../lib/data-oracle.mjs';
//   const dbCount = await scalar('/tmp/lb-e2e.db',
//     "SELECT count(*) FROM phrase WHERE language_code='es-MX' AND audio_url IS NULL");
//   assertEquals('LB-BULKAUDIO-001/data', uiCount, dbCount);   // throws on mismatch

import { execFileSync } from 'node:child_process';

/** Run a query returning a single value. Returns a number when the result is numeric. */
export async function scalar(dbPath, sql) {
  // Prefer better-sqlite3 (in-process, exact types) when available.
  try {
    const { default: Database } = await import('better-sqlite3');
    const db = new Database(dbPath, { readonly: true });
    try {
      const row = db.prepare(sql).get();
      const v = row ? Object.values(row)[0] : null;
      return coerce(v);
    } finally {
      db.close();
    }
  } catch (e) {
    if (e?.code !== 'ERR_MODULE_NOT_FOUND') throw e;
  }
  // Fallback: sqlite3 CLI.
  const out = execFileSync('sqlite3', [dbPath, sql], { encoding: 'utf8' }).trim();
  return coerce(out === '' ? null : out);
}

function coerce(v) {
  if (v === null || v === undefined) return null;
  const n = Number(v);
  return Number.isFinite(n) && String(v).trim() !== '' ? n : v;
}

/** Oracle assertion: UI value must equal DB truth. Throws a labeled error on mismatch. */
export function assertEquals(label, uiValue, dbValue) {
  const ui = coerce(uiValue);
  if (ui !== dbValue) {
    throw new Error(
      `data-oracle FAIL [${label}]: UI says ${JSON.stringify(ui)} but DB says ${JSON.stringify(dbValue)}`,
    );
  }
  return { label, ui, db: dbValue, ok: true };
}

/** Relational/metamorphic oracle: assert an invariant between two captured values. */
export function assertRelation(label, lhs, op, rhs) {
  const a = coerce(lhs), b = coerce(rhs);
  const ok = op === '>=' ? a >= b : op === '>' ? a > b : op === '==' ? a === b : null;
  if (ok === null) throw new Error(`relational oracle [${label}]: unknown op ${op}`);
  if (!ok) throw new Error(`relational oracle FAIL [${label}]: ${a} ${op} ${b} is false`);
  return { label, lhs: a, op, rhs: b, ok: true };
}
