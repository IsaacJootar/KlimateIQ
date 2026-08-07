<?php

namespace App\Notifications;

use App\Models\Alert;
use App\Models\PlatformSetting;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ThresholdBreachedNotification extends Notification
{
    use Queueable;

    public function __construct(public readonly Alert $alert) {}

    public function via(object $notifiable): array
    {
        // The alert itself always records regardless of this setting — only the email is
        // gated, so a hackathon demo isn't blocked on having a Resend key configured yet.
        if (! PlatformSetting::get('email.notifications_enabled', false)) {
            return [];
        }

        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $this->alert->loadMissing(['region', 'index', 'signalType']);

        $target = $this->alert->index?->name ?? $this->alert->signalType?->name ?? 'a signal';
        $region = $this->alert->region->name;

        return (new MailMessage)
            ->subject("Threshold breached — {$target} in {$region}")
            ->greeting('Hi '.$notifiable->name.',')
            ->line("{$target} in {$region} has crossed a threshold you set.")
            ->line("Value at trigger: {$this->alert->score_at_trigger}".
                ($this->alert->threshold_value !== null ? " (threshold: {$this->alert->threshold_value})" : ' (anomaly vs. its own recent baseline)'))
            ->action('View alert', route('alerts.index'))
            ->line('Log in to Gano.ai to acknowledge or resolve this alert.');
    }
}
