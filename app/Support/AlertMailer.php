<?php

namespace App\Support;

use App\Mail\PennyhuntAlert;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

/**
 * One-liner alert delivery with per-key dedupe. Recipient comes from
 * PENNYHUNT_ALERT_EMAIL (falls back to the first user). Silently no-ops
 * when mail is unconfigured (MAIL_MAILER=log still writes to the log,
 * which is useful in development).
 */
class AlertMailer
{
    /**
     * @param  list<string>  $lines
     * @param  string|null  $dedupeKey  suppress repeats of the same alert within $dedupeHours
     */
    public static function send(
        string $subject,
        array $lines,
        ?string $actionUrl = null,
        ?string $actionLabel = null,
        ?string $dedupeKey = null,
        int $dedupeHours = 12,
    ): void {
        $to = config('pennyhunt.alerts.email') ?: User::query()->orderBy('id')->value('email');

        if (blank($to)) {
            return;
        }

        if ($dedupeKey !== null && ! Cache::add('alertmail:'.$dedupeKey, 1, now()->addHours($dedupeHours))) {
            return;
        }

        Mail::to($to)->queue(new PennyhuntAlert($subject, $lines, $actionUrl, $actionLabel));
    }
}
