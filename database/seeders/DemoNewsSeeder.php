<?php

namespace Database\Seeders;

use App\ArticleStatus;
use App\Models\Article;
use App\Models\ArticleTranslation;
use App\Models\Category;
use App\Models\CategoryTranslation;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

class DemoNewsSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            throw new LogicException('Demo news may only be seeded explicitly in local or testing environments.');
        }

        $data = require __DIR__.'/data/demo-news.php';
        $locales = ['en', 'ru'];

        DB::transaction(function () use ($data, $locales): void {
            $author = User::firstOrCreate(
                ['email' => 'demo-editor@cryptonews.invalid'],
                ['name' => 'CryptoNews Demo Desk', 'password' => Str::random(64)],
            );
            $demo = Tag::firstOrCreate(['slug' => 'cryptonews-demo'], ['name' => 'Demo content']);
            $analysis = Tag::firstOrCreate(['slug' => 'analysis'], ['name' => 'Analysis']);
            $pick = Tag::firstOrCreate(['slug' => 'editors-pick'], ['name' => 'Editorial selection']);
            $categories = [];

            foreach ($data['categories'] as $slug => $names) {
                $existing = CategoryTranslation::where('locale', 'en')->where('slug', $slug)->first();
                $category = $existing?->category ?? Category::create(['sort_order' => count($categories)]);

                foreach ($locales as $index => $locale) {
                    if (CategoryTranslation::where('locale', $locale)->where('slug', $slug)->exists()) {
                        continue;
                    }

                    $category->translations()->firstOrCreate(['locale' => $locale], [
                        'slug' => $slug,
                        'name' => $names[$index],
                    ]);
                }

                $categories[$slug] = $category;
            }

            foreach ($data['stories'] as $index => $story) {
                $slugs = array_map(fn (string $locale): string => 'demo-'.$story['slug'].'-'.$locale, ['en', 'ru', 'hy']);

                if (ArticleTranslation::whereIn('slug', $slugs)->exists()) {
                    continue;
                }

                $article = Article::create([
                    'author_id' => $author->id,
                    'category_id' => $categories[$story['category']]->id,
                    'status' => ArticleStatus::Published,
                    'published_at' => now()->subMinutes(35 + $index * 90),
                    'featured_image' => 'editorial/'.$story['image'].'.svg',
                ]);

                foreach ($locales as $languageIndex => $locale) {
                    $notice = trans('ui.demo_notice', [], $locale);
                    $article->translations()->create([
                        'locale' => $locale,
                        'slug' => $slugs[$languageIndex],
                        'title' => $story['titles'][$languageIndex],
                        'excerpt' => $story['excerpts'][$languageIndex],
                        'body' => $notice."\n\n".$story['excerpts'][$languageIndex]."\n\n".$notice,
                        'meta_title' => $story['titles'][$languageIndex],
                        'meta_description' => $story['excerpts'][$languageIndex],
                    ]);
                }

                $article->tags()->attach(array_filter([
                    $demo->id,
                    $story['analysis'] ? $analysis->id : null,
                    $story['pick'] ? $pick->id : null,
                ]));
            }
        });

        $this->command?->info('Fictional demo content added. Existing records were preserved.');
    }
}
