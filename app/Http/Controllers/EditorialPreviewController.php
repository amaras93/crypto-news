<?php

namespace App\Http\Controllers;

use App\Models\Article;
use App\Support\ArticleContent;
use App\Support\ArticleMedia;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class EditorialPreviewController extends Controller
{
    public function __invoke(Article $article, string $locale, ArticleContent $renderer, ArticleMedia $media): Response
    {
        Gate::authorize('preview', $article);
        $article->load('translations', 'author', 'category.translations', 'tags');
        $translation = $article->translation($locale);
        abort_unless($translation, 404);
        $content = $renderer->render($translation->body, $article->isSponsored());

        return response()->view('editorial.preview', [
            'article' => $article, 'translation' => $translation, 'content' => $content,
            'hero' => $media->hero($article, $article->category?->translation('en')?->slug),
        ])->header('Cache-Control', 'private, no-store, max-age=0')->header('X-Robots-Tag', 'noindex, nofollow, noarchive');
    }
}
