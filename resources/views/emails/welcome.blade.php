<x-mail::message>
# Welcome, {{ $user->name }}

Your KlimateIQ account is ready. Here are your sign-in details:

<x-mail::panel>
**Email:** {{ $user->email }}

**Password:** {{ $plainPassword }}
</x-mail::panel>

For your security, we'd recommend changing this password after your first sign-in.

<x-mail::button :url="route('login')">
Sign in to KlimateIQ
</x-mail::button>

If you didn't create this account, please contact us immediately.

Thanks,<br>
The KlimateIQ team
</x-mail::message>
