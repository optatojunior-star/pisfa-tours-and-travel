<x-print-layout :title="'Quotation '.$quotation->number" :back="$back">
    @include('billing.partials.printable-quotation', ['quotation' => $quotation, 'brand' => $brand])
</x-print-layout>
