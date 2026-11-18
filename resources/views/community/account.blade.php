@extends('layouts.app')

@section('content')
<section class="community-page community-account" aria-labelledby="community-heading">
    <div class="community-section-head"><h1 id="community-heading">{{ __('community.account') }}</h1>
        <form method="post" action="{{ route('reader.logout', ['locale' => app()->getLocale()]) }}">@csrf<button type="submit">{{ __('community.logout') }}</button></form>
    </div>
    <x-account-navigation/><x-community-feedback/>
    @if($user->community_suspended_at)<p class="community-notice">{{ __('community.suspended') }}</p>@endif
    @unless($user->hasVerifiedEmail())
        <p class="community-notice">{{ __('community.verify_notice') }} <a href="{{ route('verification.notice', ['locale' => app()->getLocale()]) }}">{{ __('community.verify_email') }}</a></p>
    @endunless
    <div class="community-account-grid">
        <section aria-labelledby="profile-heading"><h2 id="profile-heading">{{ __('community.profile') }}</h2>
            <form method="post" action="{{ route('account.update', ['locale' => app()->getLocale()]) }}" enctype="multipart/form-data">
                @csrf @method('patch')
                @if($user->avatarUrl())<img class="community-avatar" src="{{ $user->avatarUrl() }}" alt="" width="48" height="48">@endif
                <label for="name">{{ __('community.name') }}</label><input id="name" name="name" value="{{ old('name', $user->name) }}" maxlength="80" autocomplete="name" required>
                <label for="email">{{ __('community.email') }}</label><input id="email" name="email" type="email" value="{{ old('email', $user->email) }}" maxlength="255" autocomplete="email" required>
                <p class="community-note">{{ __('community.email_help') }}</p>
                <label for="profile-password">{{ __('community.current_password') }}</label><input id="profile-password" name="current_password" type="password" autocomplete="current-password">
                <label for="avatar">{{ __('community.avatar') }}</label><input id="avatar" name="avatar" type="file" accept="image/jpeg,image/png,image/webp"><p class="community-note">{{ __('community.avatar_help') }}</p>
                <label for="preferred_locale">{{ __('community.language') }}</label>
                <select id="preferred_locale" name="preferred_locale">@foreach(config('news.locales') as $language => $name)<option value="{{ $language }}" @selected($user->preferredLocale() === $language)>{{ $name }}</option>@endforeach</select>
                <input name="reply_notifications" type="hidden" value="0">
                <label class="community-check"><input name="reply_notifications" type="checkbox" value="1" @checked($user->reply_notifications)> {{ __('community.reply_notifications') }}</label>
                <button type="submit">{{ __('community.save') }}</button>
            </form>
        </section>
        <section aria-labelledby="password-heading"><h2 id="password-heading">{{ __('community.change_password') }}</h2>
            <form method="post" action="{{ route('account.password', ['locale' => app()->getLocale()]) }}">
                @csrf @method('put')
                <label for="current_password">{{ __('community.current_password') }}</label><input id="current_password" name="current_password" type="password" autocomplete="current-password" required>
                <label for="password">{{ __('community.new_password') }}</label><input id="password" name="password" type="password" minlength="12" maxlength="64" autocomplete="new-password" required>
                <p class="community-note">{{ __('community.password_help') }}</p>
                <label for="password_confirmation">{{ __('community.confirm_password') }}</label><input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required>
                <button type="submit">{{ __('community.change_password') }}</button>
            </form>
        </section>
    </div>
    <section class="community-history" aria-labelledby="history-heading"><h2 id="history-heading">{{ __('community.activity') }}</h2>
        <nav class="community-links" aria-label="{{ __('community.activity') }}">
            <a href="{{ route('account', ['locale' => app()->getLocale(), 'tab' => 'comments']) }}" @if($tab === 'comments') aria-current="page" @endif>{{ __('community.my_comments') }}</a>
            <a href="{{ route('account', ['locale' => app()->getLocale(), 'tab' => 'liked']) }}" @if($tab === 'liked') aria-current="page" @endif>{{ __('community.my_likes') }}</a>
        </nav>
        @forelse($comments as $comment)
            @php($translation = $comment->article?->translation($comment->locale))
            @php($publicArticle = $comment->article && $comment->article->status === \App\ArticleStatus::Published && $comment->article->published_at?->isPast() && $translation && app(\App\Support\PublicUrl::class)->isPublicTranslation($translation))
            @if($tab === 'liked' && ! $publicArticle) @continue @endif
            <article class="community-history-item">
                @if($publicArticle)<a href="{{ app(\App\Support\PublicUrl::class)->article($translation) }}#comments">{{ $translation->title }}</a>@endif
                <p class="community-note"><span lang="en">{{ strtoupper($comment->locale) }}</span> · {{ __('community.status_'.$comment->status->value) }} · {{ $comment->created_at->locale(app()->getLocale())->isoFormat('lll') }}</p>
                <p class="community-comment-body">{{ $comment->body }}</p>
                @if($tab === 'comments')
                    @if($publicArticle && auth()->user()->can('update', $comment))
                        <details><summary>{{ __('community.edit') }}</summary>
                            <form method="post" action="{{ route('account.comments.update', ['locale' => app()->getLocale(), 'comment' => $comment]) }}">@csrf @method('patch')
                                <label for="comment-{{ $comment->id }}">{{ __('community.comment_body') }}</label><textarea id="comment-{{ $comment->id }}" name="body" maxlength="2000" required>{{ $comment->body }}</textarea>
                                <button type="submit">{{ __('community.save') }}</button>
                            </form>
                        </details>
                    @endif
                    @can('delete', $comment)
                        <form method="post" action="{{ route('account.comments.destroy', ['locale' => app()->getLocale(), 'comment' => $comment]) }}">@csrf @method('delete')<button type="submit">{{ __('community.delete') }}</button></form>
                    @endcan
                @endif
            </article>
        @empty
            <p>{{ __('community.no_activity') }}</p>
        @endforelse
        <x-pagination :paginator="$comments"/>
    </section>
</section>
@endsection

