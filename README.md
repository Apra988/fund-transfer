# Fund Transfer API — Symfony

Minimal, production-minded HTTP API to **transfer funds between accounts** with durable balances in **MySQL**, **Symfony 7**, and **Redis** for caching, **idempotent writes**, and **distributed rate limiting**. Includes **integration tests**, Docker Compose, and clear setup steps.

---

## Repository name (GitHub suggestion)

Recommended: `**symfony-fund-transfer-api`**  

Alternatives: `**fund-transfer-api`**, `**php-symfony-fund-transfer**` (matches stack + intent; easy to discover in your profile.)

---

## GitHub “About” description (paste into the repo field)

**Short (one line):**  
`Symfony 7 fund-transfer API — MySQL + Redis, pessimistic locking, idempotency, rate limits, integration tests & Docker.`

**Slightly longer (if the UI allows ~250 characters):**  
`Homework/demo API: synchronous transfers between accounts. PHP 8.2+, Symfony 7, Doctrine ORM, MySQL 8.x, Redis. Row-level locking + ordered locks, optional Idempotency-Key, Redis-backed limits for multi-replica setups, PHPUnit + DAMA Doctrine Test Bundle, Docker Compose.`

---

## Requirements

- PHP 8.2+ with extensions: `pdo_mysql`, `bcmath`, `ctype`, `iconv`
- Composer 2
- Docker (recommended) for MySQL 8.4 and Redis 7

---

## Quick start

### 1. Install dependencies

```bash
composer install
```

### 2. Start infrastructure

From the project root:

```bash
docker compose up -d
```

This starts:

- MySQL on `127.0.0.1:3306` (user `app`, password `app`, database `app`)
- Redis on `127.0.0.1:6379`

### 3. Configure environment

`.env` defaults match `docker-compose.yml`. Override secrets in `.env.local` if needed.


| Variable                    | Purpose                                                              |
| --------------------------- | -------------------------------------------------------------------- |
| `DATABASE_URL`              | MySQL DSN (see `.env`)                                               |
| `REDIS_URL`                 | Redis DSN for caches and distributed rate-limit state (`redis://…`)  |
| `TRANSFER_WRITE_RATE_LIMIT` | Max `POST /api/transfers` per client IP per minute (default **200**) |
| `API_KEY`                   | Optional; when set, require header `X-API-Key`                       |
| `APP_SECRET`                | Symfony secret (change in production)                                |


### 4. Run migrations

```bash
php bin/console doctrine:migrations:migrate --no-interaction
```

### 5. Run the application

```bash
php -S 127.0.0.1:8000 -t public
```

Or use the [Symfony CLI](https://symfony.com/download): `symfony server:start`

### 6. Seed demo accounts (optional)

```bash
php bin/console app:seed-demo-accounts
```

The command prints two UUID account identifiers and starting balances in **minor units** (integer strings, e.g. cents).

---

## API

Base URL: `http://127.0.0.1:8000` (when using the PHP built-in server above).

### `GET /api/health`

Liveness check.

### `GET /api/accounts/{uuid}`

Returns `accountId` and `balanceMinor` (string integer). Balance reads use Redis with a short TTL and are invalidated after transfers.

### `POST /api/transfers`

Request JSON:

```json
{
  "fromAccountId": "550e8400-e29b-41d4-a716-446655440000",
  "toAccountId": "6ba7b810-9dad-11d1-80b4-00c04fd430c8",
  "amountMinor": "1500"
}
```

Headers:

- `Content-Type: application/json`
- `Idempotency-Key` (optional, max 255 chars): same key + body replays the original transfer result without double-posting.

Responses use **201 Created** with:

```json
{
  "transferId": "...",
  "fromAccountId": "...",
  "toAccountId": "...",
  "amountMinor": "1500",
  "createdAt": "2026-05-02T12:00:00+00:00"
}
```

Validation and domain errors return JSON with `errors` or `error` and HTTP status codes such as **400**, **404**, **422**, and **429**.

### Rate limiting

`POST /api/transfers` uses a **fixed window** (`TRANSFER_WRITE_RATE_LIMIT` hits per client IP per minute). Limiter state is stored in Redis (`rate_limiter.transfer_write`), so quotas stay coherent across multiple app replicas.

In `**test`**, the limit is lower (see `config/packages/framework.yaml`) so integration tests can assert **429**. Those tests rely on Redis staying up so counters survive sequential sub-requests inside one test method.

### Optional API key

If `API_KEY` is non-empty, every `/api/`* route except `GET /api/health` requires:

```http
X-API-Key: <your-key>
```

---

## Design choices

- Amounts use **minor units as strings** and `**bcmath`** to avoid floating-point mistakes.
- Transfers run in a **single DB transaction** with **pessimistic row locks** on both accounts in **deterministic order** (lower internal id first) to reduce deadlocks.
- **Idempotency**: unique `idempotency_key` at the persistence layer plus Redis caching for faster replays when the optional header is supplied.
- **Reads under load**: short-TTL Redis cache for account balances with invalidation after successful transfers.
- **Indexes**: e.g. `transfer.created_at` for time-window queries (`migrations/Version20260202120000`).

---

## Tests

Create the test database and migrate (after Docker MySQL is up):

```bash
composer database:test:create
composer database:test:migrate
```

If `database:test:create` reports access denied on `app_test`, either recreate the Docker volume so `docker/mysql/init-test-db.sql` runs, or grant manually:

```bash
docker compose exec mysql mysql -uroot -proot -e "CREATE DATABASE IF NOT EXISTS app_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL PRIVILEGES ON app_test.* TO 'app'@'%'; FLUSH PRIVILEGES;"
```

Run tests:

```bash
composer test
# or: php vendor/bin/phpunit
```

Requirements:

- **MySQL**: `DATABASE_URL` in `.env.test` must use base database name `**app`**; Symfony’s `dbname_suffix` in `test` yields `**app_test`**.
- **Redis**: tests that assert POST rate limiting need Redis (`docker compose up -d redis`).
- **DAMA Doctrine Test Bundle** wraps integration tests in a transaction when possible.

---

## Further improvements (if this were extended to production)

- Strong authentication (OAuth2/JWT, mTLS), authorization, immutable ledger / double-entry bookkeeping, and audit trails.
- Outbox + async workers for side effects (notifications, reconciliation).
- Formal OpenAPI, contract tests, and observability (metrics, traces, structured audit logs tied to correlation IDs).

---

## Homework / submission metadata

Adjust these lines to reflect **your** work before emailing the interviewer.

- **Time spent:** *(e.g. ~4 hours — replace with your real number)*  
- **AI tools:** *(e.g. Cursor + Claude; note that AI-assisted code was reviewed and you can explain architecture and trade-offs.)*

**Suggested pre-submit sanity check:** `docker compose up -d` → migrations on `app` and `app_test` → `composer test`.

---

## License

See `composer.json` (`proprietary`). Change if you intend to open-source the homework under MIT or another license.