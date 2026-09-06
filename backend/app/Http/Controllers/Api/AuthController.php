<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $peticion): JsonResponse
    {
        $datos = $peticion->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $usuario = User::query()->where('email', $datos['email'])->first();

        if ($usuario === null || ! Hash::check($datos['password'], $usuario->password)) {
            throw ValidationException::withMessages([
                'email' => 'El correo o la contraseña no coinciden.',
            ]);
        }

        return response()->json([
            'token' => $usuario->createToken('app-movil')->plainTextToken,
            'usuario' => ['id' => $usuario->id, 'nombre' => $usuario->name, 'email' => $usuario->email],
        ]);
    }

    public function logout(Request $peticion): JsonResponse
    {
        $peticion->user()->currentAccessToken()->delete();

        return response()->json(['mensaje' => 'Sesión cerrada.']);
    }

    public function yo(Request $peticion): JsonResponse
    {
        $usuario = $peticion->user();

        return response()->json(['id' => $usuario->id, 'nombre' => $usuario->name, 'email' => $usuario->email]);
    }
}
