<?php
require_once dirname(__DIR__) . '/includes/helpers.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/models/TrainerInterval.php';

startSecureSession();
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

/** trainer_id = users.id */
function resolveTrainerUserId(array $auth, ?int $requestedUserId = null): int
{
    if ($auth['role'] === 'trainer') {
        return (int) $auth['id'];
    }

    if ($auth['role'] === 'admin' && $requestedUserId) {
        return $requestedUserId;
    }

  if ($auth['role'] === 'admin') {
        require_once dirname(__DIR__) . '/models/User.php';
        $trainers = (new User())->listTrainers();
        if (!empty($trainers)) {
            return (int) $trainers[0]['id'];
        }
        jsonResponse(['success' => false, 'error' => 'Nu exista antrenori in sistem.'], 404);
    }

    jsonResponse(['success' => false, 'error' => 'Acces interzis.'], 403);
}

function handleDbError(PDOException $e): void
{
    $msg = $e->getMessage();
    if (stripos($msg, 'trainer_intervals') !== false) {
        jsonResponse([
            'success' => false,
            'error' => 'Tabelul trainer_intervals lipseste. Importati database/mysql_migration_trainer_intervals.sql in phpMyAdmin.',
        ], 500);
    }
    jsonResponse(['success' => false, 'error' => 'Eroare baza de date: ' . $msg], 500);
}

$action = $_GET['action'] ?? '';
$intervalModel = new TrainerInterval();
$db = getDb();

switch ($action) {
    case 'save_intervals':
        $auth = requireRole(['trainer', 'admin']);
        $data = getJsonInput();
        $intervals = $data['intervals'] ?? [];
        if (!is_array($intervals)) {
            jsonResponse(['success' => false, 'error' => 'Format intervale invalid'], 400);
        }

        try {
            $trainerUserId = resolveTrainerUserId(
                $auth,
                !empty($data['trainer_id']) ? (int) $data['trainer_id'] : null
            );
            $saved = $intervalModel->saveForTrainer($trainerUserId, $intervals);
            logActivity($db, (int) $auth['id'], 'trainer_intervals_save', "Antrenor user #$trainerUserId, $saved intervale");
            jsonResponse(['success' => true, 'saved' => $saved, 'trainer_id' => $trainerUserId]);
        } catch (PDOException $e) {
            handleDbError($e);
        }
        break;

    case 'list':
        $auth = requireRole(['trainer', 'admin']);
        try {
            $trainerUserId = resolveTrainerUserId(
                $auth,
                !empty($_GET['trainer_id']) ? (int) $_GET['trainer_id'] : null
            );
            jsonResponse([
                'success' => true,
                'trainer_id' => $trainerUserId,
                'intervals' => $intervalModel->listByTrainer($trainerUserId),
            ]);
        } catch (PDOException $e) {
            handleDbError($e);
        }
        break;

    default:
        jsonResponse(['success' => false, 'error' => 'Actiune necunoscuta'], 400);
}
