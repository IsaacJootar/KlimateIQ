<?php

namespace App\Notifications;

use App\Models\Alert;
use App\Models\IndexActionRecommendation;
use App\Models\PlatformSetting;
use App\Notifications\Channels\SmsChannel;
use App\Services\Sms\SmsClient;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ThresholdBreachedNotification extends Notification
{
    use Queueable;

    public function __construct(public readonly Alert $alert) {}

    /**
     * Null when the alert targets a raw signal rather than a named index — the
     * recommendation table is keyed by index, not signal type.
     */
    private function recommendedAction(): ?string
    {
        if ($this->alert->index_id === null) {
            return null;
        }

        return IndexActionRecommendation::textFor($this->alert->index_id, (float) $this->alert->score_at_trigger);
    }

    /**
     * @param  \App\Models\User  $notifiable
     */
    public function via(object $notifiable): array
    {
        $preference = $notifiable->getOrCreateDashboardPreference();
        $channels = [];

        // In-app always records — it's the free, always-on baseline every user gets, whatever
        // they've chosen for the channels that cost something to send.
        if ($preference->wantsChannel('in_app')) {
            $channels[] = 'database';
        }

        // Two gates for email: the user has to want it AND the platform-wide toggle has to be
        // on — a single admin-visible switch to kill all outbound email without touching every
        // user's preferences.
        if ($preference->wantsChannel('email') && PlatformSetting::get('email.notifications_enabled', false)) {
            $channels[] = 'mail';
        }

        if ($preference->wantsChannel('sms') && $notifiable->phone_number && app(SmsClient::class)->isConfigured()) {
            $channels[] = SmsChannel::class;
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $this->alert->loadMissing(['region', 'index', 'signalType']);

        $target = $this->alert->index?->name ?? $this->alert->signalType?->name ?? 'a signal';
        $region = $this->alert->region->name;

        $mail = (new MailMessage)
            ->subject("Threshold breached — {$target} in {$region}")
            ->greeting('Hi '.$notifiable->name.',')
            ->line("{$target} in {$region} has crossed a threshold you set.")
            ->line("Value at trigger: {$this->alert->score_at_trigger}".
                ($this->alert->threshold_value !== null ? " (threshold: {$this->alert->threshold_value})" : ' (anomaly vs. its own recent baseline)'));

        if ($action = $this->recommendedAction()) {
            $mail->line("Recommended action: {$action}");
        }

        return $mail
            ->action('View alert', route('alerts.index'))
            ->line('Log in to Gano.ai to acknowledge or resolve this alert.');
    }

    public function toDatabase(object $notifiable): array
    {
        $this->alert->loadMissing(['region', 'index', 'signalType']);

        $target = $this->alert->index?->name ?? $this->alert->signalType?->name ?? 'a signal';
        $body = "{$target} in {$this->alert->region->name} crossed a threshold you set.";

        if ($action = $this->recommendedAction()) {
            $body .= " Recommended action: {$action}";
        }

        return [
            'type' => 'threshold_breached',
            'title' => "Threshold breached — {$target}",
            'body' => $body,
            'alert_id' => $this->alert->alert_id,
            'url' => route('alerts.index'),
        ];
    }

    public function toSms(object $notifiable): string
    {
        $this->alert->loadMissing(['region', 'index', 'signalType']);

        $target = $this->alert->index?->name ?? $this->alert->signalType?->name ?? 'a signal';

        $message = "Gano.ai alert: {$target} in {$this->alert->region->name} crossed your threshold ".
            "(value: {$this->alert->score_at_trigger}).";

        if ($action = $this->recommendedAction()) {
            $message .= " Recommended: {$action}";
        }

        return $message.' Details: '.route('alerts.index');
    }
}
