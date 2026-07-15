# CSRF Protection — Language Buddy

This project uses a **three-layer defence-in-depth** strategy instead of synchroniser tokens.
All three layers must be intact. An implementor or reviewer who sees "no CSRF token" should
verify the other two layers rather than flagging the absence of a token as a defect.

---

## The three layers

### Layer 1 — `SameSite=Lax` session cookie (cookie-level)

The `better-auth` session cookie is issued with `SameSite=Lax`. Under the Lax policy the
browser will not attach the cookie to a cross-site POST originating from another site, so a
forged form submission from `evil.com` arrives without a valid session and is rejected at
the auth preHandler before any handler code runs.

This is the primary backstop. It covers every POST in the app, including ones that forget to
call `assertHxRequest`.

**Limitation:** `SameSite=Lax` only protects against cross-*site* requests. A same-site but
cross-*origin* request (same registrable domain, different subdomain) would carry the cookie.
The origin-check layer closes this gap.

### Layer 2 — Origin-check preHandler (server-level)

`src/app.ts` registers a global `preHandler` hook on all state-changing methods
(POST / PUT / PATCH / DELETE). If the request carries an `Origin` header whose value does
not match `EXPECTED_ORIGIN` (`PUBLIC_URL` env var, default `http://localhost:3000`), the
server returns **403** immediately — before auth, before any handler.

```ts
// app.ts (simplified)
app.addHook('preHandler', async (request, reply) => {
  const method = request.method;
  if (method !== 'POST' && method !== 'PUT' && method !== 'PATCH' && method !== 'DELETE') return;
  const origin = request.headers.origin;
  if (origin && origin !== EXPECTED_ORIGIN) {
    return reply.status(403).send({ error: 'Cross-origin request blocked' });
  }
});
```

**Limitation:** browsers omit the `Origin` header on same-origin navigations and on some
older UA–initiated requests. The handler allows absent-`Origin` through and relies on Layer 1
(SameSite=Lax) as the backstop for those cases. If `Origin` is absent AND the cookie is
present, we are in a same-origin context by definition — no forgery is possible.

### Layer 3 — `assertHxRequest` custom header (per-route)

Every mutation POST handler calls `assertHxRequest(request, reply)` before any logic runs.
This function checks for the `HX-Request: true` header that HTMX adds automatically to every
request it initiates.

```ts
function assertHxRequest(request: FastifyRequest, reply: FastifyReply): boolean {
  if (request.headers['hx-request'] !== 'true') {
    reply.status(400).send({ error: 'HTMX request required' });
    return false;
  }
  return true;
}
```

A browser **cannot** send a custom request header cross-origin without a CORS preflight. This
server does not emit permissive CORS headers, so any preflight from a foreign origin is
rejected. This makes `HX-Request: true` an unforgeable proof of same-origin intent for
browser-initiated requests.

**Limitation:** non-browser API clients (curl, scripted attacks from a compromised same-origin
script) can set arbitrary headers. This layer defends against cross-origin browser-based
forgery, not against server-side or XSS-amplified attacks. XSS is mitigated separately via
CSP (see `src/app.ts` Content-Security-Policy header).

---

## Why no synchroniser token?

Token-based CSRF (the `<input type="hidden" name="_csrf">` pattern) adds implementation
surface: token generation, storage, validation, and the risk of token-replay or fixation
bugs. The three-layer approach above provides equivalent protection for this app's threat
model (browser-initiated attacks) without that surface, which is why HTMX's own documentation
endorses the custom header as a sufficient CSRF defence when combined with SameSite cookies.

If the app ever adds a REST API consumed by third-party clients or relaxes CORS policy, this
analysis must be revisited.

---

## Implementation rules

These rules are **mandatory for every mutation endpoint**. The A and T gates must verify them.

| Rule | Where enforced | Fast-fail signal |
|------|----------------|-----------------|
| Session cookie is `SameSite=Lax` | `auth.ts` / `better-auth` config — set once at boot | Cookie attribute missing on a new auth integration |
| `PUBLIC_URL` is set in prod deployment | Environment / deployment config | `EXPECTED_ORIGIN` defaulting to `localhost` in prod |
| Every mutation POST / PUT / PATCH / DELETE handler calls `assertHxRequest` **before** any logic | Per-route, checked in A review | A handler that reads `request.body` or calls a service without a preceding `assertHxRequest` call |

### Correct handler order

```ts
app.post('/some/mutation', async (request, reply) => {
  if (!request.user) return reply.redirect('/login');   // 1. auth
  if (!assertHxRequest(request, reply)) return;         // 2. CSRF layer 3
  await requireAdmin(request.em, request.user.id);      // 3. authorisation
  // ... handler logic
});
```

Never reorder these three guards. Auth first (avoids leaking information to unauthenticated
callers), then HTMX check, then role guard.

---

## Reviewer checklist

When reviewing a PR that adds or modifies a mutation route:

1. **Confirm `assertHxRequest` is called** — search the handler for the call before any DB read or write.
2. **Confirm the route is not `skipAuth`-ed accidentally** — `skipAuth` routes bypass the auth preHandler; a mutation on a skipped route would need its own session check.
3. **Confirm no permissive CORS headers are added** — `Access-Control-Allow-Origin: *` would undermine Layer 3.
4. **If the endpoint is a non-HTMX API** (e.g., a JSON REST endpoint for a mobile client) — document it explicitly and consider whether a synchroniser token or bearer token is needed instead.

---

## References

- `src/app.ts` — origin-check preHandler (search "CSRF defense-in-depth")
- `src/modules/admin/routes.ts` — `assertHxRequest` definition and usage pattern
- `src/auth.ts` — `SameSite=Lax` cookie configuration
- [HTMX security docs](https://htmx.org/docs/#security) — endorses custom header as CSRF defence
- OWASP: [Cross-Site Request Forgery Prevention Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Cross-Site_Request_Forgery_Prevention_Cheat_Sheet.html) — "Custom Request Headers" section
