<?php
/**
 * Populeaza baza de date cu date demo (ruleaza DUPA import mysql_schema.sql).
 * Nu creeaza tabele — doar INSERT-uri.
 */
require_once dirname(__DIR__) . '/config/database.php';

$db = getDb();

try {
    $count = (int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn();
} catch (PDOException $e) {
    echo "Eroare: tabelele lipsesc. Importati mai intai database/mysql_schema.sql in phpMyAdmin.\n";
    echo $e->getMessage() . "\n";
    exit(1);
}

if ($count > 0) {
    echo "Baza de date contine deja utilizatori ($count). Seed omis.\n";
    echo "Stergeti tabelele sau baza kim_db daca doriti reimport complet.\n";
    exit(0);
}

$adminHash = password_hash('admin123', PASSWORD_DEFAULT);
$trainerHash = password_hash('trainer123', PASSWORD_DEFAULT);
$memberHash = password_hash('member123', PASSWORD_DEFAULT);

$users = [
    ['admin@kim.ro', $adminHash, 'admin', 'Admin KIM'],
    ['trainer@kim.ro', $trainerHash, 'trainer', 'Ion Popescu'],
    ['member@kim.ro', $memberHash, 'member', 'Maria Ionescu'],
];

foreach ($users as [$email, $hash, $role, $name]) {
    $stmt = $db->prepare('INSERT IGNORE INTO users (email, password_hash, role) VALUES (?, ?, ?)');
    $stmt->execute([$email, $hash, $role]);
    $uid = (int) $db->lastInsertId();
    if ($uid === 0) {
        $stmt = $db->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $uid = (int) $stmt->fetchColumn();
    }
    $db->prepare('INSERT IGNORE INTO user_profiles (user_id, full_name, phone) VALUES (?, ?, ?)')
       ->execute([$uid, $name, '0700000000']);
}

$db->exec("INSERT IGNORE INTO rooms (name, capacity, description) VALUES
    ('Sala Fitness', 20, 'Sala principala fitness'),
    ('Sala Kineto', 8, 'Cabinet kinetoterapie'),
    ('Sala Forta', 12, 'Sala antrenament forta')");

$db->exec("INSERT IGNORE INTO trainers (user_id, full_name, specialty, email) VALUES
    (2, 'Ion Popescu', 'fitness,kineto', 'trainer@kim.ro'),
    (NULL, 'Ana Dumitrescu', 'kineto', 'ana@kim.ro')");

$db->exec("INSERT IGNORE INTO equipment (name, room_id, status) VALUES
    ('Banda alergare', 1, 'available'),
    ('Stepper', 1, 'available'),
    ('Masa kineto', 2, 'available')");

$db->exec("INSERT IGNORE INTO subscription_types (name, description, price, duration_days, sessions_included) VALUES
    ('Basic', 'Acces 8 sedinte/luna', 150, 30, 8),
    ('Premium', 'Acces nelimitat', 300, 30, 30),
    ('Kineto Pack', '10 sedinte kineto', 400, 60, 10)");

$start = date('Y-m-d');
$end = date('Y-m-d', strtotime('+30 days'));
$db->prepare('INSERT IGNORE INTO user_subscriptions (user_id, subscription_type_id, status, sessions_remaining, start_date, end_date) VALUES (3, 1, \'active\', 8, ?, ?)')
   ->execute([$start, $end]);

$tomorrow = date('Y-m-d H:i:s', strtotime('+1 day 10:00'));
$tomorrowEnd = date('Y-m-d H:i:s', strtotime('+1 day 11:00'));
$db->prepare('INSERT IGNORE INTO sessions (title, type, trainer_id, room_id, start_time, end_time, max_participants, created_by) VALUES (?, ?, 2, 1, ?, ?, 10, 2)')
   ->execute(['Antrenament Fitness', 'fitness', $tomorrow, $tomorrowEnd]);

try {
    $db->exec("INSERT IGNORE INTO trainer_intervals (trainer_id, day_of_week, start_time, end_time, type, capacity) VALUES
        (2, 1, '08:00:00', '12:00:00', 'fitness', 10),
        (2, 1, '14:00:00', '18:00:00', 'forta', 10),
        (2, 3, '09:00:00', '13:00:00', 'kineto', 8)");
} catch (PDOException $e) {
    // Tabel trainer_intervals poate lipsi pana la migrare
}

echo "Date demo KIM inserate cu succes.\n";
echo "Conturi: admin@kim.ro / admin123, trainer@kim.ro / trainer123, member@kim.ro / member123\n";
