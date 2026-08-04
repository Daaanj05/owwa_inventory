<?php

namespace App\Console\Commands;

use App\Models\Office;
use App\Models\User;
use App\Support\InventoryDemoReset;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class KeepSystemAdminOnlyCommand extends Command
{
    protected $signature = 'owwa:keep-system-admin-only
                            {--force : Required in production}
                            {--clear-offices : Detach admins and delete all offices}
                            {--email= : Set the remaining system admin email}
                            {--password= : Set the remaining system admin password}';

    protected $description = 'Wipe inventory data, keep system admin only (optional: clear offices and set credentials)';

    public function handle(): int
    {
        if (app()->environment('production') && ! $this->option('force')) {
            $this->error('Refusing to run in production without --force.');

            return self::FAILURE;
        }

        $adminCount = User::query()->where('role', User::ROLE_SYSTEM_ADMIN)->count();

        if ($adminCount < 1) {
            $this->error('No system_admin user found. Seed or create one first.');

            return self::FAILURE;
        }

        $this->info('Truncating inventory tables (FK-safe)…');
        InventoryDemoReset::truncateInventoryTables();
        InventoryDemoReset::resetTransactionReferenceSeries();

        DB::transaction(function (): void {
            $deleted = User::query()
                ->where('role', '!=', User::ROLE_SYSTEM_ADMIN)
                ->delete();

            $this->info("Deleted {$deleted} non–system-admin user(s).");

            if ($this->option('clear-offices')) {
                User::query()
                    ->where('role', User::ROLE_SYSTEM_ADMIN)
                    ->update(['office_id' => null, 'department_id' => null]);

                $officeCount = Office::query()->count();
                Office::query()->delete();
                $this->info("Cleared {$officeCount} office(s).");
            }

            $email = $this->option('email');
            $password = $this->option('password');

            if (filled($email) || filled($password)) {
                $admin = User::query()
                    ->where('role', User::ROLE_SYSTEM_ADMIN)
                    ->orderBy('id')
                    ->firstOrFail();

                if (filled($email)) {
                    $admin->email = (string) $email;
                }

                if (filled($password)) {
                    $admin->password = (string) $password;
                    $admin->must_change_password = false;
                }

                $admin->save();
                $this->info("Updated system admin credentials ({$admin->email}).");
            }
        });

        $this->newLine();
        $this->info('Remaining users:');
        User::query()
            ->orderBy('id')
            ->get(['id', 'email', 'role', 'office_id'])
            ->each(fn (User $user): mixed => $this->line(
                "  #{$user->id} {$user->email} | {$user->role} | office_id=".($user->office_id ?? 'null')
            ));

        return self::SUCCESS;
    }
}
