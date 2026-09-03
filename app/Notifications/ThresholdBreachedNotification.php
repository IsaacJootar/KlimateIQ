<?php

namespace App\Notifications;

use App\Models\Alert;
use App\Models\IndexActionRecommendation;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Notifications\Channels\SmsChannel;
use App\Services\Sms\SmsClient;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ThresholdBreachedNotification extends Notification
{
    use Queueable;

    public function __construct(public readonly Alert $alert) {}

    /** The forecast peak as a whole number — "62", not "62.2000". */
    private function forecastPeak(): int
    {
        return (int) round((float) $this->alert->score_at_trigger);
    }

    /** "72%" — the realised ensemble probability for a probability-threshold alert. */
    private function forecastProbabilityPct(): string
    {
        return round((float) $this->alert->forecast_probability * 100).'%';
    }

    private function isProbabilityAlert(): bool
    {
        return $this->alert->forecast_probability !== null;
    }

    /** The rule's index level, trimmed of trailing decimal zeros — "67", not "67.0000". */
    private function level(): string
    {
        return rtrim(rtrim((string) $this->alert->threshold_value, '0'), '.');
    }

    /** "in about 5 days (around Sep 9)" — the lead-time clause for a forecast alert. */
    private function forecastWhen(): string
    {
        $lead = $this->alert->forecast_lead_days;
        $date = $this->alert->forecast_target_date?->format('M j');

        return match (true) {
            $lead === 0 => 'today'.($date ? " ({$date})" : ''),
            $lead === 1 => 'tomorrow'.($date ? " ({$date})" : ''),
            default => "in about {$lead} days".($date ? " (around {$date})" : ''),
        };
    }

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
     * @param  User  $notifiable
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
        $this->alert->loadMissing(['region', 'index', 'signalType', 'thresholdConfig']);

        $target = $this->alert->index?->name ?? $this->alert->signalType?->name ?? 'a signal';
        $region = $this->alert->region->name;

        if ($this->isProbabilityAlert()) {
            $mail = (new MailMessage)
                ->subject("FORECAST — {$this->forecastProbabilityPct()} chance {$target} for {$region} crosses your level")
                ->greeting('Hi '.$notifiable->name.',')
                ->line('This is a probability from an ensemble forecast, not a certainty.')
                ->line("The ensemble gives about a {$this->forecastProbabilityPct()} chance of {$target} for {$region} ".
                    "reaching {$this->level()}+ within the forecast window — at or above the ".
                    "{$this->alert->thresholdConfig?->probability_threshold}% you asked to be warned at.")
                ->line("Most-likely peak: {$this->forecastPeak()} ".$this->forecastWhen().'.');

            if ($action = $this->recommendedAction()) {
                $mail->line("Recommended action: {$action}");
            }

            return $mail
                ->action('View alert', route('alerts.index'))
                ->line('The lead time is your window to prepare.');
        }

        if ($this->alert->is_forecast) {
            $mail = (new MailMessage)
                ->subject("FORECAST — {$target} for {$region} projected to breach")
                ->greeting('Hi '.$notifiable->name.',')
                ->line('This is a forecast, not a current reading.')
                ->line("{$target} for {$region} is projected to reach {$this->forecastPeak()} ".$this->forecastWhen().
                    ($this->alert->threshold_value !== null ? " — past the {$this->alert->threshold_value} threshold you set." : '.'));

            if ($action = $this->recommendedAction()) {
                $mail->line("Recommended action: {$action}");
            }

            return $mail
                ->action('View alert', route('alerts.index'))
                ->line('The lead time is your window to prepare.');
        }

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
            ->line('Log in to KlimateIQ to acknowledge or resolve this alert.');
    }

    public function toDatabase(object $notifiable): array
    {
        $this->alert->loadMissing(['region', 'index', 'signalType', 'thresholdConfig']);

        $target = $this->alert->index?->name ?? $this->alert->signalType?->name ?? 'a signal';

        $body = match (true) {
            $this->isProbabilityAlert() => "FORECAST: about a {$this->forecastProbabilityPct()} chance {$target} for {$this->alert->region->name} reaches {$this->level()}+ within the forecast window (most-likely peak {$this->forecastPeak()} ".$this->forecastWhen().'). A probability from an ensemble forecast, not a certainty.',
            $this->alert->is_forecast => "FORECAST: {$target} for {$this->alert->region->name} is projected to reach {$this->forecastPeak()} ".$this->forecastWhen().'. This is a forecast, not a current reading.',
            default => "{$target} in {$this->alert->region->name} crossed a threshold you set.",
        };

        if ($action = $this->recommendedAction()) {
            $body .= " Recommended action: {$action}";
        }

        return [
            'type' => match (true) {
                $this->isProbabilityAlert() => 'forecast_probability',
                $this->alert->is_forecast => 'forecast_breach',
                default => 'threshold_breached',
            },
            'title' => match (true) {
                $this->isProbabilityAlert() => "Forecast ({$this->forecastProbabilityPct()} chance) — ".$target,
                $this->alert->is_forecast => 'Forecast — '.$target,
                default => 'Threshold breached — '.$target,
            },
            'body' => $body,
            'alert_id' => $this->alert->alert_id,
            'url' => route('alerts.index'),
        ];
    }

    public function toSms(object $notifiable): string
    {
        $this->alert->loadMissing(['region', 'index', 'signalType', 'thresholdConfig']);

        $target = $this->alert->index?->name ?? $this->alert->signalType?->name ?? 'a signal';

        $message = match (true) {
            $this->isProbabilityAlert() => "KlimateIQ FORECAST: ~{$this->forecastProbabilityPct()} chance {$target} for {$this->alert->region->name} reaches {$this->level()}+ ".$this->forecastWhen().' (ensemble probability, not a certainty).',
            $this->alert->is_forecast => "KlimateIQ FORECAST: {$target} for {$this->alert->region->name} projected to reach {$this->forecastPeak()} ".$this->forecastWhen().' (forecast, not current).',
            default => "KlimateIQ alert: {$target} in {$this->alert->region->name} crossed your threshold (value: {$this->alert->score_at_trigger}).",
        };

        if ($action = $this->recommendedAction()) {
            $message .= " Recommended: {$action}";
        }

        return $message.' Details: '.route('alerts.index');
    }
}
