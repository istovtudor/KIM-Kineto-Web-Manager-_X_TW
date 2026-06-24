<?php
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/report_charts.php';
require_once dirname(__DIR__) . '/models/Session.php';
require_once dirname(__DIR__) . '/models/Trainer.php';
require_once dirname(__DIR__) . '/models/Subscription.php';

$db = getDb();
$sessionModel = new SessionModel();
$trainerModel = new Trainer();
$subModel = new Subscription();

$activeUsers = (int) $db->query('SELECT COUNT(*) FROM users WHERE is_active = 1')->fetchColumn();
$top = $trainerModel->topBySessions(10);
$byType = $subModel->getStats()['by_type'] ?? [];

$charts = [
    'trainers' => saveChartPair(
        array_column($top, 'full_name'),
        array_map('intval', array_column($top, 'session_count')),
        'Top antrenori',
        'trainers_chart'
    ),
    'bookings' => saveChartPair(
        ['Zi', 'Sapt', 'Luna'],
        [
            $sessionModel->countBookingsByPeriod('day'),
            $sessionModel->countBookingsByPeriod('week'),
            $sessionModel->countBookingsByPeriod('month'),
        ],
        'Rezervari',
        'bookings_chart'
    ),
    'subscriptions' => saveChartPair(
        array_column($byType, 'name'),
        array_map('intval', array_column($byType, 'cnt')),
        'Abonamente',
        'subscriptions_chart'
    ),
];

echo 'active_users=' . $activeUsers . PHP_EOL;
foreach ($charts as $name => $info) {
    echo $name . ': ' . $info['png'] . ' generated=' . ($info['generated'] ? 'yes' : 'no') . PHP_EOL;
}
