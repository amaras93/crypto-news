<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\User;
use App\Support\ArticleContent;
use App\Support\ArticleEditor;
use App\Support\ArticlePublication;
use App\Support\EditorialMedia;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EditorialMediaTest extends TestCase
{
    use RefreshDatabase;

    public function test_safe_images_are_stored_with_random_names_and_reused_without_automatic_deletion(): void
    {
        Storage::fake('public');
        $admin = User::factory()->admin()->create();
        $path = app(EditorialMedia::class)->store($admin, UploadedFile::fake()->image('untrusted name.png', 800, 500));
        $this->assertMatchesRegularExpression('#^articles/featured/[a-f0-9-]{36}\.png$#', $path);
        Storage::disk('public')->assertExists($path);
        app(EditorialMedia::class)->validatePath($path);
        $data = ['author_id' => $admin->id, 'featured_image' => $path, 'image_credit' => 'Photo: newsroom',
            'translations' => ['en' => ['enabled' => true, 'title' => 'Image story', 'slug' => 'image-story', 'body' => '<p>Original article.</p>', 'image_alt' => 'A newsroom image']]];
        $article = app(ArticleEditor::class)->save($admin, $data);
        $this->assertSame($path, $article->featured_image);
        $this->assertSame('A newsroom image', $article->translation('en')->image_alt);
        $this->assertSame('Photo: newsroom', $article->image_credit);
        $article = app(ArticlePublication::class)->transition($admin, $article, 'publish');
        $this->get('/en/news/image-story')->assertOk()->assertSee('alt="A newsroom image"', false)->assertSee('Photo: newsroom');
        $shared = Article::factory()->create(['featured_image' => $path]);
        app(ArticlePublication::class)->trash($admin, $article);
        Storage::disk('public')->assertExists($path);
        $this->assertSame($path, $shared->fresh()->featured_image);
    }

    #[DataProvider('unsafeUploads')]
    public function test_unsafe_uploads_are_rejected(string $kind): void
    {
        Storage::fake('public');
        $editor = User::factory()->editor()->create();
        $file = match ($kind) {
            'svg' => UploadedFile::fake()->createWithContent('script.svg', '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>'),
            'php' => UploadedFile::fake()->createWithContent('shell.jpg', '<?php echo "unsafe"; ?>'),
            'size' => UploadedFile::fake()->image('large.png')->size(5121),
            'dimensions' => UploadedFile::fake()->image('wide.png', 6001, 1),
        };
        try {
            app(EditorialMedia::class)->store($editor, $file);
            $this->fail('Unsafe upload must be rejected.');
        } catch (ValidationException) {
            $this->assertSame([], Storage::disk('public')->allFiles());
        }
    }

    public static function unsafeUploads(): array
    {
        return [['svg'], ['php'], ['size'], ['dimensions']];
    }

    public function test_regular_users_cannot_upload_and_forged_paths_cannot_be_selected(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        try {
            app(EditorialMedia::class)->store($user, UploadedFile::fake()->image('photo.jpg'));
            $this->fail('Reader uploads are forbidden.');
        } catch (AuthorizationException) {
            $this->assertSame([], Storage::disk('public')->allFiles());
        }
        foreach (['../private.png', 'https://external.invalid/photo.png', 'articles/featured/unknown.svg'] as $path) {
            try {
                app(EditorialMedia::class)->validatePath($path);
                $this->fail('Forged image references are forbidden.');
            } catch (ValidationException) {
                $this->assertSame([], Storage::disk('public')->allFiles());
            }
        }
    }

    public function test_inline_image_storage_uses_the_same_safe_validator_and_public_url_boundary(): void
    {
        Storage::fake('public');
        $editor = User::factory()->editor()->create();
        $path = app(EditorialMedia::class)->store($editor, UploadedFile::fake()->image('inline.jpg', 600, 400), 'body');
        $this->assertMatchesRegularExpression('#^articles/body/[a-f0-9-]{36}\.jpg$#', $path);
        Storage::disk('public')->assertExists($path);
        $url = Storage::disk('public')->url($path);
        $safe = app(ArticleContent::class)->sanitize('<p>Image reporting</p><img src="'.e($url).'" alt="Human description">');
        $this->assertStringContainsString('Human description', $safe);
        $this->assertStringContainsString('/articles/body/', $safe);
    }

    public function test_rich_text_storage_is_safe_and_does_not_accumulate_presentation_wrappers(): void
    {
        $editor = User::factory()->editor()->create();
        $data = ['author_id' => $editor->id, 'translations' => ['en' => [
            'enabled' => true, 'title' => 'Safe table', 'slug' => 'safe-table',
            'body' => '<h2>Data</h2><table><tr><th>Asset</th><td>Bitcoin</td></tr></table><p><a href="javascript:alert(1)">Unsafe</a></p><iframe src="https://untrusted.invalid"></iframe>',
        ]]];
        $article = app(ArticleEditor::class)->save($editor, $data);
        $body = $article->translation('en')->body;
        $this->assertStringContainsString('<table>', $body);
        $this->assertStringNotContainsString('article-table', $body);
        $this->assertStringNotContainsString('javascript:', $body);
        $this->assertStringNotContainsString('<iframe', $body);
        $again = app(ArticleEditor::class)->save($editor, app(ArticleEditor::class)->formData($article), $article);
        $this->assertSame($body, $again->translation('en')->body);
    }
}
