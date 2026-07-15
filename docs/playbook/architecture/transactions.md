# Database Transactions & Atomicity

> **Scope:** every project with a database. This is the store-agnostic policy — *when*
> atomicity is required and *how to reason about it*. For the concrete MikroORM mechanics
> (`em.transactional`, locks, atomic upsert), see
> [`../frameworks/mikro-orm/conventions.md` → Transactions & Atomicity](../frameworks/mikro-orm/conventions.md).

A write that *looks* fine in tests and *is* fine in production are different claims. The gap
is almost always **(a)** a multi-step write with no transaction, **(b)** a read-modify-write
with no lock, or **(c)** a sequence that spans **two stores** that can't share a transaction.
The first two are easy once you know to look; the third is the one that bites repeatedly and
has no "just wrap it" fix. This doc names all three and the rule for each.

---

## The one-line rules

1. **More than one write in a request → one transaction.** Any handler/service that performs
   **≥ 2 writes**, or a **read-modify-write**, runs inside a single transaction. A sequence of
   bare `flush()`es is not atomic.
2. **Read-modify-write under concurrency → lock or atomic SQL.** A transaction alone does *not*
   stop a lost update. You need a row lock (`SELECT … FOR UPDATE` / `PESSIMISTIC_WRITE`), an
   atomic statement (`INSERT … ON CONFLICT … DO UPDATE … RETURNING`), or optimistic version
   columns.
3. **You cannot make writes to two stores atomic.** A transaction lives on one connection to
   one database. Writes that cross a boundary (ORM ↔ auth library, DB ↔ object storage, DB ↔
   external API) need the **boundary pattern** below, not a transaction.
4. **SQLite hides the bugs that rules 2–3 are about.** Dev/test on SQLite (single writer)
   will pass while production on Postgres races. Concurrency and partial-failure paths must be
   tested on the production engine (or with an injected mid-sequence failure).

---

## Layer 1 — intra-store atomicity (one database)

### Multi-write = one transaction

If a unit of work touches more than one row/table and they must all land or none, wrap it:

```ts
await em.transactional(async (txEm) => {
  // every read AND write inside uses txEm — never the outer em
  const account = await txEm.findOne(UserAccount, { userId });
  account.status = 'active';
  await createAuditLog(txEm, …);
  await txEm.flush();
});
```

- **Use the transaction's handle for everything inside.** Mixing the outer `em` and the
  transactional `txEm` inside the block silently escapes the transaction — the outer-`em`
  writes are not covered and won't roll back.
- A **single** `flush()` is already atomic (the ORM wraps one flush in an implicit
  transaction). The rule is about **multiple** flushes / multiple service calls in one request.
- On rollback (any throw inside the block), nothing persists — that is the whole point. Don't
  catch-and-continue inside the block in a way that swallows a failure and then flushes anyway.

### Read-modify-write needs more than a transaction

`SELECT → mutate in app code → flush` is a **lost-update** race even inside a transaction:
two requests both read the old value, both write, one clobbers the other. Pick one:

- **Pessimistic lock** — `SELECT … FOR UPDATE` (MikroORM `LockMode.PESSIMISTIC_WRITE`) at the
  top of the transaction. The second request blocks until the first commits, then reads the
  fresh value. Correct for "count the active admins before demoting" style guards (TOCTOU).
- **Atomic statement** — push the read-and-write into one SQL statement so the DB does it
  indivisibly: `INSERT … ON CONFLICT(key) DO UPDATE SET n = n + excluded.n … RETURNING`,
  or `UPDATE … SET n = n + 1 WHERE …`. Best for counters/upserts (e.g. rate limiters).
- **Optimistic locking** — a version column; the write fails if the row changed since you read
  it, and you retry. Good for low-contention edits.

> **Postgres-only locks:** `FOR UPDATE` is a no-op concept under SQLite's single-writer model.
> Gate the lock call on the dialect (`if (isPostgres(em)) …`) so dev/test on SQLite still runs,
> but **test the guard on Postgres** — SQLite cannot reproduce the race it defends against.

---

## Layer 2 — the cross-store boundary (two databases / two systems)

**There is no transaction that spans two stores.** An ORM `EntityManager` and an auth
library's own DB adapter are different connections; a database and an object store / external
API aren't even the same protocol. So when one logical operation writes to both, all-or-nothing
is **not** available. Do **not** reach for a distributed transaction at small scale — apply the
boundary pattern instead.

### The boundary pattern (validate-first · order · document · compensate-only-if-needed)

1. **Validate everything before the first write.** Hoist every check — format, uniqueness
   conflicts, authorization, invariant guards — *ahead* of any write. Most "partial write"
   bugs are really "we wrote, then discovered the input was bad."
2. **Order writes most-likely-to-fail first.** The write whose guard can still reject under a
   race (e.g. the locked read-modify-write) goes **first**, so a rejection leaves *nothing*
   written. Writes that can only fail on unforeseen errors go last.
3. **Make each cross-boundary write idempotent** where you can, so a retry after a flaky
   failure converges instead of duplicating.
4. **Document the residual.** State, in a comment at the call site, exactly what inconsistency
   remains possible (e.g. "if `name` fails after `email` succeeds, email is left changed") and
   why it's acceptable. A known, logged, bounded residual is engineering; an unexamined one is
   a latent bug.
5. **Compensation/saga only if the residual is unacceptable.** If the data genuinely must be
   all-or-nothing, either add explicit compensation (undo the earlier write on a later failure)
   or **co-locate the data in one store** so it becomes a Layer-1 transaction. Co-location is
   usually the cheaper, more honest fix; sagas are for when you truly can't.

### Worked example — editing a user that spans an auth library + the app DB

`update-account` writes `email`/`name` to the **auth library's** user table and `role`/`status`
to the **app's** `UserAccount` (a transactional, locked read-modify-write). These are two
stores: no shared transaction. Applying the pattern:

- **Validate first:** email format + email-conflict + name-non-empty + role-change guard all
  run before any write; any failure → reject, nothing written.
- **Order:** the **role** change (the only write whose authoritative lock can reject on a
  concurrent last-admin race) goes **first**. If it rejects, email/name were never touched.
  Then the two auth-library updates, which after pre-validation only fail on unforeseen errors.
- **Residual (documented):** email and name are two independent single-row auth-library
  updates; true all-or-nothing across the boundary isn't achievable without co-locating those
  fields, which we deliberately don't do at ≤100-user scale. A name-fails-after-email case
  leaves email changed — logged, acceptable.

The wrong fix here is "wrap it in `em.transactional`": the auth-library write isn't on the
ORM connection, so the transaction wouldn't cover it — you'd get a false sense of atomicity.

---

## Testing

- **Concurrency/partial-failure paths run on the production engine.** A SQLite-only test of a
  lost-update guard is green theatre — SQLite serializes the writers the guard exists to
  separate. Run these on Postgres (a CI service container, or the dual-engine integration tier).
- **Prove the ordering.** For a cross-store sequence, test that a guard rejection (e.g. the
  most-fragile write failing) leaves the *other* fields **unchanged** — not just that the call
  returns an error. "Returns 422" and "wrote nothing" are different assertions; assert both.
- **Inject mid-sequence failure** where cheap, to exercise the residual you documented.

---

## Checklist (for the implementer, and the Architecture / Spec reviewers)

- [ ] Does this request perform **≥ 2 writes** or a **read-modify-write**? → it must be in a
      transaction (Layer 1) or follow the boundary pattern (Layer 2).
- [ ] Inside `transactional`, is **every** read/write on the transaction handle (not the outer
      `em`)?
- [ ] Is there a **read-modify-write** that needs a **lock / atomic statement / version**, not
      just a transaction?
- [ ] Does the sequence cross a **store boundary** (ORM ↔ auth lib ↔ object store ↔ external
      API)? → validate-first, order-most-fragile-first, **document the residual**.
- [ ] Are concurrency/partial-failure paths **tested on the production engine**, asserting
      "wrote nothing" — not just "returned an error"?

## Forbidden patterns (reviewer fast-fail)

- A handler/service with **≥ 2 writes or a read-modify-write** and **no transaction**.
- **Mixing the outer `em` with `txEm`** inside a `transactional` block.
- A **SELECT-then-mutate-then-flush** with no lock / atomic statement / version column on a
  row that concurrent requests can touch.
- A **multi-store sequence** with no validate-first ordering and **no documented residual**.
- A concurrency/partial-failure path **tested only on SQLite**.
</content>
