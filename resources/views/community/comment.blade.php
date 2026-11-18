<div class="community-comment-heading">
    @if($comment->user?->avatarUrl())<img class="community-avatar" src="{{ $comment->user->avatarUrl() }}" alt="" width="32" height="32" loading="lazy">@else<span class="community-avatar community-avatar-empty" aria-hidden="true"></span>@endif
    <strong>{{ $comment->user?->name ?? __('community.reader') }}</strong>
    <time datetime="{{ $comment->created_at->toIso8601String() }}">{{ $comment->created_at->locale($locale)->isoFormat('lll') }}</time>
    @if($comment->edited_at)<span class="community-note">{{ __('community.edited') }}</span>@endif
</div>
<p class="community-comment-body">{{ $comment->body }}</p>
<div class="community-comment-actions">
    @auth
        <button type="button" wire:click="like({{ $comment->id }})" aria-pressed="{{ $comment->liked ? 'true' : 'false' }}" wire:loading.attr="disabled" wire:target="like">{{ __('community.like') }} ({{ $comment->likes_count }})</button>
        @if(!$comment->parent_id)<button type="button" wire:click="reply({{ $comment->id }})">{{ __('community.reply') }}</button>@endif
        @can('update', $comment)<button type="button" wire:click="edit({{ $comment->id }})">{{ __('community.edit') }}</button>@endcan
        @can('delete', $comment)<button type="button" wire:click="remove({{ $comment->id }})" wire:confirm="{{ __('community.delete_confirm') }}">{{ __('community.delete') }}</button>@endcan
        <button type="button" wire:click="showReport({{ $comment->id }})">{{ __('community.report') }}</button>
    @else
        <span>{{ __('community.like') }} ({{ $comment->likes_count }})</span>
    @endauth
</div>

