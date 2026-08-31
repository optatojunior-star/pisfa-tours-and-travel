@props(['disabled' => false])

<input @disabled($disabled) {{ $attributes->merge(['class' => 'block min-h-11 w-full rounded-control border-ink-300 text-sm text-ink-900 shadow-sm transition placeholder:text-ink-400 focus:border-brand-700 focus:ring-brand-700 disabled:cursor-not-allowed disabled:bg-ink-50']) }}>
