<?php

use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\Admin\PasswordResetController;
use App\Http\Controllers\Api\ChannelController;
use App\Http\Controllers\Api\DocsController;
use App\Http\Controllers\Api\MemberController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\PushController;
use App\Http\Controllers\Api\SessionController;
use App\Models\Channel;
use App\Models\Setting;
use App\Models\UploadView;
use App\Support\SitePages;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Support\Facades\Route;

// Public site, in every supported language (config/app.php site_locales).
// English lives at the bare URLs (also the x-default for search engines),
// every other language under /<code>/… — each page links its counterparts
// via hreflang, so all of them can rank independently.
foreach (array_keys(config('app.site_locales')) as $locale) {
    $prefix = $locale === 'en' ? '' : '/'.$locale;
    $suffix = $locale === 'en' ? '' : '.'.$locale;
    $localized = fn (string $view) => function () use ($locale, $view) {
        app()->setLocale($locale);

        return view($view, $view === 'landing'
            ? ['openRegistrations' => Setting::registrationsOpen()] : []);
    };

    Route::get($prefix.'/', $localized('landing'))->name('landing'.$suffix);
    Route::get($prefix.'/about', $localized('pages.about'))->name('about'.$suffix);
    Route::get($prefix.'/imprint', $localized('pages.imprint'))->name('imprint'.$suffix);
    Route::get($prefix.'/contact', $localized('pages.contact'))->name('contact'.$suffix);
    Route::get($prefix.'/faq', $localized('pages.faq'))->name('faq'.$suffix);
    Route::get($prefix.'/privacy', $localized('pages.privacy'))->name('privacy'.$suffix);
    Route::get($prefix.'/terms', $localized('pages.terms'))->name('terms'.$suffix);
}

// Sitemap for the static pages: every URL in every language, each entry
// cross-linking its translations so search engines index all of them. URLs
// come from APP_URL via SitePages (never the request host).
Route::get('/sitemap.xml', function () {
    $locales = SitePages::locales();

    $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
        .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">'."\n";
    foreach (SitePages::PAGES as $page) {
        $alternates = '';
        foreach ($locales as $locale) {
            $alternates .= '    <xhtml:link rel="alternate" hreflang="'.$locale.'" href="'.SitePages::url($locale, $page)."\"/>\n";
        }
        $alternates .= '    <xhtml:link rel="alternate" hreflang="x-default" href="'.SitePages::url('en', $page)."\"/>\n";
        foreach ($locales as $locale) {
            $xml .= "  <url>\n    <loc>".SitePages::url($locale, $page)."</loc>\n"
                .'    <lastmod>'.SitePages::lastmod($locale, $page)."</lastmod>\n"
                .$alternates."  </url>\n";
        }
    }
    $xml .= '</urlset>';

    return response($xml, 200, ['Content-Type' => 'application/xml']);
})->name('sitemap');

// llms.txt (llmstxt.org): a Markdown map of the public site for AI crawlers
// and assistants, built from the same page registry as the sitemap.
Route::get('/llms.txt', function () {
    app()->setLocale('en');
    $line = fn (string $locale, string $page) => '- ['.__('site.'.SitePages::key($page).'.'.($page === '' ? 'title_default' : 'title'))
        .']('.SitePages::url($locale, $page).'): '.__('site.'.SitePages::key($page).'.description')."\n";

    $md = "# Krotze\n\n> ".__('site.meta.description')."\n\n"
        .'Krotze is an anonymous, self-destructing group-chat web app (PWA). There are no accounts, '
        .'e-mail addresses or phone numbers: an identity is a username plus a secret key that stays on the device. '
        ."Channels delete themselves after a period of inactivity; private 1:1 text messages are end-to-end encrypted.\n\n"
        ."## Pages\n\n";
    foreach (SitePages::PAGES as $page) {
        $md .= $line('en', $page);
    }
    $md .= "\n## Developers\n\n"
        .'- [API reference]('.SitePages::absolute('/api/docs').'): browsable documentation of the HTTP API for custom clients'."\n"
        .'- [OpenAPI specification]('.SitePages::absolute('/api/openapi.json').'): machine-readable description of every endpoint'."\n"
        ."\n## Other languages\n\n";
    foreach (config('app.site_locales') as $code => $meta) {
        if ($code !== 'en') {
            $md .= '- ['.$meta['name'].']('.SitePages::url($code, '').'): '.__('site.meta.description', [], $code)."\n";
        }
    }
    $md .= "\n## Optional\n\n"
        .'- [Sitemap]('.SitePages::absolute('/sitemap.xml').'): every public page in every language'."\n";

    return response($md, 200, ['Content-Type' => 'text/markdown; charset=UTF-8']);
})->name('llms');

// PWA app shell (join and registration links open the app too; JS reads the path)
Route::view('/app', 'app')->name('app');
Route::view('/join/{token}', 'app')->name('join');
Route::view('/register/{token}', 'app')->name('register');

// Embeddable chat widget for third-party websites (iframe). Only channels
// with open join mode work here — visitors join instantly, no approval.
// frame-ancestors * deliberately allows any site to iframe THIS route only.
Route::get('/embed/{token}', function (string $token) {
    $channel = Channel::where('invite_token', $token)->first();
    $available = $channel !== null
        && $channel->type === 'group'
        && $channel->join_mode === 'open';

    return response()
        ->view('embed', [
            'inviteToken' => $token,
            'available' => $available,
            'channelName' => $available ? $channel->name : null,
            // A fresh visitor can only take part if this server lets anyone
            // register; the widget explains itself instead of failing.
            'registrationsOpen' => Setting::registrationsOpen(),
        ])
        ->header('Content-Security-Policy', 'frame-ancestors *');
})->where('token', '[A-Za-z0-9]+')->name('embed');

// Uploads are served ONLY through per-member capability URLs. The token is
// personal; the first access to the full file starts a 5-minute view window,
// after which the token is destroyed and the URL stops resolving. Thumbnails
// (/u/{token}/thumb) do not start the window. There must be NO public/storage
// symlink — uploads must not be reachable outside these routes.
Route::get('/u/{token}/{variant?}', function (string $token, ?string $variant = null) {
    $view = UploadView::where('token', $token)->first();
    abort_unless($view, 404);

    $message = $view->message;
    if (! $message || ! $message->file_path || $message->fileExpired()) {
        $view->delete();
        abort(404);
    }
    if ($view->viewWindowExpired()) {
        $view->update(['token' => null]);
        abort(404);
    }

    $isThumb = $variant === 'thumb';
    if ($isThumb && ! $message->thumb_path) {
        abort(404); // never leak the full file through the thumb variant
    }
    if (! $isThumb && ! $view->first_viewed_at) {
        $view->update(['first_viewed_at' => now()]);
    }

    $path = storage_path('app/public/'.($isThumb ? $message->thumb_path : $message->file_path));
    abort_unless(is_file($path), 404);

    return response()->file($path, ['Cache-Control' => 'private, max-age=300']);
})->where('token', '[A-Za-z0-9]+')->where('variant', 'thumb');

// API reference. Public: a custom client needs it before it has an identity,
// and it describes nothing the shipped JS bundle does not already reveal.
Route::get('api/docs', [DocsController::class, 'page'])->name('api.docs');
Route::get('api/openapi.json', [DocsController::class, 'spec'])->name('api.spec');

// Anonymous chat API. Authenticated requests carry no credential: they are
// signed per request (X-Chat-Device / -Ts / -Nonce / -Sig), see ChatAuth.
Route::prefix('api')->group(function () {
    Route::post('session', [SessionController::class, 'start'])->middleware('throttle:30,1');
    Route::get('join/{token}', [MemberController::class, 'joinInfo']);

    // Registering a device from a pairing code or an exported identity token —
    // by definition unsigned, so both are rate limited against guessing.
    Route::post('devices/claim', [ProfileController::class, 'claim'])->middleware('throttle:10,1');
    Route::post('devices/import', [ProfileController::class, 'import'])->middleware('throttle:10,1');

    // The VAPID public key is handed to every subscribing browser anyway, so
    // there is nothing to protect and no reason to require an identity.
    Route::get('push/key', [PushController::class, 'key']);

    // Another Krotze server delivering notifications for devices that gave it
    // relay tokens. Each token authorises its own item; the per-item limits
    // live in the controller, this one only caps how often anybody may call.
    Route::post('push/relay/deliver', [PushController::class, 'relayDeliver'])->middleware('throttle:120,1');

    Route::middleware('chat.auth')->group(function () {
        Route::get('state', [SessionController::class, 'state']);
        Route::delete('me', [SessionController::class, 'destroyMe']);

        Route::get('profile', [ProfileController::class, 'show']);
        Route::post('profile/username', [ProfileController::class, 'updateUsername']);
        Route::post('profile/devices/key', [ProfileController::class, 'setDeviceKey']);
        Route::post('profile/devices/pair', [ProfileController::class, 'pair']);
        Route::delete('profile/devices/{deviceId}', [ProfileController::class, 'removeDevice']);
        Route::post('profile/transfer', [ProfileController::class, 'createTransfer']);
        Route::delete('profile/transfer', [ProfileController::class, 'revokeTransfer']);
        Route::post('profile/swap-token', [ProfileController::class, 'swapToken']);

        Route::post('join/{token}', [MemberController::class, 'joinApply']);
        Route::post('members/{memberId}/decision', [MemberController::class, 'decide']);
        Route::post('channels/{uuid}/requests', [MemberController::class, 'decideBulk']);

        Route::post('channels', [ChannelController::class, 'store']);
        Route::get('channels/{uuid}', [ChannelController::class, 'show']);
        Route::patch('channels/{uuid}', [ChannelController::class, 'update']);
        Route::delete('channels/{uuid}', [ChannelController::class, 'destroy']);
        Route::post('channels/{uuid}/transfer', [ChannelController::class, 'transfer']);
        Route::post('channels/{uuid}/rotate', [ChannelController::class, 'rotate']);
        Route::post('channels/{uuid}/pin', [ChannelController::class, 'pin']);
        Route::post('channels/{uuid}/hide', [ChannelController::class, 'hide']);
        Route::post('channels/{uuid}/mute', [ChannelController::class, 'mute']);
        Route::post('channels/{uuid}/purge-uploads', [ChannelController::class, 'purgeUploads']);
        Route::post('channels/{uuid}/read', [ChannelController::class, 'markRead']);
        Route::post('channels/{uuid}/leave', [ChannelController::class, 'leave']);
        Route::post('channels/{uuid}/private', [ChannelController::class, 'startPrivate']);
        Route::post('channels/{uuid}/private-response', [ChannelController::class, 'respondPrivate']);
        Route::delete('channels/{uuid}/members/{memberId}', [MemberController::class, 'remove']);

        Route::get('channels/{uuid}/messages', [MessageController::class, 'index']);
        Route::post('channels/{uuid}/messages', [MessageController::class, 'store']);
        Route::delete('channels/{uuid}/messages/{messageId}', [MessageController::class, 'destroy']);
        Route::post('channels/{uuid}/messages/{messageId}/delete-file', [MessageController::class, 'deleteFile']);

        Route::post('push/subscribe', [PushController::class, 'subscribe']);
        Route::patch('push/subscription', [PushController::class, 'update']);
        Route::delete('push/subscription', [PushController::class, 'unsubscribe']);
        Route::post('push/test', [PushController::class, 'test'])->middleware('throttle:10,1');
        Route::post('push/relay', [PushController::class, 'relayCreate']);
        Route::delete('push/relay', [PushController::class, 'relayDelete']);

        Route::post('notifications/read', [NotificationController::class, 'markRead']);
    });
});

// Admin panel (session login)
Route::prefix('admin')->group(function () {
    Route::get('login', [AdminController::class, 'showLogin'])->name('admin.login');
    // Throttled: a password guess and a six-digit code are both worth guessing
    // at, and neither is protected by a signature.
    Route::post('login', [AdminController::class, 'login'])->middleware('throttle:10,1');

    Route::get('two-factor', [AdminController::class, 'showChallenge'])->name('admin.2fa.challenge');
    Route::post('two-factor', [AdminController::class, 'challenge'])->middleware('throttle:10,1');

    // Reset by e-mail, for an admin who is locked out. Throttled on top of the
    // broker's own per-address limit, so the form cannot be used to hunt for
    // valid addresses or to flood a mailbox.
    Route::get('forgot-password', [PasswordResetController::class, 'showRequest'])->name('admin.password.request');
    Route::post('forgot-password', [PasswordResetController::class, 'sendLink'])
        ->middleware('throttle:5,1')->name('admin.password.email');
    Route::get('reset-password/{token}', [PasswordResetController::class, 'showReset'])->name('admin.password.reset');
    Route::post('reset-password', [PasswordResetController::class, 'reset'])
        ->middleware('throttle:10,1')->name('admin.password.update');

    Route::post('logout', [AdminController::class, 'logout'])->name('admin.logout');

    // AuthenticateSession is what makes "changing the password signs out every
    // other browser" true: it compares the password hash stored in each session
    // against the account's, so a stolen session dies with the old password.
    Route::middleware(['admin.auth', AuthenticateSession::class])->group(function () {
        Route::get('/', [AdminController::class, 'dashboard'])->name('admin.dashboard');
        Route::delete('channels/{channel:uuid}', [AdminController::class, 'destroyChannel'])->name('admin.channels.destroy');

        Route::post('settings', [AdminController::class, 'updateSettings'])->name('admin.settings');
        Route::post('invite/rotate', [AdminController::class, 'rotateInvite'])->name('admin.invite.rotate');
        Route::post('registrations', [AdminController::class, 'decideRegistrations'])->name('admin.registrations.decide');

        // Reachable while a password change is still outstanding — everything
        // else redirects here until it is done (see AdminAuth).
        Route::get('profile', [AdminController::class, 'profile'])->name('admin.profile');
        Route::post('profile/password', [AdminController::class, 'updatePassword'])->name('admin.password');
        Route::post('profile/email', [AdminController::class, 'updateEmail'])->name('admin.email');

        Route::post('profile/two-factor', [AdminController::class, 'startTwoFactor'])->name('admin.2fa.start');
        Route::post('profile/two-factor/confirm', [AdminController::class, 'confirmTwoFactor'])->name('admin.2fa.confirm');
        Route::delete('profile/two-factor', [AdminController::class, 'disableTwoFactor'])->name('admin.2fa.disable');
        Route::post('profile/recovery-codes', [AdminController::class, 'regenerateRecoveryCodes'])->name('admin.2fa.recovery');
    });
});
