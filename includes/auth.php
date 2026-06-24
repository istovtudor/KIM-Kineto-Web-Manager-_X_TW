<?php
require_once dirname(__DIR__) . '/config/database.php';

function getAppBasePath(): string
{
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    if (preg_match('#^(.*)/services/#', $script, $m)) {
        return $m[1] !== '' ? $m[1] : '/';
    }
    if (preg_match('#^(.*)/public/#', $script, $m)) {
        return $m[1] !== '' ? $m[1] : '/';
    }
    return '/';
}

function startSecureSession(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        $cookiePath = getAppBasePath();
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => $cookiePath,
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        ]);
        ini_set('session.use_strict_mode', '1');
        session_start();
    }
}

function requireAuth(): array
{
    startSecureSession();
    if (empty($_SESSION['user'])) {
        jsonResponse(['success' => false, 'error' => 'Neautentificat'], 401);
    }
    return $_SESSION['user'];
}

function requireRole(array $roles): array
{
    $user = requireAuth();
    if (!in_array($user['role'], $roles, true)) {
        jsonResponse(['success' => false, 'error' => 'Acces interzis'], 403);
    }
    return $user;
}
