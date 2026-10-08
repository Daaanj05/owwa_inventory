<?php

namespace App\Console\Commands;

use App\Models\Office;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class PrepareLoadTestUsers extends Command
{
    protected $signature = 'loadtest:prepare
                            {--count=20 : Number of load-test users to create or update}
                            {--password=password : Shared password for load-test users}
                            {--force : Allow outside local/testing when LOAD_TEST_ENABLED=true}';

    protected $description = 'Create verified operations users and a credentials file for k6 load tests';

    public function handle(): int
    {
        if (! $this->isAllowed()) {
            $this->error('loadtest:prepare is only allowed in local/testing, or when LOAD_TEST_ENABLED=true with --force.');

            return self::FAILURE;
        }

        $count = max(1, (int) $this->option('count'));
        $password = (string) $this->option('password');
        $office = Office::query()->active()->orderBy('id')->first() ?? Office::factory()->create();

        $users = [];

        for ($i = 1; $i <= $count; $i++) {
            $email = sprintf('loadtest.user%02d@example.test', $i);
            $role = $i <= 2 ? User::ROLE_SUPPLY_CUSTODIAN : User::ROLE_EMPLOYEE;

            $user = User::query()->firstOrNew(['email' => $email]);
            $user->forceFill([
                'first_name' => 'Load',
                'last_name' => sprintf('Tester %02d', $i),
                'name' => sprintf('Load Tester %02d', $i),
                'password' => $password,
                'role' => $role,
                'office_id' => $office->id,
                'email_verified_at' => now(),
                'must_change_password' => false,
            ])->save();

            $users[] = [
                'email' => $user->email,
                'password' => $password,
                'role' => $user->role,
            ];
        }

        $path = base_path('loadtests/.credentials.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode([
            'base_url' => rtrim((string) config('app.url'), '/'),
            'login_path' => '/login',
            'users' => $users,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        $this->info("Prepared {$count} load-test user(s).");
        $this->line('Credentials written to loadtests/.credentials.json (gitignored).');

        return self::SUCCESS;
    }

    protected function isAllowed(): bool
    {
        if (app()->environment(['local', 'testing'])) {
            return true;
        }

        return (bool) config('inventory.load_test_enabled', false) && (bool) $this->option('force');
    }
}
