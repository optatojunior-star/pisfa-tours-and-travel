@props(['rating' => 0, 'label' => null])

<span class="inline-flex items-center gap-0.5" role="img"
      aria-label="{{ $label ?? $rating.' out of 5 stars' }}">
    @for ($i = 1; $i <= 5; $i++)
        <span aria-hidden="true" @class([
            'text-base leading-none',
            'text-amber-500' => $i <= $rating,
            'text-slate-300' => $i > $rating,
        ])>&starf;</span>
    @endfor
</span>
