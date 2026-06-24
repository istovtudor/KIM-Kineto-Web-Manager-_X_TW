<?php
require_once dirname(__DIR__) . '/includes/helpers.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/models/Trainer.php';
require_once dirname(__DIR__) . '/models/Room.php';

startSecureSession();
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$action = $_GET['action'] ?? '';
$entity = $_GET['entity'] ?? 'trainers';
$trainerModel = new Trainer();
$roomModel = new Room();

function requireResourcesAdmin(): array
{
    $auth = requireAuth();
    if (($auth['role'] ?? '') !== 'admin') {
        jsonResponse(['success' => false, 'message' => 'Acces interzis.'], 403);
    }
    return $auth;
}

switch ($action) {
    case 'list_rooms':
        requireResourcesAdmin();
        jsonResponse(['success' => true, 'rooms' => $roomModel->listRoomsFormatted()]);
        break;

    case 'list_equipment':
        requireResourcesAdmin();
        jsonResponse(['success' => true, 'equipment' => $roomModel->listEquipmentFormatted()]);
        break;

    case 'add_room':
        requireResourcesAdmin();
        $data = getJsonInput();
        $name = trim((string) ($data['name'] ?? ''));
        $capacity = (int) ($data['capacity'] ?? 10);
        $available = !empty($data['available']);
        if ($name === '') {
            jsonResponse(['success' => false, 'message' => 'Numele salii este obligatoriu.'], 400);
        }
        if ($capacity < 1) {
            jsonResponse(['success' => false, 'message' => 'Capacitatea trebuie sa fie cel putin 1.'], 400);
        }
        $id = $roomModel->addRoom($name, $capacity, $available);
        jsonResponse(['success' => true, 'id' => $id]);
        break;

    case 'add_equipment':
        requireResourcesAdmin();
        $data = getJsonInput();
        $name = trim((string) ($data['name'] ?? ''));
        $status = (string) ($data['status'] ?? 'bun');
        $roomId = isset($data['room_id']) && $data['room_id'] !== '' ? (int) $data['room_id'] : null;
        if ($name === '') {
            jsonResponse(['success' => false, 'message' => 'Numele echipamentului este obligatoriu.'], 400);
        }
        $id = $roomModel->addEquipment($name, $status, $roomId);
        jsonResponse(['success' => true, 'id' => $id]);
        break;

    case 'list':
        if ($entity === 'trainers') {
            jsonResponse(['success' => true, 'data' => $trainerModel->listAll()]);
        }
        if ($entity === 'trainer_users') {
            require_once dirname(__DIR__) . '/models/User.php';
            jsonResponse(['success' => true, 'data' => (new User())->listTrainers()]);
        }
        if ($entity === 'rooms') {
            jsonResponse(['success' => true, 'data' => $roomModel->listAll()]);
        }
        if ($entity === 'equipment') {
            jsonResponse(['success' => true, 'data' => $roomModel->listEquipment()]);
        }
        jsonResponse(['success' => false, 'error' => 'Entitate necunoscuta'], 400);
        break;

    case 'create':
        requireRole(['admin']);
        $data = getJsonInput();
        if ($entity === 'trainers') {
            jsonResponse(['success' => true, 'id' => $trainerModel->create($data)]);
        }
        if ($entity === 'rooms') {
            jsonResponse(['success' => true, 'id' => $roomModel->create($data)]);
        }
        if ($entity === 'equipment') {
            jsonResponse(['success' => true, 'id' => $roomModel->createEquipment($data)]);
        }
        jsonResponse(['success' => false, 'error' => 'Entitate necunoscuta'], 400);
        break;

    case 'update':
        requireRole(['admin']);
        $id = (int) ($_GET['id'] ?? 0);
        $data = getJsonInput();
        if ($entity === 'trainers') {
            $trainerModel->update($id, $data);
        } elseif ($entity === 'rooms') {
            $roomModel->update($id, $data);
        } elseif ($entity === 'equipment') {
            $roomModel->updateEquipment($id, $data);
        }
        jsonResponse(['success' => true]);
        break;

    case 'delete':
        requireRole(['admin']);
        $id = (int) ($_GET['id'] ?? 0);
        if ($entity === 'trainers') {
            $trainerModel->delete($id);
        } elseif ($entity === 'rooms') {
            $roomModel->delete($id);
        } elseif ($entity === 'equipment') {
            $roomModel->deleteEquipment($id);
        }
        jsonResponse(['success' => true]);
        break;

    default:
        jsonResponse(['success' => false, 'error' => 'Actiune necunoscuta'], 400);
}
