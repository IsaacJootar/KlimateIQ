<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Notifications') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8 space-y-4">
            <div class="section-card divide-y divide-gray-100 dark:divide-gray-700">
                @forelse ($notifications as $notification)
                    <form method="POST" action="{{ route('notifications.read', $notification->id) }}"
                          class="block p-4 {{ $notification->read_at ? '' : 'bg-gano-50 dark:bg-gano-900/20' }}">
                        @csrf
                        <button type="submit" class="w-full text-left flex items-start justify-between gap-4">
                            <div>
                                <p class="text-sm font-medium text-gray-800 dark:text-gray-200">{{ $notification->data['title'] ?? 'Notification' }}</p>
                                <p class="text-sm text-gray-500 mt-0.5">{{ $notification->data['body'] ?? '' }}</p>
                                <p class="text-xs text-gray-400 mt-1">{{ $notification->created_at->diffForHumans() }}</p>
                            </div>
                            @unless ($notification->read_at)
                                <span class="risk-badge risk-badge-green flex-shrink-0">new</span>
                            @endunless
                        </button>
                    </form>
                @empty
                    <div class="p-8 text-center text-sm text-gray-500">No notifications yet.</div>
                @endforelse
            </div>

            <div>{{ $notifications->links() }}</div>
        </div>
    </div>
</x-app-layout>
