<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\MailServer;
use Illuminate\Database\Seeder;

class MailServerSeeder extends Seeder
{
    /**
     * Seed the application's mail server profiles.
     */
    public function run(): void
    {
        MailServer::query()->updateOrCreate(
            ['slug' => 'iranserver'],
            [
                'name' => 'IranServer',
                'imap_host' => 'mail.iranserver.com',
                'imap_port' => 993,
                'imap_encryption' => 'ssl',
                'smtp_host' => 'mail.iranserver.com',
                'smtp_port' => 465,
                'smtp_encryption' => 'ssl',
                'is_active' => true,
                'is_default' => true,
                'notes' => 'Default provider for the MVP. All email addresses are routed here regardless of domain.',
            ],
        );
    }
}
