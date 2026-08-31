@props(['schema' => null])

{{--
    JSON-LD structured data.

    This is what lets Google show a business panel with your phone number and
    opening hours rather than a plain blue link, and what puts a price and rating
    on a tour in the results. It is the highest-return SEO work available to a
    local business, because it competes on information the big aggregators do not
    have about you.

    Emitted as JSON-LD in the head rather than as microdata woven through the
    markup: it stays in one reviewable place and cannot be broken by a designer
    moving a div.
--}}
@php
    $brand = app(\App\Services\Settings\SettingsRepository::class)->brand();

    $organisation = [
        '@context' => 'https://schema.org',
        '@type' => 'TravelAgency',
        '@id' => url('/').'#organisation',
        'name' => $brand['name'],
        'url' => url('/'),
        'logo' => asset('images/logo.png'),
        'image' => asset('images/logo.png'),
        'description' => 'Tours and safaris, car hire, airport transfers, accommodation, '
            .'vehicle imports and vehicle sales across Uganda.',
        'areaServed' => [
            '@type' => 'Country',
            'name' => 'Uganda',
        ],
        'address' => array_filter([
            '@type' => 'PostalAddress',
            'addressLocality' => 'Kampala',
            'addressCountry' => 'UG',
            'streetAddress' => $brand['address'] !== '' ? $brand['address'] : null,
        ]),
        'currenciesAccepted' => implode(', ', config('pisfa.currency.supported', ['UGX', 'USD'])),
    ];

    if ($brand['phone'] !== '') {
        $organisation['telephone'] = $brand['phone'];
        $organisation['contactPoint'] = [
            '@type' => 'ContactPoint',
            'telephone' => $brand['phone'],
            'contactType' => 'reservations',
            'areaServed' => 'UG',
            'availableLanguage' => ['en'],
        ];
    }

    if ($brand['email'] !== '') {
        $organisation['email'] = $brand['email'];
    }

    $documents = [$organisation];

    if ($schema !== null) {
        $documents[] = $schema;
    }
@endphp

@foreach ($documents as $document)
    <script type="application/ld+json">{!! json_encode($document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
@endforeach
