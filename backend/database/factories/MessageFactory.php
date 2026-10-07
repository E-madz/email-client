<?php

namespace Database\Factories;

use App\Models\Folder;
use App\Models\Message;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Message>
 */
class MessageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'folder_id' => Folder::factory(),
            'uid' => fake()->unique()->numberBetween(1, 100000),
            'rfc_message_id' => sprintf('<%s@example.com>', fake()->uuid()),
            'in_reply_to' => null,
            'subject' => fake()->sentence(),
            'from_name' => fake()->name(),
            'from_email' => fake()->safeEmail(),
            'to' => [['name' => null, 'email' => fake()->safeEmail()]],
            'cc' => [],
            'bcc' => [],
            'date' => fake()->dateTimeBetween('-30 days'),
            'body_text' => fake()->paragraph(),
            'body_html' => null,
            'is_read' => false,
            'is_flagged' => false,
            'has_attachments' => false,
        ];
    }
}
