<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Admin\Services\AdminAuthService;
use App\Support\Request;
use App\Support\Response;

class AdminAuthController
{
    public function __construct(private AdminAuthService $auth)
    {
    }

    public function login(Request $request): array
    {
        $username = trim((string) ($request->body['username'] ?? ''));
        $password = (string) ($request->body['password'] ?? '');
        if ($username === '' || $password === '') {
            return Response::json(['error' => 'admin_credentials_required'], 422);
        }
        if (!$this->auth->hasUsers()) {
            return Response::json(['error' => 'admin_user_not_configured'], 503);
        }

        $session = $this->auth->login($username, $password);
        if ($session === null) {
            return Response::json(['error' => 'admin_invalid_credentials'], 401);
        }

        return Response::json(['data' => $session]);
    }

    public function me(?string $token): array
    {
        $user = $this->auth->userForToken($token);
        if ($user === null) {
            return Response::json(['error' => 'admin_session_required'], 401);
        }
        return Response::json(['data' => ['user' => $user]]);
    }

    public function logout(?string $token): array
    {
        $this->auth->revokeToken($token);
        return Response::json(['ok' => true]);
    }
}
