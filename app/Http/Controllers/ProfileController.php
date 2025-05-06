<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use App\Models\User;

class ProfileController extends Controller
{
    /**
     * Obter os dados do perfil do usuário logado
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getProfile()
    {
        $user = Auth::user();
        
        // Adicionar URL da foto de perfil se existir
        if ($user->profile_photo) {
            $user->profile_photo_url = url('storage/' . $user->profile_photo);
        }
        
        // Adicionar roles do usuário
        $roles = $user->getRoleNames();
        $user->roles = $roles;
        
        return response()->json([
            'success' => true,
            'data' => $user
        ]);
    }
    
    /**
     * Atualizar o perfil do usuário logado
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateProfile(Request $request)
    {
        $user = Auth::user();
        
        // Validação dos dados
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email,'.$user->id,
            'phone' => 'nullable|string|max:20',
            'profile_photo' => 'nullable|image|max:2048',
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Dados inválidos',
                'errors' => $validator->errors()
            ], 422);
        }
        
        // Atualizar dados básicos
        $user->name = $request->name;
        $user->email = $request->email;
        $user->phone = $request->phone;
        
        // Upload da foto de perfil
        if ($request->hasFile('profile_photo')) {
            // Remover foto anterior se existir
            if ($user->profile_photo) {
                Storage::disk('public')->delete($user->profile_photo);
            }
            
            // Salvar nova foto
            $path = $request->file('profile_photo')->store('profile-photos', 'public');
            $user->profile_photo = $path;
        }
        
        $user->save();
        
        // Adicionar URL da foto após salvar
        if ($user->profile_photo) {
            $user->profile_photo_url = url('storage/' . $user->profile_photo);
        }
        
        return response()->json([
            'success' => true,
            'message' => 'Perfil atualizado com sucesso',
            'data' => $user
        ]);
    }
    
    /**
     * Alterar a senha do usuário logado
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function changePassword(Request $request)
    {
        $user = Auth::user();
        
        // Validação dos dados
        $validator = Validator::make($request->all(), [
            'current_password' => 'required|string',
            'password' => 'required|string|min:8|confirmed',
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Dados inválidos',
                'errors' => $validator->errors()
            ], 422);
        }
        
        // Verificar senha atual
        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Senha atual incorreta'
            ], 400);
        }
        
        // Atualizar senha
        $user->password = Hash::make($request->password);
        $user->save();
        
        return response()->json([
            'success' => true,
            'message' => 'Senha alterada com sucesso'
        ]);
    }
} 