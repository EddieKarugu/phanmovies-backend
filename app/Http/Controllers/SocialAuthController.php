<?php

namespace App\Http\Controllers;

use App\Models\User;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class SocialAuthController extends Controller
{
    // ─────────────────────────────────────────────
    // GOOGLE
    // ─────────────────────────────────────────────

    public function handleGoogle(Request $request)
    {
        $request->validate(['server_auth_code' => 'required|string']);

        // 1. Exchange the server auth code for tokens
        $tokenResponse = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'code' => $request->server_auth_code,
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'redirect_uri' => 'postmessage',
            'grant_type' => 'authorization_code',
        ]);

        if ($tokenResponse->failed()) {
            return response()->json([
                'error' => 'Token exchange failed',
                'google_response' => $tokenResponse->json(),
            ], 401);
        }

        $idToken = $tokenResponse->json('id_token');
        if (! $idToken) {
            return response()->json(['error' => 'No ID token returned'], 401);
        }

        // 2. Verify the ID token
        $verifyResponse = Http::get('https://oauth2.googleapis.com/tokeninfo', [
            'id_token' => $idToken,
        ]);

        if ($verifyResponse->failed()) {
            return response()->json(['error' => 'Invalid ID token'], 401);
        }

        $payload = $verifyResponse->json();

        // 3. Confirm the token was issued for our app
        if (($payload['aud'] ?? null) !== config('services.google.client_id')) {
            return response()->json(['error' => 'Token audience mismatch'], 401);
        }

        // 4. Find or create the user
        $user = User::updateOrCreate(
            ['email' => $payload['email']],
            [
                'name' => $payload['name'] ?? 'Google User',
                'google_id' => $payload['sub'],
                'password' => Hash::make(Str::random(32)),
            ]
        );

        $token = $user->createToken('mobile')->plainTextToken;

        return response()->json([
            'user' => $user,
            'token' => $token,
        ]);
    }

    // ─────────────────────────────────────────────
    // APPLE
    // ─────────────────────────────────────────────

    public function handleApple(Request $request)
    {
        $request->validate([
            'identity_token' => 'required|string',
            'name' => 'nullable|string',
        ]);

        // 1. Fetch Apple's public keys (cached for 1 hour)
        $keys = Cache::remember('apple_public_keys', now()->addHour(), function () {
            $response = Http::get('https://appleid.apple.com/auth/keys');
            return $response->successful() ? $response->json() : null;
        });

        if (! $keys) {
            return response()->json(['error' => 'Could not fetch Apple keys'], 502);
        }

        // 2. Verify the identity token
        try {
            $decoded = JWT::decode(
                $request->identity_token,
                JWK::parseKeySet($keys)
            );
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'Invalid Apple token',
                'detail' => $e->getMessage(),
            ], 401);
        }

        $decoded = (array) $decoded;

        // 3. Verify the token audience
        if (($decoded['aud'] ?? null) !== config('services.apple.client_id')) {
            return response()->json(['error' => 'Token audience mismatch'], 401);
        }

        // 4. Verify the issuer
        if (($decoded['iss'] ?? null) !== 'https://appleid.apple.com') {
            return response()->json(['error' => 'Invalid issuer'], 401);
        }

        // 5. Email can be a private relay address
        $email = $decoded['email'] ?? null;
        if (! $email) {
            return response()->json(['error' => 'No email in token'], 401);
        }

        // Apple only sends the name on the FIRST sign-in. On subsequent
        // sign-ins, it's null. That's why we accept it as request input.
        $name = $request->input('name') ?: 'Apple User';

        $user = User::updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'apple_id' => $decoded['sub'],
                'password' => Hash::make(Str::random(32)),
            ]
        );

        $token = $user->createToken('mobile')->plainTextToken;

        return response()->json([
            'user' => $user,
            'token' => $token,
        ]);
    }
}