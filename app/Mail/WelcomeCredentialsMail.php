<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Mail\Mailable;

// Deliberately not ShouldQueue: queueing would serialize the plaintext password into the
// jobs store (Redis) until a worker picks it up. Registration is rare enough that sending
// synchronously and keeping the plaintext in-process only is the safer trade-off.
class WelcomeCredentialsMail extends Mailable
{

    public function __construct(public readonly User $user, public readonly string $plainPassword) {}

    public function build(): self
    {
        return $this
            ->subject('Welcome to KlimateIQ — your account details')
            ->markdown('emails.welcome', [
                'user' => $this->user,
                'plainPassword' => $this->plainPassword,
            ]);
    }
}
