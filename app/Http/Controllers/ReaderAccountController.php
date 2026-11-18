<?php

namespace App\Http\Controllers;

use App\Models\Comment;
use App\Support\CommunityComments;
use App\Support\CommunityLimits;
use App\Support\NewsFeed;
use App\Support\ReaderAccounts;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ReaderAccountController extends Controller
{
    public function show(Request $request, string $locale): View
    {
        $tab = $request->query('tab') === 'liked' ? 'liked' : 'comments';
        $comments = Comment::query()->when($tab === 'comments',
            fn (Builder $query): Builder => $query->where('user_id', $request->user()->id),
            fn (Builder $query): Builder => $query->approved()->whereHas('likes', fn (Builder $likes): Builder => $likes->where('user_id', $request->user()->id))
                ->whereIn('locale', array_keys(config('news.locales')))
                ->whereHas('article', fn (Builder $articles): Builder => $articles->published())
                ->whereHas('article.translations', fn (Builder $translations): Builder => $translations->whereColumn('article_translations.locale', 'comments.locale')),
        )->with(['article.translations:id,article_id,locale,title,slug'])->latest('id')->paginate(10)->withQueryString();

        return view('community.account', [
            'privatePage' => true, 'pageTitle' => __('community.account'),
            'categories' => app(NewsFeed::class)->categories($locale), 'user' => $request->user(), 'comments' => $comments, 'tab' => $tab,
        ]);
    }

    public function update(Request $request, string $locale, ReaderAccounts $accounts): RedirectResponse
    {
        CommunityLimits::hit('profile', (string) $request->user()->id, 10, 60);
        $accounts->update($request->user(), $request->all(), $request->file('avatar'));
        $request->session()->regenerate();

        return redirect()->route('account', ['locale' => $locale])->with('community_status', __('community.profile_saved'));
    }

    public function password(Request $request, string $locale, ReaderAccounts $accounts): RedirectResponse
    {
        CommunityLimits::hit('password-change', (string) $request->user()->id, 5, 900);
        $accounts->changePassword($request->user(), $request->all());
        $request->session()->regenerate();
        $request->session()->put('password_hash_web', $request->user()->fresh()->password);

        return redirect()->route('account', ['locale' => $locale])->with('community_status', __('community.password_saved'));
    }

    public function editComment(Request $request, string $locale, Comment $comment, CommunityComments $comments): RedirectResponse
    {
        $comments->edit($request->user(), $comment->id, $comment->article_id, $comment->locale, $request->all());

        return redirect()->route('account', ['locale' => $locale])->with('community_status', __('community.submitted'));
    }

    public function deleteComment(Request $request, string $locale, Comment $comment, CommunityComments $comments): RedirectResponse
    {
        $comments->remove($request->user(), $comment->id, $comment->article_id, $comment->locale);

        return redirect()->route('account', ['locale' => $locale])->with('community_status', __('community.comment_removed'));
    }
}
