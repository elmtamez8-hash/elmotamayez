<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Identity\Actions\RegisterStudent;
use App\Modules\Identity\Data\RegisterStudentData;
use App\Modules\Identity\Http\Requests\ChangePasswordRequest;
use App\Modules\Identity\Http\Requests\ForgotPasswordRequest;
use App\Modules\Identity\Http\Requests\LoginRequest;
use App\Modules\Identity\Http\Requests\RegisterRequest;
use App\Modules\Identity\Http\Requests\RegisterStudentRequest;
use App\Modules\Identity\Http\Requests\ResetPasswordRequest;
use App\Modules\Identity\Http\Requests\UpdateProfileRequest;
use App\Modules\Identity\Http\Resources\UserResource;
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

    public function registerStudent(RegisterStudentRequest $request, RegisterStudent $action): JsonResponse
    {
        $user = $action->handle(RegisterStudentData::fromArray($request->validated()));

        // Signed in straight away: the student came from a teacher's booking CTA
        // and sending them back to a login form would drop that intent.
        return response()->json([
            'user' => UserResource::make($user),
            'token' => $user->createToken('auth-token')->plainTextToken,
        ], 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->validated('email'))->first();

        if ($user === null || ! Hash::check($request->validated('password'), $user->password)) {
            throw ValidationException::withMessages([
                'email' => __('The provided credentials are incorrect.'),
            ]);
        }

        return response()->json([
            'user' => UserResource::make($user),
            'token' => $user->createToken('auth-token')->plainTextToken,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $this->currentUser($request)->currentAccessToken()->delete();

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

    public function changePassword(ChangePasswordRequest $request, DispatchNotification $notify): JsonResponse
    {
        $request->ensureCurrentPasswordIsValid();

        $user = $this->currentUser($request);
        $user->update(['password' => $request->validated('password')]);

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
