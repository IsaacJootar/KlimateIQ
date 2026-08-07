@props(['disabled' => false])

<select @disabled($disabled) {{ $attributes->merge(['class' => 'w-full px-3.5 py-2.5 text-sm text-slate-900 dark:text-white bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-600 rounded-xl shadow-sm focus:outline-none focus:border-gano-500 focus:ring-2 focus:ring-gano-500/20 disabled:bg-slate-50 dark:disabled:bg-slate-800 disabled:text-slate-500 disabled:cursor-not-allowed transition-shadow duration-150']) }}>
    {{ $slot }}
</select>
