<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Models\User;
use App\Modules\Identity\Http\Requests\ChangePasswordRequest;
use App\Modules\Identity\Http\Requests\ForgotPasswordRequest;
use App\Modules\Identity\Http\Requests\LoginRequest;
use App\Modules\Identity\Http\Requests\RegisterRequest;
use App\Modules\Identity\Http\Requests\ResetPasswordRequest;
use App\Modules\Identity\Http\Requests\UpdateProfileRequest;
use App\Modules\Identity\Http\Resources\UserResource;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
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
        $request->user()->currentAccessToken()?->delete();

        return response()->json(null, 204);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(UserResource::make($request->user()));
    }

    public function updateProfile(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        $user->update($request->validated());

        return response()->json(UserResource::make($user->fresh()));
    }

    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $request->ensureCurrentPasswordIsValid();

        $user = $request->user();
        $user->update(['password' => $request->validated('password')]);

        return response()->json(['message' => 'Password changed successfully.']);
    }

    public function sendVerificationEmail(Request $request): JsonResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return response()->json(['message' => 'Email already verified.']);
        }

        $request->user()->sendEmailVerificationNotification();

        return response()->json(['message' => 'Verification link sent.']);
    }

    public function verifyEmail(Request $request): JsonResponse
    {
        if (! hash_equals((string) $request->route('hash'), sha1((string) $request->user()->getEmailForVerification()))) {
            throw ValidationException::withMessages(['hash' => __('Invalid verification hash.')]);
        }

        if ($request->user()->hasVerifiedEmail()) {
            return response()->json(['message' => 'Email already verified.']);
        }

        $request->user()->markEmailAsVerified();

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
