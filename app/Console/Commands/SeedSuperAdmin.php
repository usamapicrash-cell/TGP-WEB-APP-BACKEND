<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Role;
use App\Models\User;
use App\Models\Company;

class SeedSuperAdmin extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'seed:superadmin';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Seed the database with roles, super admin, and company';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $this->info('Seeding Super Admin...');
        $superAdminRole = Role::where('name', 'super_admin')->first();

        $superAdmin = User::updateOrCreate(
            ['email' => 'admin@system.com'],
            [
                'name' => 'Super Admin',
                'password' => bcrypt('password'),
                'role_id' => $superAdminRole->id
            ]
        );

        $this->info('Seeding Company...');
        $company = Company::firstOrCreate(
            ['name' => 'System Company'],
            ['owner_id' => $superAdmin->id]
        );

        if (!$superAdmin->company_id) {
            $superAdmin->company_id = $company->id;
            $superAdmin->save();
        }

        $this->info('Seeding completed successfully!');
    }
}
