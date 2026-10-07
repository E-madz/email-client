<?php

namespace Database\Factories;

use App\Models\MailServer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MailServer>
 */
class MailServerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'slug' => fake()->unique()->slug(2),
            'imap_host' => 'mail.'.fake()->domainName(),
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'smtp_host' => 'mail.'.fake()->domainName(),
            'smtp_port' => 465,
            'smtp_encryption' => 'ssl',
            'is_active' => true,
            'is_default' => false,
        ];
    }
}
