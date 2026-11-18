<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CreateAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_creates_an_admin_with_a_hashed_confirmed_password(): void
    {
        $password = 'Secure-admin-2026!';

        $this->artisan('app:create-admin')
            ->expectsQuestion('Name', 'Editor')
            ->expectsQuestion('Email', 'EDITOR@example.test')
            ->expectsQuestion('Password', $password)
            ->expectsQuestion('Confirm password', $password)
            ->expectsOutput('Administrator created. Sign in at /admin.')
            ->assertSuccessful();

        $user = User::where('email', 'editor@example.test')->sole();
        $this->assertTrue($user->is_admin);
        $this->assertTrue(Hash::check($password, $user->password));
        $this->assertNotSame($password, $user->password);
    }

    #[DataProvider('invalidCredentials')]
    public function test_invalid_credentials_do_not_create_an_account(string $email, string $password, string $confirmation, string $message): void
    {
        $this->artisan('app:create-admin')
            ->expectsQuestion('Name', 'Editor')
            ->expectsQuestion('Email', $email)
            ->expectsQuestion('Password', $password)
            ->expectsQuestion('Confirm password', $confirmation)
            ->expectsOutput($message)
            ->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }

    public static function invalidCredentials(): array
    {
        return [
            'bcrypt byte limit' => ['editor@example.test', str_repeat('密', 24).'Aa1!', str_repeat('密', 24).'Aa1!', 'Use at most 72 UTF-8 bytes and no null characters. Some characters use more than one byte.'],
            'weak password' => ['editor@example.test', 'weak', 'weak', 'The password field must be at least 12 characters.'],
            'mismatched confirmation' => ['editor@example.test', 'Secure-admin-2026!', 'Different-admin-2026!', 'The password field confirmation does not match.'],
            'invalid email' => ['invalid', 'Secure-admin-2026!', 'Secure-admin-2026!', 'The email field must be a valid email address.'],
        ];
    }

    public function test_duplicate_email_never_promotes_or_overwrites_a_user(): void
    {
        $user = User::factory()->create(['email' => 'reader@example.test']);
        $originalPassword = $user->password;

        $this->artisan('app:create-admin')
            ->expectsQuestion('Name', 'Editor')
            ->expectsQuestion('Email', 'reader@example.test')
            ->expectsQuestion('Password', 'Secure-admin-2026!')
            ->expectsQuestion('Confirm password', 'Secure-admin-2026!')
            ->expectsOutput('The email has already been taken.')
            ->assertFailed();

        $this->assertDatabaseCount('users', 1);
        $this->assertFalse($user->fresh()->is_admin);
        $this->assertSame($originalPassword, $user->fresh()->password);
    }

    public function test_noninteractive_execution_fails_without_creating_an_account(): void
    {
        $this->artisan('app:create-admin', ['--no-interaction' => true])
            ->expectsOutput('Run this command interactively to enter a password securely.')
            ->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }
}
