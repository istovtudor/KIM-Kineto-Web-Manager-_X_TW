<?php
require_once dirname(__DIR__) . '/config/database.php';

class Trainer
{
    private PDO $db;

    public function __construct()
    {
        $this->db = getDb();
    }

    public function listAll(): array
    {
        return $this->db->query('SELECT * FROM trainers WHERE is_active = 1 ORDER BY full_name')->fetchAll();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM trainers WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findByUserId(int $userId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM trainers WHERE user_id = ? AND is_active = 1 LIMIT 1');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Creeaza automat profil antrenor daca userul are rol trainer dar nu exista rand in trainers.
     */
    public function ensureProfileForUser(int $userId, string $fullName, string $email): int
    {
        $existing = $this->findByUserId($userId);
        if ($existing) {
            return (int) $existing['id'];
        }
        return $this->create([
            'user_id' => $userId,
            'full_name' => $fullName !== '' ? $fullName : 'Antrenor',
            'email' => $email,
            'specialty' => 'fitness,forta,kineto',
        ]);
    }

    public function create(array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO trainers (user_id, full_name, specialty, email, phone) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $data['user_id'] ?? null, $data['full_name'], $data['specialty'] ?? '',
            $data['email'] ?? '', $data['phone'] ?? '',
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, array $data): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE trainers SET full_name=?, specialty=?, email=?, phone=?, is_active=? WHERE id=?'
        );
        return $stmt->execute([
            $data['full_name'], $data['specialty'] ?? '', $data['email'] ?? '',
            $data['phone'] ?? '', $data['is_active'] ?? 1, $id,
        ]);
    }

    public function delete(int $id): bool
    {
        return $this->db->prepare('UPDATE trainers SET is_active = 0 WHERE id = ?')->execute([$id]);
    }

    public function topBySessions(int $limit = 5): array
    {
        $limit = max(1, min(50, $limit));
        return $this->db->query(
            "SELECT COALESCE(NULLIF(p.full_name, ''), u.email) AS full_name, COUNT(s.id) AS session_count
             FROM users u
             LEFT JOIN user_profiles p ON p.user_id = u.id
             LEFT JOIN sessions s ON s.trainer_id = u.id AND s.status != 'cancelled'
             WHERE u.is_active = 1 AND u.role = 'trainer'
             GROUP BY u.id
             ORDER BY session_count DESC
             LIMIT {$limit}"
        )->fetchAll();
    }
}
