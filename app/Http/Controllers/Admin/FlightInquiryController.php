<?php

namespace App\Http\Controllers\Admin;

use App\Actions\FlightInquiries\AssignFlightInquiry;
use App\Actions\FlightInquiries\RecordFlightInquiryEntry;
use App\Actions\FlightInquiries\TransitionFlightInquiry;
use App\Enums\AccountStatus;
use App\Enums\FlightInquiryEntryType;
use App\Enums\FlightInquiryStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AssignFlightInquiryRequest;
use App\Http\Requests\Admin\IndexFlightInquiriesRequest;
use App\Http\Requests\Admin\StoreFlightInquiryEntryRequest;
use App\Http\Requests\Admin\TransitionFlightInquiryRequest;
use App\Models\FlightInquiry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FlightInquiryController extends Controller
{
    public function index(IndexFlightInquiriesRequest $request): View
    {
        $filters = $request->validated();
        $timezone = (string) config('pisfa.business_timezone', 'Africa/Kampala');

        $query = FlightInquiry::query()
            ->with(['customer:id,name,email', 'assignee:id,name'])
            ->latest('created_at')
            ->latest('id');

        if (filled($filters['q'] ?? null)) {
            $query->search((string) $filters['q']);
        }

        foreach (['status', 'scope', 'trip_type'] as $field) {
            if (filled($filters[$field] ?? null)) {
                $query->where($field, $filters[$field]);
            }
        }

        if (filled($filters['assigned_to_user_id'] ?? null)) {
            $query->where('assigned_to_user_id', $filters['assigned_to_user_id']);
        }

        match ($filters['queue'] ?? null) {
            'open' => $query->open(),
            'mine' => $query->where('assigned_to_user_id', $request->user()->getKey()),
            'unassigned' => $query->unassigned()->open(),
            default => null,
        };

        if (filled($filters['from'] ?? null)) {
            $query->whereDate('outbound_on', '>=', CarbonImmutable::parse($filters['from'], $timezone)->toDateString());
        }

        if (filled($filters['to'] ?? null)) {
            $query->whereDate('outbound_on', '<=', CarbonImmutable::parse($filters['to'], $timezone)->toDateString());
        }

        $inquiries = $query->paginate(20)->withQueryString();
        $consultants = $this->consultants();
        $openCount = FlightInquiry::query()->open()->count();
        $unassignedCount = FlightInquiry::query()->open()->unassigned()->count();

        return view('admin.flight-inquiries.index', compact(
            'inquiries',
            'filters',
            'consultants',
            'openCount',
            'unassignedCount',
        ));
    }

    public function show(Request $request, FlightInquiry $flightInquiry): View
    {
        $this->authorize('view', $flightInquiry);

        $inquiry = $flightInquiry->load([
            'customer:id,name,email,phone',
            'assignee:id,name,email',
            'entries.author:id,name',
        ]);
        $consultants = $this->consultants();
        $canReopen = $request->user()->can('reopen', $inquiry);
        $entryTypes = FlightInquiryEntryType::manualCases();

        return view('admin.flight-inquiries.show', compact(
            'inquiry',
            'consultants',
            'canReopen',
            'entryTypes',
        ));
    }

    public function transition(
        TransitionFlightInquiryRequest $request,
        FlightInquiry $flightInquiry,
        TransitionFlightInquiry $action,
    ): RedirectResponse {
        $action->execute(
            $request->user(),
            $flightInquiry,
            FlightInquiryStatus::from($request->validated('status')),
            $request->validated('reason'),
            (bool) $request->validated('notify_traveller'),
        );

        return back()->with('success', 'The flight enquiry status was updated.');
    }

    public function assign(
        AssignFlightInquiryRequest $request,
        FlightInquiry $flightInquiry,
        AssignFlightInquiry $action,
    ): RedirectResponse {
        $assigneeId = $request->validated('assigned_to_user_id');
        $assignee = $assigneeId === null ? null : User::query()->findOrFail($assigneeId);

        $action->execute($request->user(), $flightInquiry, $assignee, $request->validated('reason'));

        return back()->with('success', $assignee === null
            ? 'The enquiry was returned to the unassigned queue.'
            : 'The enquiry was assigned to '.$assignee->name.'.');
    }

    public function storeEntry(
        StoreFlightInquiryEntryRequest $request,
        FlightInquiry $flightInquiry,
        RecordFlightInquiryEntry $action,
    ): RedirectResponse {
        $action->execute(
            $request->user(),
            $flightInquiry,
            FlightInquiryEntryType::from($request->validated('entry_type')),
            $request->validated('body'),
        );

        return back()->with('success', 'The entry was added to the enquiry history.');
    }

    /** @return Collection<int, User> */
    private function consultants(): Collection
    {
        return User::query()
            ->whereIn('role', [
                UserRole::Staff->value,
                UserRole::Manager->value,
                UserRole::SuperAdmin->value,
            ])
            ->where('status', AccountStatus::Active->value)
            ->orderBy('name')
            ->get(['id', 'name', 'role']);
    }
}
