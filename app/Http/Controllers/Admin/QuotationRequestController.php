<?php

namespace App\Http\Controllers\Admin;

use App\Enums\QuotationRequestStatus;
use App\Http\Controllers\Controller;
use App\Models\QuotationRequest;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\ServiceCatalogue;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class QuotationRequestController extends Controller
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', QuotationRequest::class);

        $statusInput = $request->query('status');
        $status = is_string($statusInput) ? QuotationRequestStatus::tryFrom($statusInput) : null;
        $service = $request->query('service');

        $query = QuotationRequest::query()
            ->with(['customer:id,name,email', 'assignee:id,name'])
            ->withCount('quotations')
            ->latest('id');

        if ($status !== null) {
            $query->where('status', $status->value);
        }

        if (ServiceCatalogue::exists(is_string($service) ? $service : null)) {
            $query->where('service', $service);
        }

        if (filled($request->query('q'))) {
            $query->search((string) $request->query('q'));
        }

        return view('admin.quotation-requests.index', [
            'requests' => $query->paginate(20)->withQueryString(),
            'status' => $status,
            'service' => is_string($service) ? $service : null,
            'search' => $request->query('q'),
            'counts' => [
                'new' => QuotationRequest::query()->where('status', QuotationRequestStatus::New->value)->count(),
                'in_review' => QuotationRequest::query()->where('status', QuotationRequestStatus::InReview->value)->count(),
                'quoted' => QuotationRequest::query()->where('status', QuotationRequestStatus::Quoted->value)->count(),
                'open' => QuotationRequest::query()->open()->count(),
            ],
        ]);
    }

    public function show(QuotationRequest $quotationRequest): View
    {
        $this->authorize('view', $quotationRequest);

        return view('admin.quotation-requests.show', [
            'request' => $quotationRequest->load([
                'customer:id,name,email',
                'assignee:id,name',
                'quotations',
            ]),
        ]);
    }

    /** Assignment, internal notes, and closing an enquiry that came to nothing. */
    public function update(Request $request, QuotationRequest $quotationRequest): RedirectResponse
    {
        $this->authorize('manage', $quotationRequest);

        $validated = $request->validate([
            'status' => ['nullable', Rule::enum(QuotationRequestStatus::class)],
            'assigned_to_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'internal_notes' => ['nullable', 'string', 'max:5000'],
            'closure_reason' => ['nullable', 'string', 'min:5', 'max:255'],
        ]);

        $next = filled($validated['status'] ?? null)
            ? QuotationRequestStatus::from((string) $validated['status'])
            : null;

        DB::transaction(function () use ($request, $quotationRequest, $validated, $next): void {
            $locked = QuotationRequest::query()
                ->whereKey($quotationRequest->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $previous = $locked->status;
            $changes = [
                'assigned_to_user_id' => $validated['assigned_to_user_id'] ?? null,
                'internal_notes' => $validated['internal_notes'] ?? null,
            ];

            if ($next !== null && $next !== $previous) {
                if (! $locked->canTransitionTo($next)) {
                    throw ValidationException::withMessages([
                        'status' => "A {$previous->label()} request cannot become {$next->label()}.",
                    ]);
                }

                // Closing without a reason leaves nobody able to say why the
                // enquiry went nowhere.
                if (in_array($next, [QuotationRequestStatus::Closed, QuotationRequestStatus::Cancelled], true)
                    && blank($validated['closure_reason'] ?? null)) {
                    throw ValidationException::withMessages([
                        'closure_reason' => 'Give a reason when closing or cancelling a request.',
                    ]);
                }

                $changes['status'] = $next;
                $changes['closure_reason'] = $validated['closure_reason'] ?? $locked->closure_reason;
                $changes['closed_at'] = $next->isOpen() ? null : now();
            }

            $locked->forceFill($changes)->save();

            $this->auditLogger->record(
                event: 'quotation_request.updated',
                auditable: $locked,
                oldValues: ['status' => $previous->value],
                newValues: [
                    'status' => $locked->status->value,
                    'assigned_to_user_id' => $locked->assigned_to_user_id,
                ],
                user: $request->user(),
            );
        }, 3);

        return back()->with('success', 'The request was updated.');
    }

    /** Staff who can be assigned an enquiry. */
    public static function assignableStaff()
    {
        return User::query()
            ->whereIn('role', ['staff', 'manager', 'super_admin'])
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name']);
    }
}
