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
| Reverb   | ws://localhost:8080               | WebSocket server (Laravel Reverb)                  |
| Queue    | (background)                      | `queue:work`: start/close jobs and broadcasts      |
| Scheduler| (background)                      | `schedule:work`: start/close safety sweeps         |

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

# Only the parallel-process concurrency tests
cd backend && php artisan test --group=concurrency

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

**Concurrency tests with real parallel processes** (`tests/Feature/Concurrency`, group `concurrency`). Each test starts
several separate PHP processes with Laravel's Process pool. Every process boots the app with its own MySQL connection,
waits for a shared start time, then acts at the same instant against committed data:

- **Assignment example.** At price 50,000, A bids 51,000, B 51,500 and C 51,000 at once, on five auctions in parallel.
  Exactly one bid wins (whichever took the lock first). The others are rejected as too low, and price, leader, history and
  audit events agree.
- **Stress.** Eight processes repeatedly bid the minimum on one auction. Every stored bid respects the increment, ids
  follow acceptance order, and every accepted bid is stored exactly once.
- **Proxy.** A's auto-bid of 20,000, B's auto-bid of 15,000 and C's manual 12,000 arrive together. Every ordering ends
  with A leading at 15,500.
- **Duplicates.** The same HTTP request with the same `Idempotency-Key` is sent five times at once. One bid is placed;
  the other responses are a replay of the result or `409 DUPLICATE_REQUEST`.
- **Closing.** Three closers at once give exactly one `AUCTION_ENDED`. Bids racing the close from 60 ms before to 60 ms
  after `end_at` are either in the final result or rejected with nothing stored.

The tests were checked against a broken build: with `lockForUpdate()` removed they fail, with two winners and bids
accepted below the minimum.

### Automatic (proxy) bidding

A bidder can set a private maximum (`PUT /api/auctions/{id}/auto-bid`). The system then bids for them **only as much as
needed** to stay in the lead. The maximum is never broadcast, never returned to anyone but its owner, and never shown in
the public history.

The arithmetic lives in `ProxyBidResolver`, a pure class with no database access that is unit-tested on its own.
`BidService` calls it **inside the same auction row lock** as every other bid:

- **Defenders** are the other bidders' active maximums, plus the current leader's price if they lead with a manual bid.
  They are ranked by maximum (highest first), then by when that maximum was set (earliest first).
- **The challenger wins** if its amount beats the strongest defender. An auto-bid pays only
  `min(own max, defender max + increment(defender max))`. A manual bid is taken literally, at the amount the bidder
  typed.
- **The defender holds** otherwise. The challenger's bid is recorded, then the defender answers automatically with
  `min(defender max, challenger + increment(challenger))`. With equal maximums, whoever committed to the amount first
  wins, at that amount.
- **Updates.** Visible bids are inserted in order, and price and leader are updated. Auto-bids that can no longer place a
  valid bid are switched off (`AUTO_BID_MAX_REACHED`) so their owners can be told.

Example from the assignment, with an increment of 500: A's maximum is 20,000 and B's is 15,000. A leads at **15,500**:
B's bid shows at 15,000 and A answers with one increment. A's 20,000 stays private.

A leader can raise their maximum without placing a visible bid. A maximum can never be lowered, and it must be at least
the next valid bid (`422 MAX_AUTO_BID_REACHED`).

### Starting, anti-sniping and closing

**Starting.** Scheduling an auction queues a `StartAuctionJob` delayed until `start_at`. The job moves it from
SCHEDULED to LIVE under the auction row lock and records `AUCTION_STARTED`. As a safety net, `auctions:start-due` runs
every 10 seconds. Starting is idempotent: it only acts on a SCHEDULED auction whose start time has passed.

**Anti-sniping.** Inside the bid transaction, if an accepted bid arrives at or after `end_at - anti_snipe_seconds` and
`extensions_count < max_extensions`, then `end_at += extension_seconds` and `AUCTION_EXTENDED` is recorded. The
extension is added to the *current end*, not to "now". That way it can never shorten an auction, and the latest
possible end is always `original_end_at + max_extensions × extension_seconds`. `original_end_at` is kept.

**Closing.** When an auction goes live, a `CloseAuctionJob` is queued, delayed until `end_at`. `auctions:close-due`
runs every minute as a safety net. `AuctionCloser`:

1. locks the auction row, the same lock bids use, so a bid and the close never interleave;
2. does nothing unless the auction is still LIVE, so running it twice, or after a cancellation, is a no-op;
3. if `end_at` has moved because of an extension, does nothing; the job re-queues itself for the new end;
4. otherwise takes the leader and price from the locked row. These already encode increments, proxy resolution and
   ties, so they are the highest valid bid. It then checks the reserve, stores `result` (`SOLD`, `RESERVE_NOT_MET` or
   `NO_BIDS`), `winner_id` and `final_price` (only when sold), and records `AUCTION_ENDED` plus `WINNER_SELECTED` or
   `RESERVE_NOT_MET`.

Bids are refused once `now >= end_at` whatever the status, so the moments before the close job runs cannot accept a
late bid. The jobs use `ShouldBeUniqueUntilProcessing` to avoid duplicate pending jobs, but correctness relies only on
the locked status check.

### Bid history and audit trail

**Bids are insert-only.** There are no update or delete routes, and the `Bid` model refuses updates and deletes, so
history cannot be rewritten. The public history (`GET /api/auctions/{id}/bids`) shows amount, type (`MANUAL` or
`AUTO`), time and a per-auction pseudonym. "Bidder N" means the bidder's first bid was the Nth to arrive in that
auction. The number never changes because bids are never edited, and it differs per auction, so a person cannot be
followed across auctions. The viewer sees "You" for their own bids. User ids, emails and auto-bid maximums never
appear. Pages use a cursor, newest first, so new bids at the top do not shift the pages being read.

**Every state change writes an `auction_events` row in the same transaction.** Rejected bids are the exception: they
are written after the rollback. Each payload holds old and new values (price, leader, end time), amounts and reason
codes, so the events alone reproduce the final price, winner and end time; a test does exactly that. Admins read the
trail at `GET /api/admin/auctions/{id}/events`, filterable by type, with the actor's details or `null` for system
actions. They also have a bid history with real identities.

### Real-time updates

Live updates use **Laravel Reverb** (WebSockets, Pusher protocol). The React app connects with `laravel-echo`.

| Channel        | Who can join                                    | Events |
|----------------|-------------------------------------------------|--------|
| `auction.{id}` | anyone                                          | `BidPlaced`, `PriceChanged`, `LeaderChanged`, `AuctionExtended`, `AuctionStarted`, `AuctionEnded`, `AuctionCancelled` |
| `auctions`     | anyone                                          | `AuctionUpdated` (listing summary) |
| `user.{id}`    | only that user (`POST /api/broadcasting/auth`)  | `Outbid`, `AutoBidMaxReached`, `AuctionWon` |

- **One place for privacy.** `AuctionBroadcaster` builds every payload. Public events name bidders only by per-auction
  pseudonym ("Bidder 2"). They never carry user ids, emails, the reserve price or auto-bid maximums. A bidder's own
  maximum is sent only to them, on their private channel.
- **Sent only after commit.** Events are created inside the bid or lifecycle transaction but implement
  `ShouldDispatchAfterCommit`, so a rolled-back change is never announced. A test proves a rollback leaks nothing, and
  fails if after-commit is removed.
- **Ordering and recovery.** Every public event carries the auction's next `sequence` number. It is assigned under the
  auction row lock, so it follows the real order of changes. Events also carry `server_time`. The REST auction object
  includes the latest `sequence` and the viewer's own `my_pseudonym`. A client ignores events at or below the sequence
  it already has. It re-fetches the auction when it sees a gap or reconnects.
- **Delivery path.** Broadcasts are queued jobs (`ShouldBroadcast`), so a slow or unavailable WebSocket server never
  delays or fails a bid. The queue worker sends them to Reverb.
- **Origins.** Reverb accepts connections only from `REVERB_ALLOWED_ORIGINS` (default `localhost`).

The frontend's `VITE_REVERB_APP_KEY` must equal the backend's `REVERB_APP_KEY`.
