<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Identity\Actions\RegisterStudent;
use App\Modules\Identity\Actions\StartAuthSession;
use App\Modules\Identity\Actions\TerminateAuthSession;
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
use App\Modules\Notifications\Actions\DispatchNotification;
use App\Modules\Notifications\Data\NotificationRequest;
use App\Modules\Notifications\Support\NotificationType;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::create([
            'first_name' => $request->validated('first_name'),
            'last_name' => $request->validated('last_name', ''),
            'email' => $request->validated('email'),
            'password' => $request->validated('password'),
        ]);

        event(new Registered($user));

        return response()->json(UserResource::make($user), 201);
    }

    public function registerStudent(
        RegisterStudentRequest $request,
        RegisterStudent $action,
        StartAuthSession $startSession,
    ): JsonResponse {
        $user = $action->handle(RegisterStudentData::fromArray($request->validated()));

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
        DispatchNotification $notify,
        TerminateAuthSession $terminate,
    ): JsonResponse {
        $request->ensureCurrentPasswordIsValid();

        $user = $this->currentUser($request);
        $user->update(['password' => $request->validated('password')]);

        // Every other session goes (FR-031). If the password was changed because
        // it leaked, leaving the intruder signed in defeats the change.
        $currentTokenId = $user->currentAccessToken()->getKey();

        AuthSession::query()
            ->active()
            ->where('user_id', $user->getKey())
            ->where(fn ($query) => $query->whereNull('token_id')->orWhere('token_id', '!=', $currentTokenId))
            ->get()
            ->each(fn (AuthSession $session) => $terminate->handle($session, SessionEndReason::PasswordChange));

        // Mandatory type: it cannot be switched off, deferred or digested. A
        // password change the account holder did not make is the one message that
        // has to arrive at 3am if it happens at 3am.
        $notify->handle(new NotificationRequest(
            recipient: $user,
            type: NotificationType::SecurityAlert,
            variables: [
                'name' => $user->name,
                'event' => 'تم تغيير كلمة مرور حسابك.',
            ],
        ));

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

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password): void {
                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => [__($status)]]);
        }

        return response()->json(['message' => __($status)]);
    }
}
