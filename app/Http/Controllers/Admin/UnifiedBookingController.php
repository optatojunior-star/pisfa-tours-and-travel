<?php

namespace App\Http\Controllers\Admin;

use App\Enums\BookingStage;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UnifiedBookingFilterRequest;
use App\Services\Bookings\UnifiedBookingQuery;
use App\Support\Bookings\BookingSource;
use Illuminate\View\View;

class UnifiedBookingController extends Controller
{
    public function index(UnifiedBookingFilterRequest $request, UnifiedBookingQuery $query): View
    {
        $filters = $request->validated();

        return view('admin.bookings.index', [
            'bookings' => $query->paginate($filters, (int) ($filters['per_page'] ?? 25)),
            'stageCounts' => $query->stageCounts($filters),
            'filters' => $filters,
            'stage' => filled($filters['stage'] ?? null)
                ? BookingStage::tryFrom((string) $filters['stage'])
                : null,
            'source' => filled($filters['source'] ?? null)
                ? BookingSource::tryFrom((string) $filters['source'])
                : null,
        ]);
    }
}
