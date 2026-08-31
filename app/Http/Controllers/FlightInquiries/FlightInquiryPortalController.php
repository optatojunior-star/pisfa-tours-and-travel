<?php

namespace App\Http\Controllers\FlightInquiries;

use App\Http\Controllers\Controller;
use App\Http\Requests\FlightInquiries\IndexCustomerFlightInquiriesRequest;
use App\Models\FlightInquiry;
use Illuminate\View\View;

class FlightInquiryPortalController extends Controller
{
    public function index(IndexCustomerFlightInquiriesRequest $request): View
    {
        $filters = $request->validated();

        $query = FlightInquiry::query()
            ->forCustomer($request->user())
            ->with('assignee:id,name')
            ->latest('created_at')
            ->latest('id');

        if (filled($filters['q'] ?? null)) {
            $query->search((string) $filters['q']);
        }

        foreach (['status', 'scope'] as $field) {
            if (filled($filters[$field] ?? null)) {
                $query->where($field, $filters[$field]);
            }
        }

        $inquiries = $query->paginate(15)->withQueryString();

        return view('flight-inquiries.index', compact('inquiries', 'filters'));
    }

    public function show(FlightInquiry $customerFlightInquiry): View
    {
        $this->authorize('view', $customerFlightInquiry);

        // The traveller sees contact history, never internal notes or the
        // operational assignment trail.
        $inquiry = $customerFlightInquiry->load([
            'entries' => fn ($entries) => $entries->communications()->with('author:id,name'),
        ]);

        return view('flight-inquiries.show', compact('inquiry'));
    }
}
