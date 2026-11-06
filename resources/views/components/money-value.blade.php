@props(['symbol', 'amount'])

{{--
    Large currency totals (an at-risk Naira figure can run into the billions) must never wrap
    or overflow a metric card — abbreviate (₦2.3bn) instead of showing every digit, with the
    exact figure on hover/tap via title. Pairs with .metric-card-value's white-space: nowrap.
--}}
<span
    class="whitespace-nowrap"
    title="{{ $symbol }}{{ number_format((float) $amount) }}"
>{{ $symbol }}{{ \Illuminate\Support\Number::abbreviate((float) $amount, maxPrecision: 1) }}</span>
