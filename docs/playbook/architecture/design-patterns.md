# System Architecture Design Patterns

Guidance for structuring applications with clean separation of concerns, predictable data flow, and minimal accidental complexity. These patterns apply to web applications with a TypeScript/Node.js backend and a React frontend, but the principles are language-agnostic.

---

## Layered Architecture

Every request flows through distinct layers. Each layer has one job and depends only on the layer below it.

```
┌─────────────────────────────────────┐
│  Presentation (React, HTML, CLI)    │  Renders data, captures user input
├─────────────────────────────────────┤
│  API / Route (Hono, Express, etc.)  │  HTTP concerns: parsing, status codes, auth
├─────────────────────────────────────┤
│  Service / Business Logic           │  Domain rules, orchestration, validation
├─────────────────────────────────────┤
│  Data Access (Drizzle, Prisma, etc.)│  Queries, mutations, migrations
├─────────────────────────────────────┤
│  Database (SQLite, Postgres, etc.)  │  Storage engine
└─────────────────────────────────────┘
```

### Rules

1. **Dependency direction is always downward.** Routes call services. Services call data access. Never the reverse.
2. **No layer skipping.** Routes must not run raw SQL. Presentation must not call data access directly.
3. **Each layer returns its own types.** The data access layer returns database rows. The service layer returns domain objects. The route layer returns HTTP responses. Map between them explicitly.
4. **Shared validation schemas live outside all layers.** Zod schemas (or equivalent) belong in a shared package so both client and server use the same contract without coupling.

### Monorepo Layout Mapping

For a `packages/` monorepo:

| Layer | Package | Example |
|-------|---------|---------|
| Shared validation + types | `packages/shared` | Zod schemas, TypeScript types, constants |
| Presentation | `packages/web` | React components, pages, hooks |
| API / Route | `packages/server/src/routes/` | Hono route handlers |
| Service | `packages/server/src/services/` | Business logic functions |
| Data Access | `packages/server/src/db/` | Drizzle schema, queries, migrations |

---

## Constants and Enums

### Where constants live

Constants belong in the **shared package** (`packages/shared/src/constants/`), organized by domain, not by type.

```
packages/shared/src/constants/
  exploit-states.ts      # workflow states with isTerminal flag
  severity-levels.ts     # critical, high, medium, low, informational
  artifact-types.ts      # writeup, advisory, cve-entry, pull-request, ...
  identifier-sources.ts  # cve, ghsa, nvd, internal
```

### Patterns

- **Derive types from constants, not the reverse.** Define the constant array first, then derive the TypeScript union type from it:

  ```typescript
  // Good: constant is the source of truth
  export const SEVERITY_LEVELS = ['critical', 'high', 'medium', 'low', 'informational'] as const;
  export type SeverityLevel = typeof SEVERITY_LEVELS[number];

  // Bad: type is defined separately from the constant
  type SeverityLevel = 'critical' | 'high' | 'medium' | 'low';
  const SEVERITY_LEVELS = ['critical', 'high', 'medium', 'low']; // can drift
  ```

- **Use Zod enums for validation, derived from the same constant:**

  ```typescript
  import { z } from 'zod';
  import { SEVERITY_LEVELS } from '../constants/severity-levels';

  export const severitySchema = z.enum(SEVERITY_LEVELS);
  ```

- **Never scatter magic strings.** Every string that appears in a conditional, switch, or comparison should trace back to a named constant. If you write `if (status === 'published')`, there must be a `WORKFLOW_STATES` constant that includes `'published'`.

- **Group related constants in objects when they carry metadata:**

  ```typescript
  export const WORKFLOW_STATES = {
    draft:     { label: 'Draft',     isTerminal: false },
    active:    { label: 'Active',    isTerminal: false },
    blocked:   { label: 'Blocked',   isTerminal: false },
    published: { label: 'Published', isTerminal: true  },
    rejected:  { label: 'Rejected',  isTerminal: true  },
    archived:  { label: 'Archived',  isTerminal: true  },
  } as const;

  export type WorkflowState = keyof typeof WORKFLOW_STATES;
  ```

---

## Schema as the Single Source of Truth

In a system with shared validation (Zod) and a database ORM (Drizzle), there are two schemas that must stay in sync. Establish a clear direction of authority:

```
Drizzle schema (database) ──── is the storage source of truth
         │
         ▼
Zod schemas (shared)     ──── are the contract source of truth
         │
         ▼
TypeScript types          ──── are inferred, never hand-written
```

### Rules

1. **Drizzle defines what the database stores.** Column types, constraints, defaults, and relations.
2. **Zod defines what the API accepts and returns.** Create, update, and patch variants. Zod schemas must be a projection of the Drizzle schema — they can omit fields (e.g., `id`, `created_at`) but must not invent fields that don't exist in the database.
3. **TypeScript types are always inferred.** Use `z.infer<typeof schema>` for API types and Drizzle's `$inferSelect` / `$inferInsert` for database types. Never hand-write a type that duplicates a schema.
4. **Schema variants follow a naming convention:**
   - `createExploitSchema` — fields required to create a new record
   - `updateExploitSchema` — fields that can be changed (all optional except id)
   - `patchExploitSchema` — partial update (like update, but everything optional)
   - `exploitResponseSchema` — what the API returns (includes computed fields)

---

## Dependency Direction and Imports

### The import rule

```
shared ← server
shared ← web
server ✗ web     (server never imports from web)
web    ✗ server  (web never imports from server — it calls the API)
```

Within a package, imports follow the layer order:

```
routes → services → db       (allowed)
db → services → routes       (forbidden)
components → hooks → api     (allowed in web)
api → hooks → components     (forbidden)
```

### Detecting violations

A circular dependency or upward import is always a design error:
- If a service needs to send an HTTP response, it's doing the route's job.
- If a route needs to construct a SQL query, it's doing the data access job.
- If a component needs to call the database, a hook or API layer is missing.

---

## Error Handling

### Structured error types

Define a small set of error types that map to HTTP status codes. Services throw domain errors; routes catch them and translate to HTTP responses.

```typescript
// packages/shared/src/errors.ts
export class NotFoundError extends Error {
  constructor(entity: string, id: string | number) {
    super(`${entity} not found: ${id}`);
    this.name = 'NotFoundError';
  }
}

export class ValidationError extends Error {
  constructor(public readonly issues: z.ZodIssue[]) {
    super('Validation failed');
    this.name = 'ValidationError';
  }
}

export class ConflictError extends Error {
  constructor(message: string) {
    super(message);
    this.name = 'ConflictError';
  }
}
```

### Route-level error translation

```typescript
// packages/server/src/middleware/error-handler.ts
app.onError((err, c) => {
  if (err instanceof NotFoundError) return c.json({ error: err.message }, 404);
  if (err instanceof ValidationError) return c.json({ error: err.message, issues: err.issues }, 400);
  if (err instanceof ConflictError) return c.json({ error: err.message }, 409);
  console.error('Unhandled error:', err);
  return c.json({ error: 'Internal server error' }, 500);
});
```

### Rules

1. **Never swallow errors silently.** Every `catch` must either re-throw, log, or return a meaningful error response.
2. **Never throw generic `Error`.** Use a domain-specific error class so the caller can distinguish between "not found" and "invalid input" without parsing the message string.
3. **Validate at the boundary, trust internally.** Zod validation happens once at the API route level. Services downstream can assume the data is valid.
4. **Never expose stack traces or internal details in API responses.** Log them server-side; return a sanitized message to the client.

---

## Common Anti-Patterns

### God objects and mega-files

**Symptom:** One file that handles routing, validation, database queries, and business logic.

**Fix:** Split into layers. A route handler should be 10-20 lines: parse input, call service, return response. If a file exceeds ~200 lines, it's likely doing more than one job.

### Shotgun surgery

**Symptom:** Adding a new field requires changes in 5+ files with no clear trail.

**Fix:** Derive types from schemas. If the Zod schema is the source of truth and TypeScript types are inferred, adding a field means changing the Zod schema and the Drizzle schema — the rest follows automatically.

### Leaky abstractions

**Symptom:** React components know about database column names. Route handlers return raw database rows with `created_at` timestamps in SQLite format.

**Fix:** Map between layers. The service returns domain objects; the route serializes them for HTTP; the frontend renders the API response shape.

### Premature abstraction

**Symptom:** A `BaseService<T>` generic class with 8 type parameters, used by 2 services.

**Fix:** Wait for the third instance before abstracting. Two similar things are a coincidence. Three similar things are a pattern. Use plain functions until the abstraction earns its complexity.

### Stringly-typed code

**Symptom:** `if (status === 'actve')` — a typo that passes TypeScript but breaks at runtime.

**Fix:** All domain values come from constants with derived union types. TypeScript catches the typo at compile time.

### The `any` escape hatch

**Symptom:** `as any` or `as unknown as SomeType` to make the compiler stop complaining.

**Fix:** Fix the actual type mismatch. If the type system is fighting you, the data flow is likely wrong. Common causes:
- Missing a schema variant (e.g., using the create schema where the response schema is needed)
- Layer violation (passing a database row directly to a component)
- Incomplete type narrowing (use discriminated unions or Zod `.parse()`)

Acceptable uses of `any`: FFI boundaries, third-party libraries with missing types (add a `.d.ts` file instead when possible).

### Circular dependencies

**Symptom:** Module A imports from B, B imports from A. Bundler warns or runtime crashes with undefined.

**Fix:** Extract the shared piece into a third module that both A and B import. In a monorepo, this often means it belongs in `packages/shared`.

### Non-atomic writes

**Symptom:** A handler does two or more writes (or a read-modify-write) with no transaction — a sequence of bare `flush()`es; or `SELECT → mutate → flush` on a row concurrent requests can touch; or a sequence that writes to **two stores** (ORM ↔ auth library, DB ↔ object store) and assumes all-or-nothing. Tests pass on SQLite and the race only shows on Postgres.

**Fix:** See [`transactions.md`](transactions.md). Multi-write → one `transactional` block (every read/write on the transaction handle). Read-modify-write → a row lock, an atomic `INSERT … ON CONFLICT … RETURNING`, or a version column — a transaction alone doesn't stop a lost update. Cross-store → validate-first, order the most-fragile write first, and document the residual non-atomicity; there is no transaction across two stores. Test concurrency/partial-failure on the production engine.

---

## Base Entity Pattern

For systems where all business objects share common metadata:

```typescript
// In Drizzle schema
const baseColumns = {
  id: integer('id').primaryKey({ autoIncrement: true }),
  createdAt: text('created_at').notNull().$defaultFn(() => new Date().toISOString()),
  updatedAt: text('updated_at').notNull().$defaultFn(() => new Date().toISOString()),
};

export const exploits = sqliteTable('exploits', {
  ...baseColumns,
  title: text('title').notNull(),
  // ... entity-specific fields
});
```

This ensures every table has consistent `id`, `created_at`, and `updated_at` columns without hand-repeating them.

---

## Service Layer Patterns

### Services are plain functions, not classes

Unless a service needs injected dependencies (for testability), prefer exported functions over class instances:

```typescript
// Good: simple, testable, no ceremony
export function getExploitsByProject(db: Database, projectId: number) {
  return db.select().from(exploits).where(eq(exploits.projectId, projectId));
}

// Good: class when injection is needed
export class EnrichmentService {
  constructor(private readonly httpClient: HttpClient) {}
  async lookupCve(cveId: string): Promise<CveData> { ... }
}

// Bad: class for the sake of class
export class ExploitService {
  getAll() { return db.select().from(exploits); }  // db is a module-level global
}
```

### Pass dependencies explicitly

Functions that need database access take the `db` parameter. Functions that need external services take the service as a parameter. This makes testing trivial — pass a real DB or an in-memory one, a real HTTP client or a stub.

---

## Frontend Patterns

### Component responsibility

| Type | Job | Knows about |
|------|-----|-------------|
| **Page** | Layout, data fetching orchestration | Routes, hooks |
| **Container** | Connects data to presentation | Hooks, child components |
| **Presentational** | Renders props, emits events | Props only |

### Data fetching lives in hooks

```typescript
// packages/web/src/hooks/use-exploits.ts
export function useExploits(projectId: number) {
  return useQuery({
    queryKey: ['exploits', projectId],
    queryFn: () => api.exploits.list(projectId),
  });
}
```

Components call hooks. Hooks call the API client. The API client calls `fetch`. No component should ever call `fetch` directly.

### API client is a typed wrapper

```typescript
// packages/web/src/api/exploits.ts
export const exploitsApi = {
  list: (projectId: number) =>
    fetchJson<ExploitResponse[]>(`/api/projects/${projectId}/exploits`),
  get: (id: number) =>
    fetchJson<ExploitResponse>(`/api/exploits/${id}`),
  create: (data: CreateExploit) =>
    fetchJson<ExploitResponse>('/api/exploits', { method: 'POST', body: data }),
};
```

---

## When to Abstract

| Signal | Action |
|--------|--------|
| Two things look similar | Note it. Do nothing. |
| Three things look similar | Extract a shared function or component. |
| You're writing a generic `Base<T>` with type parameters | Stop. Write the concrete version. Generalize when forced to. |
| A utility function has more than 3 parameters | It might be doing too much. Split or use an options object. |
| You want to add a config option | Ask: will this ever be set to anything other than the default? If no, remove it. |

---

## Checklist for New Features

Before writing code for a new feature, verify:

1. [ ] Which layer does this belong in? (Route? Service? Data access? Shared?)
2. [ ] Does a constant or enum need to be added to `packages/shared`?
3. [ ] Does the Drizzle schema need a new column or table?
4. [ ] Does a Zod schema need a new variant?
5. [ ] Are TypeScript types inferred (not hand-written)?
6. [ ] Does the import direction stay correct? (shared ← server, shared ← web)
7. [ ] Are errors handled with domain-specific error types?
8. [ ] Is the new code testable without mocking libraries? (Can you pass dependencies in?)

---

## Architecture Review Checklist

Use this checklist for the A step in the coding pipeline. A reviews the changed code against the actual codebase's dominant patterns; S reviews spec compliance, and T reviews executable behavior.

### Baseline Evidence

Before writing findings, A should identify the evidence baseline:

1. [ ] The issue or brief and F handoff were read.
2. [ ] The diff was inspected against the declared base branch.
3. [ ] Every changed source file was read in full.
4. [ ] Neighboring files that establish the local pattern were read.
5. [ ] Any relevant project architecture or planning docs were cited.
6. [ ] If the diff writes to the database: it was checked against [`transactions.md`](transactions.md) — multi-write → one transaction; read-modify-write → lock/atomic-statement/version; cross-store → validate-first + ordered + documented residual; concurrency tested on the production engine. A non-atomic write is a **block**.

### Drift Dimensions

| Dimension | A checks |
|---|---|
| Layering | Routes, services, repositories, entities, UI components, hooks, and clients keep their established responsibilities. |
| Dependency direction | Imports move toward lower-level/shared modules only; modules do not reach across ownership boundaries without an existing shared seam. |
| Naming and file structure | File names, directory placement, exported names, and module boundaries match neighboring code. |
| Pattern consistency | Validation, route registration, data access, transactions, error translation, and test helper placement follow the dominant local pattern. |
| Cross-cutting concerns | Auth, logging, validation, transactions, caching, and error handling are applied at the same layer as the rest of the codebase. |
| Abstraction level | New abstractions remove real duplication or match an established pattern; one-off generic layers are flagged. |

### Severity Rules

- **block** — architectural drift that should stop the pipeline before T runs: layer violation, wrong dependency direction, duplicated competing pattern, unsafe cross-module coupling, or a schema/contract placement that will make future changes inconsistent.
- **warn** — drift worth tracking but not enough to block: ambiguous local precedent, minor naming mismatch, or a pattern inconsistency that is contained and easy to fix later.

If there is no dominant pattern, A should not invent one. Mark the ambiguity as `warn`, cite the conflicting examples, and let O decide whether to open follow-up architecture work.

### What A Does Not Own

- Runtime correctness and test coverage belong to F/T.
- Final spec compliance, visual fidelity, and acceptance gating belong to S.
- Linter-level style belongs to automated tooling.
- Product decisions and ambiguous source-of-truth conflicts belong to O and the human operator.
