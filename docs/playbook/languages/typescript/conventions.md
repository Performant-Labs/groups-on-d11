# TypeScript Naming and Style Conventions

Org-wide standards for TypeScript projects. Framework-specific extensions live in their own convention files (`frameworks/fastify/`, `frameworks/mikro-orm/`, etc.) and apply on top of these rules.

---

## Identifiers

| Kind | Convention | Example |
|---|---|---|
| Variables and parameters | camelCase | `pageCount`, `sessionCookie` |
| Functions and methods | camelCase, verb-first | `buildApp`, `resolveProject`, `listRuns` |
| Classes | PascalCase | `RunsService`, `AuthPlugin` |
| Interfaces | PascalCase, **no `I` prefix** | `RunsPage`, `FilterOptions` |
| Type aliases | PascalCase | `RunStatus`, `LayoutOption` |
| Enums | PascalCase (name); UPPER_SNAKE_CASE (members) | `enum Direction { NORTH, SOUTH }` |
| Constants (module-level) | UPPER_SNAKE_CASE | `MAX_PAGE_SIZE`, `DEFAULT_PER_PAGE` |
| Zod schemas | PascalCase + `Schema` suffix | `RunsQuerySchema`, `CreateProjectSchema` |
| Inferred types from Zod | PascalCase, no suffix | `RunsQuery`, `CreateProject` |
| React/Vue/Eta components | PascalCase for files | `RunCard.vue`, `RunCard.eta` |
| Composables / hooks | camelCase, `use` prefix | `useRunFilter`, `useSession` |
| Test files | Same name as source + `.test.ts` or `.spec.ts` | `runs.test.ts`, `runs.spec.ts` |

**No `I` prefix for interfaces.** The TypeScript team removed this recommendation in 2020; it adds noise without clarity. Use a descriptive noun: `RunsPage`, not `IRunsPage`.

---

## Booleans

Prefix boolean variables, parameters, and properties with `is`, `has`, `can`, or `should`:

```typescript
const isAuthenticated = true;
const hasEmailVerified = user.emailVerified !== null;
const canDeleteRun = user.role === 'admin';
const shouldRedirect = project === null;
```

Never name a boolean by the thing it describes alone — `admin`, `verified`, `redirect` are ambiguous about their type. The prefix makes the type self-documenting.

---

## Functions

- **Verb-first:** `getUser`, `buildFixture`, `resolveProject`, `validateSchema`
- **Factory functions** that return an instance: `build*` or `create*` — `buildApp`, `createTestRun`
- **Predicate functions** (return boolean): `is*`, `has*`, `can*` — `isHtmxRequest`, `hasSession`
- **Event handlers** (passed as callbacks): `on*` or `handle*` — `onRequest`, `handleError`
- **Async functions**: no special suffix — the return type `Promise<T>` is sufficient

Avoid generic suffixes like `doX`, `processX`, `handleX` (unless it really is a generic handler). A name like `processRun` tells you nothing; `ingestTestRun` tells you exactly what it does.

---

## Classes

- **Service classes** (business logic): `*Service` — `RunsService`, `DashboardService`
- **Plugin classes / Fastify plugins**: `*Plugin` — only when wrapping a library; most Fastify plugins are plain `async (fastify) =>` functions without a class
- **Error classes**: `*Error` — `NotFoundError`, `SetupRequiredError`
- **No `Manager`, `Handler`, `Controller` suffixes** unless the framework mandates it — these are usually signals that a class is doing too much

---

## Files and modules

- **One primary export per file, file name matches export name** (kebab-case of the class/function):
  - `RunsService` → `runs-service.ts`
  - `buildApp` → `app.ts` (factory is named after what it builds)
- **Module directories** use the feature name in kebab-case: `src/modules/test-runs/`
- **Index files** (`index.ts`) only when a directory is a public package boundary — never inside `src/` to avoid circular imports
- **Never `utils.ts`** as a file name. Name the file after the domain: `date-helpers.ts`, `slug.ts`, `pagination.ts`

---

## Private and protected members

Prefer **`#` private fields** (ECMAScript private) over the `private` keyword for true encapsulation. Use the `private` keyword only when `#` is incompatible (e.g., decorators, MikroORM entity properties):

```typescript
class RunsService {
  #cache = new Map<number, TestRun>();   // truly private, not accessible via cast

  private em: EntityManager;            // acceptable when # is incompatible
}
```

Do **not** use `_` prefix (e.g., `_privateField`) — it is a convention with no enforcement. If a field must be private, use `#` or `private`.

---

## Type aliases vs interfaces

| Use | Prefer |
|---|---|
| Object shapes that may be extended by external code | `interface` |
| Object shapes internal to a module | `type` |
| Union types, intersection types, mapped types | `type` (interfaces cannot express these) |
| Function signatures | `type` |
| Fastify augmentations (`declare module 'fastify'`) | `interface` (required for declaration merging) |

When in doubt, use `type` — it is more expressive and avoids accidental extension by other modules.

---

## Constants vs enums

Prefer **`as const` object/array constants** over TypeScript enums. Enums have surprising runtime behaviour (numeric enums, reverse mapping) and are harder to tree-shake:

```typescript
// Preferred: const + derived union type
export const RUN_STATUSES = ['passed', 'failed', 'running', 'pending', 'error'] as const;
export type RunStatus = typeof RUN_STATUSES[number];

// Avoid: TypeScript enum
enum RunStatus { Passed = 'passed', Failed = 'failed' }
```

Use `as const` objects when members carry metadata:

```typescript
export const LAYOUTS = {
  interior: { requiresAuth: true },
  exterior: { requiresAuth: false },
} as const;
export type Layout = keyof typeof LAYOUTS;
```

---

## Zod schema naming

Schema naming follows the operation, not the entity alone:

| Schema purpose | Name pattern | Example |
|---|---|---|
| Query / filter params | `*QuerySchema` | `RunsQuerySchema` |
| Create body | `Create*Schema` | `CreateProjectSchema` |
| Patch / partial update | `Patch*Schema` | `PatchProjectSchema` |
| Full replacement | `Update*Schema` | `UpdateSettingsSchema` |
| API response shape | `*ResponseSchema` | `RunResponseSchema` |

Inferred types drop the `Schema` suffix: `type RunsQuery = z.infer<typeof RunsQuerySchema>`.

Keep schemas in `src/modules/{feature}/schemas.ts`, never inline in route handlers.

---

## What not to name things

| Pattern | Problem | Alternative |
|---|---|---|
| `data`, `info`, `payload`, `result` | No meaning — could be anything | Name the domain: `run`, `sessionCookie`, `signUpResponse` |
| `temp`, `tmp`, `foo`, `bar` | Debug leftovers | Remove, or use the real name |
| `IFoo` interface prefix | Noise, TypeScript team removed this guidance | `Foo` |
| `_unused` parameter | Suppresses TS error rather than fixing it | Remove the parameter or fix the caller |
| `utils.ts` | Grab-bag that grows forever | Domain-named file: `slug.ts`, `pagination.ts` |
| `manager`, `handler`, `processor` class suffixes | Vague — masks what the class does | Use a precise verb noun: `RunIngester`, `SessionResolver` |
