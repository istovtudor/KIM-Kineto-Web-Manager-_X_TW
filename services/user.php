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

$action = $_GET['action'] ?? '';
$userModel = new User();
$db = getDb();

switch ($action) {
    case 'register':
        $data = getJsonInput();
        $email = filter_var($data['email'] ?? '', FILTER_VALIDATE_EMAIL);
        $password = $data['password'] ?? '';
        $fullName = trim($data['full_name'] ?? '');
        if (!$email || strlen($password) < 6 || $fullName === '') {
            jsonResponse(['success' => false, 'error' => 'Date invalide'], 400);
        }
        if ($userModel->findByEmail($email)) {
            jsonResponse(['success' => false, 'error' => 'Email deja inregistrat'], 409);
        }
        $id = $userModel->create($email, $password, 'member', $fullName, $data['phone'] ?? null);
        logActivity($db, $id, 'register', 'Cont nou creat');
        jsonResponse(['success' => true, 'user_id' => $id]);
        break;

    case 'login':
        $data = getJsonInput();
        $user = $userModel->findByEmail($data['email'] ?? '');
        if (!$user || !$userModel->verifyPassword($data['password'] ?? '', $user['password_hash'])) {
            jsonResponse(['success' => false, 'error' => 'Credentiale invalide'], 401);
        }
        $profile = $userModel->findById((int) $user['id']);
        $_SESSION['user'] = [
            'id' => (int) $user['id'],
            'email' => $user['email'],
            'role' => $user['role'],
            'full_name' => $profile['full_name'] ?? '',
        ];
        logActivity($db, (int) $user['id'], 'login');
        jsonResponse(['success' => true, 'user' => $_SESSION['user']]);
        break;

    case 'logout':
        $auth = requireAuth();
        logActivity($db, $auth['id'], 'logout');
        session_destroy();
        jsonResponse(['success' => true]);
        break;

    case 'me':
        $auth = requireAuth();
        $profile = $userModel->findById($auth['id']);
        jsonResponse(['success' => true, 'user' => $profile]);
        break;

    case 'profile':
        $auth = requireAuth();
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $data = getJsonInput();
            $userModel->updateProfile($auth['id'], $data);
            logActivity($db, $auth['id'], 'profile_update');
            jsonResponse(['success' => true]);
        }
        jsonResponse(['success' => true, 'user' => $userModel->findById($auth['id'])]);
        break;

    case 'activity':
        $auth = requireAuth();
        jsonResponse([
            'success' => true,
            'activity' => $userModel->getActivity(
                (int) $auth['id'],
                50,
                (string) (($userModel->findById((int) $auth['id'])['role'] ?? $auth['role'] ?? 'member'))
            ),
        ]);
        break;

    case 'list':
        requireRole(['admin']);
        jsonResponse(['success' => true, 'users' => $userModel->listAll()]);
        break;

    default:
        jsonResponse(['success' => false, 'error' => 'Actiune necunoscuta'], 400);
}
