<x-print-layout :title="'Invoice '.$invoice->number" :back="$back">
    @include('billing.partials.printable-invoice', ['invoice' => $invoice, 'brand' => $brand])
</x-print-layout>
