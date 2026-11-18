<?php

namespace App\Http\Controllers;

use App\Models\NewsletterCampaign;
use App\Models\NewsletterSubscriber;
use App\Support\CommunityLimits;
use App\Support\NewsDigests;
use App\Support\NewsFeed;
use App\Support\NewsletterSubscriptions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\URL;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class NewsletterController extends Controller
{
    public function form(Request $request, string $locale, NewsletterSubscriptions $subscriptions): View
    {
        return view('community.newsletter', ['privatePage' => true, 'pageTitle' => __('engagement.newsletter'),
            'categories' => app(NewsFeed::class)->categories($locale),
            'subscriber' => $request->user() ? $subscriptions->owned($request->user()) : null]);
    }

    public function subscribe(Request $request, string $locale, NewsletterSubscriptions $subscriptions): RedirectResponse
    {
        CommunityLimits::hit('newsletter-ip', (string) $request->ip(), 5, 60);
        $email = is_string($request->input('email')) ? $request->input('email') : 'invalid';
        CommunityLimits::hit('newsletter-email', $subscriptions->emailHash($email), 3, 3600);
        $subscriptions->request($request->all() + ['locale' => $locale], $request->user());

        return redirect()->route('newsletter.form', ['locale' => $locale])->with('community_status', __('engagement.subscription_requested'));
    }

    public function confirm(Request $request, string $locale, NewsletterSubscriber $subscriber, string $token, NewsletterSubscriptions $subscriptions): Response
    {
        $valid = URL::hasValidSignature($request, false) && $subscriptions->validConfirmation($subscriber, $token);
        $done = $valid && $request->isMethod('post') && $subscriptions->confirm($subscriber->public_id, $token);

        return response()->view('community.newsletter-link', ['privatePage' => true, 'pageTitle' => __('engagement.confirm_subscription'),
            'categories' => app(NewsFeed::class)->categories($locale), 'valid' => $valid, 'done' => $done, 'operation' => 'confirm',
            'formAction' => $request->getRequestUri()], $valid ? 200 : 410);
    }

    public function unsubscribe(Request $request, string $locale, NewsletterSubscriber $subscriber, string $token, NewsletterSubscriptions $subscriptions): Response
    {
        $valid = URL::hasValidSignature($request, false) && $subscriptions->validUnsubscribe($subscriber, $token);
        $done = $valid && $request->isMethod('post');
        if ($done) {
            $subscriptions->unsubscribe($subscriber);
        }

        return response()->view('community.newsletter-link', ['privatePage' => true, 'pageTitle' => __('engagement.unsubscribe'),
            'categories' => app(NewsFeed::class)->categories($locale), 'valid' => $valid, 'done' => $done, 'operation' => 'unsubscribe',
            'formAction' => $request->getRequestUri()], $valid ? 200 : 410);
    }

    public function preferences(Request $request, string $locale, NewsletterSubscriptions $subscriptions): RedirectResponse
    {
        $subscriptions->preferences($request->user(), $request->all());

        return redirect()->route('account.newsletter', ['locale' => $locale])->with('community_status', __('engagement.updated'));
    }

    public function stop(Request $request, string $locale, NewsletterSubscriptions $subscriptions): RedirectResponse
    {
        $subscriber = $subscriptions->owned($request->user());
        abort_unless($subscriber, 404);
        $subscriptions->unsubscribe($subscriber, $request->user());

        return redirect()->route('account.newsletter', ['locale' => $locale])->with('community_status', __('engagement.unsubscribed'));
    }

    public function preview(NewsletterCampaign $campaign, NewsDigests $digests): View
    {
        App::setLocale($campaign->locale);

        return view('emails.engagement', ['messageData' => ['kind' => 'digest', 'articles' => $digests->articles($campaign),
            'frequency' => $campaign->frequency, 'unsubscribe_url' => null, 'is_preview' => true]]);
    }
}
