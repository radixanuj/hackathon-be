# Radix Connect API — Phase 1

Base URL: `{APP_URL}/api/v1` · Auth: `Authorization: Bearer <token>` (Laravel Sanctum)

Import [`radix-connect.postman_collection.json`](./radix-connect.postman_collection.json) for a runnable
collection of all 70 endpoints. Log in once and the token is captured automatically.

## Signing in (demo mode)

The sign-in screen asks for a **name** and one **shared password** — no per-user credentials.

```http
POST /api/v1/auth/demo-login
{ "name": "Anuj Maurya", "password": "Radix123" }
```

It returns the same `{ data: { token, user } }` shape as a real login, so nothing downstream knows the
difference. An unrecognised name creates a profile on the spot, along with its New Joiner Quest, so anyone
can sign in and land somewhere useful. `Anuj Maurya` is the seeded admin.

Real `POST /auth/register` and `POST /auth/login` are untouched and still work — seeded accounts use
`Radix123` as their password.

Controlled by `config/radix.php`:

| Env var | Default | Effect |
|---|---|---|
| `DEMO_LOGIN_ENABLED` | `true` | `false` makes `/auth/demo-login` return 404, leaving only real auth |
| `DEMO_LOGIN_PASSWORD` | `Radix123` | The shared password |
| `DEMO_LOGIN_AUTO_CREATE` | `true` | `false` rejects unknown names instead of creating a profile |

## Conventions

| | |
|---|---|
| Single resource | `{ "data": { ... } }` |
| List | `{ "data": [...], "links": {...}, "meta": { "current_page", "last_page", "total", ... } }` |
| Error | `{ "message": "..." }` — plus `{ "errors": { "field": ["..."] } }` on 422 |
| Timestamps | ISO 8601, UTC |
| Paging | `?page=1&per_page=20` on every list |

Status codes: `200` ok · `201` created · `401` no/invalid token · `403` not yours · `404` missing · `422` validation or rule violation.

## Seeded accounts

38 employees across 10 teams and 6 locations, spanning the tenure split, plus 5 new joiners with live quests.
Every seeded user's password is `Radix123`. **Anuj Maurya** (`admin@radix.email`) has the `admin` role, which
is required to create Blind Meetup rounds and run matching.

---

## People — Profiles

A profile exists to answer *why might I want to talk to this person?* — not to be an employee directory.
Each profile carries four independently-editable tag sections: **can_talk_about**, **can_help_with**,
**want_to_learn** and **interest**.

| Method | Endpoint | Notes |
|---|---|---|
| `GET` | `/users` | Search and filter — see below |
| `GET` | `/users/{id}` | Full profile |
| `PATCH` | `/me` | Update own profile |
| `PUT` | `/me/tags` | Replace one tag section: `{ kind, tags: ["BigQuery", ...] }` |
| `GET` | `/tags` | Autocomplete: `?q=&type=skill\|interest&limit=` |
| `POST` | `/tags` | Create a tag |

`GET /users` filters: `q` (name, title, intro, team, location, tags), `team`, `location`, `skill` (tag slug),
`interest` (tag slug), `tag` + `kind`, `tenure_band` (`senior` = 6+ years, `junior` = under 6),
`new_joiners`, `open_to_mentoring`.

Every user payload includes `tenure_years`, `tenure_band` and `is_new_joiner`, computed from `joined_at`.

## People — New Joiner Quest

Five people to meet in the first month. Suggestions deliberately cross teams, locations and tenure groups,
and each carries a reason for the introduction.

| Method | Endpoint | Notes |
|---|---|---|
| `GET` | `/me/quest` | Built on first request if it doesn't exist |
| `POST` | `/me/quest/regenerate` | Fresh set of five |
| `PATCH` | `/quest-targets/{id}` | `{ status: "met"\|"skipped"\|"pending", note? }` |

Scoring favours a person who can help with something you want to learn, then shared interests, then a
different team, a different location, and longer tenure. At most one suggestion per team until teams run out.
The quest auto-completes once nothing is `pending`.

## Connect — Blind Meetups

Voluntary monthly sign-up. The one hard rule is the tenure split — **6+ years ↔ under 6 years**. Different
team and different location are preferences on top, so a round still matches everyone it can from a small pool.
The meetup lands on the last Friday of the month.

| Method | Endpoint | Notes |
|---|---|---|
| `GET` | `/meetups/rounds` | All rounds |
| `GET` | `/meetups/rounds/current` | Includes `my_signup` and `my_pair` |
| `GET` | `/meetups/rounds/{id}` | |
| `POST` | `/meetups/rounds/{id}/signup` | `{ note? }` — 422 if sign-ups are closed |
| `DELETE` | `/meetups/rounds/{id}/signup` | Withdraw |
| `GET` | `/me/meetup-pairs` | `partner` is the other person, from your point of view |
| `PATCH` | `/meetups/pairs/{id}` | `{ status, scheduled_at? }` — participants only |
| `POST` | `/meetups/rounds` | **admin** — `meetup_date` defaults to the last Friday of `period` |
| `POST` | `/meetups/rounds/{id}/match` | **admin** — re-running replaces existing pairs |

## Connect — Mentoring / Knowledge Sessions

30 minutes with another employee, asked for on the strength of something on their profile.

| Method | Endpoint | Notes |
|---|---|---|
| `GET` | `/session-requests` | `?direction=incoming\|outgoing\|all&status=` |
| `POST` | `/session-requests` | 422 if the recipient has `open_to_mentoring: false` |
| `GET` | `/session-requests/{id}` | Participants only |
| `POST` | `/session-requests/{id}/respond` | **Recipient only.** `{ action: "accept"\|"decline"\|"suggest_time", scheduled_at?, response_message? }` |
| `PATCH` | `/session-requests/{id}` | `{ status: "completed"\|"cancelled" }` — only the requester may cancel |

Categories: `work_knowledge`, `career`, `leadership`, `people`, `technical`, `personal_experience`.
Statuses: `pending`, `accepted`, `declined`, `time_suggested`, `completed`, `cancelled`.

## Communities — Interest Groups

Discovery only. The community itself carries on in WhatsApp, Slack or wherever it already lives — hence
`external_platform` and `external_link`, and no group chat in this product.

| Method | Endpoint | Notes |
|---|---|---|
| `GET` | `/groups` | `?q=&category=&mine=1&include_archived=1` |
| `POST` | `/groups` | Creator becomes owner and first member |
| `GET` | `/groups/{slug}` | Routed by slug, not id |
| `PATCH` / `DELETE` | `/groups/{slug}` | Owner or admin |
| `POST` / `DELETE` | `/groups/{slug}/join` | Join / leave |
| `GET` | `/groups/{slug}/members` | |

List and detail payloads carry `is_member` and `my_role` so the UI can pick Join vs Leave.

## Learn & Share — Recommendations

Two streams, **work** and **leisure**. Every recommendation must carry a `why`.

| Method | Endpoint | Notes |
|---|---|---|
| `GET` | `/recommendations` | `?q=&stream=&type=&user_id=&sort=recent\|popular` |
| `POST` | `/recommendations` | `{ title, creator?, type, stream, url?, why }` |
| `GET` `PATCH` `DELETE` | `/recommendations/{id}` | Owner or admin to write |
| `POST` / `DELETE` | `/recommendations/{id}/like` | |

Types: `book`, `podcast`, `article`, `show`, `film`, `course`, `tool`.

## Learn & Share — AMAs

| Method | Endpoint | Notes |
|---|---|---|
| `GET` | `/amas` | `?q=&status=&format=&host_id=` — drafts hidden by default |
| `POST` | `/amas` | `format: async\|live` |
| `GET` | `/amas/{id}` | Questions sorted by upvotes, each with answers and `is_upvoted` |
| `PATCH` `DELETE` | `/amas/{id}` | Host or admin |
| `POST` | `/amas/{id}/questions` | 422 unless the AMA is `open` or `scheduled` |
| `POST` / `DELETE` | `/ama-questions/{id}/upvote` | |
| `POST` | `/ama-questions/{id}/answers` | **Host only** — it's *ask me* anything |
| `DELETE` | `/ama-questions/{id}` | Author, host or admin |

## Do Together — Events

Radix Connect handles discovery and RSVPs; detailed coordination moves to existing tools.

| Method | Endpoint | Notes |
|---|---|---|
| `GET` | `/events` | `?scope=upcoming\|past\|all&category=&host_id=&group_id=&attending=1&q=` |
| `POST` | `/events` | Host is RSVP'd `going` automatically |
| `GET` `PATCH` `DELETE` | `/events/{id}` | Host or admin to write |
| `POST` | `/events/{id}/rsvp` | `{ status: "going"\|"maybe"\|"not_going" }` — 422 if full or cancelled |
| `GET` | `/events/{id}/attendees` | `?status=going` |

Payloads carry `going_count`, `spots_left`, `is_full` and the viewer's `my_rsvp`.
Categories: `outdoors`, `food`, `film`, `sports`, `games`, `culture`, `work`, `other`.

## Celebrate & Discover — Stories

Not recognition or awards — the point is *I didn't know this about that person.*

| Method | Endpoint | Notes |
|---|---|---|
| `GET` | `/stories` | `?q=&category=&user_id=` |
| `POST` | `/stories` | `{ title, body, category, media_url? }` |
| `GET` `PATCH` `DELETE` | `/stories/{id}` | Owner or admin to write |
| `POST` / `DELETE` | `/stories/{id}/react` | `clap`, `heart`, `mind_blown`, `inspired` — one per person |
| `POST` | `/stories/{id}/convert-to-ama` | A Story can naturally become an AMA; links both ways |

Categories: `sport`, `travel`, `learning`, `making`, `milestone`, `other`.

## Supporting endpoints

| Method | Endpoint | Notes |
|---|---|---|
| `POST` | `/auth/demo-login` | Name + shared password — see above |
| `POST` | `/auth/register` | Returns a token; also builds the New Joiner Quest |
| `POST` | `/auth/login` | Email + password |
| `GET` | `/auth/me` | Current user with all tag sections |
| `POST` | `/auth/logout` | Revokes the current token only |
| `GET` | `/meta` | Every enum, plus the live list of teams and locations |
| `GET` | `/dashboard` | One call for the home screen, across all six pillars |

---

## Not in Phase 1

Deliberately excluded, per the phased scope: Who Should I Meet, Cross-location Buddy, Office Hours, Open
Coffee/Lunch Invites, Challenges, Ask Radix, Teach Radix, Open Invites (Phase 2); Radix Map, smarter matching,
local chapters, anonymous questions, bucket list, spotlights and kudos (Phase 3).
