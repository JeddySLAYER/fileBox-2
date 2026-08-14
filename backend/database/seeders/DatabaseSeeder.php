<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolePermissionSeeder::class,
            SystemSettingSeeder::class,
        ]);

        $admin = User::query()->updateOrCreate(
            ['email' => 'admin@filebox.local'],
            [
                'name' => 'Administrateur FileBox',
                'password' => 'password',
                'must_change_password' => false,
                'is_active' => true,
                'department_id' => null,
            ]
        );

        $adminRole = Role::query()->where('slug', 'administrateur')->firstOrFail();
        $admin->roles()->sync([$adminRole->id]);

        $this->call(DemoDataSeeder::class);
    }
}
