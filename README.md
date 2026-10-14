# Real-Time Auction Platform

A web-based live auction platform where multiple bidders compete on the same auction at the same time.
The backend is the single authority on bid validity, and every bid is processed under a database row lock.
The system supports proxy (automatic) bidding, dynamic bid increments, anti-sniping extensions,
private reserve prices and real-time updates over WebSockets.

## Stack

| Layer     | Technology                                                          |
|-----------|---------------------------------------------------------------------|
| Frontend  | React, TypeScript, Vite, React Router, TanStack Query               |
| Backend   | Laravel (PHP 8.3+), Sanctum SPA auth, Laravel Reverb (WebSockets)   |
| Database  | MySQL 8.4 (InnoDB)                                                  |
| Infra     | Redis (queue, cache, rate limiting), scheduler, queue worker, Docker |

## Repository layout

```
backend/    Laravel REST API
frontend/   React + TypeScript SPA
docker/     Docker files and init scripts
docs/       API docs, decisions, ER and architecture diagrams
```

Setup instructions are added as each part of the system lands.
