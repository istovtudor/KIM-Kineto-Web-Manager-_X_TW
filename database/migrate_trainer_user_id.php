<?php
/**
 * Migrare sigura: trainer_id = users.id
 * Ruleaza: php database/migrate_trainer_user_id.php
 * Sau deschide in browser: http://localhost/ProiectWEB/database/migrate_trainer_user_id.php
 */
require_once dirname(__DIR__) . '/config/database.php';

header('Content-Type: text/plain; charset=utf-8');

function findForeignKey(PDO $db, string $table, string $column): ?string
{
    $stmt = $db->prepare(
        'SELECT CONSTRAINT_NAME
         FROM information_schema.KEY_COLUMN_USAGE
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND COLUMN_NAME = ?
           AND REFERENCED_TABLE_NAME IS NOT NULL
         LIMIT 1'
    );
    $stmt->execute([$table, $column]);
    $name = $stmt->fetchColumn();
    return $name ? (string) $name : null;
}

function foreignKeyExists(PDO $db, string $table, string $constraintName): bool
{
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND CONSTRAINT_NAME = ?
           AND CONSTRAINT_TYPE = \'FOREIGN KEY\''
    );
    $stmt->execute([$table, $constraintName]);
    return (int) $stmt->fetchColumn() > 0;
}

function referencedTable(PDO $db, string $table, string $column): ?string
{
    $stmt = $db->prepare(
        'SELECT REFERENCED_TABLE_NAME
         FROM information_schema.KEY_COLUMN_USAGE
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND COLUMN_NAME = ?
           AND REFERENCED_TABLE_NAME IS NOT NULL
         LIMIT 1'
    );
    $stmt->execute([$table, $column]);
    $ref = $stmt->fetchColumn();
    return $ref ? (string) $ref : null;
}

try {
    $db = getDb();
    echo "Migrare trainer_id -> users.id\n";
    echo "Baza: " . ($db->query('SELECT DATABASE()')->fetchColumn()) . "\n\n";

    $db->exec('SET FOREIGN_KEY_CHECKS = 0');

    $sessionsRef = referencedTable($db, 'sessions', 'trainer_id');
    echo "sessions.trainer_id refera: " . ($sessionsRef ?: '(niciun FK)') . "\n";

    if ($sessionsRef === 'trainers') {
        $updated = $db->exec(
            'UPDATE sessions s
             INNER JOIN trainers t ON t.id = s.trainer_id
             SET s.trainer_id = t.user_id
             WHERE t.user_id IS NOT NULL'
        );
        echo "sessions actualizate: $updated rand(uri)\n";
    } else {
        echo "sessions: date deja migrate sau fara FK trainers\n";
    }

    $intervalsRef = referencedTable($db, 'trainer_intervals', 'trainer_id');
    echo "trainer_intervals.trainer_id refera: " . ($intervalsRef ?: '(niciun FK)') . "\n";

    if ($intervalsRef === 'trainers') {
        $updated = $db->exec(
            'UPDATE trainer_intervals ti
             INNER JOIN trainers t ON t.id = ti.trainer_id
             SET ti.trainer_id = t.user_id
             WHERE t.user_id IS NOT NULL'
        );
        echo "trainer_intervals actualizate: $updated rand(uri)\n";
    } else {
        echo "trainer_intervals: date deja migrate sau fara FK trainers\n";
    }

    $sessionsFk = findForeignKey($db, 'sessions', 'trainer_id');
    if ($sessionsFk && $sessionsRef === 'trainers') {
        $db->exec("ALTER TABLE `sessions` DROP FOREIGN KEY `{$sessionsFk}`");
        echo "sessions: FK sters ($sessionsFk)\n";
    } elseif ($sessionsFk) {
        echo "sessions: FK existent ($sessionsFk) -> {$sessionsRef}, skip drop\n";
    } else {
        echo "sessions: niciun FK de sters\n";
    }

    if (!foreignKeyExists($db, 'sessions', 'fk_sessions_trainer_user')) {
        $db->exec(
            'ALTER TABLE `sessions`
             ADD CONSTRAINT `fk_sessions_trainer_user`
             FOREIGN KEY (`trainer_id`) REFERENCES `users` (`id`)'
        );
        echo "sessions: FK nou fk_sessions_trainer_user -> users(id)\n";
    } else {
        echo "sessions: FK fk_sessions_trainer_user deja exista\n";
    }

    $intervalsFk = findForeignKey($db, 'trainer_intervals', 'trainer_id');
    if ($intervalsFk && $intervalsRef === 'trainers') {
        $db->exec("ALTER TABLE `trainer_intervals` DROP FOREIGN KEY `{$intervalsFk}`");
        echo "trainer_intervals: FK sters ($intervalsFk)\n";
    } elseif ($intervalsFk) {
        echo "trainer_intervals: FK existent ($intervalsFk) -> {$intervalsRef}, skip drop\n";
    } else {
        echo "trainer_intervals: niciun FK de sters\n";
    }

    if (!foreignKeyExists($db, 'trainer_intervals', 'fk_trainer_intervals_user')) {
        $db->exec(
            'ALTER TABLE `trainer_intervals`
             ADD CONSTRAINT `fk_trainer_intervals_user`
             FOREIGN KEY (`trainer_id`) REFERENCES `users` (`id`) ON DELETE CASCADE'
        );
        echo "trainer_intervals: FK nou fk_trainer_intervals_user -> users(id)\n";
    } else {
        echo "trainer_intervals: FK fk_trainer_intervals_user deja exista\n";
    }

    $db->exec('SET FOREIGN_KEY_CHECKS = 1');

    echo "\nVerificare:\n";
    $sample = $db->query('SELECT id, trainer_id FROM sessions LIMIT 5')->fetchAll(PDO::FETCH_ASSOC);
    print_r($sample);
    $sample2 = $db->query('SELECT id, trainer_id FROM trainer_intervals LIMIT 5')->fetchAll(PDO::FETCH_ASSOC);
    print_r($sample2);

    echo "\nMigrare finalizata cu succes.\n";
} catch (Throwable $e) {
    echo "EROARE: " . $e->getMessage() . "\n";
    exit(1);
}
