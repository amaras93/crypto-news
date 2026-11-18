<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Rules\SafePasswordBytes;
use App\Support\CommunityLimits;
use App\Support\NewsFeed;
use App\Support\ReaderAccounts;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ReaderAuthController extends Controller
{
    public function form(Request $request, string $locale, ?string $token = null): View
    {
        return view('community.auth', [
            'privatePage' => true, 'categories' => app(NewsFeed::class)->categories($locale),
            'pageTitle' => __('community.'.match ($request->route()->getName()) {
                'register' => 'register', 'password.request' => 'forgot_password',
                'password.reset' => 'reset_password', 'verification.notice' => 'verify_email', default => 'login',
            }), 'token' => $token,
        ]);
    }

    public function register(Request $request, string $locale, ReaderAccounts $accounts): RedirectResponse
    {
        CommunityLimits::hit('register', (string) $request->ip(), 3, 3600);
        $user = $accounts->register($request->all(), $locale);
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('verification.notice', ['locale' => $locale]);
    }

    public function login(Request $request, string $locale): RedirectResponse
    {
        $email = is_string($request->input('email')) ? mb_strtolower(trim($request->input('email'))) : '';
        CommunityLimits::hit('login-ip', (string) $request->ip(), 20, 60);
        CommunityLimits::hit('login', $email.'|'.$request->ip(), 5, 60);
        $values = $request->validate(['email' => ['required', 'email', 'max:255'], 'password' => ['bail', 'required', 'string', 'max:256', new SafePasswordBytes], 'remember' => ['nullable', 'boolean']]);
        if (! Auth::attempt(['email' => $email, 'password' => $values['password']], $request->boolean('remember'))) {
            throw ValidationException::withMessages(['email' => __('community.login_failed')]);
        }
        $request->session()->regenerate();
        $request->session()->forget('url.intended');

        return redirect()->route('account', ['locale' => $locale]);
    }

    public function logout(Request $request, string $locale): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home', ['locale' => $locale]);
    }

    public function forgot(Request $request, string $locale): RedirectResponse
    {
        CommunityLimits::hit('forgot', (string) $request->ip(), 3, 900);
        $values = $request->validate(['email' => ['required', 'email', 'max:255']]);
        Password::sendResetLink(['email' => mb_strtolower(trim($values['email']))]);

        return back()->with('community_status', __('community.reset_sent'));
    }

    public function reset(Request $request, string $locale): RedirectResponse
    {
        CommunityLimits::hit('reset', (string) $request->ip(), 5, 900);
        $values = $request->validate(['token' => ['required', 'string'], 'email' => ['required', 'email'], 'password' => ReaderAccounts::passwordRules()]);
        $values['email'] = mb_strtolower(trim($values['email']));
        $status = Password::reset($values, function (User $user, string $password): void {
            DB::transaction(function () use ($user, $password): void {
                $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
                DB::table('sessions')->where('user_id', $user->id)->delete();
            });
            event(new PasswordReset($user));
        });
        if ($status !== Password::PasswordReset) {
            throw ValidationException::withMessages(['email' => __('community.invalid_reset')]);
        }

        return redirect()->route('login', ['locale' => $locale])->with('community_status', __('community.password_saved'));
    }

    public function verify(EmailVerificationRequest $request, string $locale): RedirectResponse
    {
        $request->fulfill();

        return redirect()->route('account', ['locale' => $locale])->with('community_status', __('community.verified'));
    }

    public function resend(Request $request, string $locale): RedirectResponse
    {
        CommunityLimits::hit('verification', (string) $request->user()->id, 3, 900);
        if (! $request->user()->hasVerifiedEmail()) {
            $request->user()->sendEmailVerificationNotification();
        }

        return back()->with('community_status', __('community.verification_sent'));
    }
}
