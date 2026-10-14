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
docker-compose.yml  Local infrastructure (MySQL, Mailpit)
```

## Local setup

### Prerequisites

- PHP 8.3+ with `pdo_mysql`, `pcntl`, `bcmath`
- Composer 2
- Node.js 20+ and npm
- Docker (for MySQL and Mailpit)
- Redis on `localhost:6379` (e.g. `brew install redis && brew services start redis`)

### 1. Infrastructure

```bash
docker compose up -d --wait
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

_To be documented with the bid engine._

### Automatic (proxy) bidding

_To be documented with proxy bidding._

### Auction closing

_To be documented with the lifecycle jobs._

### Real-time updates

_To be documented with the Reverb integration._
