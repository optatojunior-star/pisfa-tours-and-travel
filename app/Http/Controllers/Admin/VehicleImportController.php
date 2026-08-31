<?php

namespace App\Http\Controllers\Admin;

use App\Actions\VehicleImports\QuoteVehicleImportOrder;
use App\Actions\VehicleImports\TransitionVehicleImportOrder;
use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Enums\VehicleImportStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\IndexVehicleImportsRequest;
use App\Http\Requests\Admin\QuoteVehicleImportRequest;
use App\Http\Requests\Admin\TransitionVehicleImportRequest;
use App\Models\User;
use App\Models\VehicleImportOrder;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class VehicleImportController extends Controller
{
    public function index(IndexVehicleImportsRequest $request): View
    {
        $filters = $request->validated();

        $query = VehicleImportOrder::query()
            ->with(['customer:id,name,email', 'assignee:id,name'])
            ->latest('created_at')
            ->latest('id');

        if (filled($filters['q'] ?? null)) {
            $query->search((string) $filters['q']);
        }

        foreach (['status', 'origin_country'] as $field) {
            if (filled($filters[$field] ?? null)) {
                $query->where($field, $filters[$field]);
            }
        }

        match ($filters['queue'] ?? null) {
            'open' => $query->open(),
            'mine' => $query->where('assigned_to_user_id', $request->user()->getKey()),
            'unassigned' => $query->open()->whereNull('assigned_to_user_id'),
            // Quoted but the deposit has not arrived: the follow-up queue.
            'awaiting_deposit' => $query->where('status', VehicleImportStatus::Quoted->value),
            default => null,
        };

        return view('admin.vehicle-imports.index', [
            'orders' => $query->paginate(20)->withQueryString(),
            'filters' => $filters,
            'openCount' => VehicleImportOrder::query()->open()->count(),
            'awaitingDepositCount' => VehicleImportOrder::query()
                ->where('status', VehicleImportStatus::Quoted->value)
                ->count(),
        ]);
    }

    public function show(VehicleImportOrder $vehicleImport): View
    {
        $this->authorize('view', $vehicleImport);

        $order = $vehicleImport->load([
            'customer:id,name,email,phone',
            'assignee:id,name',
            'events.actor:id,name',
            'messages.author:id,name',
            'documents' => fn ($q) => $q->current()->ordered(),
            'payments',
        ]);

        return view('admin.vehicle-imports.show', [
            'order' => $order,
            'consultants' => User::query()
                ->whereIn('role', [
                    UserRole::Staff->value,
                    UserRole::Manager->value,
                    UserRole::SuperAdmin->value,
                ])
                ->where('status', AccountStatus::Active->value)
                ->orderBy('name')
                ->get(['id', 'name']),
        ]);
    }

    public function quote(
        QuoteVehicleImportRequest $request,
        VehicleImportOrder $vehicleImport,
        QuoteVehicleImportOrder $action,
    ): RedirectResponse {
        $action->execute($request->user(), $vehicleImport, $request->validated());

        return back()->with('success', 'The quotation was published and the customer was notified.');
    }

    public function transition(
        TransitionVehicleImportRequest $request,
        VehicleImportOrder $vehicleImport,
        TransitionVehicleImportOrder $action,
    ): RedirectResponse {
        $action->execute(
            $request->user(),
            $vehicleImport,
            VehicleImportStatus::from($request->validated('status')),
            $request->validated('reason'),
            (bool) $request->validated('notify_customer'),
        );

        return back()->with('success', 'The import status was updated.');
    }
}
