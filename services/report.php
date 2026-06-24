<?php
require_once dirname(__DIR__) . '/includes/helpers.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/models/User.php';
require_once dirname(__DIR__) . '/models/Session.php';
require_once dirname(__DIR__) . '/models/Trainer.php';
require_once dirname(__DIR__) . '/models/Subscription.php';

startSecureSession();
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

requireRole(['admin', 'trainer']);
$action = $_GET['action'] ?? 'dashboard';
$db = getDb();
$sessionModel = new SessionModel();
$trainerModel = new Trainer();
$subModel = new Subscription();

$reportsDir = dirname(__DIR__) . '/reports';
if (!is_dir($reportsDir)) {
    mkdir($reportsDir, 0755, true);
}

function reportPublicPath(string $filename): string
{
    return rtrim(getAppBasePath(), '/') . '/reports/' . ltrim($filename, '/');
}

function generateChart(array $labels, array $values, string $title, string $path, string $format = 'png'): bool
{
    if (!extension_loaded('gd')) {
        return false;
    }

    $labels = array_values($labels);
    $values = array_map('intval', array_values($values));
    $count = count($values);

    $w = max(600, 80 + $count * 70);
    $h = 400;
    $img = imagecreatetruecolor($w, $h);
    $white = imagecolorallocate($img, 255, 255, 255);
    $blue = imagecolorallocate($img, 41, 128, 185);
    $green = imagecolorallocate($img, 39, 174, 96);
    $gray = imagecolorallocate($img, 200, 200, 200);
    $black = imagecolorallocate($img, 30, 30, 30);
    imagefill($img, 0, 0, $white);
    imagestring($img, 5, 20, 10, $title, $black);

    if ($count === 0) {
        imagestring($img, 4, 20, (int) ($h / 2), 'Nu exista date', $black);
        $ok = $format === 'webp' && function_exists('imagewebp')
            ? imagewebp($img, $path, 80)
            : imagepng($img, $path);
        imagedestroy($img);
        return $ok;
    }

    $max = max(1, max($values));
    $barW = 40;
    $gap = max(50, min(90, (int) (($w - 80) / $count)));
    $baseY = $h - 60;
    $x = 50;

    foreach ($values as $i => $val) {
        $barH = (int) (($val / $max) * ($h - 120));
        $color = $i % 2 === 0 ? $blue : $green;
        imagefilledrectangle($img, $x, $baseY - $barH, $x + $barW, $baseY, $color);
        imagestring($img, 3, $x, $baseY + 5, substr($labels[$i] ?? '', 0, 10), $black);
        imagestring($img, 2, $x, $baseY - $barH - 15, (string) $val, $black);
        $x += $gap;
    }

    imageline($img, 40, $baseY, $w - 20, $baseY, $gray);

    $ok = $format === 'webp' && function_exists('imagewebp')
        ? imagewebp($img, $path, 80)
        : imagepng($img, $path);
    imagedestroy($img);
    return $ok;
}

function buildReportData(PDO $db, SessionModel $sessionModel, Trainer $trainerModel, Subscription $subModel): array
{
    $activeUsers = (int) $db->query('SELECT COUNT(*) FROM users WHERE is_active = 1')->fetchColumn();
    $subscriptionStats = $subModel->getStats();

    return [
        'active_users' => $activeUsers,
        'sessions_day' => $sessionModel->countByPeriod('day'),
        'sessions_week' => $sessionModel->countByPeriod('week'),
        'sessions_month' => $sessionModel->countByPeriod('month'),
        'bookings_day' => $sessionModel->countBookingsByPeriod('day'),
        'bookings_week' => $sessionModel->countBookingsByPeriod('week'),
        'bookings_month' => $sessionModel->countBookingsByPeriod('month'),
        'top_trainers' => $trainerModel->topBySessions(10),
        'subscription_stats' => $subscriptionStats,
        'active_users_list' => $db->query(
            "SELECT u.id, u.email, u.role, COALESCE(p.full_name, '') AS full_name
             FROM users u
             LEFT JOIN user_profiles p ON p.user_id = u.id
             WHERE u.is_active = 1
             ORDER BY u.id"
        )->fetchAll(),
    ];
}

function saveChartPair(array $labels, array $values, string $title, string $basename, string $reportsDir): array
{
    $pngPath = $reportsDir . '/' . $basename . '.png';
    $webpPath = $reportsDir . '/' . $basename . '.webp';
    generateChart($labels, $values, $title, $pngPath, 'png');
    generateChart($labels, $values, $title, $webpPath, 'webp');
    return [
        'png' => reportPublicPath($basename . '.png'),
        'webp' => reportPublicPath($basename . '.webp'),
    ];
}

function exportSubscriptionsData(array $stats, string $format): void
{
    $rows = [
        ['active', $stats['active']],
        ['suspended', $stats['suspended']],
        ['expired', $stats['expired']],
    ];
    foreach ($stats['by_type'] ?? [] as $row) {
        $rows[] = ['type_' . $row['name'], $row['cnt']];
    }

    if ($format === 'xml') {
        $xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><subscriptions/>');
        $summary = $xml->addChild('summary');
        $summary->addChild('active', (string) $stats['active']);
        $summary->addChild('suspended', (string) $stats['suspended']);
        $summary->addChild('expired', (string) $stats['expired']);
        $types = $xml->addChild('by_type');
        foreach ($stats['by_type'] ?? [] as $row) {
            $item = $types->addChild('type');
            $item->addChild('name', (string) $row['name']);
            $item->addChild('count', (string) $row['cnt']);
        }
        exportXmlRaw($xml->asXML(), 'subscriptions.xml');
    }

    exportCsv(['metric', 'value'], $rows, 'subscriptions.csv');
}

switch ($action) {
    case 'dashboard':
        $data = buildReportData($db, $sessionModel, $trainerModel, $subModel);
        jsonResponse(['success' => true] + $data);
        break;

    case 'chart':
        $chart = $_GET['chart'] ?? 'trainers';
        $data = buildReportData($db, $sessionModel, $trainerModel, $subModel);

        if ($chart === 'bookings') {
            $paths = saveChartPair(
                ['Zi', 'Sapt', 'Luna'],
                [$data['bookings_day'], $data['bookings_week'], $data['bookings_month']],
                'Rezervari sedinte',
                'bookings_chart',
                $reportsDir
            );
        } elseif ($chart === 'subscriptions') {
            $byType = $data['subscription_stats']['by_type'] ?? [];
            $paths = saveChartPair(
                array_column($byType, 'name'),
                array_map('intval', array_column($byType, 'cnt')),
                'Abonamente active pe tip',
                'subscriptions_chart',
                $reportsDir
            );
        } else {
            $top = $data['top_trainers'];
            $paths = saveChartPair(
                array_column($top, 'full_name'),
                array_map('intval', array_column($top, 'session_count')),
                'Top antrenori / terapeuti',
                'trainers_chart',
                $reportsDir
            );
        }

        jsonResponse(['success' => true, 'chart' => $chart] + $paths);
        break;

    case 'charts':
        $data = buildReportData($db, $sessionModel, $trainerModel, $subModel);
        $top = $data['top_trainers'];
        $byType = $data['subscription_stats']['by_type'] ?? [];

        jsonResponse([
            'success' => true,
            'trainers' => saveChartPair(
                array_column($top, 'full_name'),
                array_map('intval', array_column($top, 'session_count')),
                'Top antrenori / terapeuti',
                'trainers_chart',
                $reportsDir
            ),
            'bookings' => saveChartPair(
                ['Zi', 'Sapt', 'Luna'],
                [$data['bookings_day'], $data['bookings_week'], $data['bookings_month']],
                'Rezervari sedinte',
                'bookings_chart',
                $reportsDir
            ),
            'subscriptions' => saveChartPair(
                array_column($byType, 'name'),
                array_map('intval', array_column($byType, 'cnt')),
                'Abonamente active pe tip',
                'subscriptions_chart',
                $reportsDir
            ),
        ]);
        break;

    case 'export':
        $format = $_GET['format'] ?? 'csv';
        $type = $_GET['type'] ?? 'summary';
        $data = buildReportData($db, $sessionModel, $trainerModel, $subModel);

        if ($type === 'summary') {
            $rows = [
                ['active_users', $data['active_users']],
                ['sessions_day', $data['sessions_day']],
                ['sessions_week', $data['sessions_week']],
                ['sessions_month', $data['sessions_month']],
                ['bookings_day', $data['bookings_day']],
                ['bookings_week', $data['bookings_week']],
                ['bookings_month', $data['bookings_month']],
                ['subscriptions_active', $data['subscription_stats']['active']],
                ['subscriptions_suspended', $data['subscription_stats']['suspended']],
                ['subscriptions_expired', $data['subscription_stats']['expired']],
            ];
            foreach ($data['subscription_stats']['by_type'] ?? [] as $row) {
                $rows[] = ['subscription_type_' . $row['name'], $row['cnt']];
            }
            foreach ($data['top_trainers'] as $trainer) {
                $rows[] = ['trainer_' . $trainer['full_name'], $trainer['session_count']];
            }

            if ($format === 'xml') {
                $xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><report/>');
                $xml->addChild('active_users', (string) $data['active_users']);

                $sessions = $xml->addChild('sessions');
                $sessions->addChild('day', (string) $data['sessions_day']);
                $sessions->addChild('week', (string) $data['sessions_week']);
                $sessions->addChild('month', (string) $data['sessions_month']);

                $bookings = $xml->addChild('bookings');
                $bookings->addChild('day', (string) $data['bookings_day']);
                $bookings->addChild('week', (string) $data['bookings_week']);
                $bookings->addChild('month', (string) $data['bookings_month']);

                $subs = $xml->addChild('subscriptions');
                $subs->addChild('active', (string) $data['subscription_stats']['active']);
                $subs->addChild('suspended', (string) $data['subscription_stats']['suspended']);
                $subs->addChild('expired', (string) $data['subscription_stats']['expired']);
                $types = $subs->addChild('by_type');
                foreach ($data['subscription_stats']['by_type'] ?? [] as $row) {
                    $item = $types->addChild('type');
                    $item->addChild('name', (string) $row['name']);
                    $item->addChild('count', (string) $row['cnt']);
                }

                $trainers = $xml->addChild('top_trainers');
                foreach ($data['top_trainers'] as $trainer) {
                    $item = $trainers->addChild('trainer');
                    $item->addChild('name', (string) $trainer['full_name']);
                    $item->addChild('session_count', (string) $trainer['session_count']);
                }

                exportXmlRaw($xml->asXML(), 'report_summary.xml');
            }

            exportCsv(['metric', 'value'], $rows, 'report_summary.csv');
        }

        if ($type === 'users') {
            $rows = array_map(static fn($r) => [
                $r['id'], $r['full_name'], $r['email'], $r['role'],
            ], $data['active_users_list']);
            if ($format === 'xml') {
                exportXml('users', array_map(static fn($r) => [
                    'id' => $r['id'],
                    'full_name' => $r['full_name'],
                    'email' => $r['email'],
                    'role' => $r['role'],
                ], $data['active_users_list']), 'users.xml');
            }
            exportCsv(['id', 'full_name', 'email', 'role'], $rows, 'users.csv');
        }

        if ($type === 'bookings') {
            $rows = [
                ['day', $data['bookings_day']],
                ['week', $data['bookings_week']],
                ['month', $data['bookings_month']],
            ];
            if ($format === 'xml') {
                $xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><bookings/>');
                foreach ($rows as [$period, $count]) {
                    $node = $xml->addChild('period');
                    $node->addChild('name', $period);
                    $node->addChild('count', (string) $count);
                }
                exportXmlRaw($xml->asXML(), 'bookings.xml');
            }
            exportCsv(['period', 'count'], $rows, 'bookings.csv');
        }

        if ($type === 'trainers') {
            $rows = array_map(static fn($r) => [$r['full_name'], $r['session_count']], $data['top_trainers']);
            if ($format === 'xml') {
                exportXml('trainers', array_map(static fn($r) => [
                    'full_name' => $r['full_name'],
                    'session_count' => $r['session_count'],
                ], $data['top_trainers']), 'trainers.xml');
            }
            exportCsv(['full_name', 'session_count'], $rows, 'trainers.csv');
        }

        if ($type === 'subscriptions') {
            exportSubscriptionsData($data['subscription_stats'], $format);
        }

        if ($type === 'sessions') {
            $sessionRows = $sessionModel->listAll();
            $headers = ['id', 'title', 'type', 'trainer_name', 'room_name', 'start_time', 'status'];
            $csvRows = array_map(static fn($r) => [
                $r['id'], $r['title'], $r['type'], $r['trainer_name'],
                $r['room_name'], $r['start_time'], $r['status'],
            ], $sessionRows);
            if ($format === 'xml') {
                exportXml('sessions', array_map(static fn($r) => [
                    'id' => $r['id'],
                    'title' => $r['title'],
                    'type' => $r['type'],
                    'trainer_name' => $r['trainer_name'],
                    'room_name' => $r['room_name'],
                    'start_time' => $r['start_time'],
                    'status' => $r['status'],
                ], $sessionRows), 'sessions.xml');
            }
            exportCsv($headers, $csvRows, 'sessions.csv');
        }

        jsonResponse(['success' => false, 'error' => 'Tip export necunoscut'], 400);
        break;

    default:
        jsonResponse(['success' => false, 'error' => 'Actiune necunoscuta'], 400);
}
