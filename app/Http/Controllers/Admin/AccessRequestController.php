<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\SkNotice;
use App\Models\AccessRequest;
use App\Models\ActivityLog;
use App\Models\Barangay;
use App\Models\User;
use App\Support\AccessMailer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Step 1 review: someone asked for access. The Admin sees the details and can
 *   - approve  -> a secret code is emailed to them,
 *   - message  -> email them about a mistake; the request STAYS pending (nothing is approved or rejected),
 *   - deny     -> they are told, and may send a new request.
 */
class AccessRequestController extends Controller
{
    public function index(Request $request): View
    {
        $status  = (string) $request->query('status', AccessRequest::PENDING);
        $filters = [
            'q'           => trim((string) $request->query('q', '')),
            'barangay_id' => (int) $request->query('barangay_id', 0),
        ];

        $requests = $this->filtered($filters)
            ->with('barangay')
            ->when(array_key_exists($status, AccessRequest::STATUSES), fn ($q) => $q->where('status', $status))
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        // the numbers on the tabs follow the search and the barangay, so they match what each tab would show
        $counts = $this->filtered($filters)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return view('admin.requests.index', [
            'requests'  => $requests,
            'status'    => $status,
            'counts'    => $counts,
            'filters'   => $filters,
            'barangays' => Barangay::orderBy('name')->get(['id', 'name']),
        ]);
    }

    /** Search by name or email, and narrow to one barangay. */
    private function filtered(array $filters): Builder
    {
        return AccessRequest::query()
            ->when($filters['barangay_id'] > 0, fn ($q) => $q->where('barangay_id', $filters['barangay_id']))
            ->when($filters['q'] !== '', function ($q) use ($filters) {
                $like = '%' . $filters['q'] . '%';

                $q->where(function ($w) use ($like) {
                    $w->where('first_name', 'like', $like)
                        ->orWhere('last_name', 'like', $like)
                        ->orWhere('middle_name', 'like', $like)
                        ->orWhere('email', 'like', $like);
                });
            });
    }

    public function show(AccessRequest $accessRequest): View
    {
        $accessRequest->load('barangay');

        return view('admin.requests.show', [
            'r'      => $accessRequest,
            'seatBlock' => User::seatBlock($accessRequest->barangay_id, $accessRequest->role),
        ]);
    }

    /** Approve (or send a fresh code for a request that was approved earlier but not used yet). */
    public function approve(AccessRequest $accessRequest): RedirectResponse
    {
        $back = redirect()->route('admin.requests.show', $accessRequest);

        if (! in_array($accessRequest->status, [AccessRequest::PENDING, AccessRequest::APPROVED], true)) {
            return $back->withErrors(['request' => 'This request was already handled.']);
        }

        $barangay = $accessRequest->barangay;

        if ($block = User::seatBlock($accessRequest->barangay_id, $accessRequest->role)) {
            return $back->withErrors(['request' => $block]);
        }

        $code = AccessRequest::newCode();
        $days = (int) config('sk.code_days', 7);

        $accessRequest->forceFill([
            'status'          => AccessRequest::APPROVED,
            'code_hash'       => AccessRequest::hashCode($code),
            'code_expires_at' => now()->addDays($days),
            'code_used_at'    => null,
            'reviewed_at'     => now(),
        ])->save();

        $sent = AccessMailer::send($accessRequest->email, new SkNotice(
            'Your SK Finance secret code',
            'Your request was approved',
            [
                "Hello {$accessRequest->first_name},",
                "The SK Federation approved your request to join SK Finance as {$accessRequest->role_label} of {$barangay->name}.",
                "To make your account, open the Create account page, choose the same barangay and position, and type the secret code below. It works once and expires in {$days} days. After you make your account, the SK Federation will check it one more time before you can sign in.",
            ],
            $code,
            url('/register'),
            'Create my account'
        ));

        ActivityLog::record('approved', $accessRequest, "Approved the request of {$accessRequest->full_name} ({$barangay->name}) and issued a secret code");

        $response = $back->with(
            $sent ? 'success' : 'warning',
            $sent
                ? "Approved. The secret code was emailed to {$accessRequest->email}."
                : 'Approved, but the email could not be sent. Give the person the code below yourself.'
        );

        // Only if the email failed: show the code once, so the Admin can pass it on another way
        return $sent ? $response : $response->with('code_fallback', $code);
    }

    /** Write to the applicant about a mistake. The request stays as it is (pending or code sent). */
    public function message(Request $request, AccessRequest $accessRequest): RedirectResponse
    {
        $data = $request->validate(['message' => ['required', 'string', 'max:2000']], [
            'message.required' => 'Please write the message.',
        ]);

        $sent = AccessMailer::send($accessRequest->email, new SkNotice(
            'A note about your SK Finance request',
            'The SK Federation sent you a message',
            [
                "Hello {$accessRequest->first_name},",
                $data['message'],
                'You can reply to this email. Your request stays in review until it is sorted out.',
            ]
        ));

        $accessRequest->forceFill(['last_message' => $data['message'], 'last_message_at' => now()])->save();

        ActivityLog::record('messaged', $accessRequest, "Sent a message to {$accessRequest->full_name} about the access request");

        $back = redirect()->route('admin.requests.show', $accessRequest);

        return $sent
            ? $back->with('success', "Message emailed to {$accessRequest->email}. The request stays pending.")
            : $back->with('warning', 'The message was saved but the email could not be sent.');
    }

    public function deny(Request $request, AccessRequest $accessRequest): RedirectResponse
    {
        $back = redirect()->route('admin.requests.show', $accessRequest);

        if (! in_array($accessRequest->status, [AccessRequest::PENDING, AccessRequest::APPROVED], true)) {
            return $back->withErrors(['request' => 'This request was already handled.']);
        }

        $data   = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $reason = filled($data['reason'] ?? null) ? trim($data['reason']) : null;

        $accessRequest->forceFill([
            'status'          => AccessRequest::DENIED,
            'code_hash'       => null,
            'code_expires_at' => null,
            'last_message'    => $reason,
            'last_message_at' => $reason ? now() : $accessRequest->last_message_at,
            'reviewed_at'     => now(),
        ])->save();

        $lines = ["Hello {$accessRequest->first_name},", 'The SK Federation could not approve your request to join SK Finance.'];
        if ($reason) {
            $lines[] = "Reason: {$reason}";
        }
        $lines[] = 'If something was wrong, you can send a new request with the correct details.';

        $sent = AccessMailer::send($accessRequest->email, new SkNotice('Your SK Finance request', 'Your request was not approved', $lines));

        ActivityLog::record('denied', $accessRequest, "Denied the request of {$accessRequest->full_name} ({$accessRequest->barangay->name})");

        $response = redirect()->route('admin.requests.index');

        return $sent
            ? $response->with('success', 'Request denied and the person was emailed.')
            : $response->with('warning', 'Request denied, but the email could not be sent.');
    }
}
