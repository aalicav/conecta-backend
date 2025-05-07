<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Carbon\Carbon;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Config;

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
                'email' => ['Email ou senha estão incorretos  '],
            ]);
        }
        
        // Check if user is active
        if (!$user->is_active) {
            throw ValidationException::withMessages([
                'email' => ['Este usuário não está ativo. Por favor, contate o administrador.'],
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

    /**
     * Request a password reset link.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function requestPasswordReset(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return response()->json([
                'message' => 'Se o endereço de e-mail estiver correto, você receberá um e-mail com instruções para redefinir sua senha.'
            ]);
        }

        // Generate token and store in password_resets table
        $token = Str::random(60);
        $resetCode = strtoupper(Str::random(8));
        
        // Delete any existing password reset tokens for this user
        DB::table('password_resets')->where('email', $user->email)->delete();
        
        // Insert new password reset token
        DB::table('password_resets')->insert([
            'email' => $user->email,
            'token' => Hash::make($token),
            'reset_code' => $resetCode,
            'created_at' => Carbon::now()
        ]);
        
        // Create password reset URL
        $resetUrl = URL::temporarySignedRoute(
            'password.reset',
            Carbon::now()->addMinutes(60),
            ['token' => $token, 'email' => $user->email]
        );
        
        // Build frontend URL using the signed URL's query parameters
        $frontendResetUrl = Config::get('app.frontend_url') . '/reset-password?' . http_build_query([
            'token' => $token,
            'email' => $user->email,
            'signature' => request()->hasValidSignature()
        ]);

        // Send password reset email
        $companyName = Config::get('app.name');
        $companyAddress = Config::get('app.address', 'Address not available');
        $companyCity = Config::get('app.city', 'City not available');
        $companyState = Config::get('app.state', 'State not available');
        $supportEmail = Config::get('app.support_email', 'support@example.com');
        $supportPhone = Config::get('app.support_phone', '(00) 0000-0000');
        $socialMedia = [
            'Facebook' => 'https://facebook.com/' . Config::get('app.social.facebook', ''),
            'Instagram' => 'https://instagram.com/' . Config::get('app.social.instagram', ''),
        ];
        
        Mail::send('emails.password_reset', [
            'user' => $user,
            'resetUrl' => $frontendResetUrl,
            'resetCode' => $resetCode,
            'expirationTime' => '60 minutos',
            'companyName' => $companyName,
            'companyAddress' => $companyAddress,
            'companyCity' => $companyCity,
            'companyState' => $companyState,
            'supportEmail' => $supportEmail,
            'supportPhone' => $supportPhone,
            'socialMedia' => $socialMedia,
        ], function ($message) use ($user) {
            $message->to($user->email, $user->name)
                    ->subject('Redefinição de Senha');
        });

        return response()->json([
            'message' => 'Se o endereço de e-mail estiver correto, você receberá um e-mail com instruções para redefinir sua senha.'
        ]);
    }

    /**
     * Validate a password reset token.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function validateResetToken(Request $request)
    {
        $request->validate([
            'token' => 'required|string',
            'email' => 'required|email',
            'reset_code' => 'nullable|string',
        ]);

        $passwordReset = DB::table('password_resets')
            ->where('email', $request->email)
            ->first();

        if (!$passwordReset) {
            return response()->json([
                'message' => 'Token inválido ou expirado.',
                'valid' => false
            ], 400);
        }

        // Check if token is valid (using either the token or reset code)
        $isValidToken = Hash::check($request->token, $passwordReset->token);
        $isValidCode = $request->reset_code && $request->reset_code === $passwordReset->reset_code;

        if (!$isValidToken && !$isValidCode) {
            return response()->json([
                'message' => 'Token inválido ou expirado.',
                'valid' => false
            ], 400);
        }

        // Check if token is expired (older than 60 minutes)
        $tokenCreatedAt = Carbon::parse($passwordReset->created_at);
        if (Carbon::now()->diffInMinutes($tokenCreatedAt) > 60) {
            return response()->json([
                'message' => 'Token expirado. Por favor, solicite um novo link de redefinição de senha.',
                'valid' => false
            ], 400);
        }

        return response()->json([
            'message' => 'Token válido.',
            'valid' => true
        ]);
    }

    /**
     * Reset user password.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function resetPassword(Request $request)
    {
        $request->validate([
            'token' => 'required|string',
            'email' => 'required|email',
            'reset_code' => 'nullable|string',
            'password' => 'required|min:8|confirmed',
        ]);

        $passwordReset = DB::table('password_resets')
            ->where('email', $request->email)
            ->first();

        if (!$passwordReset) {
            return response()->json([
                'message' => 'Token inválido ou expirado.'
            ], 400);
        }

        // Check if token is valid (using either the token or reset code)
        $isValidToken = Hash::check($request->token, $passwordReset->token);
        $isValidCode = $request->reset_code && $request->reset_code === $passwordReset->reset_code;

        if (!$isValidToken && !$isValidCode) {
            return response()->json([
                'message' => 'Token inválido ou expirado.'
            ], 400);
        }

        // Check if token is expired (older than 60 minutes)
        $tokenCreatedAt = Carbon::parse($passwordReset->created_at);
        if (Carbon::now()->diffInMinutes($tokenCreatedAt) > 60) {
            return response()->json([
                'message' => 'Token expirado. Por favor, solicite um novo link de redefinição de senha.'
            ], 400);
        }

        // Update user password
        $user = User::where('email', $request->email)->first();
        $user->password = Hash::make($request->password);
        $user->save();

        // Delete the token
        DB::table('password_resets')->where('email', $request->email)->delete();

        return response()->json([
            'message' => 'Senha redefinida com sucesso.'
        ]);
    }
}