<?php

namespace App\Http\Controllers;

use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $request->merge(['email' => is_string($request->input('email')) ? mb_strtolower(trim($request->input('email'))) : $request->input('email')]);
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['bail', 'required', 'string', 'min:8', 'max:72', function ($attribute, $value, $fail) {
                if (strlen($value) > 72) {
                    $fail('The password must not exceed 72 bytes.');
                }
            }],
        ]);
        try {
            return DB::transaction(function () use ($data) {
                $user = User::create($data);

                return $this->authenticated($user, 201);
            });
        } catch (UniqueConstraintViolationException $exception) {
            throw ValidationException::withMessages(['email' => 'The email has already been taken.']);
        }
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['bail', 'required', 'string', 'max:72', function ($attribute, $value, $fail) {
                if (strlen($value) > 72) {
                    $fail('The password must not exceed 72 bytes.');
                }
            }],
        ]);
        $user = User::where('email', mb_strtolower(trim($data['email'])))->first();
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => 'The provided credentials are incorrect.']);
        }
        abort_unless($user->status === 'active', 403, 'This account is blocked.');
        if (Hash::needsRehash($user->password)) {
            $user->update(['password' => Hash::make($data['password'])]);
        }

        return $this->authenticated($user);
    }

    private function authenticated(User $user, int $status = 200): JsonResponse
    {
        return response()->json([
            'data' => new UserResource($user),
            'token' => $user->createToken('frontend', ['*'], now()->addDays(7))->plainTextToken,
            'token_type' => 'Bearer',
        ], $status)->header('Cache-Control', 'no-store');
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out.']);
    }
}
