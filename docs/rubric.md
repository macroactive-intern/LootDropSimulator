# Production-Ready Rubric — Loot Drop Simulator

## Scope

This rubric defines "production-ready" for a Laravel 12 JSON API backend. Each criterion is pass/fail with a measurable definition of pass. Partial credit is not awarded — a criterion either passes or it doesn't.

---

## Criteria

### 1. Type Safety

**Pass:** Every PHP file under `app/` contains `declare(strict_types=1)` as its first statement after `<?php`. All public and protected methods declare typed parameters and a return type. No implicit type coercions at function boundaries.

**Why it matters:** PHP silently coerces types without strict mode (e.g. `"1"` becomes `1`). In financial and inventory contexts this causes silent data corruption that does not surface as an exception.

---

### 2. Error Handling

**Pass:** Every distinct business failure mode is expressed as a named exception class or a `ValidationException` with a specific key. No raw `new \Exception(...)` thrown for business-logic errors. All exceptions that indicate internal invariant violations (e.g. calling escrow operations outside a transaction) use `LogicException` and are never caught and swallowed silently.

**Why it matters:** Undifferentiated exceptions make monitoring and alerting impossible — you cannot write a PagerDuty rule on "Exception" but you can on "EscrowIntegrityException".

---

### 3. Observability

**Pass:** Every state-changing operation emits at least one structured `Log::info()` entry with the entity ID and the actor ID. Covered operations: trade accept, trade reject, trade cancel, trade expire, loot drop.

**Why it matters:** Without log entries on state transitions, incident investigation requires full query-log analysis. With them, you can answer "what happened to trade #1234?" from a log aggregator in seconds.

---

### 4. Configuration

**Pass:** No magic numbers appear in application logic. All tunables — thresholds, limits, timeouts, ratios — live in a `config/*.php` file and are referenced via `config()`. A developer can change a limit without touching business logic.

Covered values:
- Loot pity threshold (consecutive commons before forcing rare+)
- Trade expiry window (hours)
- Maximum pending trades per user
- Trade fairness ratio
- Maximum guild memberships per user
- Guild invite expiry window (hours)

**Why it matters:** Hardcoded values require a code deploy to tune. Config values can be overridden per environment and changed without touching tested code paths.

---

### 5. Validation

**Pass:** No validation callback issues repeated DB queries for the same data within a single request. Inventory item lookups are cached for the lifetime of the request.

**Why it matters:** Validation that hits the database N times per item in a collection scales O(n) queries per request. With 10 items that's 20+ queries for a single trade proposal.

---

### 6. Data Integrity

**Pass:** Every operation that writes to more than one table is wrapped in `DB::transaction()`. Every read-then-write pattern acquires a `lockForUpdate()` before reading. Escrow operations assert they are inside a transaction before proceeding.

**Why it matters:** Without transactions and locks, concurrent requests can interleave writes, causing double-spending of inventory items, negative treasury balances, or duplicate guild memberships.

---

### 7. Security

**Pass:** No endpoint returns a 500 response with a stack trace to the client. All non-public endpoints are behind `auth:sanctum` middleware. Admin-only endpoints are additionally gated by the `EnsureUserIsAdmin` middleware. Authorization policies are applied for resource mutations.

**Why it matters:** Stack traces leak class names, file paths, and query structure to attackers. Unguarded endpoints allow privilege escalation.

---

### 8. API Consistency

**Pass:** HTTP status codes follow REST conventions throughout: `200` for reads, `201` for resource creation, `202` for accepted-but-async, `204` for no-content deletes, `422` for validation errors, `403` for authorization failures. Response shapes use API resources consistently — no controller returns raw arrays alongside resource objects.

**Why it matters:** Inconsistent status codes force API consumers to parse response bodies to detect success, making client error handling brittle.

---

### 9. Tests Pass

**Pass:** `composer test` exits with code 0. No tests are marked pending or skipped to hide known failures. Test coverage includes at least one test for each service method's happy path and primary failure path.

**Why it matters:** A test suite that only sometimes passes is not a quality signal — it is noise. Green tests are the prerequisite for every other criterion: you cannot confirm that type-safety changes, config changes, or logging additions did not break behavior without a passing suite.

---

### 10. No Hardcoded Environment Values

**Pass:** `.env.example` sets `APP_DEBUG=false`. Every key that is required for the application to start is annotated with `# REQUIRED`. No credentials, API keys, or secrets appear anywhere in tracked files.

**Why it matters:** `APP_DEBUG=true` in production exposes full exception details including stack traces and request data in HTTP responses. Shipping `.env.example` with `APP_DEBUG=true` means developers who copy it verbatim deploy with debug mode on.
