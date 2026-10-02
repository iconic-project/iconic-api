<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Auth\CreateInvitedAdmin;
use Illuminate\Console\Command;
use RuntimeException;

final class CreateAdminCommand extends Command
{
    protected $signature = 'iconic:create-admin {email} {name}';

    protected $description = 'Invite the first Admin user and print the accept-invitation link';

    public function handle(CreateInvitedAdmin $action): int
    {
        try {
            [, $url] = $action->handle(
                (string) $this->argument('email'),
                (string) $this->argument('name'),
            );
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Invitation sent.');
        $this->line($url);

        return self::SUCCESS;
    }
}
