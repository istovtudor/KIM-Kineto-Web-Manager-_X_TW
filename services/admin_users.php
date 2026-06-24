<?php
require_once dirname(__DIR__) . '/includes/helpers.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/models/User.php';

startSecureSession();
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

function requireAdminUser(): array
{
    $auth = requireAuth();
    if (($auth['role'] ?? '') !== 'admin') {
        jsonResponse(['success' => false, 'message' => 'Acces interzis.'], 403);
    }
    return $auth;
}

$action = $_GET['action'] ?? '';
$userModel = new User();
$db = getDb();

switch ($action) {
    case 'list':
        requireAdminUser();
        jsonResponse([
            'success' => true,
            'users' => $userModel->listAllForAdmin(),
        ]);
        break;

    case 'update_role':
        $auth = requireAuth();
        if (($auth['role'] ?? '') !== 'admin') {
            jsonResponse([
                'success' => false,
                'message' => 'Nu ai permisiunea să modifici rolurile.',
            ], 403);
        }

        $data = getJsonInput();
        $userId = (int) ($data['user_id'] ?? 0);
        $isTrainer = (int) ($data['is_trainer'] ?? 0) === 1;

        if ($userId <= 0) {
            jsonResponse(['success' => false, 'message' => 'ID utilizator invalid.'], 400);
        }

        $result = $userModel->setTrainerRole($userId, $isTrainer);
        if (!$result['ok']) {
            jsonResponse(['success' => false, 'message' => $result['error'] ?? 'Eroare actualizare rol'], 400);
        }

        logActivity($db, $auth['id'], 'admin_update_role', "User #$userId -> " . ($isTrainer ? 'trainer' : 'member'));
        jsonResponse(['success' => true]);
        break;

    default:
        jsonResponse(['success' => false, 'message' => 'Actiune necunoscuta'], 400);
}
