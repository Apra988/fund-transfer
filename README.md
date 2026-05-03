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

`.env` defaults match `docker-compose.yml`. Override secrets in `.env.local` if needed. A template (not loaded by Symfony) lives in **`env.local.example`** (`JWT_SECRET_KEY`, `PUBLIC_API_ALLOW_ANONYMOUS`, `API_KEY`).


| Variable                      | Purpose                                                                 |
| ----------------------------- | ----------------------------------------------------------------------- |
| `DATABASE_URL`                | MySQL DSN (see `.env`)                                                   |
| `REDIS_URL`                   | Redis DSN for caches and distributed rate-limit state (`redis://…`)     |
| `TRANSFER_WRITE_RATE_LIMIT`   | Max `POST /api/transfers` per client IP per minute (default **200**)    |
| `JWT_SECRET_KEY`              | HS256 secret (≥32 chars). When set → `Authorization: Bearer <jwt>` required (with claims `sub`; optional array `roles`) |
| `API_KEY`                     | When non-empty → `X-API-Key` accepted as alternative to JWT               |
| `PUBLIC_API_ALLOW_ANONYMOUS`  | When `JWT_SECRET_KEY` **and** `API_KEY` are set: if `1`, missing auth still allowed (avoid in prod); if `0`, credentials required |
| `APP_SECRET`                  | Symfony secret (change in production)                                  |


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

### `GET /api/me`

Returns the **resolved API principal** for this request (`subject` plus `roles`): e.g. `anonymous` when `PUBLIC_API_ALLOW_ANONYMOUS=1` with no credentials, or the JWT **`sub`** claim when a valid `Authorization: Bearer` token is sent. Use it to **verify** auth during demos (pair with `PUBLIC_API_ALLOW_ANONYMOUS=0` to see **401** without a token).

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
- `Idempotency-Key` (optional, max 255 chars): same key + **identical** JSON body replays the original **201** response; reusing the key with a **different** body returns **409 Conflict** (Stripe-style).

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

Validation and domain errors return JSON with `errors` or `error` and HTTP status codes such as **400**, **401**, **404**, **409**, **422**, and **429**.

### Authentication (Symfony Security)

All `/api/*` routes except **`GET /api/health`** require the **`ROLE_TRANSFER`** role via **`symfony/security-bundle`**:

- **Neither** `JWT_SECRET_KEY` **nor** `API_KEY` set (default local dev): API is open; requests run as pseudo-user `anonymous`.
- **`JWT_SECRET_KEY` set** (min 32 characters): send `Authorization: Bearer <token>` (`HS256`, must include **`sub`**; optional JWT claim **`roles`** as string array mapped to Symfony `ROLE_*`).
- **`API_KEY` set**: send **`X-API-Key`** (same value as env). JWT is tried first if a `Bearer` token is present.

Issue a test token (needs `JWT_SECRET_KEY` configured):

```bash
php bin/console app:security:issue-token client-tenant-1 --ttl=7200
```

**Idempotency** keys are scoped to the authenticated **`sub`** / API-key client id / `anonymous`, so two callers cannot collide on the same visible `Idempotency-Key` string.

### Transport & proxies (production)

- In **`prod`**, the **`api` firewall requires HTTPS** (`requires_channel: https`). Terminate TLS at your load balancer and set **`X-Forwarded-Proto: https`**; `when@prod` enables **`trusted_proxies: PRIVATE_SUBNETS`** and common forwarded headers so **`Request::getClientIp()`** and scheme checks work behind a reverse proxy.

### Security headers

API responses add **`X-Content-Type-Options: nosniff`**, **`X-Frame-Options: DENY`**, and **`Referrer-Policy: no-referrer`**.

### Rate limiting

`POST /api/transfers` uses a **fixed window** (`TRANSFER_WRITE_RATE_LIMIT` hits per client IP per minute). Limiter state is stored in Redis (`rate_limiter.transfer_write`), so quotas stay coherent across multiple app replicas.

In **test**, the limit is lower (see `config/packages/framework.yaml`) so integration tests can assert **429**. Those tests rely on Redis staying up so counters survive sequential sub-requests inside one test method.

---

## Design choices

- Amounts use **minor units as strings** and **bcmath** to avoid floating-point mistakes.
- Transfers run in a **single DB transaction** with **pessimistic row locks** on both accounts in **deterministic order** (lower internal id first) to reduce deadlocks.
- **Idempotency**: unique **(caller, key)** composite at the persistence layer plus Redis caching; optional `Idempotency-Key` header is scoped per authenticated subject.
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
```

This runs **two** PHPUnit passes: `phpunit.xml.dist` (the main suite, which excludes the `@jwt-integration` group) and **`phpunit.jwt-required.xml.dist`**, which sets a non-empty `JWT_SECRET_KEY` and `PUBLIC_API_ALLOW_ANONYMOUS=0` so protected routes behave like production credentials.

Alternatively:

```bash
php vendor/bin/phpunit                              # default suite only
php vendor/bin/phpunit -c phpunit.jwt-required.xml.dist
```

Requirements:

- **MySQL**: `DATABASE_URL` in `.env.test` must use base database name **app**; Symfony’s `dbname_suffix` in `test` yields **app_test**.
- **Redis**: tests that assert POST rate limiting need Redis (`docker compose up -d redis`).
- **DAMA Doctrine Test Bundle** wraps integration tests in a transaction when possible.

**Security**: `.env.test` keeps **JWT / API key unset** so the main application integration tests behave like permissive dev (anonymous `ROLE_TRANSFER`). **`ApiTokenAuthenticator`** is covered by unit tests under `tests/Security/`; JWT-required endpoints are exercised by `JwtRequiredFundTransferApiTest` in the second pass above.

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