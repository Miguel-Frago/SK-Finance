<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\SkNotice;
use App\Models\ActivityLog;
use App\Models\Barangay;
use App\Models\User;
use App\Support\AccessMailer;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Step 2 review and succession. A person made their account with the secret code; the Admin checks the details and
 * approves (they can sign in), messages them about a mistake (the account stays pending), or denies it.
 * When a term ends the Admin switches the old account off, which frees the place for the new appointee.
 */
class AccountController extends Controller
{
    public function index(Request $request): View
    {
        $filter  = (string) $request->query('status', 'pending');
        $filters = [
            'q'           => trim((string) $request->query('q', '')),
            'barangay_id' => (int) $request->query('barangay_id', 0),
        ];

        $accounts = $this->filtered($filters)
            ->with('barangay')
            ->when($filter === 'pending', fn ($q) => $q->where('status', User::STATUS_PENDING))
            ->when($filter === 'active', fn ($q) => $q->where('status', User::STATUS_APPROVED)->where('is_active', true))
            ->when($filter === 'off', fn ($q) => $q->where('status', User::STATUS_APPROVED)->where('is_active', false))
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        // the numbers on the tabs follow the search and the barangay, so they match what each tab would show
        $counts = [
            'pending' => $this->filtered($filters)->where('status', User::STATUS_PENDING)->count(),
            'active'  => $this->filtered($filters)->where('status', User::STATUS_APPROVED)->where('is_active', true)->count(),
            'off'     => $this->filtered($filters)->where('status', User::STATUS_APPROVED)->where('is_active', false)->count(),
            'all'     => $this->filtered($filters)->count(),
        ];

        return view('admin.accounts.index', [
            'accounts'  => $accounts,
            'filter'    => $filter,
            'counts'    => $counts,
            'filters'   => $filters,
            'barangays' => Barangay::orderBy('name')->get(['id', 'name']),
            'lastSeen'  => $this->lastSignIns($accounts->getCollection()->pluck('id')->all()),
        ]);
    }

    /** Search by name or email, and narrow to one barangay. */
    private function filtered(array $filters): Builder
    {
        return User::query()
            ->when($filters['barangay_id'] > 0, fn ($q) => $q->where('barangay_id', $filters['barangay_id']))
            ->when($filters['q'] !== '', function ($q) use ($filters) {
                $like = '%' . $filters['q'] . '%';

                $q->where(function ($w) use ($like) {
                    $w->where('name', 'like', $like)->orWhere('email', 'like', $like);
                });
            });
    }

    /**
     * When each account last signed in, taken from the activity log (password and QR sign-ins both write a "login"
     * line), so it also covers sign-ins from before this screen existed. Returns [user id => Carbon].
     *
     * @param  array<int, int>  $ids
     */
    private function lastSignIns(array $ids): Collection
    {
        if ($ids === []) {
            return collect();
        }

        return ActivityLog::query()
            ->where('action', 'login')
            ->whereIn('user_id', $ids)
            ->selectRaw('user_id, max(created_at) as last_at')
            ->groupBy('user_id')
            ->pluck('last_at', 'user_id')
            ->map(fn ($when) => Carbon::parse($when));
    }

    public function show(User $account): View
    {
        $account->load('barangay', 'accessRequest');

        return view('admin.accounts.show', [
            'a'      => $account,
            'seatBlock' => User::seatBlock($account->barangay_id, $account->role, $account->id),
            'lastSeen' => $this->lastSignIns([$account->id])->get($account->id),
        ]);
    }

    public function approve(User $account): RedirectResponse
    {
        $back = redirect()->route('admin.accounts.show', $account);

        if ($account->status !== User::STATUS_PENDING) {
            return $back->withErrors(['account' => 'This account is not waiting for approval.']);
        }

        if ($block = User::seatBlock($account->barangay_id, $account->role, $account->id)) {
            return $back->withErrors(['account' => $block]);
        }

        $account->forceFill([
            'status'         => User::STATUS_APPROVED,
            'approved_at'    => now(),
            'is_active'      => true,
            'deactivated_at' => null,
        ])->save();

        $sent = AccessMailer::send($account->email, new SkNotice(
            'Your SK Finance account is approved',
            'You can now sign in',
            [
                "Hello {$account->name},",
                "The SK Federation approved your account as {$account->role_label} of {$account->barangay->name}. You can sign in now.",
            ],
            null,
            route('login'),
            'Sign in to SK Finance'
        ));

        ActivityLog::record('approved', $account, "Approved the account of {$account->name} ({$account->role_label}, {$account->barangay->name})");

        return $sent
            ? $back->with('success', 'Account approved and the person was emailed.')
            : $back->with('warning', 'Account approved, but the email could not be sent. Tell the person yourself.');
    }

    /** Write about a mistake in the details. The account stays pending. */
    public function message(Request $request, User $account): RedirectResponse
    {
        $data = $request->validate(['message' => ['required', 'string', 'max:2000']], [
            'message.required' => 'Please write the message.',
        ]);

        $sent = AccessMailer::send($account->email, new SkNotice(
            'A note about your SK Finance account',
            'The SK Federation sent you a message',
            [
                "Hello {$account->name},",
                $data['message'],
                'You can reply to this email. Your account stays pending until it is sorted out.',
            ]
        ));

        // The notes are kept on the request this account came from
        $account->accessRequest?->forceFill(['last_message' => $data['message'], 'last_message_at' => now()])->save();

        ActivityLog::record('messaged', $account, "Sent a message to {$account->name} about the pending account");

        $back = redirect()->route('admin.accounts.show', $account);

        return $sent
            ? $back->with('success', "Message emailed to {$account->email}. The account stays pending.")
            : $back->with('warning', 'The message could not be emailed.');
    }

    /** Deny a pending account: it is removed (it never had access or records) and the person may request again. */
    public function deny(Request $request, User $account): RedirectResponse
    {
        if ($account->status !== User::STATUS_PENDING) {
            return redirect()->route('admin.accounts.show', $account)->withErrors(['account' => 'Only a pending account can be denied. Switch an approved one off instead.']);
        }

        $data   = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $reason = filled($data['reason'] ?? null) ? trim($data['reason']) : null;

        $lines = ["Hello {$account->name},", 'The SK Federation could not approve your SK Finance account.'];
        if ($reason) {
            $lines[] = "Reason: {$reason}";
        }
        $lines[] = 'If something was wrong, you can send a new request with the correct details.';

        $sent = AccessMailer::send($account->email, new SkNotice('Your SK Finance account', 'Your account was not approved', $lines));

        $summary = "Denied the pending account of {$account->name} ({$account->role_label}, {$account->barangay->name})";
        ActivityLog::record('denied', $account, $summary);

        $account->accessRequest?->forceFill([
            'status'          => 'denied',
            'last_message'    => $reason,
            'last_message_at' => $reason ? now() : null,
            'reviewed_at'     => now(),
        ])->save();

        $account->delete();

        $response = redirect()->route('admin.accounts.index');

        return $sent
            ? $response->with('success', 'Account denied and the person was emailed.')
            : $response->with('warning', 'Account denied, but the email could not be sent.');
    }

    /** End of term or resignation: the account can no longer sign in, but everything it recorded stays. */
    /**
     * The person cannot sign in and has lost the password: the Admin gives a temporary one, shown ONCE on this screen
     * (and optionally emailed). The person must choose their own at the next sign-in, and every browser they were signed in on is signed out.
     */
    public function resetPassword(Request $request, User $account): RedirectResponse
    {
        abort_unless($account->canSignIn(), 404);   // approved and switched on: nobody else has a password worth resetting

        $temporary = \App\Support\Passwords::temporary();
        \App\Support\Passwords::set($account, $temporary, true);

        \App\Models\ActivityLog::record('password_reset', $account, 'Gave a temporary password to ' . $account->name);

        $emailed = false;
        if ($request->boolean('email')) {
            $emailed = \App\Support\AccessMailer::send($account->email, new \App\Mail\SkNotice(
                'Your temporary SK Finance password',
                'A temporary password was set for you',
                ['Hello ' . $account->name . ',', 'The SK Federation set a temporary password for your account. Sign in with it, and you will be asked to choose your own password right away.'],
                $temporary,
            ));
        }

        return redirect()->route('admin.accounts.show', $account)
            ->with('temp_password', $temporary)
            ->with('temp_emailed', $request->boolean('email') ? ($emailed ? 'yes' : 'failed') : 'no');
    }

    public function disable(User $account): RedirectResponse
    {
        $back = redirect()->route('admin.accounts.show', $account);

        if ($account->status !== User::STATUS_APPROVED || ! $account->isActive()) {
            return $back->withErrors(['account' => 'Only an active account can be switched off.']);
        }

        $account->forceFill(['is_active' => false, 'deactivated_at' => now()])->save();

        ActivityLog::record('disabled', $account, "Switched off the account of {$account->name} ({$account->role_label}, {$account->barangay->name})");

        return $back->with('success', 'Account switched off. It can no longer sign in, and its records stay in the barangay.');
    }

    public function enable(User $account): RedirectResponse
    {
        $back = redirect()->route('admin.accounts.show', $account);

        if ($account->status !== User::STATUS_APPROVED || $account->isActive()) {
            return $back->withErrors(['account' => 'Only a switched-off account can be switched on.']);
        }

        if ($block = User::seatBlock($account->barangay_id, $account->role, $account->id)) {
            return $back->withErrors(['account' => $block]);
        }

        $account->forceFill(['is_active' => true, 'deactivated_at' => null])->save();

        ActivityLog::record('enabled', $account, "Switched the account of {$account->name} back on ({$account->role_label}, {$account->barangay->name})");

        return $back->with('success', 'Account switched on again.');
    }
}
