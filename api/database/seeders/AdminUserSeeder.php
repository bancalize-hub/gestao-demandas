<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('ADMIN_EMAIL', 'pauloguilherme.aformula@gmail.com');
        $password = env('ADMIN_PASSWORD');

        if (! $password) {
            // Sem ADMIN_PASSWORD no .env: não cria/atualiza às cegas.
            $this->command?->warn('AdminUserSeeder: defina ADMIN_PASSWORD no .env para criar o admin.');

            return;
        }

        $user = User::updateOrCreate(
            ['email' => $email],
            ['name' => 'Administrador', 'password' => Hash::make($password)],
        );
        $user->is_admin = true;
        $user->save();

        $this->command?->info("Admin garantido: {$email}");
    }
}
