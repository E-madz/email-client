<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_can_update_their_name_and_username(): void
    {
        $user = User::factory()->create(['name' => 'Old Name', 'username' => 'oldusername']);

        $response = $this->actingAs($user)->putJson('/api/profile', [
            'name' => 'New Name',
            'username' => 'newusername',
        ]);

        $response->assertOk()->assertJson([
            'name' => 'New Name',
            'username' => 'newusername',
        ]);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'New Name',
            'username' => 'newusername',
        ]);
    }

    public function test_updating_the_profile_does_not_change_email_or_password(): void
    {
        $user = User::factory()->create(['email' => 'kept@example.com']);
        $originalPasswordHash = $user->password;

        $this->actingAs($user)->putJson('/api/profile', [
            'name' => 'New Name',
            'username' => 'newusername',
        ])->assertOk();

        $user->refresh();
        $this->assertSame('kept@example.com', $user->email);
        $this->assertSame($originalPasswordHash, $user->password);
    }

    public function test_username_must_stay_unique_across_other_users(): void
    {
        User::factory()->create(['username' => 'taken']);
        $user = User::factory()->create(['username' => 'mine']);

        $response = $this->actingAs($user)->putJson('/api/profile', [
            'name' => 'New Name',
            'username' => 'taken',
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors('username');
    }

    public function test_a_user_can_keep_their_own_current_username(): void
    {
        $user = User::factory()->create(['username' => 'mine']);

        $response = $this->actingAs($user)->putJson('/api/profile', [
            'name' => 'New Name',
            'username' => 'mine',
        ]);

        $response->assertOk();
    }

    public function test_updating_the_profile_requires_authentication(): void
    {
        $response = $this->putJson('/api/profile', [
            'name' => 'New Name',
            'username' => 'newusername',
        ]);

        $response->assertUnauthorized();
    }

    public function test_a_user_can_change_their_password_with_the_correct_current_password(): void
    {
        $user = User::factory()->create(['password' => 'oldpassword123']);

        $response = $this->actingAs($user)->putJson('/api/profile/password', [
            'current_password' => 'oldpassword123',
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ]);

        $response->assertNoContent();

        $this->assertTrue(Hash::check('newpassword123', $user->refresh()->password));
    }

    public function test_changing_the_password_fails_with_the_wrong_current_password(): void
    {
        $user = User::factory()->create(['password' => 'oldpassword123']);
        $originalPasswordHash = $user->password;

        $response = $this->actingAs($user)->putJson('/api/profile/password', [
            'current_password' => 'wrongpassword',
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->assertSame($originalPasswordHash, $user->refresh()->password);
    }

    public function test_changing_the_password_requires_confirmation_to_match(): void
    {
        $user = User::factory()->create(['password' => 'oldpassword123']);

        $response = $this->actingAs($user)->putJson('/api/profile/password', [
            'current_password' => 'oldpassword123',
            'password' => 'newpassword123',
            'password_confirmation' => 'doesnotmatch',
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors('password');
    }
}
