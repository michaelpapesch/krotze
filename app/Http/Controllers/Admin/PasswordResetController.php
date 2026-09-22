<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\AdminPasswordReset;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

/**
 * Password reset by e-mail, for an admin who cannot get in.
 *
 * Two things are deliberately *not* done here. A successful reset does not sign
 * anybody in — with two-factor on, the code is still required, so somebody who
 * only controls the mailbox gets nowhere. And the request step never reveals
 * whether an address belongs to an account.
 */
class PasswordResetController extends Controller
{
    public function showRequest()
    {
        return view('admin.forgot-password');
    }

    public function sendLink(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        $user = User::where('email', $request->input('email'))->first();

        if ($user) {
            // Laravel's broker handles token creation, hashing and its own
            // per-address throttle; only the notification is ours.
            $token = Password::broker()->createToken($user);
            $user->notify(new AdminPasswordReset($token));
        }

        // Same answer either way: whether an address is an admin account is not
        // something a stranger should be able to test.
        return back()->with('status', 'If that address belongs to an admin account, a reset link is on its way. It expires in an hour.');
    }

    public function showReset(Request $request, string $token)
    {
        return view('admin.reset-password', [
            'token' => $token,
            'email' => $request->query('email', ''),
        ]);
    }

    public function reset(Request $request)
    {
        $request->validate([
            'token' => 'required|string',
            'email' => 'required|email',
            'password' => ['required', 'confirmed', PasswordRule::min(12)->letters()->numbers()->symbols()],
        ], [
            'password.confirmed' => 'The two passwords do not match.',
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => $password,
                    'password_changed_at' => now(),
                    // Any session still open under the old password dies with
                    // it, because AuthenticateSession compares the hash.
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            return back()
                ->withInput($request->only('email'))
                ->withErrors(['email' => __($status)]);
        }

        // Not signed in on purpose: with two-factor on, controlling the mailbox
        // must not be enough to reach the panel.
        return redirect()->route('admin.login')
            ->with('status', 'Password changed. Sign in with your new password.');
    }
}
