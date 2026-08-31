<?php

namespace App\Http\Controllers\VehicleImports;

use App\Actions\VehicleImports\CreateVehicleImportOrder;
use App\Enums\VehicleImportBodyType;
use App\Enums\VehicleImportDriveType;
use App\Enums\VehicleImportFuelType;
use App\Enums\VehicleImportSteering;
use App\Enums\VehicleImportTransmission;
use App\Http\Controllers\Controller;
use App\Http\Requests\VehicleImports\StoreVehicleImportOrderRequest;
use App\Models\VehicleImportOrder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Illuminate\View\View;

class VehicleImportController extends Controller
{
    public function create(): View
    {
        return view('vehicle-imports.create', [
            'idempotencyKey' => (string) Str::uuid(),
            'bodyTypes' => VehicleImportBodyType::options(),
            'fuelTypes' => VehicleImportFuelType::options(),
            'transmissions' => VehicleImportTransmission::options(),
            'driveTypes' => VehicleImportDriveType::options(),
            'steeringOptions' => VehicleImportSteering::options(),
            'originCountries' => (array) config('vehicle_imports.origin_countries', []),
            'purposes' => (array) config('vehicle_imports.purposes', []),
            'currentYear' => (int) now()->format('Y'),
        ]);
    }

    public function store(
        StoreVehicleImportOrderRequest $request,
        CreateVehicleImportOrder $action,
    ): RedirectResponse {
        $validated = $request->validated();

        $order = $action->execute($request->user(), $validated, $validated['idempotency_key']);

        $message = 'Import request received. Our sourcing team will send a quotation. '
            .'Nothing is ordered and no payment is due yet.';

        if ($order->isGuest()) {
            return redirect()
                ->route('vehicle-imports.track', ['token' => $order->tracking_token])
                ->with('success', $message);
        }

        return redirect()
            ->route('portal.vehicle-imports.show', $order)
            ->with('success', $message);
    }

    /**
     * Public tracking by unguessable token.
     *
     * The token is 32 bytes of CSPRNG output held in a unique column, so it is
     * the credential. It is looked up directly rather than being paired with a
     * reference, because requiring both would not add security and would make
     * the emailed link fragile.
     */
    public function track(string $token): View
    {
        // Length is checked before touching the database so a short or absent
        // token cannot become a broad scan.
        abort_unless(preg_match('/\A[0-9a-f]{64}\z/', $token) === 1, 404);

        $order = VehicleImportOrder::query()
            ->where('tracking_token', $token)
            ->with(['events' => fn ($q) => $q->customerVisible()])
            ->firstOrFail();

        return view('vehicle-imports.track', [
            'order' => $order,
            'timeline' => $order->events,
            'isGuestView' => true,
        ]);
    }
}
