<?php

namespace Database\Factories;

use App\Models\EmailAccount;
use App\Models\MailServer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmailAccount>
 */
class EmailAccountFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'mail_server_id' => fn () => MailServer::query()->where('slug', 'iranserver')->first()?->id
                ?? MailServer::factory()->create(['is_default' => true])->id,
            'email' => fake()->unique()->safeEmail(),
            'password' => fake()->password(),
            'status' => 'active',
            'last_verified_at' => now(),
        ];
    }
}
