<x-guest-layout>
    <form method="POST" action="{{ route('register') }}" x-data="{ newAgency: false, otherDesignation: {{ old('designation') === 'OTHER' ? 'true' : 'false' }} }">
        @csrf

        <!-- Name -->
        <div>
            <x-input-label for="name" :value="__('Name')" />
            <x-text-input id="name" class="block mt-1 w-full" type="text" name="name" :value="old('name')" required autofocus autocomplete="name" />
            <x-input-error :messages="$errors->get('name')" class="mt-2" />
        </div>

        <!-- Email Address -->
        <div class="mt-4">
            <x-input-label for="email" :value="__('Email')" />
            <x-text-input id="email" class="block mt-1 w-full" type="email" name="email" :value="old('email')" required autocomplete="username" />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <!-- Password -->
        <div class="mt-4">
            <x-input-label for="password" :value="__('Password')" />

            <x-text-input id="password" class="block mt-1 w-full"
                            type="password"
                            name="password"
                            required autocomplete="new-password" />

            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <!-- Confirm Password -->
        <div class="mt-4">
            <x-input-label for="password_confirmation" :value="__('Confirm Password')" />

            <x-text-input id="password_confirmation" class="block mt-1 w-full"
                            type="password"
                            name="password_confirmation" required autocomplete="new-password" />

            <x-input-error :messages="$errors->get('password_confirmation')" class="mt-2" />
        </div>

        <!-- Role -->
        <div class="mt-4">
            <x-input-label for="designation" :value="__('Your role')" />
            <select id="designation" name="designation" required class="block mt-1 w-full rounded-md"
                    x-on:change="otherDesignation = ($event.target.value === 'OTHER')">
                <option value="">Select your role</option>
                @foreach ($designations as $key => $label)
                    <option value="{{ $key }}" @selected(old('designation') === $key)>{{ $label }}</option>
                @endforeach
            </select>
            <x-text-input x-show="otherDesignation" x-cloak class="block mt-1 w-full" type="text" name="other_designation"
                :value="old('other_designation')" placeholder="Describe your role" />
            <x-input-error :messages="$errors->get('designation')" class="mt-2" />
            <x-input-error :messages="$errors->get('other_designation')" class="mt-2" />
        </div>

        <!-- State -->
        <div class="mt-4">
            <x-input-label for="state" :value="__('State')" />
            <select id="state" name="state" required class="block mt-1 w-full rounded-md">
                <option value="">Select your state</option>
                @foreach ($states as $state)
                    <option value="{{ $state }}" @selected(old('state') === $state)>{{ $state }}</option>
                @endforeach
            </select>
            <x-input-error :messages="$errors->get('state')" class="mt-2" />
        </div>

        <!-- Agency / Organization -->
        <div class="mt-4">
            <x-input-label for="agency_id" :value="__('Agency / Organization (optional)')" />
            <select id="agency_id" name="agency_id" x-show="!newAgency" class="block mt-1 w-full rounded-md">
                <option value="">— None —</option>
                @foreach ($agencies as $agency)
                    <option value="{{ $agency->agency_id }}" @selected(old('agency_id') === $agency->agency_id)>{{ $agency->name }}</option>
                @endforeach
            </select>
            <x-text-input x-show="newAgency" x-cloak class="block mt-1 w-full" type="text" name="new_agency_name"
                :value="old('new_agency_name')" placeholder="Your agency's name" />
            <button type="button" class="mt-1 text-xs link-nav" @click="newAgency = !newAgency">
                <span x-show="!newAgency">My agency isn't listed</span>
                <span x-show="newAgency" x-cloak>Choose from the list instead</span>
            </button>
            <x-input-error :messages="$errors->get('agency_id')" class="mt-2" />
        </div>

        <div class="flex items-center justify-end mt-4">
            <a class="underline text-sm text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-100 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gano-500 dark:focus:ring-offset-gray-800" href="{{ route('login') }}">
                {{ __('Already registered?') }}
            </a>

            <x-primary-button class="ms-4">
                {{ __('Register') }}
            </x-primary-button>
        </div>
    </form>
</x-guest-layout>
