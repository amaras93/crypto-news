<?php

namespace App\Livewire;

use App\CommentStatus;
use App\Models\Comment;
use App\Models\User;
use App\Support\CommunityComments;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

class ArticleComments extends Component
{
    use WithPagination;

    #[Locked]
    public int $articleId;

    #[Locked]
    public string $locale;

    public string $sort = 'newest';

    public string $body = '';

    public string $website = '';

    public ?int $replyTo = null;

    public ?int $editingId = null;

    public ?int $reportingId = null;

    public string $reportReason = 'spam';

    public ?int $expandedThread = null;

    public string $notice = '';

    public function mount(int $articleId, string $locale): void
    {
        app(CommunityComments::class)->publicArticle($articleId, $locale);
        $this->articleId = $articleId;
        $this->locale = $locale;
    }

    public function hydrate(): void
    {
        abort_unless(array_key_exists($this->locale, config('news.locales')), 404);
        app()->setLocale($this->locale);
    }

    public function updatedSort(): void
    {
        $this->resetPage('commentsPage');
        $this->expandedThread = null;
    }

    public function submit(): void
    {
        $this->resetValidation();
        $service = app(CommunityComments::class);
        if ($this->editingId) {
            $service->edit($this->actor(), $this->editingId, $this->articleId, $this->locale, ['body' => $this->body, 'website' => $this->website]);
        } else {
            $service->create($this->actor(), $this->articleId, $this->locale, ['body' => $this->body, 'website' => $this->website], $this->replyTo);
        }
        $this->reset('body', 'website', 'replyTo', 'editingId');
        $this->notice = __('community.submitted');
    }

    public function reply(int $commentId): void
    {
        $this->actor();
        $comment = $this->comment($commentId);
        abort_unless($comment->parent_id === null && $comment->status === CommentStatus::Approved, 404);
        $this->reset('body', 'editingId');
        $this->replyTo = $comment->id;
    }

    public function edit(int $commentId): void
    {
        $comment = $this->comment($commentId);
        Gate::forUser($this->actor()->fresh())->authorize('update', $comment);
        $this->editingId = $comment->id;
        $this->replyTo = null;
        $this->body = $comment->body;
    }

    public function cancel(): void
    {
        $this->reset('body', 'replyTo', 'editingId', 'reportingId');
        $this->resetValidation();
    }

    public function remove(int $commentId): void
    {
        app(CommunityComments::class)->remove($this->actor(), $commentId, $this->articleId, $this->locale);
        $this->notice = __('community.comment_removed');
    }

    public function like(int $commentId): void
    {
        app(CommunityComments::class)->toggleLike($this->actor(), $commentId, $this->articleId, $this->locale);
    }

    public function showReport(int $commentId): void
    {
        $this->actor();
        $comment = $this->comment($commentId);
        abort_unless($comment->status === CommentStatus::Approved, 404);
        $this->reportingId = $comment->id;
    }

    public function report(): void
    {
        $this->resetValidation();
        abort_unless($this->reportingId !== null, 422);
        app(CommunityComments::class)->report($this->actor(), $this->reportingId, $this->articleId, $this->locale, $this->reportReason);
        $this->reportingId = null;
        $this->notice = __('community.report_sent');
    }

    public function expand(int $commentId): void
    {
        $comment = $this->comment($commentId, true);
        abort_unless($comment->parent_id === null, 404);
        $this->expandedThread = $comment->id;
        $this->resetPage('repliesPage');
    }

    public function render(): View
    {
        app(CommunityComments::class)->publicArticle($this->articleId, $this->locale);
        $userId = auth()->id();
        $loadComments = fn (Builder|Relation $query): Builder|Relation => $query->approved()->with('user:id,name,avatar_path')
            ->withCount('likes')->withExists(['likes as liked' => fn (Builder $likes): Builder => $likes->where('user_id', $userId ?? 0)]);
        $query = Comment::withTrashed()->where('article_id', $this->articleId)->where('locale', $this->locale)->whereNull('parent_id')
            ->where(fn (Builder $visible): Builder => $visible
                ->where(fn (Builder $parent): Builder => $parent->whereNull('deleted_at')->approved())
                ->orWhereHas('replies', fn (Builder $replies): Builder => $replies->approved()))
            ->with('user:id,name,avatar_path')->withCount('likes')
            ->withCount(['replies' => fn (Builder $replies): Builder => $replies->approved()])
            ->withExists(['likes as liked' => fn (Builder $likes): Builder => $likes->where('user_id', $userId ?? 0)])
            ->with(['replies' => fn (Builder|Relation $replies): Builder|Relation => $loadComments($replies)->oldest('id')->limit(3)]);
        match ($this->sort) {
            'oldest' => $query->oldest('id'),
            'liked' => $query->orderByDesc('likes_count')->latest('id'),
            default => $query->latest('id'),
        };
        $threads = $query->paginate(config('community.comments_per_page'), ['*'], 'commentsPage');
        $expandedReplies = null;
        if ($this->expandedThread && $threads->contains('id', $this->expandedThread)) {
            $expandedReplies = $loadComments(Comment::query()->where('parent_id', $this->expandedThread)
                ->where('article_id', $this->articleId)->where('locale', $this->locale))
                ->oldest('id')->paginate(config('community.replies_per_page'), ['*'], 'repliesPage');
        }
        $count = Comment::query()->where('article_id', $this->articleId)->where('locale', $this->locale)->approved()->count();

        return view('livewire.article-comments', compact('threads', 'expandedReplies', 'count'));
    }

    private function actor(): User
    {
        abort_unless(auth()->user(), 403);

        return auth()->user();
    }

    private function comment(int $commentId, bool $withTrashed = false): Comment
    {
        app(CommunityComments::class)->publicArticle($this->articleId, $this->locale);

        return Comment::query()->when($withTrashed, fn (Builder $query): Builder => $query->withTrashed())
            ->where('article_id', $this->articleId)->where('locale', $this->locale)->findOrFail($commentId);
    }
}
