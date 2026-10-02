<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\AgencyUser;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class PortalResetPasswordNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $token)
    {
        $this->afterCommit();
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $email = $notifiable instanceof AgencyUser
            ? $notifiable->getEmailForPasswordReset()
            : (string) $notifiable->email;

        $url = rtrim((string) config('iconic.portal_url'), '/').'/reset-password?'.http_build_query([
            'token' => $this->token,
            'email' => $email,
        ]);

        return (new MailMessage)
            ->subject('Reset your Iconic portal password')
            ->line('You are receiving this email because we received a password reset request for your account.')
            ->action('Reset password', $url)
            ->line('This password reset link expires in 60 minutes.');
    }
}
