<?php
require_once dirname(__DIR__) . '/includes/helpers.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/models/Subscription.php';

startSecureSession();
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$action = $_GET['action'] ?? '';
$model = new Subscription();
$db = getDb();
$model->expireOld();

switch ($action) {
    case 'types':
        jsonResponse(['success' => true, 'types' => $model->listTypes()]);
        break;

    case 'type_create':
        requireRole(['admin']);
        $data = getJsonInput();
        $id = $model->createType($data);
        jsonResponse(['success' => true, 'id' => $id]);
        break;

    case 'type_update':
        requireRole(['admin']);
        $id = (int) ($_GET['id'] ?? 0);
        $model->updateType($id, getJsonInput());
        jsonResponse(['success' => true]);
        break;

    case 'type_delete':
        requireRole(['admin']);
        $model->deleteType((int) ($_GET['id'] ?? 0));
        jsonResponse(['success' => true]);
        break;

    case 'activate':
        $auth = requireAuth();
        $data = getJsonInput();
        $userId = (int) ($data['user_id'] ?? $auth['id']);
        if ($userId !== $auth['id'] && $auth['role'] !== 'admin') {
            jsonResponse(['success' => false, 'error' => 'Acces interzis'], 403);
        }
        $id = $model->activateForUser($userId, (int) ($data['type_id'] ?? 0));
        if (!$id['id']) {
            jsonResponse(['success' => false, 'error' => 'Tip abonament invalid'], 400);
        }
        logActivity($db, $userId, 'subscription_activate', "Abonament #{$id['id']}");
        $breakdown = $model->getRemainingBreakdown($userId);
        jsonResponse([
            'success' => true,
            'id' => $id['id'],
            'sessions_remaining' => $id['sessions_remaining'],
            'fitness_forta' => $breakdown['fitness_forta'],
            'kineto' => $breakdown['kineto'],
            'allowed_types' => $id['allowed_types'],
            'total_remaining' => $model->getTotalRemainingSessions($userId),
        ]);
        break;

    case 'get_remaining':
        $auth = requireAuth();
        $breakdown = $model->getRemainingBreakdown($auth['id']);
        jsonResponse([
            'success' => true,
            'fitness_forta' => $breakdown['fitness_forta'],
            'kineto' => $breakdown['kineto'],
            'sessions_remaining' => $model->getTotalRemainingSessions($auth['id']),
            'total_remaining' => $model->getTotalRemainingSessions($auth['id']),
            'has_active' => !empty($model->getActiveSubscriptions($auth['id'])),
            'allowed_types' => $model->getAllowedSessionTypes($auth['id']),
            'active_subscriptions' => $model->getActiveSubscriptions($auth['id']),
        ]);
        break;

    case 'suspend':
        requireRole(['admin']);
        $model->updateStatus((int) ($_GET['id'] ?? 0), 'suspended');
        jsonResponse(['success' => true]);
        break;

    case 'expire':
        requireRole(['admin']);
        $model->updateStatus((int) ($_GET['id'] ?? 0), 'expired');
        jsonResponse(['success' => true]);
        break;

    case 'history':
        $auth = requireAuth();
        $userId = (int) ($_GET['user_id'] ?? $auth['id']);
        if ($userId !== $auth['id'] && $auth['role'] !== 'admin') {
            jsonResponse(['success' => false, 'error' => 'Acces interzis'], 403);
        }
        jsonResponse(['success' => true, 'subscriptions' => $model->getUserHistory($userId)]);
        break;

    case 'stats':
        requireRole(['admin', 'trainer']);
        jsonResponse(['success' => true, 'stats' => $model->getStats()]);
        break;

    default:
        jsonResponse(['success' => false, 'error' => 'Actiune necunoscuta'], 400);
}
