# Radix Connect API — Phase 1

Base URL: `{APP_URL}/api/v1` · Auth: `Authorization: Bearer <token>` (Laravel Sanctum)

Import [`radix-connect.postman_collection.json`](./radix-connect.postman_collection.json) for a runnable
collection of all 129 endpoints (Phase 1 + Phase 2). Log in once and the token is captured automatically.

## Signing in (demo mode)

The sign-in screen asks who you are and one **shared password** — no per-user credentials.

Typing in the name box searches the roster, and you pick yourself out of the results:

```http
GET /api/v1/auth/directory?q=kh
{ "data": [ { "id": 39, "name": "Sahar Khan", "job_title": "…", "team": "…", "location": "…" } ] }
```

Then sign in as the person you picked:

```http
POST /api/v1/auth/demo-login
{ "user_id": 39, "password": "Radix123" }
```

Sending a `user_id` is what the screen does, so "Sahar Khan" and "Saif Khan" can never be confused for
one another. A `name` is still accepted instead, and an unrecognised one creates a profile on the spot
along with its New Joiner Quest — so someone the roster has never heard of can still get in:

```http
POST /api/v1/auth/demo-login
{ "name": "Brand New", "password": "Radix123" }
```

Either way it returns the same `{ data: { token, user } }` shape as a real login, so nothing downstream
knows the difference. `Anuj Maurya` is the seeded admin.

`/auth/directory` is unauthenticated by necessity — it is read before anyone has a token — so it is
deliberately thin: id, name, job title, team and location, capped at ten results, active people only.
No email or contact details. It returns 404 whenever demo sign-in is disabled.

Real `POST /auth/register` and `POST /auth/login` are untouched and still work — seeded accounts use
`Radix123` as their password.

Controlled by `config/radix.php`:

| Env var | Default | Effect |
|---|---|---|
| `DEMO_LOGIN_ENABLED` | `true` | `false` makes `/auth/demo-login` and `/auth/directory` return 404, leaving only real auth |
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

# Phase 1 — Create Connections

## People — Profiles

A profile exists to answer *why might I want to talk to this person?* — not to be an employee directory.
Each profile carries four independently-editable tag sections: **can_talk_about**, **can_help_with**,
**want_to_learn** and **interest**.

| Method | Endpoint | Notes |
|---|---|---|
| `GET` | `/users` | Search and filter — see below |
| `GET` | `/users/{id}` | Full profile, plus `nudge` — where you and they stand (see Nudges) |
| `POST` | `/users/{id}/nudge` | Nudge them, or nudge back — see Nudges |
| `PATCH` | `/me` | Update own profile |
| `PUT` | `/me/tags` | Replace one tag section: `{ kind, tags: ["BigQuery", ...] }` |
| `PUT` | `/me/currently` | Replace "Currently into": `{ currently: [{ label: "Reading", value: "Project Hail Mary" }, ...] }` |
| `GET` | `/tags` | Autocomplete: `?q=&type=skill\|interest&limit=` |
| `POST` | `/tags` | Create a tag |

`GET /users` filters: `q` (name, title, intro, team, location, tags), `team`, `location`, `skill` (tag slug),
`interest` (tag slug), `tag` + `kind`, `tenure_band` (`senior` = 6+ years, `junior` = under 6),
`new_joiners`, `open_to_mentoring`.

Every user payload includes `tenure_years`, `tenure_band` and `is_new_joiner`, computed from `joined_at`.

`currently` is the "Currently into" list — up to four `{ icon, label, value }` lines (*Reading: Project Hail
Mary*) that date a profile on purpose. Labels are open text; `icon` is optional on write and is resolved from
the label when omitted. Lines with a blank label or value are dropped rather than stored.

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

## People — Nudges

The old-fashioned poke. No message, no meeting, no agenda — the lowest-effort way to tell a colleague
you thought of them, and the only reply is the same gesture back.

| Method | Endpoint | Notes |
|---|---|---|
| `POST` | `/users/{id}/nudge` | Nudge someone, or nudge them back — same call either way |
| `GET` | `/users/{id}/nudge` | Where the two of you stand |
| `GET` | `/me/nudges` | Your exchanges: `scope=all\|sent\|received`, `status=all\|outstanding\|returned`, `per_page` |
| `GET` | `/me/nudges/summary` | Just the counts, for a badge |

**One rule holds it up: you cannot nudge the same person twice in a row.** Until they nudge back it stays
their turn, and a second nudge is a `422`. That is what makes a nudge worth something, and it makes nudging
all ninety-eight people in a loop impossible by construction rather than by rate limit.

Nudging back is not a separate endpoint — whether a nudge counts as a reply depends only on who nudged last,
so the caller never has to say which it is doing. Answering stamps `returned_at` on their nudge and opens
yours, one `streak` higher. The streak is the running total for the pair and never resets, so a long-running
back-and-forth shows as *"That is 6 nudges between you two."*

Every nudge row is told from the point of view of whoever asked: `person` is always the *other* one,
`direction` is `sent` or `received`, and `waiting_on_you` / `waiting_on_them` say whose turn it is.
`GET /users/{id}` carries the same state inline as `nudge`, so a profile can draw its button — and know
whether it says "back" — without a second call.

Nudging yourself, or someone deactivated, is a `422`.

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

---

# Phase 2 — Make Connection Easier

Added inside the same six pillars rather than as new sections.

## People — Who Should I Meet?

Ranks colleagues on the three things the pillar asks for: **shared interests**, **complementary knowledge**,
and **lack of previous interaction**.

| Method | Endpoint | Notes |
|---|---|---|
| `GET` | `/me/suggestions` | `?limit=5`. Each suggestion has `person`, `reasons[]` and `previously_connected` |
| `POST` | `/users/{id}/dismiss-suggestion` | "Not right now" — keeps them out of future suggestions |
| `DELETE` | `/users/{id}/dismiss-suggestion` | Undo the dismissal |

Prior interaction means a real 1:1 link: a quest person marked met, a blind meetup pair, a buddy pairing, a
mentoring request in either direction, an office hours booking, or a shared coffee invite. Sharing a group or
an event does **not** count — sitting in the same WhatsApp group is not the same as having spoken.

Someone you have already connected with is ranked far down rather than hidden. In a company this size everyone
eventually meets everyone, and an empty screen helps nobody — `previously_connected` lets the UI label it.

## Connect — Cross-location Buddy

An ongoing pairing with someone in another office. No monthly round: you opt in and get paired the moment
someone elsewhere is waiting. A **different location is a hard requirement** — if nobody qualifies you stay in
the pool rather than being paired with a neighbour. A different team is preferred on top of that.

| Method | Endpoint | Notes |
|---|---|---|
| `GET` | `/me/buddy` | `{ signup, pairing }`, either may be null; `pairing.buddy` is the other person |
| `POST` | `/me/buddy` | Opt in and attempt a match. 422 with no location on your profile, or if already paired |
| `DELETE` | `/me/buddy` | Leave the pool |
| `GET` | `/me/buddy/history` | Every pairing you've had |
| `POST` | `/buddy-pairings/{id}/end` | Either buddy can end it |

The matcher will not re-pair two people who have been buddies before.

## Connect — Office Hours

A host publishes open slots; anyone books one. No accept/decline step — that's what Mentoring is for.

| Method | Endpoint | Notes |
|---|---|---|
| `GET` | `/office-hours` | `?scope=upcoming\|past\|all&host_id=&available_only=1` |
| `POST` | `/office-hours` | `{ starts_at, duration_minutes?, capacity?, title?, location?, link? }` |
| `GET` `PATCH` `DELETE` | `/office-hours/{id}` | Host or admin to write |
| `POST` | `/office-hours/{id}/book` | `{ topic? }` — 422 if full, past, cancelled, or your own slot |
| `DELETE` | `/office-hours/{id}/book` | Cancelling frees the seat |
| `GET` | `/me/office-hour-bookings` | Slots you've booked |

## Connect — Open Coffee / Lunch Invites

Lighter than an Event: a time, a place, a couple of seats.

| Method | Endpoint | Notes |
|---|---|---|
| `GET` | `/coffee-invites` | `?scope=open\|past\|mine\|all&kind=&location=` |
| `POST` | `/coffee-invites` | `kind`: `coffee`, `lunch`, `walk` |
| `GET` `PATCH` `DELETE` | `/coffee-invites/{id}` | Host or admin to write |
| `POST` / `DELETE` | `/coffee-invites/{id}/join` | 422 if full, cancelled, past, or you're the host |

`capacity` counts guest seats — the host doesn't occupy one.

## Communities — Challenges

A challenge counts one thing and `unit` says what (km, books, photos, days). Participants log entries; the
leaderboard falls out of the totals.

| Method | Endpoint | Notes |
|---|---|---|
| `GET` | `/challenges` | `?scope=active\|upcoming\|past\|mine\|all&category=&group_id=&q=` |
| `POST` | `/challenges` | Creator is enrolled automatically; slugs made unique on collision |
| `GET` | `/challenges/{slug}` | Includes ranked leaderboard and your `my_participation` |
| `PATCH` `DELETE` | `/challenges/{slug}` | Creator or admin |
| `POST` / `DELETE` | `/challenges/{slug}/join` | |
| `POST` | `/challenges/{slug}/logs` | `{ value, note?, logged_on? }` — 422 if not joined or not running |
| `GET` | `/challenges/{slug}/leaderboard` | Ranked, with `rank` on each row |

Categories: `running`, `reading`, `photography`, `sports`, `learning`, `other`.

## Learn & Share — Ask Radix

The point is that the asker doesn't need to know who can help. Tags route the question, and relevant people can
either write an answer **or simply volunteer to talk** — often the more useful of the two.

| Method | Endpoint | Notes |
|---|---|---|
| `GET` | `/questions` | `?status=&tag=&user_id=&q=` |
| `GET` | `/questions?for_me=1` | **The routing feature.** Open questions, not your own, tagged with something you listed under *can talk about* or *can help with* |
| `POST` | `/questions` | `{ title, body?, tags[] }` — tags created on the fly |
| `GET` `PATCH` `DELETE` | `/questions/{id}` | Asker or admin to write |
| `POST` | `/questions/{id}/answers` | 422 if the question is closed |
| `POST` / `DELETE` | `/questions/{id}/volunteer` | `{ note? }` — 422 on your own question |
| `POST` | `/question-answers/{id}/accept` | **Asker only, no admin bypass** — marks the question answered |
| `DELETE` | `/question-answers/{id}` | Author, asker or admin |

Accepting a second answer unsets the first.

## Learn & Share — Teach Radix

The mirror of Ask Radix: offer a session and see whether anyone wants it before committing to a date.

| Method | Endpoint | Notes |
|---|---|---|
| `GET` | `/teach-offers` | `?status=&level=&format=&user_id=&q=` |
| `GET` | `/teach-offers?ready=1` | Offers that hit `min_interested` and just need a date |
| `POST` | `/teach-offers` | `{ title, format, level?, topic?, duration_minutes?, min_interested?, preferred_times? }` |
| `GET` `PATCH` `DELETE` | `/teach-offers/{id}` | Owner or admin to write |
| `POST` / `DELETE` | `/teach-offers/{id}/interest` | 422 on your own offer |
| `POST` | `/teach-offers/{id}/schedule` | Owner only. Creates an Event (`ends_at` from `duration_minutes`) and RSVPs the teacher plus everyone interested. Returns the Event |

Formats: `session`, `workshop`, `walkthrough`. Levels: `any`, `beginner`, `intermediate`, `advanced`.

## Do Together — Open Invites

"Anyone interested?" with no date and no logistics. Becomes a real Event only once enough people say yes.

| Method | Endpoint | Notes |
|---|---|---|
| `GET` | `/open-invites` | `?status=&category=&mine=1&q=` — sorted by interest |
| `POST` | `/open-invites` | Posting counts as interest, so the count starts at 1 |
| `GET` `PATCH` `DELETE` | `/open-invites/{id}` | Author or admin to write |
| `POST` / `DELETE` | `/open-invites/{id}/interest` | 422 once the invite is closed |
| `POST` | `/open-invites/{id}/convert-to-event` | Author only. Creates an Event in the same category and RSVPs everyone interested. Returns the Event |

## Celebrate & Discover — Stories through interests

| Method | Endpoint | Notes |
|---|---|---|
| `GET` | `/stories/discover` | Other people's stories, ranked by how many tags match your profile interests, then reactions and recency |
| `GET` | `/stories?tag={slug}` | Filter by tag |
| `POST` `PATCH` | `/stories` | Now accept `tags[]` (max 6) |

---

## Notifications

Cross-cutting: every pillar above raises them. You are only ever notified about something you
already have a stake in — it is yours, you signed up for it, or you were matched with someone.
Nothing broadcasts to people because a tag or a group looked like a fit; discovery is a pull
(`/questions?for_me=1`, `/dashboard`, the various lists), not a push.

**Nothing is ever deleted.** `read_at` records that you have seen it and `archived_at` takes it out of
the inbox; archived notifications stay readable under `?scope=archived` for as long as the account exists.
There is no `DELETE /notifications/{id}`, by design.

| Method | Endpoint | Notes |
|---|---|---|
| `GET` | `/notifications` | `scope=inbox\|archived\|all` (default `inbox`), `status=unread\|read\|all`, `category`, `type`, `per_page`. Paginated, plus a `summary` block alongside `data` |
| `GET` | `/notifications/summary` | Just the counts — small enough to poll for the bell badge |
| `PATCH` | `/notifications/{id}/read` | Mark one as read |
| `DELETE` | `/notifications/{id}/read` | Mark it unread again |
| `POST` | `/notifications/{id}/archive` | Out of the inbox, into Archived. Implies read |
| `DELETE` | `/notifications/{id}/archive` | Restore it to the inbox |
| `POST` | `/notifications/read-all` | Mark the whole inbox read |
| `POST` | `/notifications/archive-all` | Clear the inbox. `only_read=1` leaves anything still unread in place |

Every write is scoped to the signed-in user with no admin bypass — an inbox is nobody else's to
manage, so touching someone else's notification is a `403`.

A notification carries `type`, `category`, `icon` (an emoji), `title`, `body`, the `actor` who caused
it, the `subject` it is about, and an `action_url` that deep-links into the app
(`/connect?tab=office-hours`, `/community?tab=events`, …). `summary` gives `unread`, `inbox`,
`archived` and a `by_category` breakdown for the filter pills.

### What raises one

| Pillar | Raised when |
|---|---|
| People | Your New Joiner Quest is complete; somebody nudges you |
| Connect | Someone asks you for a session, or replies to / cancels / completes yours; your Blind Meetup round is matched (or you were left unmatched); you are paired with a cross-location buddy, or your buddy ends it; someone books or cancels on your office hours, or a host cancels a slot you booked; someone joins or leaves your coffee invite, or a host cancels one you joined |
| Communities | Someone joins your group or your challenge; someone passes you on a challenge leaderboard |
| Learn & Share | Someone likes your recommendation; someone asks a question at your AMA, or the host answers yours; someone answers or volunteers on your question; your answer is accepted; someone wants your teaching offer, or an offer you wanted gets a date |
| Do Together | Someone RSVPs to your event; an event you are attending moves or is cancelled; someone is in for your open invite, or one you wanted becomes a real event |
| Celebrate | Someone reacts to your story |

The full catalogue of types lives in `App\Models\Notification::TYPES`, and `/meta` returns
`notification_categories` and `notification_types`.

---

## Supporting endpoints

| Method | Endpoint | Notes |
|---|---|---|
| `POST` | `/auth/demo-login` | `user_id` (or `name`) + shared password — see above |
| `GET` | `/auth/directory` | `?q=` — the roster the sign-in name box searches. No token required |
| `POST` | `/auth/register` | Returns a token; also builds the New Joiner Quest |
| `POST` | `/auth/login` | Email + password |
| `GET` | `/auth/me` | Current user with all tag sections |
| `POST` | `/auth/logout` | Revokes the current token only |
| `GET` | `/meta` | Every enum, plus the live list of teams and locations |
| `GET` | `/dashboard` | One call for the home screen, across all six pillars |
| `GET` | `/notifications` | See the Notifications section above |

---

## Not built

Phase 3, deliberately excluded: Radix Map and richer network suggestions, smarter matching from previous
connections, local chapters and discussion rooms, anonymous *Ask a Dumb Question* and Lessons Learned, shared
bucket list, volunteering and side projects, employee spotlights and peer kudos.

Anonymous questions sit in Phase 3 rather than here because they need clear moderation and privacy rules first.
