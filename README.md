# horoshop-test-task

## Setup

Start containers:

```bash
docker compose up -d
```

Install dependencies if needed:

```bash
docker compose exec php composer install
```

Run migrations before fixtures. Fixtures need the database tables to exist first:

```bash
docker compose exec php php bin/console doctrine:migrations:migrate --no-interaction
```

Load test users:

```bash
docker compose exec php php bin/console doctrine:fixtures:load --no-interaction
```

Run tests:

```bash
docker compose exec php ./vendor/bin/phpunit
```

## Fixture Users

Fixtures create 50 users:

- `u001` through `u050`
- `u001` has `ROLE_ROOT`
- all others have `ROLE_USER`
- password is the login repeated twice

Examples:

```text
login: u001
pass:  u001u001
role:  ROLE_ROOT

login: u002
pass:  u002u002
role:  ROLE_USER
```

## API

Base URL in Docker:

```text
http://localhost:8080
```

### Login

```http
POST /v1/api/login
```

Request:

```json
{
  "login": "u001",
  "pass": "u001u001"
}
```

Response:

```json
{
  "token": "...",
  "token_type": "Bearer",
  "expires_at": "..."
}
```

Use the token for protected requests:

```http
Authorization: Bearer <token>
```

### Users

```http
GET /v1/api/users?id=1
POST /v1/api/users
PUT /v1/api/users
DELETE /v1/api/users?id=1
```

`POST` body:

```json
{
  "login": "newuser",
  "phone": "0991234567",
  "pass": "secret"
}
```

`PUT` body:

```json
{
  "id": 1,
  "login": "u001",
  "phone": "0990000001",
  "pass": "u001u001"
}
```

## Access Rules

- `POST /v1/api/login` is public.
- `/v1/api/users` requires Bearer authentication.
- `ROLE_ROOT` can use `GET`, `POST`, `PUT`, and `DELETE`.
- `ROLE_USER` can use `GET` and `PUT` only for their own user.
- `ROLE_USER` cannot delete users.

## Implementation Decisions

The original task has several ambiguous or unsafe requirements. This implementation follows secure and practical choices:

- The API accepts the password field as `pass`, because that is how the task describes it.
- Plain `pass` is never stored in the database.
- Passwords are hashed and stored as `passwordHash`.
- API responses never return `pass` or `passwordHash`.
- There is no unique index on `login + pass`; passwords should not be identity fields.
- `login` is unique.
- `phone` is unique.
- `phone` is exactly 10 digits, matching Ukrainian phone numbers without country code.
- `/v1/api/login` exists because Bearer auth needs a way to obtain a token.
- API tokens are stored only as SHA-256 hashes.
- Login returns the plain token only once.
- Each token expires after one day.
- Each user can have at most 3 active tokens. When the limit is reached, the oldest active token is revoked and a new token is created.
- All API errors under `/v1/api` are returned as JSON without stack traces.

## Validation Rules

User:

- `login`: required, max length 8, unique
- `phone`: required, exactly 10 digits, unique
- incoming `pass`: required, max length 8
- `passwordHash`: required, length 255 column
- `roles`: JSON array, defaults to `ROLE_USER`
