<?php

namespace App\Http\Controllers\VehicleImports;

use App\Http\Controllers\Controller;
use App\Models\VehicleImportOrder;
use Illuminate\Http\Request;
use Illuminate\View\View;

class VehicleImportPortalController extends Controller
{
    public function index(Request $request): View
    {
        $orders = VehicleImportOrder::query()
            ->forCustomer($request->user())
            ->latest('created_at')
            ->latest('id')
            ->paginate(15);

        return view('vehicle-imports.index', compact('orders'));
    }

    public function show(VehicleImportOrder $customerVehicleImport): View
    {
        $this->authorize('view', $customerVehicleImport);

        $order = $customerVehicleImport->load([
            // Internal events and internal notes never reach the customer.
            'events' => fn ($q) => $q->customerVisible(),
            'messages' => fn ($q) => $q->shared()->with('author:id,name'),
            'documents' => fn ($q) => $q->current()->ordered(),
        ]);

        return view('vehicle-imports.show', [
            'order' => $order,
            'timeline' => $order->events,
            'isGuestView' => false,
        ]);
    }
}
