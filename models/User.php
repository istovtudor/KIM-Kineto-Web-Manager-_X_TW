<?php
require_once dirname(__DIR__) . '/config/database.php';

class User
{
    private PDO $db;

    public function __construct()
    {
        $this->db = getDb();
    }

    public function findByEmail(string $email): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM users WHERE email = ? AND is_active = 1');
        $stmt->execute([$email]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT u.id, u.email, u.role, u.created_at, p.full_name, p.phone, p.bio
             FROM users u LEFT JOIN user_profiles p ON p.user_id = u.id
             WHERE u.id = ? AND u.is_active = 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function create(string $email, string $password, string $role, string $fullName, ?string $phone = null): int
    {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $this->db->prepare('INSERT INTO users (email, password_hash, role) VALUES (?, ?, ?)');
        $stmt->execute([$email, $hash, $role]);
        $userId = (int) $this->db->lastInsertId();
        $this->db->prepare('INSERT INTO user_profiles (user_id, full_name, phone) VALUES (?, ?, ?)')
            ->execute([$userId, $fullName, $phone]);
        return $userId;
    }

    public function verifyPassword(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    public function updateProfile(int $userId, array $data): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE user_profiles SET full_name = ?, phone = ?, bio = ? WHERE user_id = ?'
        );
        return $stmt->execute([
            $data['full_name'] ?? '',
            $data['phone'] ?? '',
            $data['bio'] ?? '',
            $userId,
        ]);
    }

    public function getActivity(int $userId, int $limit = 50, ?string $role = null): array
    {
        $limit = max(1, min(100, $limit));
        $stmt = $this->db->prepare(
            "SELECT action, details, created_at FROM user_activity WHERE user_id = ? ORDER BY created_at DESC LIMIT {$limit}"
        );
        $stmt->execute([$userId]);
        $activity = $stmt->fetchAll();

        foreach ($activity as &$row) {
            $row['action_label'] = self::activityLabel((string) $row['action']);
        }
        unset($row);

        if ($role === null) {
            $user = $this->findById($userId);
            $role = $user['role'] ?? 'member';
        }

        if ($role === 'member') {
            $activity = array_merge($activity, $this->getPastReservationsActivity($userId));
            usort($activity, static function (array $a, array $b): int {
                return strcmp((string) $b['created_at'], (string) $a['created_at']);
            });
            $activity = array_slice($activity, 0, $limit);
        }

        return $activity;
    }

    /** Rezervari finalizate (sedinta trecuta) pentru istoric membru. */
    public function getPastReservationsActivity(int $userId): array
    {
        $stmt = $this->db->prepare(
            "SELECT s.title, s.type, s.start_time, s.end_time,
                    COALESCE(NULLIF(tp.full_name, ''), tu.email) AS trainer_name
             FROM session_bookings b
             JOIN sessions s ON s.id = b.session_id
             JOIN users tu ON tu.id = s.trainer_id
             LEFT JOIN user_profiles tp ON tp.user_id = tu.id
             WHERE b.user_id = ?
               AND b.status != 'cancelled'
               AND s.status != 'cancelled'
               AND s.end_time < NOW()
             ORDER BY s.end_time DESC
             LIMIT 50"
        );
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll();

        $items = [];
        foreach ($rows as $row) {
            $when = date('d.m.Y H:i', strtotime((string) $row['start_time']));
            $items[] = [
                'action' => 'session_completed',
                'action_label' => 'Sedinta efectuata',
                'details' => sprintf(
                    '%s (%s) cu %s — %s',
                    (string) $row['title'],
                    (string) $row['type'],
                    (string) $row['trainer_name'],
                    $when
                ),
                'created_at' => (string) $row['end_time'],
            ];
        }
        return $items;
    }

    public static function activityLabel(string $action): string
    {
        $labels = [
            'session_book' => 'Rezervare sedinta',
            'session_completed' => 'Sedinta efectuata',
            'session_unbook' => 'Anulare rezervare',
            'session_create' => 'Sedinta creata',
            'session_update' => 'Sedinta modificata',
            'session_cancel' => 'Sedinta anulata',
            'login' => 'Autentificare',
            'logout' => 'Deconectare',
            'register' => 'Inregistrare cont',
            'profile_update' => 'Actualizare profil',
            'subscription_activate' => 'Activare abonament',
        ];
        return $labels[$action] ?? $action;
    }

    public function listAll(): array
    {
        return $this->db->query(
            'SELECT u.id, u.email, u.role, u.created_at, p.full_name
             FROM users u LEFT JOIN user_profiles p ON p.user_id = u.id
             WHERE u.is_active = 1 ORDER BY u.id'
        )->fetchAll();
    }

    /** @return array<int, array<string, mixed>> */
    public function listAllForAdmin(): array
    {
        $rows = $this->db->query(
            'SELECT u.id, u.email, u.role, p.full_name
             FROM users u
             LEFT JOIN user_profiles p ON p.user_id = u.id
             WHERE u.is_active = 1
             ORDER BY u.id'
        )->fetchAll();

        $users = [];
        foreach ($rows as $row) {
            $names = self::splitFullName((string) ($row['full_name'] ?? ''));
            $users[] = [
                'id' => (int) $row['id'],
                'first_name' => $names['first_name'],
                'last_name' => $names['last_name'],
                'email' => (string) $row['email'],
                'role' => (string) $row['role'],
                'is_trainer' => $row['role'] === 'trainer',
            ];
        }
        return $users;
    }

    /**
     * @return array{ok: bool, error?: string}
     */
    public function setTrainerRole(int $userId, bool $isTrainer): array
    {
        $stmt = $this->db->prepare('SELECT u.id, u.email, u.role, p.full_name FROM users u LEFT JOIN user_profiles p ON p.user_id = u.id WHERE u.id = ? AND u.is_active = 1');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        if (!$user) {
            return ['ok' => false, 'error' => 'Utilizator negasit'];
        }
        if ($user['role'] === 'admin') {
            return ['ok' => false, 'error' => 'Nu poti modifica rolul unui administrator'];
        }

        $newRole = $isTrainer ? 'trainer' : 'member';
        $this->db->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$newRole, $userId]);

        return ['ok' => true];
    }

    /** Utilizatori cu rol trainer (pentru dropdown-uri; id = users.id). */
    public function listTrainers(): array
    {
        return $this->db->query(
            'SELECT u.id, u.email, COALESCE(NULLIF(p.full_name, \'\'), u.email) AS full_name
             FROM users u
             LEFT JOIN user_profiles p ON p.user_id = u.id
             WHERE u.is_active = 1 AND u.role = \'trainer\'
             ORDER BY full_name'
        )->fetchAll();
    }

    /** @return array{first_name: string, last_name: string} */
    public static function splitFullName(string $fullName): array
    {
        $fullName = trim($fullName);
        if ($fullName === '') {
            return ['first_name' => '', 'last_name' => ''];
        }
        $parts = preg_split('/\s+/', $fullName, 2);
        return [
            'first_name' => $parts[0] ?? '',
            'last_name' => $parts[1] ?? '',
        ];
    }
}
