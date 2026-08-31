# Player API — React Native integration guide

Everything a player-facing mobile client needs from the Zarmani108 backend. Scope is the
**player** surface only: every route in this document is reachable by a user holding the
`user` or `vip` role. Admin routes (`/api/v1/admin/*`) are deliberately excluded — those
belong to the `lotto_dashboard` web client.

Verified against `routes/api.php`, the controllers, the form requests and the services on
branch `feat/3d-live-endpoint`. Where this document and `docs/openapi.yaml` disagree, this
document is the newer one — the OpenAPI file predates deposits, withdrawals, the wallet
endpoints and the live/history reads.

- **Base URL:** `https://api.zarmani108.uk/api/v1` (production), `http://localhost:8000/api/v1` (local)
- **Content type:** `application/json` for everything except the deposit proof upload
- **Always send `Accept: application/json`.** Several error handlers in `bootstrap/app.php`
  bail out (`return null`) when the request does not expect JSON, and you get an HTML error
  page instead of the envelope.

---

## 1. The response envelope

Every endpoint below returns the same three-key envelope, on success and on failure alike:

```jsonc
{
  "message": "Bets retrieved successfully.",   // human-readable, safe to surface
  "data":    { "...": "..." },                  // null on most errors
  "errors":  null                               // object keyed by field on failure
}
```

`data` is always an **object with a named key** — never a bare array. The payload for
`GET /bets` is `data.bets`, for `GET /me/wallet` it is `data.wallet`, and so on. Each
endpoint below names its key.

### The two exceptions

`FcmTokenController` and `NotificationController` were written outside this convention and
do **not** return the envelope. They return ad-hoc shapes (`{message}`, `{data}`,
`{count, data}`). Do not route them through a shared envelope parser — see §9.

### Suggested TypeScript shape

```ts
export interface ApiEnvelope<TData> {
  message: string;
  data: TData | null;
  errors: Record<string, string[]> | null;
}
```

---

## 2. Authentication

Sanctum personal access tokens. Send the token on every authenticated request:

```
Authorization: Bearer 12|AbCdEf...
```

There is no refresh token and no expiry rotation. A token lives until `POST /logout`
deletes it, so persist it in secure storage (`expo-secure-store` / Keychain / Keystore),
not `AsyncStorage`.

### `POST /register` → 201

Public.

| Field | Rules |
|---|---|
| `username` | required, string, max 255, unique |
| `email` | required, email, max 255, unique |
| `password` | required, min 8, `confirmed` — send `password_confirmation` too |
| `pin` | required, exactly 6 digits, `confirmed` — send `pin_confirmation` too |
| `pin_confirmation` | required, exactly 6 characters |
| `currency` | optional, `MMK` or `THB` |

```jsonc
// data
{
  "user":  { "id": "...", "username": "...", "email": "...", "created_at": "..." },
  "token": "12|AbCdEf..."
}
```

> **Shape warning.** Register returns the **raw `User` model** (minus `password`,
> `remember_token`, `security_pin`), while login and `/me` return a hand-built payload with
> `role`, `roles` and `is_banned`. They are not the same object. Treat the register
> response as "token + id" only, then call `GET /me` for the canonical user.

Passing `currency` here creates the wallet and **locks the currency immediately** (§4).

### `POST /login` → 200

Public. Body: `email`, `password`.

```jsonc
// data
{
  "user": {
    "id": "...", "name": "...", "username": "...", "email": "...",
    "role": "vip",                       // "vip" | "user" | null
    "roles": ["user", "vip"],            // raw Spatie role names
    "is_banned": false,
    "banned_at": null,
    "created_at": "...", "updated_at": "..."
  },
  "token": "12|AbCdEf..."
}
```

Failures:

| Status | Body | Meaning |
|---|---|---|
| 401 | `errors.credentials` | wrong email or password |
| 403 | `errors.authorization` | account is banned — show `message` verbatim |

### `GET /me` → 200

`data.user`, same payload as login. Use it on cold start to validate a stored token: a 401
means the token was revoked and you should clear storage and route to login.

### `POST /logout` → 200

Deletes **only the current token** (`currentAccessToken()`). Other devices stay signed in.
To sign out everywhere, also call `POST /fcm/logout-all` (§9) to stop push to those devices.

### `POST /security-pin` → 200

Changes the caller's own security PIN.

```json
{ "password": "…", "pin": "654321", "pin_confirmation": "654321" }
```

Gated on the **account password**, not the current PIN — the player this exists for is the
one who cannot remember the PIN. A wrong password is a 422 under `password`. Rate limited
to 5/minute and 20/hour per user.

Until this endpoint existed there was no way to change a PIN at all: a forgotten one locked
the player out of betting *and* withdrawals permanently. Surface it from the profile screen,
and offer it on the "wrong PIN" error.

Succeeding also clears the player's failed-attempt counter, so a reset immediately after
being throttled works rather than waiting out the minute.

### Banned users

`EnsureUserIsNotBanned` sits on the whole authenticated group. If a user is banned
mid-session, every subsequent call fails — handle it globally, not per screen.

---

## 3. Errors

| Status | When | Where to read the detail |
|---|---|---|
| 401 | missing/invalid/revoked token | `errors.auth` |
| 403 | banned account, or a role the user lacks | `errors.authorization` |
| 404 | record missing, or **not owned by the caller** | `message` |
| 409 | domain rule violated (`DomainException`) | `errors.domain` |
| 422 | validation failed | `errors.<field>` — array of strings |
| 500 | unexpected | do not parse |

Two rules worth building into the client:

**404 doubles as "not yours."** `showForUser`-style lookups scope by `user_id`, so another
player's bet, deposit or withdrawal returns 404 rather than 403. Never show "deleted" — show
"not found."

**409 is the interesting one.** Any `DomainException` thrown in a service is rendered
globally as 409 with `errors.domain = [message]`. The message is written for humans and is
the best thing to show the player. Recurring cases: `Insufficient balance.`,
`Only PENDING deposits can be cancelled.`, `Please set a security PIN before placing bets.`,
and betting-paused messages.

**Validation errors are field-keyed** and nested keys use dot paths, e.g.
`errors["bet_numbers.2.amount"]`. Map them onto form fields by that exact key.

---

## 4. Onboarding order (do this before the first bet)

`POST /bets` rejects the request unless all three are already true. Building the flow in
this order avoids a dead end on the betting screen:

1. **Wallet currency is set** — `PUT /me/wallet/currency`. One-way: once set, any later
   attempt fails 422 with `Wallet currency is already set and cannot be changed.` Only an
   admin can reset it. Ask the player deliberately, with a confirmation step.
2. **Bank info is complete** — `POST /me/bank-info` (`bank_name`, `account_name`,
   `account_number`, all required). Editing later is rate-limited to **once every 30 days**;
   a too-soon update returns 422 with `errors.bank_info` and `errors.next_allowed_at`
   (ISO 8601) — render that as a date, not a raw string.
3. **Security PIN exists** — set during registration. Every bet re-sends it.

The bet request's currency must equal the wallet's currency, or it fails 422 on
`wallet_currency`.

---

## 5. Wallet

### `GET /me/wallet` → 200 · `data.wallet`

```jsonc
{
  "id": 1,
  "balance": 150000,                          // integer, minor-unit-free whole currency
  "currency": "MMK",                          // null until set
  "currency_locked_at": "2026-08-01T10:00:00+07:00",
  "bank_name": "KBZ",
  "account_name": "...",
  "account_number": "..."
}
```

Returns **404** when the user has no wallet row yet (a fresh account that registered
without `currency`). Treat 404 as "zero balance, not yet configured," not as an error.

`balance` is an integer. Do not divide by 100.

### `PUT /me/wallet/currency` → 200 · `data.wallet`

Body: `{ "currency": "MMK" | "THB" }`. Creates the wallet if absent. Irreversible (§4).

### `GET /me/wallet/transactions` → 200

Query: `type`, `from` (date), `to` (date), `page`, `page_size` (default 15).

```jsonc
{
  "transactions": [
    {
      "id": 1, "wallet_id": 1, "user_id": "...",
      "type": "BET_PLACE", "direction": "DEBIT",
      "amount": 5000, "balance_after": 145000, "currency": "MMK",
      "reference_type": "App\\Models\\Bet", "reference_id": "...",
      "note": null, "created_by_user_id": "...",
      "created_at": "...", "updated_at": "..."
    }
  ],
  "pagination": { "current_page": 1, "last_page": 4, "per_page": 15, "total": 52 }
}
```

`type` ∈ `DEPOSIT`, `BET_PLACE`, `BET_REFUND`, `BET_WIN`, `BET_WIN_REVERSAL`, `WITHDRAWAL`,
`WITHDRAWAL_REFUND`, `ADJUSTMENT`. `direction` ∈ `CREDIT`, `DEBIT`. Colour the row from
`direction`, label it from `type`.

### Bank info

| Method | Path | Notes |
|---|---|---|
| `GET` | `/me/bank-info` | `data.bank_info`; **404** when never set |
| `POST` | `/me/bank-info` | 201; all three fields required |
| `PUT` | `/me/bank-info` | fields are `sometimes` — partial update allowed; 30-day cooldown |
| `DELETE` | `/me/bank-info` | clears it; blocks betting until re-added |

`bank_name` must be one of: `KBZ`, `AYA`, `CB`, `UAB`, `YOMA`, `SCB`, `KBANK`, `BBL`,
`KTB`, `BAY`, `TTB`, `GSB`, `OTHER`.

---

## 6. Money in and out

### Deposits

**`GET /bank-settings`** → `data.bank_settings` — the active admin bank accounts to pay
into. Call this first; its `id` is the `admin_bank_setting_id` the deposit needs.

**`POST /deposits`** → 201 · `data.deposit`. **`multipart/form-data`**, not JSON.

| Field | Rules |
|---|---|
| `admin_bank_setting_id` | required, integer, must exist |
| `currency` | required, `MMK` or `THB` |
| `claimed_amount` | required, integer, ≥ 1 |
| `transfer_note` | optional, ≤ 255 chars |
| `proof_image` | required, jpg/jpeg/png/webp only, ≤ 10 MB |

```ts
const body = new FormData();
body.append('admin_bank_setting_id', String(bankId));
body.append('currency', 'MMK');
body.append('claimed_amount', String(amount));
body.append('proof_image', { uri, name: 'proof.jpg', type: 'image/jpeg' } as any);

await fetch(`${BASE}/deposits`, {
  method: 'POST',
  headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
  body, // do NOT set Content-Type — let fetch add the multipart boundary
});
```

**`GET /deposits`** → `data.deposits` + `data.pagination` (`page`, `page_size`, default 15).
**`GET /deposits/{id}`** → `data.deposit`, 404 if not yours.
**`POST /deposits/{id}/cancel`** → 200. Only `PENDING`; otherwise 409
`Only PENDING deposits can be cancelled.` A cancelled deposit lands in `REJECTED`.

Deposit payload:

```jsonc
{
  "id": "uuid", "user_id": "uuid", "admin_bank_setting_id": 3,
  "currency": "MMK",
  "claimed_amount": 50000,        // what the player says they sent
  "approved_amount": null,        // what the admin credited; null until approved
  "transfer_note": "...",
  "status": "PENDING",            // PENDING | APPROVED | REJECTED
  "admin_note": null, "rejection_reason": null,
  "reviewed_by_user_id": null, "reviewed_at": null,
  "proof_image": { "exists": true, "download_url": "...", "file_name": "...", "mime_type": "image/jpeg", "size": 84213 },
  "created_at": "...", "updated_at": "..."
}
```

Balance moves on **admin approval**, not on submission. Show `PENDING` deposits as
"awaiting review" and never add them to the displayed balance.

### Withdrawals

**`POST /withdrawals`** → 201 · `data.withdrawal`. JSON: `currency`, `amount` (integer ≥ 1).
The balance is debited **immediately** on submission — a rejection or cancellation credits it
back (`WITHDRAWAL_REFUND`). Insufficient balance is a 409 `Insufficient balance.`

The wallet's bank details are snapshotted onto the withdrawal at creation
(`bank_snapshot`), so later edits to bank info do not retarget a pending payout. Show
`bank_snapshot`, not the live wallet fields, on a withdrawal receipt.

**`GET /withdrawals`** → `data.withdrawals` + `data.pagination`.
**`GET /withdrawals/{id}`** → `data.withdrawal`.
**`POST /withdrawals/{id}/cancel`** → `PENDING` only, else 409.

Status: `PENDING` | `COMPLETED` | `REJECTED`. `payout_proof` uses the same five-key
descriptor as `proof_image`, and is only populated once `COMPLETED`.

### Fetching proof images

This is the one thing that reliably breaks in React Native. The media disk has **no public
URL** — every image is streamed by a controller behind `auth:sanctum`. A bare
`<Image source={{ uri: download_url }} />` sends no `Authorization` header and renders
nothing.

```tsx
<Image source={{ uri: downloadUrl, headers: { Authorization: `Bearer ${token}` } }} />
```

Header support on `Image` is inconsistent across RN versions and caching layers. The
reliable path is to fetch the blob yourself and hand the component a local/base64 URI:

```ts
const res  = await fetch(downloadUrl, { headers: { Authorization: `Bearer ${token}` } });
const blob = await res.blob();
// FileSystem.writeAsStringAsync(...) or FileReader → data: URI
```

`download_url` is absolute and built from `APP_URL` on the server. If it points at
`localhost` in a build talking to production, `APP_URL` is misconfigured — do not paper over
it client-side.

---

## 7. Betting

### Before the bet screen renders

| Endpoint | Key | Why |
|---|---|---|
| `GET /odd-settings` | `data.odd_settings` | payout multipliers by `bet_type` + `currency` + `user_type`; VIP users get their own row |
| `GET /bet-pauses` | `data.bet_pauses` | whether 2D/3D betting is stopped |
| `GET /closed-numbers` | see below | numbers the admin has closed or capped |

**`GET /bet-pauses`** → array of:

```jsonc
{
  "id": 1, "bet_type": "2D", "is_enabled": true,
  "pause_from": "2026-08-14T16:00:00+07:00",
  "status": "scheduled",         // "inactive" | "scheduled" | "paused"
  "message": "Maintenance break", "updated_at": "..."
}
```

`status` is computed server-side against the current time. `paused` means bets are being
refused right now — disable the submit button and show `message`. `scheduled` means it
starts at `pause_from`; a countdown reads well here.

**`GET /closed-numbers`** — query: `bet_type` (`2D`/`3D`), `currency`, `stock_date`
(default: today in Asia/Bangkok), `target_opentime` (2D only; default: the currently active
period).

```jsonc
{
  "period": { "target_opentime": "12:01:00", "stock_date": "2026-08-14" },
  "bet_type": "2D", "currency": "MMK",
  "closed":  [17, 45],                                          // hard-closed
  "limited": [{ "number": 23, "sales_limit": 100000, "remaining": 12000 }]
}
```

Grey out `closed`, and show `remaining` on `limited` numbers. This is advisory — the server
re-checks under a row lock at creation time, so a number can close between the read and the
submit. Handle the 422 (below) as a normal outcome, not an edge case.

Passing an invalid `target_opentime` returns 422.

### `POST /bets` → 201 · `data.bet`

```jsonc
{
  "bet_type": "2D",                  // "2D" | "3D"
  "currency": "MMK",                 // must equal the wallet currency
  "target_opentime": "12:01:00",     // 2D: required, one of the four below. 3D: omit
  "security_pin": "123456",          // the 6-digit PIN, every time
  "bet_numbers": [
    { "number": 23, "amount": 1000 },
    { "number": 7,  "amount": 500  }
  ]
}
```

- `target_opentime` for 2D must be exactly `11:00:00`, `12:01:00`, `15:00:00` or `16:30:00`.
- `number`: 0–99 for 2D, 0–999 for 3D. Send it as an **integer**, or as a digit-only string.
  Leading-zero display values (`"05"`) are your formatting concern — the API stores `5`, and
  echoes back `5`. Pad on render, using the bet's own width (2 or 3).
- `amount`: integer ≥ 1. The same number may appear more than once in one request — each
  entry is kept as its own line, and the amounts are summed for the wallet debit, the
  sales-limit check and the payout.
- `status`, `bet_result_status` and `payout_status` are `prohibited` — sending any of them
  fails the whole request.

On success the wallet is debited in the same transaction and the bet is created already
`ACCEPTED`. There is no pending-review step for the player.

Failure modes, all of which a real player will hit:

| Status | `errors` key | Cause |
|---|---|---|
| 422 | `security_pin` | wrong PIN |
| 429 | `security_pin` | five wrong PINs in a minute — `data.code = SECURITY_PIN_THROTTLED`, `data.retry_after` seconds |
| 409 | `domain` | no PIN set yet — `Please set a security PIN before placing bets.` |
| 409 | `domain` | stored PIN unusable — `Your security PIN must be reset. Please contact support.` |
| 422 | `bank_info` | bank details incomplete |
| 422 | `wallet_currency` | wallet currency unset, or ≠ request currency |
| 422 | `bet_type` | betting paused for that `bet_type` — `data.code = BETTING_PAUSED`, `data.bet_type` |
| 422 | `bet_numbers` | `Number 17 is closed for this period.` / `...exceeds the sales limit...` |
| 409 | `domain` | `Insufficient balance.` |

Show the 422 `bet_numbers` strings verbatim — they name the offending number.

**Branch on `data.code`, not on the message.** A paused bet type is the one rejection a
player can do nothing about, and it is by far the most common — it deserves its own screen
rather than the generic error toast. It used to arrive as an anonymous 422 under
`bet_type`, indistinguishable from a malformed one, and a live pause was consequently
reported as "the PIN is broken".

### Reading bets

| Endpoint | `data` key | Contents |
|---|---|---|
| `GET /bets` | `bets` | all of the user's bets, newest first |
| `GET /bets/accepted-payments` | `accepted_payments` | `ACCEPTED` only, by `updated_at` |
| `GET /bets/payout-history` | `payout_history` | won-and-paid, plus refunded |
| `GET /bets/{id}` | `bet` | single bet, with `betNumbers` and `user.wallet` |

All four take `page` (default 1) and `page_size` (default 10, **capped at 100**).

> **No pagination block here.** Unlike deposits, withdrawals and transactions, the bet lists
> return a bare array with no `pagination` meta. Infer "has more" from
> `items.length === page_size`. (The admin list `GET /admin/bets` does return the block; the
> player lists do not.)

Bet payload:

```jsonc
{
  "id": "uuid",
  "user_id": "uuid",
  "bet_type": "2D",
  "currency": "MMK",
  "target_opentime": "12:01:00",
  "stock_date": "2026-08-14",          // plain calendar day, no time, no zone
  "total_amount": "1500.00",           // decimal:2 → arrives as a STRING
  "status": "ACCEPTED",                // PENDING | ACCEPTED | REJECTED | REFUNDED
  "bet_result_status": "WON",          // OPEN | WON | LOST | INVALID
  "payout_status": "PENDING",          // PENDING | PAID_OUT | REFUNDED
  "placed_at": "2026-08-14T09:15:00Z",
  "settled_at": "2026-08-14T12:05:00Z",
  "settled_result_history_id": "...",
  "paid_out_at": null, "payout_reference": null, "payout_note": null,
  "bet_slip": "uuid",
  "bet_numbers": [
    { "id": 1, "bet_id": "uuid", "number": 23, "amount": 1000, "potential_winning": "85000.00" }
  ]
}
```

Two casts to handle deliberately:

- **`total_amount` and `potential_winning` are strings** (`decimal:2`). Parse before
  arithmetic; never `+` them.
- **`placed_at` / `settled_at` carry a literal `Z` that is not UTC.** The cast is
  `datetime:Y-m-d\TH:i:s\Z`, which appends `Z` to the app-timezone wall clock. If
  `APP_TIMEZONE` is a Myanmar/Bangkok zone, parsing these as UTC shifts them by the offset.
  Format them as wall-clock strings, or strip the `Z` before parsing. `stock_date` is safe —
  it is cast `date:Y-m-d` precisely to dodge this.

### Three status enums, not one

They are orthogonal and every screen needs the right one:

- `status` — admin review state. Player bets start `ACCEPTED`.
- `bet_result_status` — settlement outcome. `OPEN` until the draw settles.
- `payout_status` — has the money moved. A `WON` bet with `payout_status: PENDING` is
  awaiting an admin payout; that is the state to surface as "winnings on the way."

`DELETE /bets/{id}` exists but returns 409 once the bet has dependent results, which is
almost immediately. Do not build the UI around cancelling a bet.

---

## 8. Results

### 2D

| Endpoint | `data` key | Notes |
|---|---|---|
| `GET /two-d-results` | `two_d_results` | `page`, `page_size` (default 20, max 100), `stock_date`, `open_time`, `history_id` |
| `GET /two-d-results/latest` | `two_d_result` | single most recent |
| `GET /two-d-results/last-5-days` | `two_d_results` | history strip |
| `GET /two-d-results/live` | `live` | the home-screen ticker |
| `GET /two-d-side-numbers/last-5-days` | `two_d_side_numbers` | modern/internet numbers |

```jsonc
// GET /two-d-results/live → data.live
{ "twod": "45", "set": "1234.56", "value": "78,901.23", "time": "2026-08-14 12:01:00", "stale": false }
```

`stale: true` means the vendor was unreachable or the daily call budget is spent, and this
is the last known value. Show a subtle "last updated" marker rather than hiding the number.

The live ticker is **preview only and never a settlement source** — a bet is settled against
the saved result, not this. Don't reconcile a player's win/loss against `/live`.

Poll it sparingly. The server caches with a TTL that tightens around draw times (a few
seconds) and relaxes overnight; a 10–15s client poll is plenty, and a shorter one just
serves the same cached value.

Side numbers are display-only and must never be presented as a draw result.

### 3D

| Endpoint | `data` key | Notes |
|---|---|---|
| `GET /three-d-results` | `three_d_results` | `page`, `page_size`, `stock_date`. **These settle bets.** |
| `GET /three-d-results/latest` | `three_d_result` | most recent saved result |
| `GET /three-d-results/history` | `three_d_history` + `stale` | vendor feed, **display only** |
| `GET /three-d-results/live` | `three_d_live` + `stale` | vendor's current draw, display only |

```jsonc
// GET /three-d-results/live
{ "three_d_live": { "threed": "456", "stock_date": "2026-08-14" }, "stale": false }
```

`three_d_live` is `null` when the vendor has published nothing and nothing was cached.
Handle null before `stale`.

The split matters: `/three-d-results` is the settlement record, `/history` and `/live` are a
vendor display feed served from cache. They can disagree, briefly. Anything tied to a
player's money must read the first.

---

## 9. Push notifications (FCM)

**These endpoints do not use the envelope.** Bypass your shared response parser.

| Endpoint | Body | Response |
|---|---|---|
| `POST /fcm/token` | `token` (string, min 100), `device_type` (`android`\|`ios`\|`web`), `device_name` (optional) | 201 `{ message, data: FcmToken }` |
| `DELETE /fcm/token` | `token` | `{ message }`, or 404 `{ message: "Token not found" }` |
| `POST /fcm/logout-all` | — | `{ message }`; deactivates every token for the user |
| `GET /fcm/tokens` | — | `{ count, data: [...] }`, token strings hidden |

Register the token after login and on every FCM token refresh. Registration is keyed on
`token` alone, matching the unique index, so re-posting the same token is safe and
idempotent — it just bumps `last_used_at`.

An FCM token identifies an app install, not an account. A token already registered to a
**different** user is therefore **reassigned to the caller, not rejected**: the device
belongs to whoever signed in on it last. Do not treat this as an error case.

Delete the token on logout — while the bearer is still valid, so before `POST /logout`.
Skipping it leaves the old account's pushes arriving on that handset until the next player
signs in. Use `DELETE /fcm/token`, not `/fcm/logout-all`, which would also silence the
user's other devices.

| Endpoint | Response |
|---|---|
| `POST /notifications/test` | `{ message }`; **400** when the user has no active tokens |
| `GET /notifications/logs` | `{ data: <Laravel paginator> }` — filters `type`, `status`, `start_date`, `end_date`, `per_page` (20) |
| `GET /notifications/stats` | `{ data: { total, sent, failed, unread, by_type } }` |
| `POST /notifications/read-all` | `{ message, updated_count }` |

`/notifications/logs` returns Laravel's **full paginator object** (`current_page`, `data`,
`links`, `per_page`, `total`, …) nested under `data` — a different shape from the
`{items, pagination}` convention everywhere else. The rows are in `data.data`.

Use `stats.unread` for the badge count.

---

## 10. Content and app state

| Endpoint | `data` key | Auth | Notes |
|---|---|---|---|
| `GET /app-settings/maintenance` | `maintenance` | **public** | `{ is_enabled, message }` |
| `GET /announcements` | `announcements` | yes | list |
| `GET /announcements/{id}` | `announcement` | yes | detail |
| `GET /popup-ads` | `popup_ads` | yes | **active ads only** |
| `GET /popup-ads/{id}/image` | binary | yes | streamed, needs the Bearer header |

`GET /app-settings/maintenance` is the only authenticated-group escapee — it sits outside
the middleware so a client can check it **before** login and gate the whole app. Call it on
launch; when `is_enabled`, show `message` and stop.

Popup ad payload: `{ id, title, link_url, is_active, image: {exists, download_url, file_name, mime_type, size}, created_at, updated_at }`.

Image uploads across the API (deposit proof, payout proof, popup ad artwork) accept
**jpg, jpeg, png and webp only**, up to 10 MB. SVG is refused: it is a scriptable
document, not a picture. The check reads the file's bytes, so renaming a `.svg` to
`.png` does not get it through. Every image download is sent as an attachment with
`X-Content-Type-Options: nosniff` — render it into an `<Image>`/`img`, never into a
WebView.
Deactivating an ad hides its artwork from players — a request for an inactive ad's image
404s for non-admins even with a valid id. Fetch the image the same way as deposit proofs
(§6); it is behind the same auth wall.

---

## 11. A minimal client

```ts
const BASE = 'https://api.zarmani108.uk/api/v1';

export class ApiError extends Error {
  constructor(
    message: string,
    readonly status: number,
    readonly errors: Record<string, string[]> | null,
  ) { super(message); }

  /** First message for a field, for inline form errors. */
  fieldError(field: string) { return this.errors?.[field]?.[0]; }
  /** 409s carry their explanation here. */
  get domainMessage() { return this.errors?.domain?.[0] ?? this.message; }
}

export async function apiRequest<T>(
  path: string,
  { token, method = 'GET', body }: { token?: string; method?: string; body?: unknown } = {},
): Promise<T> {
  const isForm = body instanceof FormData;

  const res = await fetch(`${BASE}${path}`, {
    method,
    headers: {
      Accept: 'application/json',
      ...(isForm ? {} : body ? { 'Content-Type': 'application/json' } : {}),
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    },
    body: isForm ? body : body ? JSON.stringify(body) : undefined,
  });

  // FCM/notification routes return no envelope — see §1 and §9.
  const json = await res.json().catch(() => null);

  if (!res.ok) {
    if (res.status === 401) { /* clear stored token, route to login */ }
    throw new ApiError(json?.message ?? 'Request failed', res.status, json?.errors ?? null);
  }

  return json.data as T;
}
```

---

## 12. Enum reference

| Enum | Values |
|---|---|
| `Currency` | `MMK`, `THB` |
| `BetType` | `2D`, `3D` |
| 2D `target_opentime` | `11:00:00`, `12:01:00`, `15:00:00`, `16:30:00` |
| `BetStatus` | `PENDING`, `ACCEPTED`, `REJECTED`, `REFUNDED` |
| `BetResultStatus` | `OPEN`, `WON`, `LOST`, `INVALID` |
| `BetPayoutStatus` | `PENDING`, `PAID_OUT`, `REFUNDED` |
| `DepositStatus` | `PENDING`, `APPROVED`, `REJECTED` |
| `WithdrawalStatus` | `PENDING`, `COMPLETED`, `REJECTED` |
| `WalletTransactionType` | `DEPOSIT`, `BET_PLACE`, `BET_REFUND`, `BET_WIN`, `BET_WIN_REVERSAL`, `WITHDRAWAL`, `WITHDRAWAL_REFUND`, `ADJUSTMENT` |
| `WalletTransactionDirection` | `CREDIT`, `DEBIT` |
| `BankName` | `KBZ`, `AYA`, `CB`, `UAB`, `YOMA`, `SCB`, `KBANK`, `BBL`, `KTB`, `BAY`, `TTB`, `GSB`, `OTHER` |
| Bet pause `status` | `inactive`, `scheduled`, `paused` |

---

## 13. Gotchas, collected

1. `Accept: application/json` on every request, or errors come back as HTML.
2. Player bet lists have **no** `pagination` block; deposits, withdrawals and transactions do.
3. `total_amount` and `potential_winning` are **strings**.
4. `placed_at` / `settled_at` end in `Z` but hold app-timezone wall clock — do not parse as UTC.
5. `GET /me/wallet` 404s for a wallet-less account; that is normal, not an error.
6. Wallet currency is **one-way**. Confirm before setting.
7. Bank info edits are locked for **30 days**; `errors.next_allowed_at` tells you when.
8. All media is behind `auth:sanctum` — plain `<Image uri>` will not load it.
9. Deposit creation is `multipart/form-data`; let `fetch` set the boundary.
10. `/two-d-results/live` and `/three-d-results/live` are display-only, never settlement truth.
11. FCM and notification routes are outside the envelope convention.
12. 404 can mean "belongs to another user."
13. Bet numbers are integers server-side; pad `"05"` only at render.
