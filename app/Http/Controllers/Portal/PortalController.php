<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Portal\ActivityFilterRequest;
use App\Models\User;
use App\Services\Dashboard\CustomerSnapshot;
use App\Services\Portal\CustomerActivityQuery;
use App\Services\Portal\CustomerDocumentQuery;
use App\Support\Portal\ActivityKind;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PortalController extends Controller
{
    public function __construct(
        private readonly CustomerActivityQuery $activity,
        private readonly CustomerDocumentQuery $documents,
    ) {}

    /** The portal home: what needs attention, and what is coming. */
    public function index(Request $request, CustomerSnapshot $snapshot): View
    {
        $customer = $this->customer($request);

        return view('portal.index', [
            'customer' => $customer,
            'snapshot' => $snapshot->forCustomer($customer),
            'recent' => $this->activity->recent($customer),
            'unread' => $customer->unreadNotifications()->count(),
        ]);
    }

    /** Everything, in one list. */
    public function activity(ActivityFilterRequest $request): View
    {
        $customer = $this->customer($request);
        $filters = $request->validated();

        return view('portal.activity', [
            'customer' => $customer,
            'items' => $this->activity->paginate($customer, $filters),
            'counts' => $this->activity->counts($customer),
            'filters' => $filters,
            'kind' => filled($filters['kind'] ?? null)
                ? ActivityKind::tryFrom((string) $filters['kind'])
                : null,
            'timezone' => config('pisfa.business_timezone', 'Africa/Kampala'),
        ]);
    }

    /** Every contract, quotation, invoice, and receipt in one place. */
    public function documents(Request $request): View
    {
        $customer = $this->customer($request);

        return view('portal.documents', [
            'customer' => $customer,
            'documents' => $this->documents->forCustomer($customer),
            'timezone' => config('pisfa.business_timezone', 'Africa/Kampala'),
        ]);
    }

    private function customer(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
