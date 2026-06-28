# Balance System — Backend Implementation Plan

Target executor: **Claude Code**.
Stack: Laravel 11+, MariaDB/MySQL, Sanctum auth, Spatie Media Library, Spatie Permission, UUID users.
Environment: **staging — no production data, destructive migrations are fine, `php artisan migrate:fresh --seed` is the cutover path.**

---

## 0. Decisions Locked (from grilling session)

### Backend Summary Endpoints (required by Admin Dashboard)

Add these 3 lightweight admin read endpoints to `AdminFinanceController` (or `AdminDashboardController`). Auth: admin only. No pagination — scalar responses.

```
GET /api/v1/admin/deposits/summary
  → { pending_count: int, approved_today_count: int, approved_today_amount: int }

GET /api/v1/admin/withdrawals/summary
  → { pending_count: int, completed_today_count: int }

GET /api/v1/admin/wallets/summary
  → { total_balance: int, total_users_with_balance: int, currency_breakdown: [{ currency, total_balance, user_count }] }
```

All three are simple aggregate queries on `wallets` / `deposits` / `withdrawals` tables.

---

1. **Append-only `wallet_transactions` ledger + maintained `wallets.balance` column.**
2. **Bet placement:** hard debit on place, default to `ACCEPTED`. Admin can still `REJECT`/`REFUND` → compensating credit.
3. **Win settlement:** auto-credit balance in the same DB transaction as the status flip. No admin payout step. No `payout_proof_image`.
4. **Withdrawal** is a new resource: submit-time debit, `PENDING → COMPLETED` (with proof) or `PENDING → REJECTED` (with reason + compensating credit). One pending withdrawal per user.
5. **Deposit** is a new resource: `claimed_amount` + nullable `approved_amount` (partial approval allowed, admin note required if differ). Multiple pending allowed. No min/max limits.
6. **Single-currency wallet, multi-currency support.** Each wallet has one currency, chosen via explicit setup, immutable once set. All deposits/withdrawals/bets must match wallet currency.
7. **Admin manual balance adjustment** endpoint with required `reason` (min 10 chars), no caps. Type: `ADJUSTMENT`.
8. **Pessimistic row lock** via `lockForUpdate()` on the wallet row inside a DB transaction. All balance writes go through a single `App\Services\Wallet\WalletMutator` service. Nothing else writes to `wallets.balance`.
9. **Hard cutover**, destructive migrations allowed, no legacy preservation. Existing `pay_slip` / `payout_proof` / `transaction_id_last_two_digits` for bets is removed.

---

## 1. Domain Model

```
User (uuid)
 └─ Wallet (1:1)               -- balance + currency + bank info (combined, single table)
      ├─ WalletTransaction[]   -- append-only ledger
      ├─ Deposit[]             -- user-initiated, admin-approved
      ├─ Withdrawal[]          -- user-initiated, admin-completed
      └─ Bet[]                 -- existing model, flow simplified
```

`AdminBankSetting` (existing) is referenced by `Deposit` to record which admin account the user transferred to.

---

## 2. Phase 1 — Schema

> Reuse existing migration files where they conflict (since `migrate:fresh` is fine). Otherwise add new ones with timestamps after the existing wallet migration.

### 2.1 `wallets` table — modify

Edit `database/migrations/2026_03_10_000002_create_wallets_table.php` to add currency columns. Final shape:

```php
Schema::create('wallets', function (Blueprint $table) {
    $table->id();
    $table->uuid('user_id')->unique();
    $table->unsignedBigInteger('balance')->default(0);

    // NEW: wallet currency (immutable once set)
    $table->string('currency', 8)->nullable();
    $table->timestamp('currency_locked_at')->nullable();

    // Existing bank info (user's payout account)
    $table->enum('bank_name', ['KBZ', 'AYA', 'CB', 'UAB', 'YOMA', 'OTHER'])->nullable();
    $table->string('account_name')->nullable();
    $table->string('account_number')->nullable();
    $table->timestamp('bank_info_updated_at')->nullable();

    $table->timestamps();

    $table->foreign('user_id')
        ->references('id')->on('users')
        ->onUpdate('cascade')->onDelete('restrict');

    $table->index('currency');
});
```

Update `App\Models\Wallet` `$fillable` and `casts()` to include `currency` (cast to `App\Enums\Currency`) and `currency_locked_at` (datetime).

### 2.2 `wallet_transactions` — new

```php
Schema::create('wallet_transactions', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->foreignId('wallet_id')->constrained('wallets')->cascadeOnUpdate()->restrictOnDelete();
    $table->uuid('user_id');                              // denormalized for fast user-scoped queries
    $table->string('type', 32);                           // DEPOSIT|BET_PLACE|BET_REFUND|BET_WIN|WITHDRAWAL|WITHDRAWAL_REFUND|ADJUSTMENT
    $table->string('direction', 8);                       // CREDIT|DEBIT
    $table->unsignedBigInteger('amount');
    $table->unsignedBigInteger('balance_after');          // audit snapshot
    $table->string('currency', 8);                        // denormalized from wallet at write time
    $table->string('reference_type')->nullable();         // App\Models\Deposit, Withdrawal, Bet, or null
    $table->uuid('reference_id')->nullable();
    $table->text('note')->nullable();
    $table->uuid('created_by_user_id');                   // admin id for admin actions, user id for self-actions
    $table->timestamps();

    $table->index(['user_id', 'created_at']);
    $table->index(['reference_type', 'reference_id']);
    $table->index('type');
});
```

Notes:
- `id` is UUID (consistent with `bets`).
- `amount` and `balance_after` are `unsignedBigInteger` (whole MMK / whole currency units, never fractional).
- No FK on `reference_id` because it's polymorphic.

### 2.3 `deposits` — new

```php
Schema::create('deposits', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->uuid('user_id');
    $table->foreignId('admin_bank_setting_id')->constrained('admin_bank_settings')->restrictOnDelete();
    $table->string('currency', 8);
    $table->unsignedBigInteger('claimed_amount');
    $table->unsignedBigInteger('approved_amount')->nullable();
    $table->string('transfer_note')->nullable();          // last 6 digits / ref code
    $table->string('status', 16);                         // PENDING|APPROVED|REJECTED
    $table->text('admin_note')->nullable();               // required when approved_amount != claimed_amount
    $table->text('rejection_reason')->nullable();
    $table->uuid('reviewed_by_user_id')->nullable();
    $table->timestamp('reviewed_at')->nullable();
    $table->timestamps();

    $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();

    $table->index(['user_id', 'status']);
    $table->index('status');
    $table->index('reviewed_at');
});
```

Proof image attached via Spatie Media Library: `proof_of_payment` collection on `Deposit` model (single file).

### 2.4 `withdrawals` — new

```php
Schema::create('withdrawals', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->uuid('user_id');
    $table->string('currency', 8);
    $table->unsignedBigInteger('amount');
    $table->string('status', 16);                         // PENDING|COMPLETED|REJECTED
    $table->json('bank_snapshot');                        // { bank_name, account_name, account_number } at submit time
    $table->text('admin_note')->nullable();
    $table->text('rejection_reason')->nullable();
    $table->uuid('reviewed_by_user_id')->nullable();
    $table->timestamp('reviewed_at')->nullable();
    $table->timestamps();

    $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();

    $table->index(['user_id', 'status']);
    $table->index('status');
    $table->index('reviewed_at');
});
```

Enforce one-pending-withdrawal-per-user with a **partial unique index** (MySQL 8+ / MariaDB 10.6+) — if your engine doesn't support partial unique indexes, enforce at application level inside the locked transaction.

```sql
-- For MariaDB 10.6+ / PostgreSQL — add after the create
CREATE UNIQUE INDEX withdrawals_one_pending_per_user
  ON withdrawals(user_id) WHERE status = 'PENDING';
```

If on MySQL 8 without partial indexes: skip the SQL and rely on the service check inside `WalletMutator`-wrapped transaction.

Proof image attached via Spatie: `payout_proof` collection on `Withdrawal` model (single file). Required when transitioning to `COMPLETED`.

### 2.5 `bets` table — drop legacy columns

Edit the existing bets migration. **Remove**:
- `transaction_id_last_two_digits` column
- All seed data and factory defaults that set it.

Keep `payout_status` enum (`PENDING`, `PAID_OUT`, `REFUNDED`) but the lifecycle changes:
- New bets start at `payout_status = PENDING`.
- On settlement (win): atomically flips to `PAID_OUT` + writes `BET_WIN` ledger credit.
- On settlement (loss): stays at `PENDING` forever (no payout was due). Optionally rename to `NOT_APPLICABLE` — but not required.
- On admin refund of a placed/accepted bet: flips to `REFUNDED` + writes `BET_REFUND` ledger credit.

Drop the `pay_slip` media collection registration from `Bet::registerMediaCollections()`. Keep `payout_proof` registration on `Bet` only if you want to allow historical attachments; otherwise drop it too. Recommended: **drop both**, since `payout_proof` now lives on `Withdrawal`.

Remove the `pay_slip` and `payout_proof` accessor methods from `Bet`. Remove the `downloadPaySlip` and `downloadPayoutProof` routes and controller methods.

---

## 3. Phase 2 — Enums

Create or update these files under `app/Enums/`:

```php
// WalletTransactionType.php
enum WalletTransactionType: string
{
    case DEPOSIT             = 'DEPOSIT';
    case BET_PLACE           = 'BET_PLACE';
    case BET_REFUND          = 'BET_REFUND';
    case BET_WIN             = 'BET_WIN';
    case WITHDRAWAL          = 'WITHDRAWAL';
    case WITHDRAWAL_REFUND   = 'WITHDRAWAL_REFUND';
    case ADJUSTMENT          = 'ADJUSTMENT';
}

// WalletTransactionDirection.php
enum WalletTransactionDirection: string
{
    case CREDIT = 'CREDIT';
    case DEBIT  = 'DEBIT';
}

// DepositStatus.php
enum DepositStatus: string
{
    case PENDING  = 'PENDING';
    case APPROVED = 'APPROVED';
    case REJECTED = 'REJECTED';
}

// WithdrawalStatus.php
enum WithdrawalStatus: string
{
    case PENDING   = 'PENDING';
    case COMPLETED = 'COMPLETED';
    case REJECTED  = 'REJECTED';
}
```

`App\Enums\Currency` already exists — reuse it for the wallet `currency` column.

---

## 4. Phase 3 — `WalletMutator` Service

**This is the single most important class in the system.** Every balance change goes through here.

`app/Services/Wallet/WalletMutator.php`:

```php
namespace App\Services\Wallet;

use App\Enums\WalletTransactionDirection;
use App\Enums\WalletTransactionType;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class WalletMutator
{
    /**
     * Mutate a user's wallet balance atomically and append a ledger row.
     *
     * MUST be called from inside a DB::transaction OR will open one.
     * Locks the wallet row FOR UPDATE.
     *
     * @throws DomainException when wallet currency is unset or balance would go negative.
     */
    public function mutate(
        string $userId,
        WalletTransactionType $type,
        WalletTransactionDirection $direction,
        int $amount,
        ?Model $reference,
        string $createdByUserId,
        ?string $note = null,
    ): WalletTransaction {
        if ($amount <= 0) {
            throw new DomainException('Wallet mutation amount must be positive.');
        }

        return DB::transaction(function () use (
            $userId, $type, $direction, $amount, $reference, $createdByUserId, $note
        ) {
            $wallet = Wallet::query()
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->first();

            if ($wallet === null) {
                throw new DomainException('Wallet not found.');
            }

            if ($wallet->currency === null) {
                throw new DomainException('Wallet currency is not set.');
            }

            $newBalance = $direction === WalletTransactionDirection::CREDIT
                ? $wallet->balance + $amount
                : $wallet->balance - $amount;

            if ($newBalance < 0) {
                throw new DomainException('Insufficient balance.');
            }

            $txn = WalletTransaction::create([
                'wallet_id'           => $wallet->id,
                'user_id'             => $userId,
                'type'                => $type->value,
                'direction'           => $direction->value,
                'amount'              => $amount,
                'balance_after'       => $newBalance,
                'currency'            => $wallet->currency->value,
                'reference_type'      => $reference ? $reference::class : null,
                'reference_id'        => $reference?->getKey(),
                'note'                => $note,
                'created_by_user_id'  => $createdByUserId,
            ]);

            $wallet->update(['balance' => $newBalance]);

            return $txn;
        });
    }
}
```

**Hard rule for codebase contributors:** any service that calls `$wallet->update(['balance' => ...])` directly is a bug. Add a note to `README.md` under a "Wallet invariants" section and reject any PR that violates it.

Optional: add a model observer on `Wallet` that asserts balance writes happen inside a transaction. Belt-and-suspenders; skip if it slows things down.

---

## 5. Phase 4 — Wallet Currency Setup

### 5.1 Endpoint

```
PUT /api/v1/me/wallet/currency
  body: { currency: "MMK" }   -- must be a valid Currency enum value
  auth: user
```

Behavior:
- If wallet doesn't exist for user, create one (with `balance = 0`).
- If `wallets.currency` is already non-null → **422** with `{ "currency": ["Wallet currency is already set and cannot be changed."] }`.
- On success: set `currency`, set `currency_locked_at = now()`, return wallet.

Implementation: `App\Http\Controllers\Api\V1\WalletCurrencyController` + `App\Http\Requests\Wallet\SetWalletCurrencyRequest` + `App\Services\Wallet\WalletCurrencyService`.

### 5.2 Cross-cutting guards

Add a private helper used across deposit/withdrawal/bet flows:

```php
// In each relevant service
private function assertWalletCurrencyMatches(string $userId, Currency $requested): void
{
    $wallet = Wallet::query()->where('user_id', $userId)->first();
    if ($wallet === null || $wallet->currency === null) {
        throw ValidationException::withMessages([
            'wallet_currency' => ['Please set up your wallet currency before continuing.'],
        ]);
    }
    if ($wallet->currency !== $requested) {
        throw ValidationException::withMessages([
            'currency' => ['Currency must match your wallet currency.'],
        ]);
    }
}
```

---

## 6. Phase 5 — Deposit Flow

### 6.1 Model

`app/Models/Deposit.php` — UUID primary key (use `HasUuids` trait), `HasMedia` interface, `proof_of_payment` single-file media collection. Casts: `status` → `DepositStatus`, `currency` → `Currency`, `reviewed_at` → datetime.

Relationships: `user()`, `adminBankSetting()`, `reviewer()` (User via reviewed_by_user_id).

### 6.2 Service

`app/Services/Deposit/DepositService.php` with methods:

```php
public function createForUser(string $userId, array $attributes, UploadedFile $proofImage): Deposit;
public function listForUser(string $userId, int $page, int $pageSize): Collection;
public function listForAdmin(int $page, int $pageSize, ?DepositStatus $status): Collection;
public function approve(string $depositId, string $adminUserId, ?int $approvedAmount, ?string $adminNote): Deposit;
public function reject(string $depositId, string $adminUserId, string $rejectionReason): Deposit;
public function cancelForUser(string $userId, string $depositId): Deposit;  // user can cancel own PENDING
```

`approve()` logic (inside DB transaction):
1. Lock deposit row `FOR UPDATE`, verify status is `PENDING`.
2. Resolve final amount: `approved_amount = $approvedAmount ?? $deposit->claimed_amount`.
3. If `approved_amount !== claimed_amount` and `$adminNote === null` → 422.
4. Set `status = APPROVED`, `approved_amount`, `admin_note`, `reviewed_by_user_id`, `reviewed_at = now()`.
5. Call `WalletMutator::mutate(userId: $deposit->user_id, type: DEPOSIT, direction: CREDIT, amount: $approvedAmount, reference: $deposit, createdByUserId: $adminUserId, note: $adminNote)`.
6. Return refreshed deposit.

`reject()`: lock, verify PENDING, set status REJECTED + reason + reviewer. **No ledger write** (no balance was held).

`cancelForUser()`: lock, verify PENDING, set status REJECTED with `rejection_reason = 'Cancelled by user.'` and `reviewed_by_user_id = $userId`. No ledger write.

### 6.3 Validation rules (StoreDepositRequest)

```php
[
    'admin_bank_setting_id' => ['required', 'integer', 'exists:admin_bank_settings,id'],
    'currency'              => ['required', new Enum(Currency::class)],
    'claimed_amount'        => ['required', 'integer', 'min:1'],
    'transfer_note'         => ['nullable', 'string', 'max:255'],
    'proof_image'           => ['required', 'file', 'image', 'max:10240'], // 10MB
]
```

Service-layer additional checks: wallet currency matches `currency`; wallet currency is set.

### 6.4 Endpoints

User:
```
POST   /api/v1/deposits                   -- create (multipart/form-data, includes proof_image)
GET    /api/v1/deposits                   -- list own (paginated: ?page, ?page_size)
GET    /api/v1/deposits/{deposit}         -- show own
GET    /api/v1/deposits/{deposit}/proof   -- download own proof (auth or admin only)
POST   /api/v1/deposits/{deposit}/cancel  -- cancel own PENDING
```

Admin:
```
GET    /api/v1/admin/deposits             -- list all (filter by ?status=PENDING)
GET    /api/v1/admin/deposits/{deposit}   -- show one
POST   /api/v1/admin/deposits/{deposit}/approve   -- body: { approved_amount?: int, admin_note?: string }
POST   /api/v1/admin/deposits/{deposit}/reject    -- body: { rejection_reason: string (min 5) }
```

---

## 7. Phase 6 — Withdrawal Flow

### 7.1 Model

`app/Models/Withdrawal.php` — UUID primary key, `HasMedia` interface, `payout_proof` single-file media collection. Casts: `status` → `WithdrawalStatus`, `currency` → `Currency`, `bank_snapshot` → array, `reviewed_at` → datetime.

### 7.2 Service

`app/Services/Withdrawal/WithdrawalService.php`:

```php
public function createForUser(string $userId, array $attributes): Withdrawal;
public function listForUser(string $userId, int $page, int $pageSize): Collection;
public function listForAdmin(int $page, int $pageSize, ?WithdrawalStatus $status): Collection;
public function complete(string $withdrawalId, string $adminUserId, UploadedFile $proofImage, ?string $adminNote): Withdrawal;
public function reject(string $withdrawalId, string $adminUserId, string $rejectionReason): Withdrawal;
public function cancelForUser(string $userId, string $withdrawalId): Withdrawal;
```

`createForUser()` (inside DB transaction):
1. Verify wallet exists, currency set, currency matches.
2. Verify user has complete bank info (reuse the check from existing `BetService::assertUserHasCompleteBankInfo`).
3. Verify no existing PENDING withdrawal for this user (`->where('user_id')->where('status', PENDING)->lockForUpdate()->exists()`). If exists → 409.
4. Snapshot user's current bank info into `bank_snapshot` JSON.
5. Create withdrawal row with status PENDING.
6. Call `WalletMutator::mutate(type: WITHDRAWAL, direction: DEBIT, amount: $amount, reference: $withdrawal, createdByUserId: $userId)`. **This is where insufficient balance fails the whole request.**
7. Return refreshed withdrawal.

`complete()` (inside DB transaction):
1. Lock withdrawal row, verify status PENDING.
2. Attach `payout_proof` media (validated: image, max 10MB).
3. Set status COMPLETED, reviewed_by, reviewed_at, optional admin_note.
4. **No additional ledger write** (debit happened at submit time). The existing ledger entry stays.

`reject()` (inside DB transaction):
1. Lock withdrawal row, verify status PENDING.
2. Set status REJECTED + reason + reviewer + reviewed_at.
3. Call `WalletMutator::mutate(type: WITHDRAWAL_REFUND, direction: CREDIT, amount: $withdrawal->amount, reference: $withdrawal, createdByUserId: $adminUserId, note: $rejectionReason)`.

`cancelForUser()`: same shape as reject but `created_by_user_id = $userId`, `rejection_reason = 'Cancelled by user.'`. WITHDRAWAL_REFUND credit fires.

### 7.3 Validation (StoreWithdrawalRequest)

```php
[
    'currency' => ['required', new Enum(Currency::class)],
    'amount'   => ['required', 'integer', 'min:1'],   // global min via app_settings if you want later
]
```

### 7.4 Endpoints

User:
```
POST   /api/v1/withdrawals                  -- create
GET    /api/v1/withdrawals                  -- list own
GET    /api/v1/withdrawals/{withdrawal}     -- show own
GET    /api/v1/withdrawals/{withdrawal}/proof  -- download (auth or admin only; only if COMPLETED)
POST   /api/v1/withdrawals/{withdrawal}/cancel
```

Admin:
```
GET    /api/v1/admin/withdrawals
GET    /api/v1/admin/withdrawals/{withdrawal}
POST   /api/v1/admin/withdrawals/{withdrawal}/complete   -- multipart/form-data: { payout_proof: file, admin_note?: string }
POST   /api/v1/admin/withdrawals/{withdrawal}/reject     -- body: { rejection_reason: string }
```

---

## 8. Phase 7 — Bet Flow Changes

### 8.1 `BetService::createForUser` rewrite

Rip out:
- `pay_slip_image` parameter, validation, media attachment.
- `transaction_id_last_two_digits` parameter and the `assertHasValidTransactionIdLastTwoDigits` helper.
- The `pay_slip` media collection write.

Keep / add:
- The existing `assertUserHasCompleteBankInfo` check (still needed — payout target).
- `assertWalletCurrencyMatches($userId, $currency)`.
- After `Bet::create(...)` + `replaceBetNumbers(...)` inside the existing DB transaction, call `WalletMutator::mutate(type: BET_PLACE, direction: DEBIT, amount: $totalAmount, reference: $bet, createdByUserId: $userId)`. **Insufficient balance throws and the whole transaction rolls back — the bet is never persisted.**
- Bet status defaults to `BetStatus::ACCEPTED` (not `PENDING`). Update the `bets` factory and any seeders accordingly.

### 8.2 `StoreBetRequest` rewrite

Remove `pay_slip_image` and `transaction_id_last_two_digits`. New shape:

```php
[
    'bet_type'        => ['required', new Enum(BetType::class)],
    'currency'        => ['required', new Enum(Currency::class)],
    'target_opentime' => ['required_if:bet_type,2D', 'nullable', 'date_format:H:i:s'],
    'bet_numbers'     => ['required', 'array', 'min:1'],
    'bet_numbers.*.number' => ['required', 'integer'],
    'bet_numbers.*.amount' => ['required', 'integer', 'min:1'],
]
```

### 8.3 `updateReviewStatusForAdmin` — wire the refund path

When admin transitions a bet to `REJECTED` or `REFUNDED`, the service must:
1. Verify policy allows transition (existing logic, keep).
2. Inside the existing DB transaction, call `WalletMutator::mutate(type: BET_REFUND, direction: CREDIT, amount: $bet->total_amount, reference: $bet, createdByUserId: $adminUserId)`.
3. Update bet status / `payout_status = REFUNDED` for the REFUNDED case.

Note: `REJECTED` is a status-update-time refund; `REFUNDED` is the explicit refund path. Both write `BET_REFUND` credits. If you want to distinguish them in the ledger, you can use different `note` strings — e.g., `note: 'Bet rejected by admin'` vs `'Bet refunded by admin'`.

### 8.4 Remove the old payout endpoints

In `routes/api.php`, **delete**:
- `Route::post('/bets/{bet}/payout', ...)` under admin
- `Route::get('/{bet}/pay-slip', ...)` under user bets
- `Route::get('/{bet}/payout-proof', ...)` under user bets

In `BetController`, **delete** these methods entirely:
- `payout()`
- `downloadPaySlip()`
- `downloadPayoutProof()`

Keep `refund()` if it still goes through `updateReviewStatusForAdmin` — otherwise also delete and rely on `PATCH /admin/bets/{bet}/status` with `status=REFUNDED`. Recommended: **delete `refund()`** since the status PATCH covers it.

Delete `App\Services\Bet\BetPayoutService` entirely.

---

## 9. Phase 8 — Settlement Auto-Credit

Edit `App\Services\Bet\BetSettlementService`. Find where a bet is marked `WON` and `potential_winning` is settled. Within the same DB transaction wrapping that settlement run:

For each won bet:
1. Compute total payout = sum of `bet_numbers.potential_winning` for winning numbers. (Use whatever logic already settles this.)
2. Set `payout_status = PAID_OUT`, `paid_out_at = now()`, `paid_out_by_user_id = null` (no admin involved).
3. Call `WalletMutator::mutate(type: BET_WIN, direction: CREDIT, amount: $payout, reference: $bet, createdByUserId: $bet->user_id, note: "2D/3D settlement $historyId")`.

**Important:** `WalletMutator::mutate` opens its own transaction. Laravel handles nested transactions with savepoints, but if the settlement is iterating many bets, you want to avoid one giant outer transaction holding many wallet locks simultaneously (deadlock risk). Option: settle each winning bet in its own sub-transaction:

```php
foreach ($wonBets as $bet) {
    DB::transaction(function () use ($bet, $historyId) {
        $bet->update([
            'payout_status' => BetPayoutStatus::PAID_OUT,
            'paid_out_at'   => now(),
        ]);
        $this->walletMutator->mutate(
            userId: $bet->user_id,
            type: WalletTransactionType::BET_WIN,
            direction: WalletTransactionDirection::CREDIT,
            amount: $bet->total_winning_amount,   // however you currently compute it
            reference: $bet,
            createdByUserId: $bet->user_id,
            note: "Settlement {$historyId}",
        );
    });
}
```

Idempotency: the existing `bet_settlement_runs` table already prevents double-settlement at the per-history-id level. Belt: also check `payout_status !== PAID_OUT` before crediting, so a re-run can't double-credit. **Acceptance test required.**

---

## 10. Phase 9 — Admin Manual Adjustment

### 10.1 Endpoint

```
POST /api/v1/admin/users/{user}/balance-adjustment
  body: {
    direction: "CREDIT" | "DEBIT",
    amount: integer (min 1),
    reason: string (min 10, max 500)    -- REQUIRED, surfaced in audit
  }
  auth: admin
```

### 10.2 Service / controller

`AdminBalanceAdjustmentController::store(AdminBalanceAdjustmentRequest, User $user)` calls:

```php
$this->walletMutator->mutate(
    userId: $user->id,
    type: WalletTransactionType::ADJUSTMENT,
    direction: WalletTransactionDirection::from($validated['direction']),
    amount: $validated['amount'],
    reference: null,
    createdByUserId: $request->user()->id,
    note: $validated['reason'],
);
```

Behavior: insufficient-balance error returns 409 with structured error. Otherwise 200 with the new transaction.

---

## 11. Phase 10 — Wallet Read APIs

User-facing reads — needed by frontend:

```
GET /api/v1/me/wallet                          -- { id, balance, currency, currency_locked_at, bank_name?, account_name?, account_number? }
GET /api/v1/me/wallet/transactions             -- list own ledger entries (paginated, filterable by ?type, ?from, ?to)
```

Admin reads:
```
GET /api/v1/admin/users/{user}/wallet
GET /api/v1/admin/users/{user}/wallet/transactions
```

Implementation: `WalletController::show`, `WalletTransactionController::index`, `AdminWalletController::*`. Straightforward — no balance writes.

---

## 12. Phase 11 — Routes Summary

Final `routes/api.php` under the existing `Route::prefix('v1')->middleware(['auth:sanctum', 'not_banned'])` group:

```php
// Wallet (self)
Route::get('/me/wallet', [WalletController::class, 'show']);
Route::put('/me/wallet/currency', [WalletCurrencyController::class, 'set']);
Route::get('/me/wallet/transactions', [WalletTransactionController::class, 'index']);

// Bank info (existing — unchanged routes)
Route::get('/me/bank-info', [WalletBankInfoController::class, 'show']);
Route::post('/me/bank-info', [WalletBankInfoController::class, 'store']);
Route::put('/me/bank-info', [WalletBankInfoController::class, 'update']);
Route::delete('/me/bank-info', [WalletBankInfoController::class, 'destroy']);

// Deposits (self)
Route::prefix('deposits')->controller(DepositController::class)->group(function () {
    Route::get('/', 'index');
    Route::post('/', 'store');
    Route::get('/{deposit}', 'show');
    Route::get('/{deposit}/proof', 'downloadProof')->name('deposits.proof');
    Route::post('/{deposit}/cancel', 'cancel');
});

// Withdrawals (self)
Route::prefix('withdrawals')->controller(WithdrawalController::class)->group(function () {
    Route::get('/', 'index');
    Route::post('/', 'store');
    Route::get('/{withdrawal}', 'show');
    Route::get('/{withdrawal}/proof', 'downloadProof')->name('withdrawals.proof');
    Route::post('/{withdrawal}/cancel', 'cancel');
});

// Bets (self) — pay-slip routes REMOVED
Route::prefix('bets')->controller(BetController::class)->group(function () {
    Route::get('/', 'index');
    Route::get('/accepted-payments', 'acceptedPayments');
    Route::get('/payout-history', 'payoutHistory');
    Route::get('/{bet}', 'show');
    Route::post('/', 'store');
    Route::delete('/{bet}', 'destroy');
});

// Admin
Route::prefix('admin')->middleware('role:admin')->group(function () {
    Route::prefix('deposits')->controller(AdminDepositController::class)->group(function () {
        Route::get('/', 'index');
        Route::get('/{deposit}', 'show');
        Route::post('/{deposit}/approve', 'approve');
        Route::post('/{deposit}/reject', 'reject');
    });

    Route::prefix('withdrawals')->controller(AdminWithdrawalController::class)->group(function () {
        Route::get('/', 'index');
        Route::get('/{withdrawal}', 'show');
        Route::post('/{withdrawal}/complete', 'complete');
        Route::post('/{withdrawal}/reject', 'reject');
    });

    Route::prefix('users/{user}')->group(function () {
        Route::get('/wallet', [AdminWalletController::class, 'show']);
        Route::get('/wallet/transactions', [AdminWalletController::class, 'transactions']);
        Route::post('/balance-adjustment', [AdminBalanceAdjustmentController::class, 'store']);
    });

    Route::get('/bets', [BetController::class, 'adminIndex']);
    Route::patch('/bets/{bet}/status', [BetController::class, 'updateReviewStatus']);
    // /bets/{bet}/payout and /bets/{bet}/refund REMOVED
});
```

---

## 13. Phase 12 — Tests to Write

All under `tests/Feature/` following the existing convention. **Each test uses `RefreshDatabase` and exercises one behavior.**

### 13.1 Wallet currency setup
- `WalletCurrencySetupTest::test_user_can_set_currency_once`
- `WalletCurrencySetupTest::test_second_set_attempt_returns_422`
- `WalletCurrencySetupTest::test_invalid_currency_value_returns_422`
- `WalletCurrencySetupTest::test_creates_wallet_row_if_missing`

### 13.2 `WalletMutator`
- `WalletMutatorTest::test_credit_increments_balance_and_writes_ledger`
- `WalletMutatorTest::test_debit_below_zero_throws_and_writes_nothing`
- `WalletMutatorTest::test_concurrent_debits_serialize_via_lock` (use parallel processes / `DB::transaction` with `pcntl_fork` or accept this is a manual-verify case)
- `WalletMutatorTest::test_unset_currency_throws`
- `WalletMutatorTest::test_zero_or_negative_amount_throws`
- `WalletMutatorTest::test_polymorphic_reference_stored_correctly`

### 13.3 Deposits
- `DepositCreateTest::test_user_can_submit_deposit_with_proof`
- `DepositCreateTest::test_requires_wallet_currency_set`
- `DepositCreateTest::test_rejects_currency_mismatch_with_wallet`
- `DepositCreateTest::test_proof_image_required`
- `DepositApproveTest::test_admin_approve_full_amount_credits_balance_and_writes_ledger`
- `DepositApproveTest::test_admin_partial_approve_requires_admin_note`
- `DepositApproveTest::test_admin_partial_approve_credits_approved_amount`
- `DepositApproveTest::test_cannot_approve_non_pending_deposit`
- `DepositRejectTest::test_admin_reject_does_not_touch_balance`
- `DepositRejectTest::test_reject_requires_reason`
- `DepositCancelTest::test_user_can_cancel_own_pending_deposit`
- `DepositCancelTest::test_cancel_non_pending_returns_409`
- `DepositListTest::test_user_lists_only_own`
- `DepositListTest::test_admin_can_filter_by_status`
- `DepositMultiPendingTest::test_user_can_have_multiple_pending_deposits`

### 13.4 Withdrawals
- `WithdrawalCreateTest::test_user_can_submit_when_balance_sufficient`
- `WithdrawalCreateTest::test_insufficient_balance_returns_409_no_row_persisted`
- `WithdrawalCreateTest::test_requires_complete_bank_info`
- `WithdrawalCreateTest::test_only_one_pending_at_a_time`
- `WithdrawalCreateTest::test_bank_snapshot_captures_current_info`
- `WithdrawalCreateTest::test_currency_must_match_wallet`
- `WithdrawalCompleteTest::test_admin_complete_attaches_proof_no_ledger_write`
- `WithdrawalCompleteTest::test_complete_requires_proof_image`
- `WithdrawalRejectTest::test_admin_reject_credits_balance_back`
- `WithdrawalRejectTest::test_reject_requires_reason`
- `WithdrawalCancelTest::test_user_cancel_credits_balance_back`

### 13.5 Bets
- `BetCreateTest::test_create_debits_balance_and_sets_status_accepted`
- `BetCreateTest::test_create_rejects_when_balance_insufficient_nothing_persisted`
- `BetCreateTest::test_create_rejects_when_wallet_currency_unset`
- `BetCreateTest::test_create_rejects_when_currency_mismatches_wallet`
- `BetCreateTest::test_pay_slip_field_no_longer_accepted` (or test it's silently ignored — implementer's call, but recommended: 422 if present)
- `BetCreateTest::test_transaction_id_field_no_longer_accepted`
- `BetCreateTest::test_create_requires_complete_bank_info` (kept from existing)
- `BetAdminTransitionTest::test_reject_pending_or_accepted_credits_back_balance`
- `BetAdminTransitionTest::test_refund_credits_back_balance`
- `BetAdminTransitionTest::test_double_reject_does_not_double_credit`

### 13.6 Settlement
- `SettlementAutoCreditTest::test_won_bet_credits_user_balance_and_sets_paid_out`
- `SettlementAutoCreditTest::test_lost_bet_does_not_credit`
- `SettlementAutoCreditTest::test_settlement_idempotent_no_double_credit_on_rerun`
- `SettlementAutoCreditTest::test_settlement_ledger_entry_references_correct_bet`

### 13.7 Admin manual adjustment
- `AdminBalanceAdjustmentTest::test_credit_increments_balance_writes_ledger`
- `AdminBalanceAdjustmentTest::test_debit_below_zero_returns_409`
- `AdminBalanceAdjustmentTest::test_reason_required_min_length`
- `AdminBalanceAdjustmentTest::test_non_admin_returns_403`
- `AdminBalanceAdjustmentTest::test_unset_wallet_currency_returns_409`

### 13.8 Wallet reads
- `WalletReadTest::test_me_wallet_returns_balance_and_currency`
- `WalletReadTest::test_me_wallet_transactions_paginates`
- `WalletReadTest::test_me_wallet_transactions_filters_by_type`
- `AdminWalletReadTest::test_admin_can_view_any_user_wallet`

---

## 14. Acceptance Criteria

Before declaring done, all of the following must hold:

- [ ] `php artisan migrate:fresh --seed` runs clean against a fresh DB.
- [ ] `php artisan test` is green; all new tests above present and passing.
- [ ] Grepping the codebase for `$wallet->update(['balance'` returns **only** `WalletMutator.php`.
- [ ] Grepping for `pay_slip` returns no live production code paths (only historical migrations / old artifacts deleted or commented).
- [ ] `routes:list | grep payout` returns no admin payout routes.
- [ ] `routes:list | grep deposit` and `routes:list | grep withdrawal` show the full new route set.
- [ ] The `BetPayoutService` class is deleted.
- [ ] A `README.md` section ("Wallet invariants") documents the `WalletMutator`-only rule.
- [ ] `AdminSeeder` (existing) is updated so the admin user's wallet has `currency` set to a sensible default (MMK) or remains nullable for explicit setup — implementer's call, but document the choice.
- [ ] Seed a couple of normal users with `currency = MMK`, `balance > 0` so manual smoke-testing works.

---

## 15. Out of Scope (Explicitly Deferred)

- **All frontend changes.** React/TS UI, API client, types, i18n strings — separate task with its own grill-me pass.
- **Notifications** (push / email when deposit approved, withdrawal completed, bet won).
- **Per-currency conversion rates** (only relevant if multi-currency conversions are ever needed; currently no conversion happens since wallets are single-currency).
- **Rate limiting** on deposits / withdrawals beyond standard Laravel throttling.
- **Reconciliation reports** (sum of ledger should equal balance; nice-to-have admin tool).
- **Multi-admin approval for large adjustments** — deliberately skipped (Q7 option C rejected).
- **`app_settings` for deposit/withdrawal min/max** — deliberately skipped per Q5; revisit when ops needs it.

---

## 16. Execution Order Recommended for Claude Code

1. Phase 1 (schema) + Phase 2 (enums) — together in one PR. Migrations + enums only; no controllers yet.
2. Phase 3 (`WalletMutator`) — with its full unit test coverage. Nothing in the app calls it yet.
3. Phase 4 (wallet currency setup) + Phase 10 (wallet read APIs) — together. Now you can manually set currency on a seeded user.
4. Phase 5 (deposit flow) — full end-to-end with tests.
5. Phase 6 (withdrawal flow) — full end-to-end with tests.
6. Phase 7 (bet flow rewrite) — this is the most invasive change; do it after deposit/withdrawal are solid so the testing surface is small.
7. Phase 8 (settlement auto-credit) — small change, big consequence; ship behind dedicated tests.
8. Phase 9 (admin manual adjustment) — small bolt-on.
9. Phase 11 (routes summary review) — final sanity check.
10. Phase 13 (full test suite green) — gate before merging.

Each phase should land in its own commit/PR if possible. Especially Phase 3 — `WalletMutator` correctness is the foundation everything else stands on.

---

*End of plan. Feed this file to Claude Code with the instruction: "Implement this plan phase-by-phase. After each phase, run `php artisan test` and stop if anything fails."*
