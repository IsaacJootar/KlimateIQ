@props(['value', 'decimals' => 0, 'comma' => false])

{{--
    Purely cosmetic — animates from 0 to the real server-computed value on mount. Never used to
    fake a number: the target is always {{ $value }}, exactly what the backend already sent.

    The animation logic lives in an x-data method (animate()) rather than inline in x-init.
    Alpine's expression parser mis-parses a multi-statement x-init body that starts with a `//`
    comment (throws "Unexpected token" and silently never runs) — calling a single method
    sidesteps that entirely, and was the actual bug behind an earlier version of this component
    getting stuck showing 0.
--}}
<span
    x-data="{
        display: (0).toFixed({{ (int) $decimals }}),
        target: {{ (float) $value }},
        comma: {{ $comma ? 'true' : 'false' }},
        format(n) {
            const fixed = n.toFixed({{ (int) $decimals }});
            return this.comma ? Number(fixed).toLocaleString(undefined, { minimumFractionDigits: {{ (int) $decimals }}, maximumFractionDigits: {{ (int) $decimals }} }) : fixed;
        },
        animate() {
            const steps = 24;
            const duration = 700;
            const to = this.target;
            let i = 0;
            const tick = () => {
                i++;
                const progress = Math.min(i / steps, 1);
                const eased = 1 - Math.pow(1 - progress, 3);
                this.display = this.format(to * eased);
                if (progress < 1) setTimeout(tick, duration / steps);
            };
            tick();
        },
    }"
    x-init="animate()"
    x-text="display"
>{{ number_format((float) $value, (int) $decimals) }}</span>
