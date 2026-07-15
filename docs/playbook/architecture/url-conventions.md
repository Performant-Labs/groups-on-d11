# URL Conventions

Org-wide standards for route paths, query parameters, and path parameters across all web projects. Projects may add project-specific extensions but must not contradict these rules.

---

## Route paths

- **Lowercase, kebab-case only.** Never camelCase or snake_case in a path segment: `/test-runs`, `/forgot-password`, `/reset-password`
- **Plural nouns for resource collections.** A path that addresses many items uses the plural: `/runs`, `/projects`, `/organizations`
- **Singular for singletons and auth-flow pages.** Dashboard, setup wizard, and auth pages are not collections: `/dashboard`, `/login`, `/setup`, `/logout`
- **Sub-resources extend the parent collection path.** A resource nested under another uses the parent's collection path as the prefix: `/runs/:id`, `/projects/:slug/settings`
- **API routes are prefixed `/api/`.** Browser-rendered HTML routes have no prefix. Machine-readable JSON responses always live under `/api/`: `/api/ingest`, `/api/auth/*`, `/api/v1/*`
- **No trailing slash.** `/runs` is canonical; `/runs/` returns 301 or 404
- **Versioning under `/api/`.** When a breaking API change is needed, add a version segment: `/api/v1/runs`, `/api/v2/runs`. Browser routes are not versioned

---

## Path parameters

| Parameter type | Format | Example |
|---|---|---|
| Numeric integer ID | `:id` — raw integer | `/runs/42` |
| User-addressable slug | `:slug` — lowercase, hyphens only | `/projects/my-project` |

**Slug rules:**
- Lowercase letters, digits, and hyphens only — no underscores, no dots
- Maximum 60 characters
- Generated from a display name by lowercasing and replacing non-alphanumerics with hyphens: `"My Project!"` → `my-project`
- Slugs are stable after creation; renaming the display name does not automatically change the slug

Use integer IDs for internal/system resources (test runs, ingest events, artifacts). Use slugs for user-addressable resources that appear in the browser bar and may be shared externally (organizations, projects, teams).

---

## Query parameters

- **snake_case always.** Never camelCase: `per_page`, `sort_by`, `created_at`
- **Pagination:** `page` (1-indexed integer, default `1`) and `per_page` (integer, default `20`, max `100`)
- **Date ranges:** `from` and `to` in `YYYY-MM-DD` format — the server interprets them as start-of-day and end-of-day in UTC unless the route specifies otherwise
- **Enum filters** use the exact entity field name and value: `status=passed`, `status=failed`
- **Text filters** use the entity field name: `environment=ci`, `reporter=jest`
- **Sort:** `sort=field_name` (ascending) or `sort=-field_name` (descending with leading minus). Default sort is specified per-route
- **Boolean flags:** `1`/`0` or `true`/`false` are both acceptable; Zod coercion normalizes them. Prefer `true`/`false` in generated URLs

### Pagination defaults by resource

| Resource | Default `per_page` | Max `per_page` |
|---|---|---|
| Test runs | 20 | 100 |
| Test cases | 50 | 200 |
| API token list | 20 | 100 |

---

## HTTP methods

| Method | Semantics | Notes |
|---|---|---|
| `GET` | Read — collection or single resource | Safe, idempotent |
| `POST` | Create a new resource | Also used for non-CRUD actions that don't fit a resource model (e.g. `/logout`) |
| `PATCH` | Partial update — only the fields provided | Prefer over `PUT` for partial updates |
| `PUT` | Full replacement | Only when a client always sends the entire resource body |
| `DELETE` | Remove a resource | Returns `200` with a status fragment (HTMX) or `204` (JSON API) |

**HTMX forms** that need `PATCH` or `DELETE` must use `hx-patch` / `hx-delete` on the triggering element — never method-override hidden inputs.

---

## Response format by path prefix

| Path prefix | Requester | Response format |
|---|---|---|
| `/api/*` | Machine (ingest, REST client) | JSON |
| anything else | Browser (direct nav or HTMX) | HTML (Eta template) |

Error responses follow the same rule: `/api/*` errors return JSON `{ error: string }` with the appropriate status code; browser route errors return an HTML error page via `reply.page('error', ..., { layout: 'exterior' })`.
