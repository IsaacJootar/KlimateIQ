<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\WelcomeCredentialsMail;
use App\Models\Agency;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    /**
     * Display the registration view.
     */
    public function create(): View
    {
        return view('auth.register', [
            'agencies' => Agency::query()->orderBy('name')->get(),
            'designations' => config('nigeria.designations'),
            'states' => config('nigeria.states'),
        ]);
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'designation' => ['required', 'in:'.implode(',', array_keys(config('nigeria.designations')))],
            'other_designation' => ['nullable', 'required_if:designation,OTHER', 'string', 'max:100'],
            'state' => ['required', 'in:'.implode(',', config('nigeria.states'))],
            'agency_id' => ['required_without:new_agency_name', 'nullable', 'uuid', 'exists:agencies,agency_id'],
            'new_agency_name' => ['required_without:agency_id', 'nullable', 'string', 'max:200'],
        ], [
            'agency_id.required_without' => 'Select your organization, or add it if it isn\'t listed.',
            'new_agency_name.required_without' => 'Enter your organization\'s name.',
        ]);

        $agencyId = $request->filled('new_agency_name')
            ? Agency::query()->firstOrCreate(['name' => trim($request->string('new_agency_name'))])->agency_id
            : $request->input('agency_id');

        $designation = $request->input('designation') === 'OTHER'
            ? trim($request->string('other_designation'))
            : config('nigeria.designations')[$request->input('designation')];

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'agency_id' => $agencyId,
            'designation' => $designation,
            'state' => $request->input('state'),
        ]);

        event(new Registered($user));

        Mail::to($user)->send(new WelcomeCredentialsMail($user, $request->string('password')->toString()));

        Auth::login($user);

        return redirect(route('dashboard', absolute: false));
    }
}
