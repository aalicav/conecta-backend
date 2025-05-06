<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use App\Models\User;

class AuthController extends Controller
{
    /**
     * Handle user login.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     * @throws ValidationException
     */
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
            'device_name' => 'nullable|string',
        ]);

        // Busca o usuário diretamente pelo e-mail
        $user = User::where('email', $request->email)->first();

        // Verifica se o usuário existe e a senha está correta
        if (!$user || !Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }
        
        // Check if user is active
        if (!$user->is_active) {
            throw ValidationException::withMessages([
                'email' => ['This account is not active. Please contact the administrator.'],
            ]);
        }

        // Load role and permissions data
        $user->load('roles', 'permissions');

        // Create token
        $deviceName = $request->device_name ?? $request->userAgent() ?? 'unknown';
        $token = $user->createToken($deviceName)->plainTextToken;

        return response()->json([
            'user' => $user,
            'token' => $token,
            'roles' => $user->getRoleNames(),
        ]);
    }

    /**
     * Handle user logout.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function logout(Request $request)
    {
        // Revoke the current token
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Successfully logged out']);
    }

    /**
     * Get authenticated user information.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function user(Request $request)
    {
        $user = $request->user();
        
        // Load entity data through polymorphic relationship
        if ($user->entity_type && $user->entity_id) {
            $user->load('entity');
        }
        
        // Load role and permissions data
        $user->load('roles', 'permissions');

        return response()->json([
            'user' => $user,
            'roles' => $user->getRoleNames(),
            'permissions' => $user->getAllPermissions()->pluck('name'),
        ]);
    }
}