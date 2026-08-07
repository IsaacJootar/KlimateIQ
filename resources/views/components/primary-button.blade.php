<button {{ $attributes->merge(['type' => 'submit', 'class' => 'btn-primary text-xs uppercase tracking-widest focus:outline-none focus:ring-2 focus:ring-gano-500 focus:ring-offset-2 dark:focus:ring-offset-gray-800']) }}>
    {{ $slot }}
</button>
