@props(['messages'])

@if ($messages)
    {{-- role="alert" so a screen reader announces the problem rather than
         leaving it as text the user has to go looking for. --}}
    <ul {{ $attributes->merge(['class' => 'mt-1.5 space-y-1 text-sm font-medium text-rose-700']) }} role="alert">
        @foreach ((array) $messages as $message)
            <li>{{ $message }}</li>
        @endforeach
    </ul>
@endif
