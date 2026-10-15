# Real-Time Auction Platform

A web-based live auction platform where multiple bidders compete on the same auction at the same time.
The backend is the single authority on bid validity, and every bid is processed under a database row lock.
The system supports proxy (automatic) bidding, dynamic bid increments, anti-sniping extensions,
private reserve prices and real-time updates over WebSockets.

## Stack

| Layer     | Technology                                                           |
|-----------|----------------------------------------------------------------------|
| Frontend  | React, TypeScript, Vite, React Router, TanStack Query                |
| Backend   | Laravel 13 (PHP 8.3+), Sanctum SPA auth, Laravel Reverb (WebSockets) |
| Database  | MySQL 8.4 (InnoDB)                                                   |
| Infra     | Redis (queue, cache, rate limiting), scheduler, queue worker, Docker |

## Repository layout

```
backend/            Laravel REST API
frontend/           React + TypeScript SPA (Vite)
docker/             Docker files and init scripts
docker-compose.yml  Local stack (MySQL, Redis, Mailpit, backend, frontend)
```

## Quick start with Docker

Requires only Docker Desktop.

```bash
docker compose up -d --build
```

| Service  | URL / address                     | Notes                                              |
|----------|-----------------------------------|----------------------------------------------------|
| Frontend | http://localhost:5173             | Vite dev server with hot reload                    |
| API      | http://localhost:8000             | Laravel; migrates and seeds itself on start        |
| Mailpit  | http://localhost:8025             | Catches outgoing mail                              |
| MySQL    | `127.0.0.1:3307`                  | `auction` / `secret`, for database GUI tools       |
| Redis    | internal only                     | Used by the backend container                      |

On start, the backend container installs Composer dependencies if missing, creates `.env` from
`.env.example` on a fresh clone, runs migrations and the (idempotent) seeders. Source folders are
bind-mounted, so code changes apply without rebuilding.

```bash
docker compose ps                 # status of every service
docker compose logs -f backend    # follow API logs
docker compose exec backend php artisan test
docker compose down               # stop (add -v to also delete the database volume)
```

Do not run `php artisan serve` or `npm run dev` on the host at the same time: they would compete for
ports 8000 and 5173.

## Local setup without Docker

### Prerequisites

- PHP 8.3+ with `pdo_mysql`, `pcntl`, `bcmath`
- Composer 2
- Node.js 22+ and npm
- Docker (for MySQL and Mailpit)
- Redis on `localhost:6379` (e.g. `brew install redis && brew services start redis`)

### 1. Infrastructure

Start only the database and mail services; PHP and Node run on the host.

```bash
docker compose up -d --wait mysql mailpit
```

| Service | Host port  | Notes                                                             |
|---------|------------|-------------------------------------------------------------------|
| MySQL   | 3307       | db `auction`, user `auction` / `secret`; test db `auction_test`   |
| Mailpit | 1025, 8025 | SMTP on 1025, web UI on http://localhost:8025                     |

The `auction_test` database is created by `docker/mysql/init/01-create-test-db.sql` the first time the
MySQL volume is initialised.

### 2. Backend

```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed   # schema + demo data
php artisan serve            # http://localhost:8000
```

`php artisan migrate:fresh --seed` rebuilds the database from scratch at any time.

#### Demo accounts

All demo accounts use the password `password` (local development only).

| Role   | Email                                          |
|--------|------------------------------------------------|
| Admin  | `admin@auction.test`                           |
| Bidder | `bidder1@auction.test` … `bidder5@auction.test` |

The seeders also create the bid increment tiers and one demo auction in each lifecycle state
(draft, scheduled, live, completed).

### 3. Frontend

```bash
cd frontend
npm install
cp .env.example .env
npm run dev                  # http://localhost:5173
```

## Running the tests

```bash
# Backend — runs against MySQL `auction_test`, never SQLite
cd backend && php artisan test

# Frontend
cd frontend && npm run typecheck && npm run lint && npm test -- --run
```

Backend tests deliberately use MySQL: the bid engine relies on `SELECT ... FOR UPDATE` row locks, which
SQLite does not support, so locking tests on SQLite would prove nothing. A guard test
(`tests/Feature/TestDatabaseTest.php`) fails if the suite is ever pointed at another database.

## Authentication

The SPA uses Laravel Sanctum's cookie-based session authentication, not bearer tokens, so no token is
ever stored in browser JavaScript. The browser first calls `GET /sanctum/csrf-cookie`, then
`POST /api/auth/login` or `POST /api/auth/register`. CORS allows credentialed requests only from
`FRONTEND_URL`. Self-registration always creates a bidder account; the admin account is seeded.

## Technical decisions

The sections below are filled in as each part of the system is built.

### Concurrency

Every bid is handled by `BidService` in **one database transaction that starts by locking the auction row**
(`SELECT ... FOR UPDATE`):

1. The lock is taken first. Concurrent bids on the same auction queue on this lock and run one at a time, in the order they
   acquire it. Bids on different auctions lock different rows and never wait for each other.
2. Only then is the server clock read and the bid validated against the locked row: time window (regardless of status, so a
   bid after `end_at` is refused even before the closing job runs), status, and the minimum next bid from
   `IncrementCalculator`. The client's idea of the price is never trusted.
3. The bid is inserted, `current_price` and `leader_id` are updated, and `BID_PLACED` / `LEADER_CHANGED` audit events are
   written in the same transaction, so either everything commits or nothing does.
4. Bid history is ordered by the auto-increment `id`, which is assigned while the lock is held, so the order is exactly the
   acceptance order.

If the lock cannot be obtained within a few seconds (`AUCTION_BID_LOCK_WAIT_SECONDS`) or MySQL reports a deadlock, the
transaction is retried (`AUCTION_BID_TRANSACTION_ATTEMPTS`) and finally answered with `409 BID_CONFLICT_RETRY`. Rejected
bids are recorded as `BID_REJECTED` after the rollback.

**Duplicate requests.** Bid requests carry an `Idempotency-Key` header (a UUID per user action). The key is claimed in its own
committed insert, guarded by a unique index on `(user_id, key)`, before the bid runs. A retry of a finished request replays the
stored response, a retry while the first is still running gets `409 DUPLICATE_REQUEST`, and reusing a key for a different
body gets `422`. Retryable outcomes (`5xx`, `429`, `BID_CONFLICT_RETRY`) are not stored, so they can be retried with the same
key.

Tests run on MySQL, never SQLite, because SQLite has no row locks. One test holds the auction lock from a second database
connection and proves that a blocked bid ends in `BID_CONFLICT_RETRY` without changing anything.

### Automatic (proxy) bidding

_To be documented with proxy bidding._

### Auction closing

_To be documented with the lifecycle jobs._

### Real-time updates

_To be documented with the Reverb integration._
