<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Models\ChatUser;
use App\Models\Message;
use App\Models\Setting;
use App\Models\User;
use App\Support\Scheduler;
use App\Support\WebPush;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class AdminController extends Controller
{
    public function showLogin()
    {
        return Auth::check() ? redirect()->route('admin.dashboard') : view('admin.login');
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            return back()->withErrors(['email' => 'Invalid credentials.'])->onlyInput('email');
        }

        // With 2FA on, the password alone does not sign anybody in: the session
        // only remembers who is halfway through, and the guard stays untouched
        // until a code is accepted.
        if ($user->twoFactorEnabled()) {
            $request->session()->put('admin.2fa.id', $user->id);

            return redirect()->route('admin.2fa.challenge');
        }

        Auth::login($user, true);
        $request->session()->regenerate();

        return redirect()->route('admin.dashboard');
    }

    /** Second step of login: the code from the authenticator, or a recovery code. */
    public function showChallenge(Request $request)
    {
        return $request->session()->has('admin.2fa.id')
            ? view('admin.two-factor-challenge')
            : redirect()->route('admin.login');
    }

    public function challenge(Request $request)
    {
        $id = $request->session()->get('admin.2fa.id');
        $user = $id ? User::find($id) : null;

        if (! $user) {
            return redirect()->route('admin.login');
        }

        $data = $request->validate(['code' => 'required|string|max:64']);

        if (! $user->verifyTwoFactor($data['code'])) {
            // Explicitly back to the challenge rather than back(): the previous
            // URL is whatever the session last saw, so anybody who opened
            // another page mid-login would be sent there instead, error and all.
            return redirect()->route('admin.2fa.challenge')
                ->withErrors(['code' => 'That code is not valid. Codes change every 30 seconds.']);
        }

        $request->session()->forget('admin.2fa.id');
        Auth::login($user, true);
        $request->session()->regenerate();

        $left = count($user->two_factor_recovery_codes ?? []);
        $status = $left < User::RECOVERY_CODE_COUNT
            ? "Signed in with a recovery code. {$left} left — generate new ones if you are running low."
            : null;

        return redirect()->route('admin.dashboard')->with('status', $status);
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }

    public function dashboard(Request $request)
    {
        $uploadBytes = 0;
        foreach (Storage::disk('public')->allFiles('uploads') as $f) {
            $uploadBytes += Storage::disk('public')->size($f);
        }

        $query = trim((string) $request->query('q', ''));

        $pending = ChatUser::where('status', ChatUser::PENDING)
            ->when($query !== '', fn ($q) => $q->where('username', 'like', '%'.$query.'%'))
            ->withCount('devices')
            ->orderBy('created_at')
            ->paginate(15)
            ->withQueryString();

        return view('admin.dashboard', [
            'stats' => [
                'users' => ChatUser::count(),
                'channels' => Channel::where('type', 'group')->count(),
                'privateChats' => Channel::where('type', 'private')->count(),
                'messages' => Message::count(),
                'uploadBytes' => $uploadBytes,
            ],
            'openRegistrations' => Setting::registrationsOpen(),
            'manualApproval' => Setting::bool('manual_approval'),
            'uploadAutodelete' => Setting::bool('upload_autodelete', true),
            'uploadRetentionDays' => (int) Setting::get('upload_retention_days', '1'),
            'uploadViewLimit' => Setting::bool('upload_view_limit', true),
            'uploadViewMinutes' => (int) Setting::get('upload_view_minutes', '5'),
            'pushConfigured' => WebPush::configured(),
            'pushEnabled' => WebPush::configured() && Setting::bool('push_enabled', true),
            'schedulerAlive' => Scheduler::isAlive(),
            'schedulerLastRun' => Scheduler::lastRun(),
            'inviteUrl' => Setting::inviteUrl(),
            'pending' => $pending,
            'pendingTotal' => ChatUser::where('status', ChatUser::PENDING)->count(),
            'deniedTotal' => ChatUser::where('status', ChatUser::DENIED)->count(),
            'search' => $query,
            'channels' => Channel::withCount(['members', 'messages'])
                ->orderByDesc('last_activity_at')
                ->get(),
        ]);
    }

    public function destroyChannel(Channel $channel)
    {
        $channel->destroyCompletely();

        return back()->with('status', 'Channel deleted.');
    }

    /* ------------------------------- profile ------------------------------ */

    public function profile(Request $request)
    {
        return view('admin.profile', [
            'admin' => $request->user(),
            // Shown once, right after enrolment or regeneration. Recovery codes
            // are never retrievable again — that is the point of them.
            'recoveryCodes' => $request->session()->pull('recovery_codes'),
            'enrolment' => $request->session()->get('two_factor_enrolment'),
        ]);
    }

    public function updateEmail(Request $request)
    {
        $admin = $request->user();

        $data = $request->validate([
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($admin->id)],
            'current_password' => 'required|string',
        ]);

        if (! Hash::check($data['current_password'], $admin->password)) {
            return back()->withErrors(['current_password' => 'That is not your current password.']);
        }

        $admin->forceFill(['email' => $data['email']])->save();

        return back()->with('status', 'E-mail address updated. Use it to sign in from now on.');
    }

    public function updatePassword(Request $request)
    {
        $admin = $request->user();

        $data = $request->validate([
            // Nothing to confirm on the very first change: the current password
            // is the seeded one, which is exactly what we are getting rid of.
            'current_password' => [Rule::requiredIf(! $admin->mustChangePassword()), 'nullable', 'string'],
            // Deliberately not ->uncompromised(): that checks the password
            // against Have I Been Pwned over the network. The check is a good
            // one, but this app makes no third-party requests anywhere else,
            // and putting one in the path of a forced first-run password change
            // means an unreachable API can stand between an admin and their own
            // panel.
            'password' => ['required', 'confirmed', Password::min(12)->letters()->numbers()->symbols()],
        ], [
            'password.confirmed' => 'The two passwords do not match.',
        ]);

        if (! $admin->mustChangePassword() && ! Hash::check($data['current_password'] ?? '', $admin->password)) {
            return back()->withErrors(['current_password' => 'That is not your current password.']);
        }
        if (Hash::check($data['password'], $admin->password)) {
            return back()->withErrors(['password' => 'That is the password you are already using.']);
        }

        $admin->forceFill([
            'password' => $data['password'],
            'password_changed_at' => now(),
        ])->save();

        // Any other session that was signed in with the old password is now
        // signed out; this one is kept.
        Auth::logoutOtherDevices($data['password']);
        $request->session()->regenerate();

        return redirect()->route('admin.profile')->with('status', 'Password changed.');
    }

    /* --------------------------- two-factor auth -------------------------- */

    /** Generate a secret and show the QR code; nothing changes about login yet. */
    public function startTwoFactor(Request $request)
    {
        $secret = $request->user()->startTwoFactorEnrolment();

        return redirect()->route('admin.profile')->with('two_factor_enrolment', $secret);
    }

    public function confirmTwoFactor(Request $request)
    {
        $data = $request->validate(['code' => 'required|string|max:16']);

        $codes = $request->user()->confirmTwoFactor($data['code']);
        if ($codes === null) {
            return back()
                ->with('two_factor_enrolment', $request->user()->two_factor_secret)
                ->withErrors(['code' => 'That code is not valid. Check your device clock and try the current code.']);
        }

        return redirect()->route('admin.profile')
            ->with('recovery_codes', $codes)
            ->with('status', 'Two-factor authentication is on.');
    }

    public function disableTwoFactor(Request $request)
    {
        $data = $request->validate(['current_password' => 'required|string']);

        if (! Hash::check($data['current_password'], $request->user()->password)) {
            return back()->withErrors(['current_password' => 'That is not your current password.']);
        }

        $request->user()->disableTwoFactor();

        return back()->with('status', 'Two-factor authentication is off.');
    }

    public function regenerateRecoveryCodes(Request $request)
    {
        abort_unless($request->user()->twoFactorEnabled(), 404);

        return redirect()->route('admin.profile')
            ->with('recovery_codes', $request->user()->regenerateRecoveryCodes())
            ->with('status', 'New recovery codes generated. The old ones no longer work.');
    }

    /** Who may register on this server, whether an admin vets them, and upload retention. */
    public function updateSettings(Request $request)
    {
        $data = $request->validate([
            // Both numbers are disabled while their switch is off, so they may
            // be absent — keep whatever was configured before in that case.
            'upload_retention_days' => 'nullable|integer|min:1|max:365',
            'upload_view_minutes' => 'nullable|integer|min:1|max:60',
        ]);

        Setting::put('open_registrations', $request->boolean('open_registrations'));
        Setting::put('manual_approval', $request->boolean('manual_approval'));
        Setting::put('upload_autodelete', $request->boolean('upload_autodelete'));
        Setting::put('upload_retention_days', (string) ($data['upload_retention_days']
            ?? Setting::get('upload_retention_days', '1')));
        Setting::put('upload_view_limit', $request->boolean('upload_view_limit'));
        Setting::put('upload_view_minutes', (string) ($data['upload_view_minutes']
            ?? Setting::get('upload_view_minutes', '5')));

        // The push checkbox is rendered disabled — and therefore not submitted —
        // when no VAPID keypair exists. Leave the stored value alone in that
        // case, so configuring keys later does not find the switch turned off.
        if (WebPush::configured()) {
            Setting::put('push_enabled', $request->boolean('push_enabled'));
        }

        return back()->with('status', 'Settings saved.');
    }

    /** Retire a leaked invite link; the old one stops working immediately. */
    public function rotateInvite()
    {
        Setting::rotateInviteToken();

        return back()->with('status', 'A new registration invite link was created.');
    }

    /**
     * Approve or deny a batch of waiting registrations. Approved identities can
     * use the app from their next poll; denied ones keep the row (so the same
     * device cannot simply try again) but are locked out.
     */
    public function decideRegistrations(Request $request)
    {
        $data = $request->validate([
            'action' => 'required|in:approve,deny',
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
        ]);

        $approve = $data['action'] === 'approve';

        $count = ChatUser::whereIn('id', $data['ids'])
            ->where('status', ChatUser::PENDING)
            ->update([
                'status' => $approve ? ChatUser::APPROVED : ChatUser::DENIED,
                'decided_at' => now(),
            ]);

        return back()->with('status', $count.' registration'.($count === 1 ? '' : 's')
            .' '.($approve ? 'approved' : 'denied').'.');
    }
}
