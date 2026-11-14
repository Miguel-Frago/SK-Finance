<?php

namespace App\Providers;

use App\Models\AccessRequest;
use App\Models\Budget;
use App\Models\User;
use Illuminate\Support\Facades\Blade;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Who can add, edit or delete budgets, expenses and fund movements: the treasurer, and the SK chairman
        // only while the treasurer has switched "Chairman can edit" on for the barangay.
        // Used as  ->middleware('can:manage')  on routes and  @can('manage')  in views.
        // Messaging limits. Named limiters keep their own count, so the box's regular polling can never use up the
        // allowance for sending. Counted per signed-in person (barangay account or Admin), by address when not signed in.
        $messagingKey = function (Request $request): string {
            $admin = $request->routeIs('admin.*');
            $id = Auth::guard($admin ? 'admin' : 'web')->id();

            return $id ? ($admin ? 'admin:' : 'user:') . $id : 'ip:' . $request->ip();
        };
        // Forgot password: a few tries per hour for each address on the network, so the form cannot be used to flood inboxes
        RateLimiter::for('password-reset', fn (Request $request) => Limit::perHour(10)->by('ip:' . $request->ip()));
        RateLimiter::for('messages-read', fn (Request $request) => Limit::perMinute(300)->by($messagingKey($request)));    // list, people, open chat, polling, read
        RateLimiter::for('messages-write', fn (Request $request) => Limit::perMinute(40)->by($messagingKey($request)));    // send, edit, delete, start a chat, hide, group changes
        RateLimiter::for('messages-upload', fn (Request $request) => Limit::perMinute(60)->by($messagingKey($request)));   // one request per file
        RateLimiter::for('messages-files', fn (Request $request) => Limit::perMinute(900)->by($messagingKey($request)));   // photos and videos loading inside a chat

        Gate::define('manage', fn (User $user) => $user->canManageFinance());

        // The treasurer's switch that lets the SK chairman edit finance records.
        Gate::define('barangay-settings', fn (User $user) => $user->isTreasurer());
        Gate::define('reconcile', fn (User $user) => $user->isTreasurer());   // only the Treasurer saves a bank reconciliation

        // The names and signatures on the report: treasurer and SK chairman only.
        Gate::define('edit-report', fn (User $user) => $user->canEditReport());

        // Printing / saving as PDF: the treasurer and the SK chairman. Secretary and kagawads only look.
        // Used as @can('print') in views.
        Gate::define('print', fn (User $user) => $user->canPrint());

        // The SK Secretary writes the minutes of meetings; the Secretary and the Chairman can print them.
        Gate::define('manage-minutes', fn (User $user) => $user->canManageMinutes());
        Gate::define('print-minutes', fn (User $user) => $user->canPrintMinutes());

        // @peso($amount)  ->  ₱12,345.00
        Blade::directive('peso', function (string $expression) {
            return "<?php echo '₱' . number_format((float) ({$expression}), 2); ?>";
        });

        // Budget list + the active budget are available to the main layout (switcher in the top bar)
        // and to the page views themselves. A child page's content is built before its layout,
        // so the pages need to be listed here too, not only 'layouts.app'.
        // The Admin menu shows how many people are waiting
        View::composer('layouts.admin', function ($view) {
            $view->with('waiting', [
                'requests' => AccessRequest::where('status', AccessRequest::PENDING)->count(),
                'accounts' => User::where('status', User::STATUS_PENDING)->count(),
                'unlocks'  => \App\Models\PeriodLock::withoutGlobalScopes()->where('status', \App\Models\PeriodLock::UNLOCK_REQUESTED)->count(),
            ]);
        });

        View::composer([
            'layouts.app',
            'dashboard.*',
            'budget.*',
            'transactions.*',
            'fund-movements.*',
            'journal.*',
            'ledger.*',
            'reports.*',
        ], function ($view) {
            $view->with('budgets', Budget::latestFirst()->get())
                 ->with('currentBudget', Budget::current());
        });
    }
}