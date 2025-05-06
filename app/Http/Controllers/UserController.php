<?php

namespace App\Http\Controllers;

use App\Http\Requests\UserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

class UserController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
                'email' => 'required|string|email|max:255|unique:users',
                'password' => 'required|string|min:8',
                'profile_photo' => 'nullable|image|max:2048',
                'roles' => 'sometimes|array',
                'roles.*' => 'exists:roles,name',
                'entity_type' => 'nullable|string',
                'entity_id' => 'nullable|integer',
                'phone' => 'nullable|string|max:20',
                'is_active' => 'boolean',
                'send_welcome_email' => 'sometimes|boolean'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            DB::beginTransaction();

            // Handle profile photo upload if provided
            $profilePhotoPath = null;
            if ($request->hasFile('profile_photo')) {
                $profilePhotoPath = $request->file('profile_photo')->store('users/profile-photos', 'public');
            }

            // Create user
            $user = new User();
            $user->name = $request->name;
            $user->email = $request->email;
            $user->password = Hash::make($request->password);
            $user->profile_photo = $profilePhotoPath;
            $user->entity_type = $request->entity_type ?? null;
            $user->entity_id = $request->entity_id ?? null;
            $user->phone = $request->phone ?? null;
            $user->is_active = $request->has('is_active') ? $request->is_active : true;
            $user->save();

            // Assign roles if provided
            if ($request->has('roles') && is_array($request->roles)) {
                $user->assignRole($request->roles);
            }

            // Send welcome email with password if requested
            $sendEmail = $request->has('send_welcome_email') ? $request->boolean('send_welcome_email') : true;
            if ($sendEmail) {
                $plainPassword = $request->password;
                
                // Get company data from config
                $companyName = config('app.name');
                $companyAddress = config('app.address', 'Address not available');
                $companyCity = config('app.city', 'City not available');
                $companyState = config('app.state', 'State not available');
                $supportEmail = config('app.support_email', 'support@example.com');
                $supportPhone = config('app.support_phone', '(00) 0000-0000');
                $socialMedia = [
                    'Facebook' => 'https://facebook.com/' . config('app.social.facebook', ''),
                    'Instagram' => 'https://instagram.com/' . config('app.social.instagram', ''),
                ];
                
                // Send welcome email
                Mail::send('emails.welcome_user', [
                    'user' => $user,
                    'password' => $plainPassword,
                    'loginUrl' => config('app.frontend_url') . '/login',
                    'companyName' => $companyName,
                    'companyAddress' => $companyAddress,
                    'companyCity' => $companyCity,
                    'companyState' => $companyState,
                    'supportEmail' => $supportEmail,
                    'supportPhone' => $supportPhone,
                    'socialMedia' => $socialMedia,
                ], function ($message) use ($user) {
                    $message->to($user->email, $user->name)
                            ->subject('Bem-vindo ao ' . config('app.name') . ' - Detalhes da sua conta');
                });
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'User created successfully',
                'data' => new UserResource($user)
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error creating user: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to create user',
                'error' => $e->getMessage()
            ], 500);
        }
    }
} 