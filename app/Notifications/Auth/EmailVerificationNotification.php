<?php

declare(strict_types=1);

namespace App\Notifications\Auth;

use App\Models\PlatformSetting;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

final class EmailVerificationNotification extends Notification
{
    use Queueable;

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        /** @var User $notifiable */
        $hours = (int) PlatformSetting::get('email_verification_ttl_hours', 24);

        // Signed URL: id + hash(email) form the routable params; expires +
        // signature are appended by Laravel. If the user later changes their
        // email, the hash check on the receiving end blocks the link.
        $url = URL::temporarySignedRoute(
            'auth.email.verify',
            now()->addHours($hours),
            ['id' => $notifiable->id, 'hash' => sha1((string) $notifiable->email)],
        );

        return (new MailMessage)
            ->subject('Verify your email address')
            ->greeting('Hello '.$notifiable->first_name.',')
            ->line('Please verify your email address by clicking the button below.')
            ->action('Verify Email', $url)
            ->line('This link expires in '.$hours.' hours.')
            ->line('If you did not request this, ignore this email.');
    }
}
