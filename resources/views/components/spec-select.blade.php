@props([
    'name',
    'label',
    'options' => [],
    'current' => null,
    'required' => false,
    'placeholder' => 'Select…',
    'help' => null,
])

@php
    use App\Support\VehicleSpecification;

    $id = $attributes->get('id', $name);
    $selected = (string) old($name, $current ?? '');
    // The record's own value survives even if it predates this list.
    $choices = VehicleSpecification::optionsPreserving($options, $selected);
    $hasError = $errors->has($name);
    $describedBy = trim(($help ? $id.'-help ' : '').($hasError ? $id.'-error' : ''));
@endphp

<div>
    <label for="{{ $id }}" class="block text-sm font-semibold text-ink-800">
        {{ $label }}
        @unless ($required)<span class="font-normal text-ink-500">(optional)</span>@endunless
    </label>

    <select id="{{ $id }}" name="{{ $name }}" @required($required)
            @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif
            @if ($hasError) aria-invalid="true" @endif
            {{ $attributes->except('id')->class([
                'mt-1 block w-full rounded-xl shadow-sm focus:ring-2',
                'border-slate-300 focus:border-emerald-600 focus:ring-emerald-600' => ! $hasError,
                'border-rose-400 bg-rose-50 focus:border-rose-600 focus:ring-rose-600' => $hasError,
            ]) }}>
        <option value="">{{ $placeholder }}</option>
        @foreach ($choices as $value => $text)
            <option value="{{ $value }}" @selected($selected === (string) $value)>{{ $text }}</option>
        @endforeach
    </select>

    @if ($help)
        <p id="{{ $id }}-help" class="mt-1 text-xs text-slate-500">{{ $help }}</p>
    @endif

    @error($name)
        <p id="{{ $id }}-error" class="mt-1 text-sm font-semibold text-rose-700" role="alert">{{ $message }}</p>
    @enderror
</div>
