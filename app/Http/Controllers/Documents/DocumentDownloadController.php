<?php

namespace App\Http\Controllers\Documents;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Services\Documents\DocumentStorage;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentDownloadController extends Controller
{
    public function __construct(private readonly DocumentStorage $storage) {}

    /**
     * Authorized download for a signed-in customer, driver, or staff member.
     * Authorization is delegated to the owning record's policy through
     * DocumentPolicy, so there is no second ownership rule to drift.
     */
    public function show(Request $request, Document $document): StreamedResponse
    {
        $this->authorize('download', $document);

        return $this->stream($document, $request->boolean('inline'));
    }

    /**
     * Short-lived signed link for a recipient with no account, such as a guest
     * quotation. The signature is the authorization, so this route must never
     * serve a document whose owner requires a session to identify.
     */
    public function signed(Request $request, Document $document): StreamedResponse
    {
        abort_unless($document->isPrivate(), 404);

        return $this->stream($document, $request->boolean('inline'));
    }

    private function stream(Document $document, bool $inline): StreamedResponse
    {
        try {
            return $this->storage->download($document, $inline);
        } catch (RuntimeException) {
            // The row exists but the bytes are gone. A 404 is the honest
            // answer; a 500 would suggest the caller did something wrong.
            abort(404, 'The stored file is no longer available.');
        }
    }
}
