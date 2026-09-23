<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AccessToken;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/** Creates the person if needed and issues them a token, shown once. */
final class IssueAccessToken extends Command
{
    protected $signature = 'rfq:issue-token
        {email : who the token is for}
        {--name= : their name, for a new user (shown on reviews)}
        {--label=default : what the token is for, e.g. "laptop" or "ERP integration"}
        {--revoke= : revoke the token with this id instead}';

    protected $description = 'Issue (or revoke) a personal access token';

    public function handle(): int
    {
        if (is_string($this->option('revoke'))) {
            $revoked = AccessToken::query()->whereKey((int) $this->option('revoke'))->whereNull('revoked_at')->update(['revoked_at' => now()]);
            $this->info($revoked === 1 ? 'Revoked.' : 'No active token with that id.');

            return $revoked === 1 ? self::SUCCESS : self::FAILURE;
        }

        $email = (string) $this->argument('email');
        $user = User::query()->firstWhere('email', $email);
        if ($user === null) {
            if (! is_string($this->option('name')) || $this->option('name') === '') {
                $this->error("No user {$email}; pass --name to create one.");

                return self::FAILURE;
            }
            $user = User::query()->create(['email' => $email, 'name' => $this->option('name'), 'password' => Str::random(40)]);
        }

        [$token, $plain] = AccessToken::issue($user, (string) $this->option('label'));
        $this->info("Token {$token->id} for {$user->name} <{$user->email}>. Shown once — store it now:");
        $this->line($plain);

        return self::SUCCESS;
    }
}
