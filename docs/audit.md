# Pre-Fix Audit — Loot Drop Simulator

Audit performed against `docs/rubric.md` on 2026-06-15, branch `NateMccL9`, commit `101ee14`.

---

## Results

| # | Criterion | Result | Finding |
|---|-----------|--------|---------|
| 1 | Type Safety | ❌ FAIL | 0 of 61 `app/` PHP files contain `declare(strict_types=1)` |
| 2 | Error Handling | ✅ PASS | All business failures use `ValidationException` with specific keys; `LogicException` for escrow invariant; `UnexpectedValueException` / `InvalidArgumentException` for domain guards — no raw `\Exception` |
| 3 | Observability | ❌ FAIL | `TradeService` emits no log entries; 4 of 5 required state-change operations (accept, reject, cancel, expire) are invisible in logs |
| 4 | Configuration | ❌ FAIL | Six tunables hardcoded in business logic — see detail below |
| 5 | Validation | ✅ PASS | `ProposeTradeRequest` caches the inventory query in `$this->inventoryItems` — single DB round-trip per request |
| 6 | Data Integrity | ✅ PASS | All multi-table writes are transactional; row locks acquired before all read-then-write paths; `EscrowService` guards against being called outside a transaction |
| 7 | Security | ✅ PASS | Two intentionally public endpoints (`GET /loot-drops/global-stats`, `POST /guilds/invites/{token}/accept`); all others behind `auth:sanctum`; admin route additionally gated by `EnsureUserIsAdmin`; all resource mutations go through `Gate::authorize()` with `GuildPolicy` |
| 8 | API Consistency | ❌ FAIL | `LootController` returns raw `response()->json()` arrays on three methods while returning `DroppedItemResource` on two others in the same controller |
| 9 | Tests Pass | ✅ PASS | 216 tests, 822 assertions, all green — `4.52s` |
| 10 | No Hardcoded Env Values | ❌ FAIL | `.env.example` ships `APP_DEBUG=true`; `APP_KEY=` has no `# REQUIRED` annotation |

**Summary: 5 fail, 5 pass.**

---

## Criterion 1 — Detail: Type Safety

All 61 PHP files under `app/` were checked:

```bash
grep -rL "declare(strict_types=1)" app --include="*.php"
# Result: 61 files, 0 with the declaration
```

Method signatures across services and controllers are well-typed (typed parameters and return types are present throughout). The sole failure is the missing file-level `declare(strict_types=1)`, which means PHP does not enforce those type declarations at call boundaries.

---

## Criterion 3 — Detail: Observability

`LogLootDrop` listener correctly emits a structured `Log::info('Loot dropped', [...])` with `dropped_item_id`, `user_id`, and other context — the loot drop case passes.

`TradeService` covers five state transitions. Coverage before fixes:

| Method | Log emitted |
|--------|-------------|
| `propose()` | None |
| `accept()` | None |
| `reject()` | None |
| `cancel()` | None |
| `expireIfPending()` | None |

All five transitions are silent in the log channel.

---

## Criterion 4 — Detail: Configuration

Six values are hardcoded directly in business logic with no config file backing:

| Hardcoded value | Location | Controls |
|-----------------|----------|----------|
| `10` | `LootService::shouldForceRareOrHigher()` | Consecutive commons before pity trigger |
| `24` | `TradeService::propose()` | Trade expiry window in hours |
| `10` | `TradeService::MAX_PENDING_TRADES_PER_USER` (class constant) | Max pending trades per user |
| `0.75` | `ProposeTradeRequest::validateFairTradeValue()` | Minimum value ratio for a valid trade |
| `5` | `GuildService::joinGuild()` and `acceptLockedInvite()` | Max guild memberships per user |
| `48` | `GuildService::createInvite()` | Guild invite expiry in hours |

The class constant `MAX_PENDING_TRADES_PER_USER` does avoid the literal appearing in multiple places, but it is still not environment-overridable config.

---

## Criterion 8 — Detail: API Consistency

`LootController` mixes response shapes within the same controller:

| Method | Response type |
|--------|--------------|
| `store()` | `response()->json(['success' => true, 'message' => '...'], 202)` — raw array |
| `index()` | `DroppedItemResource::collection(...)` — API resource |
| `stats()` | `response()->json(['user_id' => ..., 'total_drops' => ...])` — raw array |
| `globalStats()` | `response()->json(['total_drops' => ..., 'legendary_count' => ...])` — raw array |
| `grant()` | `(new DroppedItemResource(...))->response()->setStatusCode(201)` — API resource |

`store()` is an async job dispatch — no `DroppedItem` exists to wrap at response time, so the 202 acknowledgment is architecturally justified. `stats()` and `globalStats()` return structured data that has no resource wrapper, while the same controller uses `DroppedItemResource` for entity responses. This is the inconsistency the criterion targets.

All other controllers (`GuildController`, `TradeController`, `GuildMemberController`, `GuildTreasuryController`, `GuildInviteController`, `InventoryController`) use API resources consistently and do not mix shapes.

---

## Criterion 10 — Detail: .env.example

```
APP_KEY=            ← no annotation; app will not boot without this value
APP_DEBUG=true      ← ships as true; developer who copies verbatim runs in debug mode
```

`APP_DEBUG=true` causes Laravel to return full stack traces and request data in HTTP error responses.
