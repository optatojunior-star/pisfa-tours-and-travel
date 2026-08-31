<?php

namespace App\Services\Portal;

use App\Enums\InvoiceStatus;
use App\Enums\QuotationStatus;
use App\Models\Document;
use App\Models\Invoice;
use App\Models\Quotation;
use App\Models\User;
use App\Support\Bookings\BookingSource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Every document a customer is entitled to, in one place.
 *
 * Ownership is resolved per owner type rather than by scanning the polymorphic
 * table: a query that matched on `documentable_id` alone would hand a customer
 * someone else's contract the moment two tables shared an id.
 *
 * Only *current* versions are listed. A superseded contract is retained as
 * evidence but is not what a customer should be reading.
 */
class CustomerDocumentQuery
{
    /** @return Collection<int, Document> */
    public function forCustomer(User $customer): Collection
    {
        $owners = $this->ownerKeys($customer);

        if ($owners === []) {
            return Document::query()->whereRaw('1 = 0')->get();
        }

        return Document::query()
            ->where('is_current', true)
            ->where(function (Builder $query) use ($owners): void {
                foreach ($owners as $type => $ids) {
                    $query->orWhere(fn (Builder $nested) => $nested
                        ->where('documentable_type', $type)
                        ->whereIn('documentable_id', $ids));
                }
            })
            ->with('documentable')
            ->latest('created_at')
            ->get();
    }

    /**
     * The record ids this customer owns, keyed by morph class.
     *
     * @return array<string, list<int>>
     */
    private function ownerKeys(User $customer): array
    {
        $owners = [];

        foreach (BookingSource::cases() as $source) {
            $ids = DB::table($source->table())
                ->where('customer_id', $customer->getKey())
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all();

            if ($ids !== []) {
                $owners[(new ($source->model()))->getMorphClass()] = $ids;
            }
        }

        foreach ([Quotation::class, Invoice::class] as $class) {
            $model = new $class;

            $ids = $model->newQuery()
                ->where('customer_id', $customer->getKey())
                // A draft is internal, and so is the PDF filed against it.
                ->whereIn('status', $class === Quotation::class
                    ? QuotationStatus::customerVisibleValues()
                    : InvoiceStatus::customerVisibleValues())
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all();

            if ($ids !== []) {
                $owners[$model->getMorphClass()] = $ids;
            }
        }

        return $owners;
    }

    /** A short description of what a document belongs to. */
    public static function ownerLabel(Document $document): string
    {
        $owner = $document->documentable;

        if ($owner === null) {
            return 'Removed record';
        }

        $reference = $owner->getAttribute('reference')
            ?? $owner->getAttribute('number')
            ?? '';

        return trim(class_basename($owner).' '.$reference);
    }
}
