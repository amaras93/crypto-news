<?php

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\User;
use App\Notifications\VerifyReaderEmail;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ReaderAccountTest extends TestCase
{
    use RefreshDatabase;

    private function profile(User $user): array
    {
        return ['name' => 'Updated Reader', 'email' => $user->email, 'preferred_locale' => 'fr', 'reply_notifications' => '1'];
    }

    public function test_account_requires_authentication_and_has_no_public_seo_metadata(): void
    {
        $this->get('/fr/account')->assertRedirect('/fr/login');
        $user = User::factory()->create();
        $this->actingAs($user)->get('/fr/account')->assertOk()->assertSee($user->email)
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')->assertDontSee('application/ld+json', false)
            ->assertDontSee('rel="canonical"', false)->assertDontSee('rel="alternate"', false);
        $this->get('/sitemap-pages.xml')->assertDontSee('/account')->assertDontSee('/login');
        $this->get('/en/users')->assertNotFound();
    }

    public function test_profile_updates_are_own_only_and_cannot_change_editorial_roles(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->admin()->create();
        $this->actingAs($user)->patch('/en/account', $this->profile($user) + ['user_id' => $other->id, 'is_admin' => true])->assertSessionHasErrors('community');
        $this->assertFalse($user->fresh()->is_admin);
        $this->assertTrue($other->fresh()->is_admin);
        $this->patch('/en/account', $this->profile($user))->assertRedirect('/en/account')->assertSessionHasNoErrors();
        $user->refresh();
        $this->assertSame('Updated Reader', $user->name);
        $this->assertSame('fr', $user->preferred_locale);
        $this->assertTrue($user->reply_notifications);
        $this->assertFalse($user->is_editor);
    }

    public function test_email_change_requires_password_and_revokes_verification(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $this->actingAs($user);
        $payload = array_replace($this->profile($user), ['email' => 'new@example.test']);
        $this->patch('/en/account', $payload)->assertSessionHasErrors('current_password');
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $this->patch('/en/account', $payload + ['current_password' => 'password'])->assertSessionHasNoErrors();
        $this->assertSame('new@example.test', $user->fresh()->email);
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
        Notification::assertSentTo($user, VerifyReaderEmail::class);
    }

    public function test_avatar_uploads_have_safe_names_and_shared_files_are_retained(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $this->actingAs($user)->patch('/en/account', $this->profile($user) + ['avatar' => UploadedFile::fake()->image('unsafe-name.png', 64, 64)])->assertSessionHasNoErrors();
        $path = $user->fresh()->avatar_path;
        $this->assertMatchesRegularExpression('#^avatars/[a-f0-9-]{36}\\.png$#', $path);
        Storage::disk('public')->assertExists($path);
        $this->patch('/en/account', $this->profile($user) + ['avatar' => UploadedFile::fake()->image('replacement.jpg')])->assertSessionHasNoErrors();
        Storage::disk('public')->assertExists($path);
        $this->assertNotSame($path, $user->fresh()->avatar_path);
    }

    public function test_svg_oversized_and_oversized_dimension_avatars_are_rejected(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $this->actingAs($user);
        foreach ([
            UploadedFile::fake()->createWithContent('avatar.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
            UploadedFile::fake()->image('large.png')->size(2049),
            UploadedFile::fake()->image('wide.png', 2049, 1),
        ] as $avatar) {
            $this->patch('/en/account', $this->profile($user) + ['avatar' => $avatar])->assertSessionHasErrors('avatar');
        }
        $this->assertNull($user->fresh()->avatar_path);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_password_change_requires_current_password_rotates_token_and_preserves_roles(): void
    {
        $user = User::factory()->editor()->create();
        $oldToken = $user->remember_token;
        DB::table('sessions')->insert(['id' => 'other-session', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
        $this->actingAs($user);
        $payload = ['current_password' => 'wrong', 'password' => 'Changed-Reader123!', 'password_confirmation' => 'Changed-Reader123!'];
        $this->put('/en/account/password', $payload)->assertSessionHasErrors('current_password');
        $this->put('/en/account/password', array_replace($payload, ['current_password' => 'password']))->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('Changed-Reader123!', $user->fresh()->password));
        $this->assertNotSame($oldToken, $user->fresh()->remember_token);
        $this->assertTrue($user->fresh()->is_editor);
        $this->assertDatabaseMissing('sessions', ['id' => 'other-session']);
    }

    public function test_my_comments_and_likes_are_paginated_private_and_only_owned_activity_is_shown(): void
    {
        $user = User::factory()->create();
        $mine = Comment::factory()->for($user)->create(['body' => 'My pending text']);
        $other = Comment::factory()->approved()->create(['body' => 'Other approved text']);
        Comment::factory()->create(['body' => 'Secret other pending']);
        $other->likes()->create(['user_id' => $user->id]);
        $this->actingAs($user)->get('/en/account')->assertSee('My pending text')->assertDontSee('Secret other pending')->assertDontSee('Other approved text');
        $this->get('/en/account?tab=liked')->assertSee('Other approved text')->assertDontSee('My pending text');
        $this->patch('/en/account/comments/'.$other->id, ['body' => 'Hijacked'])->assertForbidden();
        $this->delete('/en/account/comments/'.$other->id)->assertForbidden();
        $this->patch('/en/account/comments/'.$mine->id, ['body' => 'My corrected comment'])->assertSessionHasNoErrors();
        $this->assertSame('My corrected comment', $mine->fresh()->body);
        $this->delete('/en/account/comments/'.$mine->id)->assertSessionHasNoErrors();
        $this->assertSoftDeleted($mine);
    }

    public function test_optional_reply_notifications_default_to_disabled_and_can_be_disabled(): void
    {
        $user = User::factory()->create();
        $this->assertFalse($user->fresh()->reply_notifications);
        $this->actingAs($user)->patch('/en/account', $this->profile($user))->assertSessionHasNoErrors();
        $this->assertTrue($user->fresh()->reply_notifications);
        $this->patch('/en/account', array_replace($this->profile($user), ['reply_notifications' => '0']))->assertSessionHasNoErrors();
        $this->assertFalse($user->fresh()->reply_notifications);
    }

    public function test_liked_comments_disappear_when_their_translation_is_removed(): void
    {
        $user = User::factory()->create();
        $comment = Comment::factory()->approved()->create(['body' => 'Translation removed sentinel']);
        $comment->likes()->create(['user_id' => $user->id]);
        $this->actingAs($user)->get('/en/account?tab=liked')->assertSee($comment->body);
        $comment->article->translations()->delete();
        $this->get('/en/account?tab=liked')->assertDontSee($comment->body);
    }

    public function test_failed_avatar_storage_does_not_report_success_or_change_the_profile(): void
    {
        $disk = \Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('putFileAs')->once()->andReturn(false);
        Storage::shouldReceive('disk')->with('public')->andReturn($disk);
        $user = User::factory()->create();
        $this->actingAs($user)->patch('/en/account', $this->profile($user) + ['avatar' => UploadedFile::fake()->image('avatar.png')])
            ->assertSessionHasErrors('avatar');
        $this->assertNull($user->fresh()->avatar_path);
        $this->assertSame($user->name, $user->fresh()->name);
    }
}
