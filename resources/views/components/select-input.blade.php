@props(['disabled' => false])

<select @disabled($disabled) {{ $attributes->merge(['class' => 'border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-gano-500 dark:focus:border-gano-600 focus:ring-gano-500 dark:focus:ring-gano-600 rounded-md shadow-sm py-2 px-3']) }}>
    {{ $slot }}
</select>
