<?php

use App\Http\Controllers\Admin\AccessRequestController as AdminAccessRequestController;
use App\Http\Controllers\Admin\AccountController as AdminAccountController;
use App\Http\Controllers\Admin\ActivityArchiveController as AdminActivityArchiveController;
use App\Http\Controllers\Admin\ActivityLogController as AdminActivityLogController;
use App\Http\Controllers\Admin\AuthController as AdminAuthController;
use App\Http\Controllers\Admin\BarangayController as AdminBarangayController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\GuideCharacterController as AdminGuideCharacterController;
use App\Http\Controllers\Admin\LiveController as AdminLiveController;
use App\Http\Controllers\Admin\UnlockRequestController as AdminUnlockRequestController;
use App\Http\Controllers\Admin\SystemController as AdminSystemController;
use App\Http\Controllers\PeriodLockController;
use App\Http\Controllers\ReconciliationController;
use App\Support\MessageRoutes;
use App\Http\Controllers\Admin\ProfileController as AdminProfileController;
use App\Http\Controllers\Admin\ThemeController as AdminThemeController;
use App\Http\Controllers\AccessIdController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BarangaySettingsController;
use App\Http\Controllers\BudgetController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FundMovementController;
use App\Http\Controllers\JoinController;
use App\Http\Controllers\JournalController;
use App\Http\Controllers\LedgerController;
use App\Http\Controllers\MinutesController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProfileDetailsController;
use App\Http\Controllers\QrLoginController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\RequestAccessController;
use App\Http\Controllers\ThemeController;
use App\Http\Controllers\TreasurerGuideController;
use App\Http\Controllers\TransactionController;
use App\Http\Middleware\EnsureAccountActive;
use App\Http\Middleware\EnsurePasswordChanged;
use App\Http\Controllers\ForcedPasswordController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Middleware\EnsureAdminReady;
use Illuminate\Support\Facades\Route;

// --- Guest routes: sign in, ask for access, and create an account (needs the secret code the Admin emailed) ---
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->name('login.attempt');
    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:6,1')->name('register.attempt');

    // Step 1 of joining: ask the Admin for access
    Route::get('/request-access', [RequestAccessController::class, 'create'])->name('request-access');
    Route::post('/request-access', [RequestAccessController::class, 'store'])->middleware('throttle:6,1')->name('request-access.store');
    Route::get('/request-access/sent', [RequestAccessController::class, 'sent'])->name('request-access.sent');

    // Forgot the password: a link by email (the same answer for every address)
    Route::get('/forgot-password', [PasswordResetController::class, 'request'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetController::class, 'sendLink'])->middleware('throttle:password-reset')->name('password.email');
    Route::get('/reset-password/{token}', [PasswordResetController::class, 'showReset'])->name('password.reset');
    Route::post('/reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:password-reset')->name('password.update');
});

// --- Sign in by scanning the QR code on the SK Access ID, then typing the PIN ---
// Not inside the "guest" group on purpose: a scan must ask for the PIN even if this browser is already signed in.
Route::get('/qr/{token}', [QrLoginController::class, 'show'])->where('token', '[A-Za-z0-9]{48}')->name('qr.show');
Route::post('/qr/{token}', [QrLoginController::class, 'login'])->where('token', '[A-Za-z0-9]{48}')->middleware('throttle:20,1')->name('qr.login');

// --- Everyone signed in. An account that was switched off is signed out on its next click. ---
Route::middleware(['auth', EnsureAccountActive::class, EnsurePasswordChanged::class])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    // An account with a temporary password (given by the Admin) can only do this until it has chosen its own
    Route::get('/password/choose', [ForcedPasswordController::class, 'show'])->name('password.choose');
    Route::put('/password/choose', [ForcedPasswordController::class, 'update'])->middleware('throttle:10,1')->name('password.choose.update');

    // --- Dashboard: summary cards and charts ---
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    // =====================================================================================
    // Everyone signed in (treasurer AND SK chairman): view and print
    // =====================================================================================
    Route::get('/budget', [BudgetController::class, 'index'])->name('budget.index');
    Route::post('/budget/switch', [BudgetController::class, 'switch'])->name('budget.switch'); // only changes which budget is shown

    Route::get('/transactions', [TransactionController::class, 'index'])->name('transactions.index');
    Route::get('/fund-movements', [FundMovementController::class, 'index'])->name('fund-movements.index');

    // --- Journal (read-only): journal.index ---
    Route::get('/journal', [JournalController::class, 'index'])->name('journal.index');

    // --- Ledger (read-only): ledger.index ---
    Route::get('/ledger', [LedgerController::class, 'index'])->name('ledger.index');

    // --- Colour theme of the signed-in person (treasurer AND chairman) ---
    Route::put('/theme', [ThemeController::class, 'update'])->middleware('throttle:30,1')->name('theme.update');

    // --- Which SK Guide character the signed-in person picked (boy or girl) ---
    Route::put('/guide-character', [TreasurerGuideController::class, 'update'])->middleware('throttle:30,1')->name('guide.character');

    // --- Report generation: the chairman can edit this part too (filters, names and signatures) ---
    Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
    Route::put('/reports/signatories', [ReportController::class, 'saveSignatories'])->middleware(['can:edit-report', 'throttle:30,1'])->name('reports.signatories');

    // --- In-app messaging: chats, groups, announcements, files. JSON only; the Messages box in the top bar uses these ---
    MessageRoutes::register();

    // --- Quarter lock (the Treasurer asks, the Chairman approves; the Admin decides on unlocking) and bank reconciliation ---
    Route::get('/period-locks', [PeriodLockController::class, 'index'])->name('period-locks.index');
    Route::prefix('/period-locks/{year}/{quarter}')->whereNumber(['year', 'quarter'])->middleware('throttle:30,1')->group(function () {
        Route::post('/request', [PeriodLockController::class, 'requestLock'])->name('period-locks.request');
        Route::post('/withdraw', [PeriodLockController::class, 'withdraw'])->name('period-locks.withdraw');
        Route::post('/approve', [PeriodLockController::class, 'approve'])->name('period-locks.approve');
        Route::post('/reject', [PeriodLockController::class, 'reject'])->name('period-locks.reject');
        Route::post('/unlock', [PeriodLockController::class, 'requestUnlock'])->name('period-locks.unlock');
    });
    Route::get('/reconciliation', [ReconciliationController::class, 'index'])->name('reconciliation.index');
    Route::post('/reconciliation', [ReconciliationController::class, 'store'])->middleware(['can:reconcile', 'throttle:20,1'])->name('reconciliation.store');

    // --- Minutes of meetings: the SK Secretary writes them; everyone in the barangay reads the final ones ---
    Route::get('/minutes', [MinutesController::class, 'index'])->name('minutes.index');
    Route::middleware('can:manage-minutes')->group(function () {
        Route::get('/minutes/create', [MinutesController::class, 'create'])->name('minutes.create');
        Route::post('/minutes', [MinutesController::class, 'store'])->name('minutes.store');
        Route::get('/minutes/{minute}/edit', [MinutesController::class, 'edit'])->name('minutes.edit');
        Route::put('/minutes/{minute}', [MinutesController::class, 'update'])->name('minutes.update');
        Route::delete('/minutes/{minute}', [MinutesController::class, 'destroy'])->name('minutes.destroy');
    });
    Route::get('/minutes/{minute}', [MinutesController::class, 'show'])->name('minutes.show');
    Route::get('/minutes/{minute}/print', [MinutesController::class, 'print'])->middleware('can:print-minutes')->name('minutes.print');

    // =====================================================================================
    // Treasurer (and the SK chairman while the treasurer allows it): anything that adds, edits or deletes data
    // =====================================================================================
    Route::middleware('can:manage')->group(function () {

        // --- Share sign-up: big QR codes for the Create account and Sign in pages ---
        Route::get('/join', [JoinController::class, 'show'])->name('join.show');

        // --- Budget & allocation ---
        Route::prefix('budget')->name('budget.')->group(function () {
            Route::post('/', [BudgetController::class, 'store'])->name('store');

            Route::get('/supplemental/create', [BudgetController::class, 'createSupplemental'])->name('supplemental.create');
            Route::post('/supplemental', [BudgetController::class, 'storeSupplemental'])->name('supplemental.store');

            Route::put('/{budget}', [BudgetController::class, 'update'])->name('update');

            Route::post('/{budget}/categories', [BudgetController::class, 'storeCategory'])->name('categories.store');
            Route::put('/categories/{category}', [BudgetController::class, 'updateCategory'])->name('categories.update');
            Route::delete('/categories/{category}', [BudgetController::class, 'destroyCategory'])->name('categories.destroy');

            Route::post('/categories/{category}/subcategories', [BudgetController::class, 'storeSubcategory'])->name('subcategories.store');
            Route::put('/subcategories/{subcategory}', [BudgetController::class, 'updateSubcategory'])->name('subcategories.update');
            Route::delete('/subcategories/{subcategory}', [BudgetController::class, 'destroySubcategory'])->name('subcategories.destroy');
        });

        // --- Transactions (expenses): create / store / edit / update. Never deleted: they are VOIDED with a reason ---
        Route::resource('transactions', TransactionController::class)->except(['index', 'show', 'destroy']);
        Route::post('/transactions/{transaction}/void', [TransactionController::class, 'void'])->middleware('throttle:30,1')->name('transactions.void');

        // --- Fund movements (receipts, withdrawals, deposits): create / store / edit / update, and void ---
        Route::resource('fund-movements', FundMovementController::class)
            ->except(['index', 'show', 'destroy'])
            ->parameters(['fund-movements' => 'fundMovement']);
        Route::post('/fund-movements/{fundMovement}/void', [FundMovementController::class, 'void'])->middleware('throttle:30,1')->name('fund-movements.void');
    });

    // --- My profile: name, email and password of the signed-in treasurer ---
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('/profile', [ProfileController::class, 'update'])->middleware('throttle:10,1')->name('profile.update');
    Route::put('/profile/password', [ProfileController::class, 'updatePassword'])->middleware('throttle:6,1')->name('profile.password');

    // --- The treasurer's switch: may the SK chairman add and edit finance records? ---
    Route::put('/barangay/settings', [BarangaySettingsController::class, 'update'])
        ->middleware(['can:barangay-settings', 'throttle:20,1'])->name('barangay.settings');

    // --- Photo and position printed on the Access ID ---
    Route::put('/profile/details', [ProfileDetailsController::class, 'update'])->middleware('throttle:20,1')->name('profile.details');

    // --- QR Access ID: create / replace (POST) and revoke (DELETE) ---
    Route::post('/profile/access-id', [AccessIdController::class, 'store'])->middleware('throttle:6,1')->name('access-id.store');
    Route::get('/profile/access-id/card', [AccessIdController::class, 'card'])->name('access-id.card');
    Route::post('/profile/access-id/card/done', [AccessIdController::class, 'done'])->name('access-id.card.done');
    Route::delete('/profile/access-id', [AccessIdController::class, 'destroy'])->middleware('throttle:6,1')->name('access-id.destroy');

});

// =====================================================================================
// The Admin (SK Federation President): a separate sign-in and a separate system.
// Checks and approves people, switches accounts on and off, and monitors activity. No finance data is shown here.
// =====================================================================================
Route::prefix('admin')->name('admin.')->group(function () {
    Route::get('/login', [AdminAuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AdminAuthController::class, 'login'])->middleware('throttle:20,1')->name('login.attempt');

    Route::middleware(['auth:admin', EnsureAdminReady::class])->group(function () {
        Route::post('/logout', [AdminAuthController::class, 'logout'])->name('logout');

        // The Admin's own account: details, password, and the handover to a new president
        Route::get('/profile', [AdminProfileController::class, 'edit'])->name('profile');
        Route::put('/profile', [AdminProfileController::class, 'updateDetails'])->middleware('throttle:10,1')->name('profile.details');
        Route::put('/profile/password', [AdminProfileController::class, 'updatePassword'])->middleware('throttle:10,1')->name('profile.password');
        Route::post('/profile/handover', [AdminProfileController::class, 'handover'])->middleware('throttle:5,1')->name('profile.handover');
        Route::post('/profile/test-email', [AdminProfileController::class, 'testEmail'])->middleware('throttle:5,1')->name('profile.test-email');

        // The Admin's own colour theme (the Theme button in the top bar)
        Route::put('/theme', [AdminThemeController::class, 'update'])->middleware('throttle:30,1')->name('theme.update');

        // Which SK Guide character the Admin picked (boy or girl)
        Route::put('/guide-character', [AdminGuideCharacterController::class, 'update'])->middleware('throttle:30,1')->name('guide.character');

        Route::get('/', [AdminDashboardController::class, 'index'])->name('dashboard');

        // Asked every few seconds by the open Admin page: did anything change? (so the page updates by itself)
        Route::get('/live', [AdminLiveController::class, 'state'])->middleware('throttle:150,1')->name('live');

        // --- In-app messaging: chats, groups, announcements, files. JSON only; the Messages box in the top bar uses these ---
        MessageRoutes::register();

        // Step 1: access requests
        Route::get('/requests', [AdminAccessRequestController::class, 'index'])->name('requests.index');
        Route::get('/requests/{accessRequest}', [AdminAccessRequestController::class, 'show'])->name('requests.show');
        Route::post('/requests/{accessRequest}/approve', [AdminAccessRequestController::class, 'approve'])->name('requests.approve');
        Route::post('/requests/{accessRequest}/message', [AdminAccessRequestController::class, 'message'])->name('requests.message');
        Route::post('/requests/{accessRequest}/deny', [AdminAccessRequestController::class, 'deny'])->name('requests.deny');

        // Step 2: accounts waiting for approval, and switching accounts on and off
        Route::get('/accounts', [AdminAccountController::class, 'index'])->name('accounts.index');
        Route::get('/accounts/{account}', [AdminAccountController::class, 'show'])->name('accounts.show');
        Route::post('/accounts/{account}/approve', [AdminAccountController::class, 'approve'])->name('accounts.approve');
        Route::post('/accounts/{account}/message', [AdminAccountController::class, 'message'])->name('accounts.message');
        Route::post('/accounts/{account}/deny', [AdminAccountController::class, 'deny'])->name('accounts.deny');
        Route::post('/accounts/{account}/disable', [AdminAccountController::class, 'disable'])->name('accounts.disable');
        Route::post('/accounts/{account}/password', [AdminAccountController::class, 'resetPassword'])->middleware('throttle:20,1')->name('accounts.password');
        Route::post('/accounts/{account}/enable', [AdminAccountController::class, 'enable'])->name('accounts.enable');

        Route::get('/barangays', [AdminBarangayController::class, 'index'])->name('barangays');
        Route::get('/activity', [AdminActivityLogController::class, 'index'])->name('activity');
        Route::get('/system', [AdminSystemController::class, 'index'])->name('system');
        Route::get('/unlocks', [AdminUnlockRequestController::class, 'index'])->name('unlocks');
        Route::post('/unlocks/{id}/approve', [AdminUnlockRequestController::class, 'approve'])->whereNumber('id')->middleware('throttle:30,1')->name('unlocks.approve');
        Route::post('/unlocks/{id}/deny', [AdminUnlockRequestController::class, 'deny'])->whereNumber('id')->middleware('throttle:30,1')->name('unlocks.deny');

        // Log archive: one folder per year, inside it a file for each finished month and a whole-year file
        Route::prefix('activity/archive')->name('archive.')->where(['year' => '[0-9]{4}'])->group(function () {
            Route::get('/', [AdminActivityArchiveController::class, 'index'])->name('index');
            Route::get('/{year}', [AdminActivityArchiveController::class, 'year'])->name('year');
            Route::get('/{year}/{period}', [AdminActivityArchiveController::class, 'show'])->where('period', '0[1-9]|1[0-2]|year')->name('show');
            Route::get('/{year}/{period}/download', [AdminActivityArchiveController::class, 'download'])->where('period', '0[1-9]|1[0-2]|year')->name('download');
        });
    });
});