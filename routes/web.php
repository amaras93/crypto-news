<?php

use App\Http\Controllers\AdsTxtController;
use App\Http\Controllers\AdvertisingPreviewController;
use App\Http\Controllers\ArticleController;
use App\Http\Controllers\EditorialPreviewController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LegacyArmenianController;
use App\Http\Controllers\NewsController;
use App\Http\Controllers\NewsletterController;
use App\Http\Controllers\PriceAlertController;
use App\Http\Controllers\ReaderAccountController;
use App\Http\Controllers\ReaderAuthController;
use App\Http\Controllers\ReaderNotificationController;
use App\Http\Controllers\SavedArticleController;
use App\Http\Controllers\SitemapController;
use App\Http\Controllers\WatchlistController;
use App\Http\Middleware\PrivateCommunityPage;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/en');
Route::get('/ads.txt', AdsTxtController::class)->name('advertising.ads-txt');
Route::get('/admin/advertising/campaigns/{campaign}/preview/{locale}', AdvertisingPreviewController::class)
    ->whereIn('locale', array_keys(config('news.locales')))
    ->middleware(['auth', 'can:view,campaign', PrivateCommunityPage::class])->name('advertising.preview');
Route::get('/admin/engagement/campaigns/{campaign}/preview', [NewsletterController::class, 'preview'])
    ->middleware(['auth', 'can:view,campaign', PrivateCommunityPage::class])->name('engagement.preview');
Route::redirect('/hy', '/en', 301);
Route::redirect('/hy/news', '/en/news', 301);
Route::get('/hy/news/{slug}', LegacyArmenianController::class)->name('legacy.hy.article');
Route::get('/sitemap.xml', [SitemapController::class, 'index'])->name('sitemap.index');
Route::get('/sitemap-pages.xml', [SitemapController::class, 'pages'])->name('sitemap.pages');
Route::get('/sitemap-articles-{start}.xml', [SitemapController::class, 'articles'])->whereNumber('start')->name('sitemap.articles');
Route::get('/robots.txt', [SitemapController::class, 'robots'])->name('robots');
Route::get('/editorial/preview/{article}/{locale}', EditorialPreviewController::class)
    ->whereIn('locale', array_keys(config('news.locales')))
    ->middleware(['auth', 'locale', 'can:preview,article'])
    ->name('editorial.preview');

Route::prefix('{locale}')
    ->whereIn('locale', array_keys(config('news.locales')))
    ->middleware(['locale', PrivateCommunityPage::class])
    ->group(function (): void {
        Route::middleware('guest')->group(function (): void {
            Route::get('/register', [ReaderAuthController::class, 'form'])->name('register');
            Route::post('/register', [ReaderAuthController::class, 'register'])->name('reader.register');
            Route::get('/login', [ReaderAuthController::class, 'form'])->name('login');
            Route::post('/login', [ReaderAuthController::class, 'login'])->name('reader.login');
            Route::get('/forgot-password', [ReaderAuthController::class, 'form'])->name('password.request');
            Route::post('/forgot-password', [ReaderAuthController::class, 'forgot'])->name('password.email');
            Route::get('/reset-password/{token}', [ReaderAuthController::class, 'form'])->name('password.reset');
            Route::post('/reset-password', [ReaderAuthController::class, 'reset'])->name('password.update');
        });
        Route::middleware(['auth', 'auth.session'])->group(function (): void {
            Route::post('/logout', [ReaderAuthController::class, 'logout'])->name('reader.logout');
            Route::get('/verify-email', [ReaderAuthController::class, 'form'])->name('verification.notice');
            Route::get('/verify-email/{id}/{hash}', [ReaderAuthController::class, 'verify'])->middleware(['signed:relative', 'throttle:6,1'])->name('verification.verify');
            Route::post('/verify-email', [ReaderAuthController::class, 'resend'])->name('verification.send');
            Route::get('/account', [ReaderAccountController::class, 'show'])->name('account');
            Route::patch('/account', [ReaderAccountController::class, 'update'])->name('account.update');
            Route::put('/account/password', [ReaderAccountController::class, 'password'])->name('account.password');
            Route::patch('/account/comments/{comment}', [ReaderAccountController::class, 'editComment'])->name('account.comments.update');
            Route::delete('/account/comments/{comment}', [ReaderAccountController::class, 'deleteComment'])->name('account.comments.destroy');
            Route::get('/account/watchlist', [WatchlistController::class, 'index'])->name('account.watchlist');
            Route::post('/account/watchlist', [WatchlistController::class, 'store'])->name('account.watchlist.store');
            Route::put('/account/watchlist/order', [WatchlistController::class, 'reorder'])->name('account.watchlist.order');
            Route::patch('/account/watchlist/{item}/move', [WatchlistController::class, 'move'])->name('account.watchlist.move');
            Route::delete('/account/watchlist/{item}', [WatchlistController::class, 'destroy'])->name('account.watchlist.destroy');
            Route::get('/account/saved', [SavedArticleController::class, 'index'])->name('account.saved');
            Route::post('/account/saved/{article}', [SavedArticleController::class, 'store'])->whereNumber('article')->name('account.saved.store');
            Route::delete('/account/saved/{saved}', [SavedArticleController::class, 'destroy'])->name('account.saved.destroy');
            Route::get('/account/alerts', [PriceAlertController::class, 'index'])->name('account.alerts');
            Route::post('/account/alerts', [PriceAlertController::class, 'store'])->name('account.alerts.store');
            Route::patch('/account/alerts/{alert}', [PriceAlertController::class, 'update'])->name('account.alerts.update');
            Route::patch('/account/alerts/{alert}/pause', [PriceAlertController::class, 'pause'])->name('account.alerts.pause');
            Route::delete('/account/alerts/{alert}', [PriceAlertController::class, 'destroy'])->name('account.alerts.destroy');
            Route::get('/account/notifications', [ReaderNotificationController::class, 'index'])->name('account.notifications');
            Route::patch('/account/notifications/{notification}', [ReaderNotificationController::class, 'read'])->whereUuid('notification')->name('account.notifications.read');
            Route::put('/account/notification-preferences', [ReaderNotificationController::class, 'preferences'])->name('account.notifications.preferences');
            Route::get('/account/newsletter', [NewsletterController::class, 'form'])->name('account.newsletter');
            Route::patch('/account/newsletter', [NewsletterController::class, 'preferences'])->name('account.newsletter.preferences');
            Route::delete('/account/newsletter', [NewsletterController::class, 'stop'])->name('account.newsletter.stop');
        });
        Route::get('/newsletter', [NewsletterController::class, 'form'])->name('newsletter.form');
        Route::post('/newsletter', [NewsletterController::class, 'subscribe'])->name('newsletter.subscribe');
        Route::get('/newsletter/confirm/{subscriber:public_id}/{token}', [NewsletterController::class, 'confirm'])->name('newsletter.confirm');
        Route::post('/newsletter/confirm/{subscriber:public_id}/{token}', [NewsletterController::class, 'confirm'])->name('newsletter.confirm.submit');
        Route::get('/newsletter/unsubscribe/{subscriber:public_id}/{token}', [NewsletterController::class, 'unsubscribe'])->name('newsletter.unsubscribe');
        Route::post('/newsletter/unsubscribe/{subscriber:public_id}/{token}', [NewsletterController::class, 'unsubscribe'])->name('newsletter.unsubscribe.submit');
        Route::get('/', HomeController::class)->name('home');
        Route::get('/news', NewsController::class)->name('news.index');
        Route::get('/news/{slug}', ArticleController::class)->name('news.show');
    });
