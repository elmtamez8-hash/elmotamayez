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
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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

    public function logout(Request $request, TerminateAuthSession $terminate): JsonResponse
    {
        $token = $this->currentUser($request)->currentAccessToken();

        $session = AuthSession::query()->where('token_id', $token->getKey())->first();

        if ($session !== null) {
            $terminate->handle($session, SessionEndReason::Logout);
        } else {
            // A token minted before this feature existed has no session row.
            $token->delete();
        }

        return response()->json(null, 204);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(UserResource::make($this->currentUser($request)));
    }

    public function updateProfile(UpdateProfileRequest $request): JsonResponse
    {
        $user = $this->currentUser($request);
        $user->update($request->validated());

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
        $status = Password::sendResetLink(['email' => $request->validated('email')]);

        return response()->json(['message' => __($status)]);
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
