<?php

namespace App\Http\Controllers\CarHire;

use App\Actions\CarHire\DeleteCarHireDocument;
use App\Actions\CarHire\StoreCarHireDocument;
use App\Enums\CarHireDocumentType;
use App\Http\Controllers\Controller;
use App\Http\Requests\CarHire\UploadCarHireDocumentRequest;
use App\Models\CarHireBooking;
use App\Models\CarHireDocument;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CarHireDocumentController extends Controller
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function store(UploadCarHireDocumentRequest $request, CarHireBooking $customerCarHireBooking, StoreCarHireDocument $action): RedirectResponse
    {
        $action->execute($request->user(), $customerCarHireBooking, CarHireDocumentType::from($request->string('document_type')->toString()), $request->file('file'));

        return back()->with('success', 'The private document was uploaded securely.');
    }

    public function destroy(Request $request, CarHireBooking $customerCarHireBooking, CarHireDocument $carHireDocument, DeleteCarHireDocument $action): RedirectResponse
    {
        abort_unless($carHireDocument->car_hire_booking_id === $customerCarHireBooking->getKey(), 404);
        $this->authorize('uploadDocument', $customerCarHireBooking);
        $action->execute($request->user(), $carHireDocument);

        return back()->with('success', 'The private document was removed.');
    }

    public function downloadForCustomer(CarHireBooking $customerCarHireBooking, CarHireDocument $carHireDocument): StreamedResponse
    {
        $this->authorize('downloadDocument', $customerCarHireBooking);

        return $this->download($customerCarHireBooking, $carHireDocument);
    }

    public function downloadForAdministration(CarHireBooking $carHireBooking, CarHireDocument $carHireDocument): StreamedResponse
    {
        $this->authorize('downloadDocument', $carHireBooking);

        return $this->download($carHireBooking, $carHireDocument);
    }

    private function download(CarHireBooking $booking, CarHireDocument $document): StreamedResponse
    {
        abort_unless($document->car_hire_booking_id === $booking->getKey(), 404);
        $disk = Storage::disk($document->disk);
        abort_unless($disk->exists($document->path), 404);

        $this->auditLogger->record(
            event: 'car_hire.document_downloaded',
            auditable: $document,
            newValues: [
                'booking_reference' => $booking->reference,
                'document_type' => $document->document_type->value,
                'mime_type' => $document->mime_type,
                'size_bytes' => $document->size_bytes,
            ],
        );

        return $disk->download($document->path, basename($document->original_name), [
            'Content-Type' => $document->mime_type,
            'Cache-Control' => 'private, no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'Expires' => '0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
