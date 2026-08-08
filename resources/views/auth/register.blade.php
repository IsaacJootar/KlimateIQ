<x-guest-layout max-width="xl">
    <form method="POST" action="{{ route('register') }}" x-data="{ newAgency: false, otherDesignation: {{ old('designation') === 'OTHER' ? 'true' : 'false' }} }">
        @csrf

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <x-input-label for="name" :value="__('Name')" />
                <x-text-input id="name" class="block mt-1 w-full" type="text" name="name" :value="old('name')" required autofocus autocomplete="name" />
                <x-input-error :messages="$errors->get('name')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="email" :value="__('Email')" />
                <x-text-input id="email" class="block mt-1 w-full" type="email" name="email" :value="old('email')" required autocomplete="username" />
                <x-input-error :messages="$errors->get('email')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="password" :value="__('Password')" />
                <x-text-input id="password" class="block mt-1 w-full" type="password" name="password" required autocomplete="new-password" />
                <x-input-error :messages="$errors->get('password')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="password_confirmation" :value="__('Confirm Password')" />
                <x-text-input id="password_confirmation" class="block mt-1 w-full" type="password" name="password_confirmation" required autocomplete="new-password" />
                <x-input-error :messages="$errors->get('password_confirmation')" class="mt-2" />
            </div>
        </div>

        <div class="mt-6 pt-6 border-t border-gray-100 dark:border-gray-700">
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">Your role</p>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-3">
                <div>
                    <x-input-label for="designation" :value="__('Role')" />
                    <x-select-input id="designation" name="designation" required class="block mt-1 w-full"
                            x-on:change="otherDesignation = ($event.target.value === 'OTHER')">
                        <option value="">Select your role</option>
                        @foreach ($designations as $key => $label)
                            <option value="{{ $key }}" @selected(old('designation') === $key)>{{ $label }}</option>
                        @endforeach
                    </x-select-input>
                    <div x-show="otherDesignation" x-cloak>
                        <x-text-input class="block mt-2 w-full" type="text" name="other_designation"
                            :value="old('other_designation')" placeholder="Describe your role" />
                    </div>
                    <x-input-error :messages="$errors->get('designation')" class="mt-2" />
                    <x-input-error :messages="$errors->get('other_designation')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="state" :value="__('Organization\'s State')" />
                    <x-select-input id="state" name="state" required class="block mt-1 w-full">
                        <option value="">Select a state</option>
                        @foreach ($states as $state)
                            <option value="{{ $state }}" @selected(old('state') === $state)>{{ $state }}</option>
                        @endforeach
                    </x-select-input>
                    <p class="mt-1 text-xs text-slate-400">Where your organization operates — not necessarily your own location.</p>
                    <x-input-error :messages="$errors->get('state')" class="mt-2" />
                </div>
            </div>

            <div class="mt-4">
                <x-input-label for="agency_id" :value="__('Organization')" />
                <p class="text-xs text-slate-400 mb-1">Required, so we know who's using the platform.</p>
                <div x-show="!newAgency">
                    <x-select-input id="agency_id" name="agency_id" class="block mt-1 w-full">
                        <option value="">Select your organization</option>
                        @foreach ($agencies as $agency)
                            <option value="{{ $agency->agency_id }}" @selected(old('agency_id') === $agency->agency_id)>{{ $agency->name }}</option>
                        @endforeach
                    </x-select-input>
                </div>
                <div x-show="newAgency" x-cloak>
                    <x-text-input class="block mt-1 w-full" type="text" name="new_agency_name"
                        :value="old('new_agency_name')" placeholder="Your organization's name" />
                </div>
                <button type="button" class="mt-1 text-xs link-nav" @click="newAgency = !newAgency">
                    <span x-show="!newAgency">My organization isn't listed</span>
                    <span x-show="newAgency" x-cloak>Choose from the list instead</span>
                </button>
                <x-input-error :messages="$errors->get('agency_id')" class="mt-2" />
                <x-input-error :messages="$errors->get('new_agency_name')" class="mt-2" />
            </div>
        </div>

        <div class="flex items-center justify-end gap-4 mt-6 pt-6 border-t border-gray-100 dark:border-gray-700">
            <a class="underline text-sm text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-100 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gano-500 dark:focus:ring-offset-gray-800" href="{{ route('login') }}">
                {{ __('Already registered?') }}
            </a>

            <x-primary-button>
                {{ __('Register') }}
            </x-primary-button>
        </div>
    </form>
</x-guest-layout>
