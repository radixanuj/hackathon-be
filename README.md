# Radix Connect — API

Backend for Radix Connect: *Discover people. Meet people. Learn from people. Find your people.
Do things together. Know the people behind the work.*

Laravel 12 · PHP 8.2 · Sanctum token auth · SQLite locally, Postgres in Docker.

## Phase 1 scope

| Pillar | Shipped |
|---|---|
| People | Profiles + New Joiner Quest |
| Connect | Blind Meetups + Mentoring |
| Communities | Interest Groups |
| Learn & Share | Recommendations + AMA |
| Do Together | Events |
| Celebrate & Discover | Beyond-work Stories |

Phase 2 and 3 features are deliberately not built — see the bottom of [`docs/API.md`](docs/API.md).

## Run it

```bash
composer install
cp .env.example .env && php artisan key:generate
touch database/database.sqlite
php artisan migrate:fresh --seed     # 38 employees with live data across every pillar
php artisan serve                    # http://localhost:8000
```

Or with Docker (Postgres): `docker compose up --build` → http://localhost:8080

```bash
php artisan test                     # 38 tests
```

## For the frontend

- **[`docs/radix-connect.postman_collection.json`](docs/radix-connect.postman_collection.json)** — all 70
  endpoints, runnable. Set `base_url`, run *Auth → Demo login*, and the token is captured for every other
  request automatically.
- **[`docs/API.md`](docs/API.md)** — endpoint reference, filters and payload conventions.

**Signing in:** the demo screen asks only for a name and the shared password `Radix123`. An unrecognised name
creates a profile on the spot. Sign in as **Anuj Maurya** for admin rights. Real email/password auth still
works alongside it; see `config/radix.php`.

Two endpoints exist to make the UI cheap to build:

- `GET /api/v1/meta` — every enum, plus the live list of teams and locations.
- `GET /api/v1/dashboard` — one call for the home screen, across all six pillars.

Point the frontend at the API with `CORS_ALLOWED_ORIGINS` in `.env` (defaults to `*`).

## Layout

```
app/Models/                  15 domain models
app/Http/Controllers/Api/    12 controllers, one per pillar area
app/Http/Resources/          JSON shapes — every response wraps in `data`
app/Services/
  QuestBuilder              New Joiner Quest: five people, crossing teams, each with a reason
  BlindMeetupMatcher        Pairs 6+ years with under 6, preferring different team and location
database/seeders/            A believable Radix for demoing every pillar
```
