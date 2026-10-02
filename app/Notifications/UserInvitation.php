<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Actions\Auth\SendUserInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class UserInvitation extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $token,
        public ?string $inviterName,
        public string $roleName,
    ) {
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
        $url = SendUserInvitation::url($notifiable, $this->token);

        $who = $this->inviterName !== null && $this->inviterName !== ''
            ? "{$this->inviterName} invited you"
            : 'You have been invited';

        return (new MailMessage)
            ->subject('Set your Iconic password')
            ->line("{$who} to Iconic as {$this->roleName}.")
            ->action('Set your password', $url)
            ->line('This invitation expires in 7 days.');
    }
}
