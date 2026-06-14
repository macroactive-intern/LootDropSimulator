# Loot Drop Simulator

A Laravel 12 JSON API for a game loot and trading system. Players roll for items, trade them peer-to-peer with escrow protection, and join guilds with treasury management.

## Requirements

- PHP 8.2+
- Composer
- Node.js 18+ and npm
- SQLite (default) or MySQL

## Quick start (fresh clone)

```bash
composer setup
```

This single command does everything:

1. `composer install` — installs PHP dependencies
2. Copies `.env.example` → `.env` (if `.env` does not already exist)
3. `php artisan key:generate` — sets `APP_KEY`
4. `php artisan migrate --force` — runs all database migrations
5. `npm install` — installs JS dependencies
6. `npm run build` — compiles front-end assets

## Running the dev server

```bash
composer dev
```

Starts three processes concurrently:

| Process | Description |
|---------|-------------|
| `php artisan serve` | HTTP server on `http://localhost:8000` |
| `php artisan queue:listen` | Processes queued loot drop jobs |
| `npm run dev` | Vite asset watcher |

## Running tests

```bash
composer test
```

## API overview

All endpoints are under `/api`. Requests require `Accept: application/json`.

Authentication uses Laravel Sanctum bearer tokens. Obtain a token through your own auth flow (Sanctum token creation is outside this simulator's scope).

### Loot

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| `POST` | `/api/loot-drop` | Required | Queue a loot roll for the authenticated user |
| `GET` | `/api/loot-drops` | Required | List your dropped items (paginated, filterable by `?rarity=`) |
| `GET` | `/api/loot-drops/stats` | Required | Your loot stats (total drops, legendary count, pity counter) |
| `GET` | `/api/loot-drops/global-stats` | None | Aggregate drop counts across all users |
| `POST` | `/api/admin/loot-grant` | Admin | Grant a specific item to a user |

### Trades

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| `POST` | `/api/trades` | Required | Propose a trade |
| `GET` | `/api/trades` | Required | List your trades (filterable by `?status=`) |
| `GET` | `/api/trades/{id}` | Required | Get a single trade |
| `POST` | `/api/trades/{id}/accept` | Required | Accept a trade (recipient only) |
| `POST` | `/api/trades/{id}/reject` | Required | Reject a trade (recipient only) |
| `POST` | `/api/trades/{id}/cancel` | Required | Cancel a trade (initiator only) |

### Guilds

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| `GET` | `/api/guilds` | Required | List guilds |
| `POST` | `/api/guilds` | Required | Create a guild |
| `GET` | `/api/guilds/{id}` | Required | Get a guild |
| `PUT` | `/api/guilds/{id}` | Required | Update a guild (leader only) |
| `DELETE` | `/api/guilds/{id}` | Required | Delete a guild (creator + leader only) |
| `POST` | `/api/guilds/{id}/join` | Required | Join an open guild |
| `POST` | `/api/guilds/{id}/leave` | Required | Leave a guild |
| `DELETE` | `/api/guilds/{id}/members/{userId}` | Required | Kick a member (leader/officer only) |
| `PUT` | `/api/guilds/{id}/members/{userId}` | Required | Change a member's role (leader only) |
| `POST` | `/api/guilds/{id}/treasury/deposit` | Required | Deposit gold into treasury |
| `POST` | `/api/guilds/{id}/treasury/withdraw` | Required | Withdraw gold from treasury (leader only) |
| `POST` | `/api/guilds/{id}/invites` | Required | Send an invite by email (leader/officer) |
| `POST` | `/api/guilds/invites/{token}/accept` | None | Accept an invite via token link |
| `GET` | `/api/guilds/{id}/events` | Required | Audit log for the guild (leader/officer) |

### Inventory

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| `GET` | `/api/inventory` | Required | List your inventory items |

## Configuration

Tunables live in `config/` and can be overridden via environment variables:

| Config key | Default | Description |
|------------|---------|-------------|
| `loot.pity_threshold` | `10` | Consecutive common drops before a rare+ is forced |
| `loot.guild_leader_legendary_multiplier` | `2.0` | Legendary weight multiplier for guild leaders |
| `trade.expiry_hours` | `24` | Hours until a pending trade expires |
| `trade.max_pending_per_user` | `10` | Max pending trades a user can have at once |
| `trade.fairness_ratio` | `0.75` | Minimum value ratio for a trade to be accepted |
| `guild.max_guilds_per_user` | `5` | Max guilds a user can belong to |
| `guild.invite_expiry_hours` | `48` | Hours until an invite link expires |

## CI

GitHub Actions runs on every push and pull request:

1. **Pint** — code style check (`./vendor/bin/pint --test`)
2. **PHPStan** — static analysis at level 5 (`./vendor/bin/phpstan analyse --level=5`)
3. **Pest** — test suite (`php artisan test`)

See `.github/workflows/ci.yml`.
