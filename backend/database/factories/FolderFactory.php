<?php

namespace Database\Factories;

use App\Models\EmailAccount;
use App\Models\Folder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Folder>
 */
class FolderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'email_account_id' => EmailAccount::factory(),
            'name' => 'INBOX',
            'type' => 'inbox',
            'display_name' => 'صندوق ورودی',
            'uid_validity' => null,
            'last_uid' => 0,
            'last_synced_at' => null,
        ];
    }
}
