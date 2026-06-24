<?php
require_once dirname(__DIR__) . '/includes/helpers.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/models/Session.php';
require_once dirname(__DIR__) . '/models/Subscription.php';
require_once dirname(__DIR__) . '/models/TrainerInterval.php';

startSecureSession();
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$action = $_GET['action'] ?? '';
$model = new SessionModel();
$db = getDb();

/** Admin: orice rezervare; trainer/member: doar rezervarile proprii (user_id). */
function canDeleteBooking(array $auth, int $bookingUserId): bool
{
    $role = (string) ($auth['role'] ?? '');
    $userId = (int) ($auth['id'] ?? 0);
    if ($bookingUserId <= 0 || $userId <= 0) {
        return false;
    }
    if ($role === 'admin') {
        return true;
    }
    return $userId === $bookingUserId;
}

/** users.id pentru antrenorul conectat (rol trainer). */
function getTrainerUserId(array $auth): ?int
{
    if (($auth['role'] ?? '') === 'trainer') {
        $id = (int) ($auth['id'] ?? 0);
        return $id > 0 ? $id : null;
    }
    return null;
}

/** Admin: orice sesiune; trainer: doar sesiunile proprii (trainer_id = users.id); member: nu. */
function canModifySession(array $auth, array $session): bool
{
    $role = (string) ($auth['role'] ?? '');
    if ($role === 'admin') {
        return true;
    }
    if ($role === 'trainer') {
        return (int) ($auth['id'] ?? 0) === (int) ($session['trainer_id'] ?? 0);
    }
    return false;
}

function denySessionModify(): void
{
    jsonResponse([
        'success' => false,
        'message' => 'Nu ai permisiunea să modifici această sesiune.',
    ], 403);
}

switch ($action) {
    case 'list':
        $auth = requireAuth();
        $sessions = $model->listAll(
            $_GET['type'] ?? null,
            $_GET['from'] ?? null,
            $_GET['to'] ?? null
        );
        foreach ($sessions as &$session) {
            $session['can_modify'] = canModifySession($auth, $session);
        }
        unset($session);
        jsonResponse([
            'success' => true,
            'sessions' => $sessions,
            'current_user_id' => (int) $auth['id'],
            'current_user_role' => $auth['role'],
            'current_trainer_id' => getTrainerUserId($auth),
        ]);
        break;

    case 'get':
        $auth = requireAuth();
        $session = $model->findById((int) ($_GET['id'] ?? 0));
        if (!$session) {
            jsonResponse(['success' => false, 'error' => 'Sedinta negasita'], 404);
        }
        $session['can_modify'] = canModifySession($auth, $session);
        jsonResponse([
            'success' => true,
            'session' => $session,
            'bookings' => $model->getBookings((int) $session['id']),
            'current_user_id' => (int) $auth['id'],
            'current_user_role' => $auth['role'],
            'current_trainer_id' => getTrainerUserId($auth),
        ]);
        break;

    case 'create':
        $auth = requireRole(['trainer', 'admin']);
        $data = getJsonInput();
        if ($auth['role'] === 'trainer') {
            $data['trainer_id'] = (int) $auth['id'];
        }
        if ($model->hasConflict(
            (int) $data['trainer_id'], (int) $data['room_id'],
            $data['start_time'], $data['end_time']
        )) {
            jsonResponse(['success' => false, 'error' => 'Conflict trainer/sala'], 409);
        }
        $data['created_by'] = $auth['id'];
        $id = $model->create($data);
        logActivity($db, $auth['id'], 'session_create', "Sedinta #$id");
        sendEmailLog($db, $auth['id'], 'Sedinta noua', 'Sedinta ' . ($data['title'] ?? '') . ' a fost creata.');
        jsonResponse(['success' => true, 'id' => $id]);
        break;

    case 'update':
        $auth = requireRole(['trainer', 'admin']);
        $id = (int) ($_GET['id'] ?? 0);
        $existing = $model->findById($id);
        if (!$existing) {
            jsonResponse(['success' => false, 'error' => 'Sedinta negasita'], 404);
        }
        if (!canModifySession($auth, $existing)) {
            denySessionModify();
        }
        $data = getJsonInput();
        if ($auth['role'] === 'trainer') {
            $data['trainer_id'] = (int) $existing['trainer_id'];
        }
        if ($model->hasConflict(
            (int) $data['trainer_id'], (int) $data['room_id'],
            $data['start_time'], $data['end_time'], $id
        )) {
            jsonResponse(['success' => false, 'error' => 'Conflict trainer/sala'], 409);
        }
        $model->update($id, $data);
        logActivity($db, $auth['id'], 'session_update', "Sedinta #$id");
        sendEmailLog($db, $auth['id'], 'Sedinta modificata', "Sedinta #$id a fost actualizata.");
        jsonResponse(['success' => true]);
        break;

    case 'cancel':
        $auth = requireRole(['trainer', 'admin']);
        $id = (int) ($_GET['id'] ?? 0);
        $session = $model->findById($id);
        if (!$session) {
            jsonResponse(['success' => false, 'error' => 'Sedinta negasita'], 404);
        }
        if (!canModifySession($auth, $session)) {
            denySessionModify();
        }
        $model->cancel($id);
        logActivity($db, $auth['id'], 'session_cancel', "Sedinta #$id");
        sendEmailLog($db, $auth['id'], 'Sedinta anulata', "Sedinta #$id a fost anulata.");
        jsonResponse(['success' => true]);
        break;

    case 'book':
        $auth = requireAuth();
        $sessionId = (int) ($_GET['id'] ?? getJsonInput()['session_id'] ?? 0);
        $result = $model->book($sessionId, $auth['id']);
        if (!$result['ok']) {
            jsonResponse(['success' => false, 'error' => $result['error']], 409);
        }
        logActivity($db, $auth['id'], 'session_book', "Sedinta #$sessionId");
        sendEmailLog($db, $auth['id'], 'Rezervare confirmata', "Rezervare sedinta #$sessionId");
        jsonResponse(['success' => true]);
        break;

    case 'book_with_subscription':
        $auth = requireAuth();
        $sessionId = (int) ($_GET['id'] ?? getJsonInput()['session_id'] ?? 0);
        $subModel = new Subscription();
        $subModel->expireOld();

        $session = $model->findById($sessionId);
        if (!$session) {
            jsonResponse(['success' => false, 'error' => 'Sedinta negasita'], 404);
        }

        $active = $subModel->findSubscriptionForBooking((int) $auth['id'], (string) $session['type']);
        if (!$active) {
            $total = $subModel->getTotalRemainingSessions((int) $auth['id']);
            if ($total <= 0) {
                jsonResponse(['success' => false, 'error' => 'Nu mai ai sedinte disponibile. Poti activa un abonament nou.'], 409);
            }
            jsonResponse(['success' => false, 'error' => 'Abonamentul tau nu acopera acest tip de sedinta.'], 409);
        }
        if ($model->hasBookingOnSameDay($auth['id'], $sessionId)) {
            jsonResponse(['success' => false, 'error' => 'Ai deja o rezervare in aceasta zi.'], 409);
        }
        if ($model->hasActiveBookingForSession($sessionId)) {
            $mine = $model->getSlotBookingStatus($sessionId, (int) $auth['id'], false);
            if ($mine['status'] === 'mine') {
                jsonResponse(['success' => false, 'error' => 'Deja inscris la aceasta sedinta'], 409);
            }
            jsonResponse(['success' => false, 'error' => 'Ora este deja rezervata de alt membru'], 409);
        }

        $result = $model->book($sessionId, $auth['id']);
        if (!$result['ok']) {
            jsonResponse(['success' => false, 'error' => $result['error']], 409);
        }

        $decremented = $subModel->decrementSessionByType((int) $active['id'], (string) $session['type']);
        if ($decremented === null) {
            $model->cancelBooking($sessionId, $auth['id']);
            jsonResponse(['success' => false, 'error' => 'Nu mai ai sedinte disponibile pentru acest tip.'], 409);
        }

        $breakdown = $subModel->getRemainingBreakdown((int) $auth['id']);
        $totalRemaining = $subModel->getTotalRemainingSessions((int) $auth['id']);
        logActivity($db, $auth['id'], 'session_book', "Sedinta #$sessionId (abonament)");
        sendEmailLog($db, $auth['id'], 'Rezervare confirmata', "Rezervare sedinta #$sessionId. Sedinte ramase total: $totalRemaining");
        jsonResponse([
            'success' => true,
            'message' => 'Rezervare confirmata.',
            'sessions_remaining' => $totalRemaining,
            'fitness_forta' => $breakdown['fitness_forta'],
            'kineto' => $breakdown['kineto'],
        ]);
        break;

    case 'get_intervals':
        $auth = requireAuth();
        $subModel = new Subscription();
        $subModel->expireOld();
        $intervalModel = new TrainerInterval();

        $weekStart = $_GET['week_start'] ?? date('Y-m-d', strtotime('monday this week'));
        $weekStart = date('Y-m-d', strtotime($weekStart));

        if (in_array($auth['role'], ['trainer', 'admin'], true)) {
            $allowedTypes = ['fitness', 'forta', 'kineto'];
            $message = null;
        } else {
            $allowedTypes = $subModel->getAllowedSessionTypes((int) $auth['id']);
            $message = empty($allowedTypes)
                ? 'Nu ai un abonament activ compatibil. Activeaza un abonament pentru a vedea intervalele.'
                : null;
        }

        if (empty($allowedTypes)) {
            jsonResponse([
                'success' => true,
                'message' => $message,
                'allowed_types' => [],
                'week_start' => $weekStart,
                'days' => [],
            ]);
        }

        $typeFilter = $_GET['type'] ?? 'all';
        if ($typeFilter && $typeFilter !== 'all' && in_array($typeFilter, ['fitness', 'forta', 'kineto'], true)) {
            $allowedTypes = array_values(array_intersect($allowedTypes, [$typeFilter]));
        }

        if (empty($allowedTypes)) {
            jsonResponse([
                'success' => true,
                'message' => $message,
                'allowed_types' => [],
                'filter_type' => $typeFilter,
                'week_start' => $weekStart,
                'days' => [],
            ]);
        }

        $rawIntervals = $intervalModel->listFiltered($allowedTypes);
        $isTrainerView = in_array($auth['role'], ['trainer', 'admin'], true);
        $days = $intervalModel->buildWeeklySchedule(
            $rawIntervals,
            $weekStart,
            $model,
            $isTrainerView ? (int) $auth['id'] : null,
            (int) $auth['id'],
            $isTrainerView
        );

        jsonResponse([
            'success' => true,
            'message' => $message,
            'allowed_types' => $allowedTypes,
            'filter_type' => $typeFilter ?? 'all',
            'week_start' => $weekStart,
            'current_user_id' => (int) $auth['id'],
            'current_user_role' => $auth['role'],
            'current_trainer_id' => getTrainerUserId($auth),
            'days' => $days,
        ]);
        break;

    case 'trainer_bookings':
        $auth = requireRole(['trainer', 'admin']);
        $date = $_GET['date'] ?? date('Y-m-d');
        $trainerUserId = null;
        if ($auth['role'] === 'trainer') {
            $trainerUserId = (int) $auth['id'];
        } elseif (!empty($_GET['trainer_id'])) {
            $trainerUserId = (int) $_GET['trainer_id'];
        }
        $bookings = $model->listBookingsForDate($date, $trainerUserId);
        foreach ($bookings as &$row) {
            $row['can_delete'] = canDeleteBooking($auth, (int) $row['user_id']);
        }
        unset($row);

        jsonResponse([
            'success' => true,
            'date' => $date,
            'current_user_id' => (int) $auth['id'],
            'current_user_role' => $auth['role'],
            'bookings' => $bookings,
        ]);
        break;

    case 'ensure_session':
        $auth = requireAuth();
        $data = getJsonInput();
        $trainerId = (int) ($data['trainer_id'] ?? 0);
        $type = $data['type'] ?? '';
        $date = $data['date'] ?? '';
        $time = $data['time'] ?? '';
        $capacity = 1;

        if (!$trainerId || !$type || !$date || !$time) {
            jsonResponse(['success' => false, 'error' => 'Date incomplete'], 400);
        }

        if ($auth['role'] === 'member') {
            $subModel = new Subscription();
            $allowed = $subModel->getAllowedSessionTypes((int) $auth['id']);
            if (!in_array($type, $allowed, true)) {
                jsonResponse(['success' => false, 'error' => 'Abonamentul tau nu permite acest tip de sedinta.'], 403);
            }
        }

        $sessionId = $model->findOrCreateSlotSession(
            $trainerId,
            $type,
            $date,
            $time,
            $capacity,
            null
        );
        jsonResponse(['success' => true, 'session_id' => $sessionId]);
        break;

    case 'delete_booking':
        $auth = requireAuth();
        $input = getJsonInput();
        $bookingId = (int) ($_GET['booking_id'] ?? $input['booking_id'] ?? 0);

        if ($bookingId <= 0) {
            jsonResponse(['success' => false, 'message' => 'ID rezervare invalid.'], 400);
        }

        $booking = $model->getBookingById($bookingId);
        if (!$booking || !$model->isBookingStatusActive((string) $booking['status'])) {
            jsonResponse(['success' => false, 'message' => 'Rezervare negasita.'], 404);
        }

        if (!canDeleteBooking($auth, (int) $booking['user_id'])) {
            jsonResponse([
                'success' => false,
                'message' => 'Nu ai permisiunea să ștergi această rezervare.',
            ], 403);
        }

        $model->cancelBookingById($bookingId);
        logActivity($db, $auth['id'], 'session_unbook', "Rezervare #$bookingId");
        jsonResponse(['success' => true]);
        break;

    case 'unbook':
        $auth = requireAuth();
        $input = getJsonInput();
        $bookingId = (int) ($_GET['booking_id'] ?? $input['booking_id'] ?? 0);
        $sessionId = (int) ($_GET['id'] ?? $input['session_id'] ?? 0);

        if ($bookingId > 0) {
            $booking = $model->getBookingById($bookingId);
            if (!$booking || !$model->isBookingStatusActive((string) $booking['status'])) {
                jsonResponse(['success' => false, 'message' => 'Rezervare negasita.'], 404);
            }
            if (!canDeleteBooking($auth, (int) $booking['user_id'])) {
                jsonResponse([
                    'success' => false,
                    'message' => 'Nu ai permisiunea să ștergi această rezervare.',
                ], 403);
            }
            $model->cancelBookingById($bookingId);
            logActivity($db, $auth['id'], 'session_unbook', "Rezervare #$bookingId");
            jsonResponse(['success' => true]);
        }

        if ($sessionId <= 0) {
            jsonResponse(['success' => false, 'message' => 'ID rezervare invalid.'], 400);
        }

        if ($auth['role'] === 'admin') {
            $booking = $model->getActiveBookingForSession($sessionId);
        } else {
            $booking = $model->getActiveBookingForSessionUser($sessionId, (int) $auth['id']);
        }

        if (!$booking) {
            jsonResponse(['success' => false, 'message' => 'Rezervare negasita.'], 404);
        }
        if (!canDeleteBooking($auth, (int) $booking['user_id'])) {
            jsonResponse([
                'success' => false,
                'message' => 'Nu ai permisiunea să ștergi această rezervare.',
            ], 403);
        }

        $model->cancelBookingById((int) $booking['id']);
        logActivity($db, $auth['id'], 'session_unbook', "Sedinta #$sessionId / rezervare #{$booking['id']}");
        jsonResponse(['success' => true]);
        break;

    default:
        jsonResponse(['success' => false, 'error' => 'Actiune necunoscuta'], 400);
}
