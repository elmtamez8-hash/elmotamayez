<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Identity\Actions\AttachReferral;
use App\Modules\Identity\Actions\RegisterAccount;
use App\Modules\Identity\Actions\RegisterStudent;
use App\Modules\Identity\Actions\StartAuthSession;
use App\Modules\Identity\Actions\TerminateAuthSession;
use App\Modules\Identity\Actions\TerminateOtherSessions;
use App\Modules\Identity\Actions\UpdateAccountDetails;
use App\Modules\Identity\Data\RegisterAccountData;
use App\Modules\Identity\Data\RegisterStudentData;
use App\Modules\Identity\Http\Requests\ChangePasswordRequest;
use App\Modules\Identity\Http\Requests\ForgotPasswordRequest;
use App\Modules\Identity\Http\Requests\LoginRequest;
use App\Modules\Identity\Http\Requests\RegisterRequest;
use App\Modules\Identity\Http\Requests\RegisterStudentRequest;
use App\Modules\Identity\Http\Requests\ResetPasswordRequest;
use App\Modules\Identity\Http\Requests\UpdateProfileRequest;
use App\Modules\Identity\Http\Resources\UserResource;
use App\Modules\Identity\Models\AuthSession;
use App\Modules\Identity\Support\SessionEndReason;
use App\Modules\Identity\Support\TwoFactorChallenges;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    public function register(
        RegisterRequest $request,
        RegisterAccount $action,
        AttachReferral $referrals,
    ): JsonResponse {
        $user = $action->handle(RegisterAccountData::fromArray($request->validated()));

        // ⚠️ AFTER the account exists and never blocking it: an unknown or
        // duplicate code attaches nothing and is not an error. Composed here
        // rather than threaded through the DTO because BOTH register doors need
        // it, and one Action reached from two controllers is one rule — a
        // parameter added to two DTOs and two Actions is two.
        $referrals->handle($user, $request->validated('referral_code'));

        return response()->json(UserResource::make($user), 201);
    }

    public function registerStudent(
        RegisterStudentRequest $request,
        RegisterStudent $action,
        StartAuthSession $startSession,
        AttachReferral $referrals,
    ): JsonResponse {
        $user = $action->handle(RegisterStudentData::fromArray($request->validated()));

        $referrals->handle($user, $request->validated('referral_code'));

        // Signed in straight away: the student came from a teacher's booking CTA
        // and sending them back to a login form would drop that intent.
        $result = $startSession->handle($user, $request);

        return response()->json([
            'user' => UserResource::make($user),
            'token' => $result->plainTextToken,
            'session_uuid' => $result->session->uuid,
        ], 201);
    }

    public function login(LoginRequest $request, StartAuthSession $startSession): JsonResponse
    {
        $user = User::where('email', $request->validated('email'))->first();

        if ($user === null || ! Hash::check($request->validated('password'), $user->password)) {
            throw ValidationException::withMessages([
                'email' => __('The provided credentials are incorrect.'),
            ]);
        }

        // A correct password on a two-factor account issues NO token (SC-007).
        // Issuing one and then restricting it would mean any flaw in the
        // restriction is a full sign-in with the password alone.
        if ($user->hasTwoFactorEnabled()) {
            return response()->json([
                'two_factor' => true,
                'challenge' => TwoFactorChallenges::issue($user),
            ]);
        }

        $result = $startSession->handle($user, $request);

        return response()->json([
            'user' => UserResource::make($user),
            'token' => $result->plainTextToken,
            // Kept by the client so it can ask why it was signed out after the
            // token is already gone.
            'session_uuid' => $result->session->uuid,
        ]);
    }

    /**
     * ⛔ **`currentAccessToken()` IS NOT ALWAYS A TOKEN, AND ASSUMING IT WAS
     * CRASHED THE ONE REQUEST THAT MUST NEVER FAIL.**
     *
     * Measured on production 2026-09-16: `Call to undefined method
     * Laravel\Sanctum\TransientToken::getKey()`, 500, `userId: 1`. Sanctum's
     * `statefulApi()` authenticates a same-domain request by the SESSION COOKIE
     * when one is present — and every teacher on this platform has one, because
     * `/admin` is session-based — and for that request `currentAccessToken()`
     * returns a {@see TransientToken}: a marker meaning «there is no token
     * here», with no key, no id and nothing to delete.
     *
     * ⚠️ AND THE 500 WAS THE SMALL HALF. The line threw BEFORE anything was
     * revoked, so «تسجيل الخروج» left the session alive while the browser
     * cleared its own storage and moved to `/login` — the account stayed signed
     * in on a machine whose owner had just asked to leave it. That is the same
     * failure `StartAuthSession`'s device limit exists to prevent, reached from
     * the opposite direction.
     *
     * The two shapes are answered separately because they ARE separate: a
     * bearer token is a row to revoke, a cookie session is state to invalidate,
     * and neither instrument can end the other. The `instanceof` is the same
     * predicate {@see User::currentTokenId()} uses — this branch keeps the
     * OBJECT because it has to delete it, and that is the only reason it is
     * spelled here rather than read from there.
     */
    public function logout(Request $request, TerminateAuthSession $terminate): JsonResponse
    {
        $token = $this->currentUser($request)->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $session = AuthSession::query()->where('token_id', $token->getKey())->first();

            if ($session !== null) {
                $terminate->handle($session, SessionEndReason::Logout);
            } else {
                // A token minted before this feature existed has no session row.
                $token->delete();
            }

            return response()->json(null, 204);
        }

        /*
        | The cookie half. `logout()` alone clears the guard and leaves the
        | session id valid, so a stolen cookie still names a live session —
        | `invalidate()` is what actually ends it, and the token regenerate
        | keeps the next form on the login page from being refused.
        |
        | Guarded on the session's existence rather than assumed: this route is
        | in the `api` group, and a request that arrived with a bearer token has
        | no session at all — which is the branch above, but a future caller
        | reaching here without one must not trade a 500 for a 500.
        */
        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(null, 204);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(UserResource::make($this->currentUser($request)));
    }

    /**
     * Through {@see UpdateAccountDetails}, the Action the panel already uses: it
     * owns «a new address drops its verification» and «the owner is told», so the
     * two doors that change an address cannot disagree about either. The password
     * a new address costs is checked by the request and never reaches the write.
     */
    public function updateProfile(UpdateProfileRequest $request, UpdateAccountDetails $action): JsonResponse
    {
        $user = $action->handle($this->currentUser($request), $request->profile());

        return response()->json(UserResource::make($user->fresh()));
    }

    public function changePassword(
        ChangePasswordRequest $request,
        TerminateOtherSessions $terminateOthers,
    ): JsonResponse {
        $request->ensureCurrentPasswordIsValid();

        $user = $this->currentUser($request);
        $user->update(['password' => $request->validated('password')]);

        // Every other session goes (FR-031). If the password was changed because
        // it leaked, leaving the intruder signed in defeats the change.
        $terminateOthers->handle(
            $user,
            SessionEndReason::PasswordChange,
            'تم تغيير كلمة مرور حسابك.',
            $this->currentTokenId($request),
        );

        return response()->json(['message' => 'Password changed successfully.']);
    }

    public function sendVerificationEmail(Request $request): JsonResponse
    {
        if ($this->currentUser($request)->hasVerifiedEmail()) {
            return response()->json(['message' => 'Email already verified.']);
        }

        $this->currentUser($request)->sendEmailVerificationNotification();

        return response()->json(['message' => 'Verification link sent.']);
    }

    public function verifyEmail(Request $request): JsonResponse
    {
        if (! hash_equals((string) $request->route('hash'), sha1((string) $this->currentUser($request)->getEmailForVerification()))) {
            throw ValidationException::withMessages(['hash' => __('Invalid verification hash.')]);
        }

        if ($this->currentUser($request)->hasVerifiedEmail()) {
            return response()->json(['message' => 'Email already verified.']);
        }

        $this->currentUser($request)->markEmailAsVerified();

        return response()->json(['message' => 'Email verified.']);
    }

    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        Password::sendResetLink(['email' => $request->validated('email')]);

        /*
        | ⛔ ONE ANSWER WHATEVER HAPPENED. `__($status)` said «لا يوجد حساب بهذا
        | البريد» for an unknown address and «انتظر قليلاً» only for a known one
        | (the broker throttles per existing account) — an unauthenticated oracle
        | for who has an account here. The broker still decides what is sent.
        */
        return response()->json(['message' => __('passwords.sent')]);
    }

    /**
     * ⚠️ AND IT SIGNS THE OTHER DEVICES OUT, EXACTLY AS {@see changePassword()}
     * DOES. Two doors change a password and only one of them revoked anything —
     * and the door that did not is the one somebody uses AFTER a compromise. An
     * intruder who phished a reset link and signed in keeps a Sanctum token in
     * `localStorage`; the victim resets, and that token is untouched for ever.
     *
     * ⚠️ `remember_token` IS NOT THAT REVOCATION. It governs Laravel's remember-me
     * cookie and has no bearing on a bearer token or on an `auth_sessions` row.
     * Neither is `PasswordReset`: the event reaches no listener in this tree.
     *
     * ⚠️ `$keepTokenId` IS NULL HERE, and that is the difference from the sibling.
     * Somebody changing their password is signed in on the screen they did it
     * from; somebody resetting one holds no token yet, so there is nothing to
     * keep and every live session belongs to whoever had the old password.
     */
    public function resetPassword(
        ResetPasswordRequest $request,
        TerminateOtherSessions $terminateOthers,
    ): JsonResponse {
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) use ($terminateOthers): void {
                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                ])->save();

                $terminateOthers->handle(
                    $user,
                    SessionEndReason::PasswordChange,
                    'تمت إعادة تعيين كلمة مرور حسابك.',
                );

                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => [__($status)]]);
        }

        return response()->json(['message' => __($status)]);
    }
}
