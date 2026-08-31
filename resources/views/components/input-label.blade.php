@props(['value'])

<label {{ $attributes->merge(['class' => 'block text-sm font-semibold text-ink-800']) }}>
    {{ $value ?? $slot }}
</label>
