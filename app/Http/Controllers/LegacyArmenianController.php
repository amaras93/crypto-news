<?php

namespace App\Http\Controllers;

use App\Models\Article;
use App\Support\PublicUrl;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\RedirectResponse;

class LegacyArmenianController extends Controller
{
    public function __invoke(string $slug, PublicUrl $urls): RedirectResponse
    {
        $article = Article::query()->published()
            ->whereHas('translations', fn (Builder $query) => $query->where('locale', 'hy')->where('slug', $slug))
            ->with('translations')->firstOrFail();
        $english = $article->translation('en');

        return redirect($english && $urls->isPublicTranslation($english)
            ? $urls->article($english)
            : $urls->route('news.index', ['locale' => 'en']), 301);
    }
}
