<?php

declare(strict_types=1);

namespace App\Notifications\Auth;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class PasswordResetEmailNotification extends Notification
{
    use Queueable;

    /**
     * The opaque base64-encoded signed token built by PasswordResetService.
     * It encodes user id + email hash + expiry + HMAC. Sending it back to
     * the frontend via email lets us avoid storing reset tokens in the DB —
     * the signature is the credential.
     */
    public function __construct(private readonly string $signedToken) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        /** @var User $notifiable */
        // The frontend reset page is expected at APP_URL/reset-password. It
        // pulls `token` from the query string and POSTs to
        // /api/auth/password/reset/email.
        $url = rtrim((string) config('app.url'), '/')
            .'/reset-password?token='.urlencode($this->signedToken);

        return (new MailMessage)
            ->subject('Reset your password')
            ->greeting('Hello '.$notifiable->first_name.',')
            ->line('You requested a password reset. Click the button below to set a new password.')
            ->action('Reset Password', $url)
            ->line('This link expires in 60 minutes.')
            ->line('If you did not request this, ignore this email — your password remains unchanged.');
    }
}
