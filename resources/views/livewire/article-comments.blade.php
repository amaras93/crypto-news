<section class="community-discussion" id="comments" aria-labelledby="comments-heading">
    <div class="community-section-head">
        <h2 id="comments-heading">{{ __('community.discussion') }} <span class="community-note">({{ $count }})</span></h2>
        <label class="community-sort">{{ __('community.sort') }}<select wire:model.live="sort">
            <option value="newest">{{ __('community.newest') }}</option><option value="oldest">{{ __('community.oldest') }}</option><option value="liked">{{ __('community.most_liked') }}</option>
        </select></label>
    </div>
    <p class="community-note">{{ __('community.comment_guidance') }}</p>
    @if($notice)<p class="community-notice" role="status">{{ $notice }}</p>@endif
    @if($errors->any())<div class="community-errors" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    @auth
        @if(auth()->user()->community_suspended_at)
            <p>{{ __('community.suspended') }}</p>
        @elseif(!auth()->user()->hasVerifiedEmail())
            <p><a href="{{ route('verification.notice', ['locale' => $locale]) }}">{{ __('community.verify_to_comment') }}</a></p>
        @else
            <form wire:submit="submit" class="community-comment-form">
                @if($replyTo)<p>{{ __('community.replying') }} <button type="button" wire:click="cancel">{{ __('community.cancel') }}</button></p>@endif
                @if($editingId)<p>{{ __('community.editing') }} <button type="button" wire:click="cancel">{{ __('community.cancel') }}</button></p>@endif
                <label for="comment-body">{{ __('community.comment_body') }}</label>
                <textarea id="comment-body" wire:model="body" maxlength="2000" rows="3" required></textarea>
                <div class="community-honeypot" aria-hidden="true"><label for="comment-website">{{ __('community.website') }}</label><input id="comment-website" wire:model="website" tabindex="-1" autocomplete="off"></div>
                <button type="submit" wire:loading.attr="disabled" wire:target="submit">{{ $editingId ? __('community.save') : __('community.submit_comment') }}</button>
            </form>
        @endif
    @else
        <p><a href="{{ route('login', ['locale' => $locale]) }}">{{ __('community.login_to_comment') }}</a> · <a href="{{ route('register', ['locale' => $locale]) }}">{{ __('community.register') }}</a></p>
    @endauth
    @if($reportingId)
        <form wire:submit="report" class="community-report-form">
            <label for="report-reason">{{ __('community.report_reason') }}</label>
            <select id="report-reason" wire:model="reportReason">@foreach(\App\Models\CommentReport::REASONS as $reason)<option value="{{ $reason }}">{{ __('community.reason_'.$reason) }}</option>@endforeach</select>
            <button type="submit" wire:loading.attr="disabled">{{ __('community.send_report') }}</button><button type="button" wire:click="cancel">{{ __('community.cancel') }}</button>
        </form>
    @endif
    <div class="community-threads">
        @forelse($threads as $thread)
            <article class="community-thread" wire:key="thread-{{ $thread->id }}">
                @if(!$thread->trashed() && $thread->status === \App\CommentStatus::Approved)
                    @include('community.comment', ['comment' => $thread])
                @else
                    <p class="community-note">{{ __('community.removed_parent') }}</p>
                @endif
                @if($thread->replies_count)
                    <div class="community-replies">
                        @foreach($expandedThread === $thread->id && $expandedReplies ? $expandedReplies : $thread->replies as $reply)
                            <article class="community-reply" wire:key="reply-{{ $reply->id }}">@include('community.comment', ['comment' => $reply])</article>
                        @endforeach
                        @if($expandedThread === $thread->id && $expandedReplies)
                            {{ $expandedReplies->links('community.pagination') }}
                        @elseif($thread->replies_count > 3)
                            <button type="button" wire:click="expand({{ $thread->id }})">{{ __('community.more_replies', ['count' => $thread->replies_count]) }}</button>
                        @endif
                    </div>
                @endif
            </article>
        @empty
            <p>{{ __('community.no_comments') }}</p>
        @endforelse
    </div>
    {{ $threads->links('community.pagination') }}
    <div wire:loading.delay role="status" class="community-note">{{ __('community.loading') }}</div>
</section>

