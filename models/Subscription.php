<?php
require_once dirname(__DIR__) . '/config/database.php';

class Subscription
{
    private PDO $db;

    public function __construct()
    {
        $this->db = getDb();
    }

    public function listTypes(): array
    {
        return $this->db->query('SELECT * FROM subscription_types WHERE is_active = 1 ORDER BY price')->fetchAll();
    }

    public function getType(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM subscription_types WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function createType(array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO subscription_types (name, description, price, duration_days, sessions_included) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $data['name'],
            $data['description'] ?? '',
            $data['price'],
            $data['duration_days'] ?? 30,
            $data['sessions_included'] ?? 4,
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function updateType(int $id, array $data): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE subscription_types SET name=?, description=?, price=?, duration_days=?, sessions_included=?, is_active=? WHERE id=?'
        );
        return $stmt->execute([
            $data['name'],
            $data['description'] ?? '',
            $data['price'],
            $data['duration_days'] ?? 30,
            $data['sessions_included'] ?? 4,
            $data['is_active'] ?? 1,
            $id,
        ]);
    }

    public function deleteType(int $id): bool
    {
        return $this->db->prepare('UPDATE subscription_types SET is_active = 0 WHERE id = ?')->execute([$id]);
    }

    public function getActiveSubscriptions(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT us.*, st.name AS type_name, st.price, st.sessions_included
             FROM user_subscriptions us
             JOIN subscription_types st ON st.id = us.subscription_type_id
             WHERE us.user_id = ? AND us.status = \'active\' AND us.end_date >= CURDATE()
             ORDER BY us.created_at DESC'
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    public function getActiveSubscription(int $userId): ?array
    {
        $all = $this->getActiveSubscriptions($userId);
        return $all[0] ?? null;
    }

    public function getTotalRemainingSessions(int $userId): int
    {
        $stmt = $this->db->prepare(
            'SELECT COALESCE(SUM(sessions_remaining), 0) FROM user_subscriptions
             WHERE user_id = ? AND status = \'active\' AND end_date >= CURDATE()'
        );
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    }

    public function getRemainingSessions(int $userId): int
    {
        return $this->getTotalRemainingSessions($userId);
    }

    /** @return array{fitness_forta: int, kineto: int} */
    public function getRemainingBreakdown(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT COALESCE(SUM(sessions_remaining_fitness), 0), COALESCE(SUM(sessions_remaining_kineto), 0)
             FROM user_subscriptions
             WHERE user_id = ? AND status = \'active\' AND end_date >= CURDATE()'
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_NUM);
        return [
            'fitness_forta' => (int) ($row[0] ?? 0),
            'kineto' => (int) ($row[1] ?? 0),
        ];
    }

    /** @return string[] */
    public static function typesForPackageName(string $packageName): array
    {
        $name = strtolower($packageName);
        if (strpos($name, 'kineto') !== false && strpos($name, 'basic') === false && strpos($name, 'premium') === false) {
            return ['kineto'];
        }
        if (strpos($name, 'premium') !== false) {
            return ['fitness', 'forta', 'kineto'];
        }
        if (strpos($name, 'basic') !== false) {
            return ['fitness', 'forta'];
        }
        return ['fitness', 'forta'];
    }

    public function attachSubscriptionTypes(int $userSubscriptionId, array $types): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO user_subscription_types (user_subscription_id, type) VALUES (?, ?)'
        );
        foreach (array_unique($types) as $type) {
            $stmt->execute([$userSubscriptionId, $type]);
        }
    }

    /**
     * @return array{id: int, sessions_remaining: int, allowed_types: string[]}
     */
    public function activateForUser(int $userId, int $typeId): array
    {
        $type = $this->getType($typeId);
        if (!$type) {
            return ['id' => 0, 'sessions_remaining' => 0, 'allowed_types' => []];
        }
        $sessionsIncluded = (int) ($type['sessions_included'] ?? 4);
        $includedTypes = self::typesForPackageName((string) $type['name']);
        $fitnessRemaining = 0;
        $kinetoRemaining = 0;
        if (in_array('fitness', $includedTypes, true) || in_array('forta', $includedTypes, true)) {
            $fitnessRemaining = $sessionsIncluded;
        }
        if (in_array('kineto', $includedTypes, true)) {
            $kinetoRemaining = $sessionsIncluded;
        }
        $start = date('Y-m-d');
        $end = date('Y-m-d', strtotime('+' . (int) $type['duration_days'] . ' days'));
        $stmt = $this->db->prepare(
            'INSERT INTO user_subscriptions (
                user_id, subscription_type_id, status, sessions_remaining,
                sessions_remaining_fitness, sessions_remaining_kineto, start_date, end_date
             ) VALUES (?, ?, \'active\', ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $userId, $typeId, $sessionsIncluded,
            $fitnessRemaining, $kinetoRemaining, $start, $end,
        ]);
        $subId = (int) $this->db->lastInsertId();
        try {
            $this->attachSubscriptionTypes($subId, $includedTypes);
        } catch (PDOException $e) {
            // Tabel user_subscription_types poate lipsi pana la migrare
        }
        return [
            'id' => $subId,
            'sessions_remaining' => $sessionsIncluded,
            'allowed_types' => $this->getAllowedSessionTypes($userId),
        ];
    }

    public function findSubscriptionForBooking(int $userId, string $sessionType): ?array
    {
        $subs = $this->getActiveSubscriptions($userId);
        foreach ($subs as $sub) {
            $types = $this->getTypesForSubscription((int) $sub['id'], (string) $sub['type_name']);
            if (!in_array($sessionType, $types, true)) {
                continue;
            }
            if ($sessionType === 'kineto') {
                if ((int) ($sub['sessions_remaining_kineto'] ?? 0) > 0) {
                    return $sub;
                }
            } elseif ((int) ($sub['sessions_remaining_fitness'] ?? 0) > 0) {
                return $sub;
            }
        }
        return null;
    }

    /** @return string[] */
    public function getTypesForSubscription(int $userSubscriptionId, string $packageName): array
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT type FROM user_subscription_types WHERE user_subscription_id = ?'
            );
            $stmt->execute([$userSubscriptionId]);
            $types = $stmt->fetchAll(PDO::FETCH_COLUMN);
            if (!empty($types)) {
                return array_values($types);
            }
        } catch (PDOException $e) {
            // fallback
        }
        return self::typesForPackageName($packageName);
    }

    public function decrementSession(int $subscriptionId): ?int
    {
        $result = $this->decrementSessionByType($subscriptionId, 'fitness');
        return $result ? (int) $result['sessions_remaining'] : null;
    }

    /**
     * @return array{sessions_remaining: int, sessions_remaining_fitness: int, sessions_remaining_kineto: int}|null
     */
    public function decrementSessionByType(int $subscriptionId, string $sessionType): ?array
    {
        $col = $sessionType === 'kineto' ? 'sessions_remaining_kineto' : 'sessions_remaining_fitness';
        $stmt = $this->db->prepare(
            "UPDATE user_subscriptions
             SET $col = $col - 1, sessions_remaining = sessions_remaining - 1
             WHERE id = ? AND status = 'active' AND $col > 0 AND sessions_remaining > 0"
        );
        $stmt->execute([$subscriptionId]);
        if ($stmt->rowCount() === 0) {
            return null;
        }
        $remaining = $this->db->prepare(
            'SELECT sessions_remaining, sessions_remaining_fitness, sessions_remaining_kineto
             FROM user_subscriptions WHERE id = ?'
        );
        $remaining->execute([$subscriptionId]);
        $row = $remaining->fetch();
        if (!$row) {
            return null;
        }
        return [
            'sessions_remaining' => (int) $row['sessions_remaining'],
            'sessions_remaining_fitness' => (int) $row['sessions_remaining_fitness'],
            'sessions_remaining_kineto' => (int) $row['sessions_remaining_kineto'],
        ];
    }

    public function updateStatus(int $id, string $status): bool
    {
        return $this->db->prepare('UPDATE user_subscriptions SET status = ? WHERE id = ?')->execute([$status, $id]);
    }

    public function expireOld(): int
    {
        return $this->db->exec(
            'UPDATE user_subscriptions SET status = \'expired\' WHERE status = \'active\' AND end_date < CURDATE()'
        );
    }

    public function getUserHistory(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT us.*, st.name AS type_name, st.price, st.sessions_included
             FROM user_subscriptions us
             JOIN subscription_types st ON st.id = us.subscription_type_id
             WHERE us.user_id = ? ORDER BY us.created_at DESC'
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    public function getStats(): array
    {
        $active = (int) $this->db->query('SELECT COUNT(*) FROM user_subscriptions WHERE status = \'active\'')->fetchColumn();
        $suspended = (int) $this->db->query('SELECT COUNT(*) FROM user_subscriptions WHERE status = \'suspended\'')->fetchColumn();
        $expired = (int) $this->db->query('SELECT COUNT(*) FROM user_subscriptions WHERE status = \'expired\'')->fetchColumn();
        $byType = $this->db->query(
            'SELECT st.name, COUNT(us.id) AS cnt FROM user_subscriptions us
             JOIN subscription_types st ON st.id = us.subscription_type_id
             WHERE us.status = \'active\' GROUP BY st.id'
        )->fetchAll();
        return ['active' => $active, 'suspended' => $suspended, 'expired' => $expired, 'by_type' => $byType];
    }

    /**
     * Tipuri permise din toate abonamentele active (combinat: Basic + Kineto etc.)
     * @return string[]
     */
    public function getAllowedSessionTypes(int $userId): array
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT DISTINCT ust.type
                 FROM user_subscription_types ust
                 JOIN user_subscriptions us ON us.id = ust.user_subscription_id
                 WHERE us.user_id = ? AND us.status = \'active\' AND us.end_date >= CURDATE()'
            );
            $stmt->execute([$userId]);
            $types = $stmt->fetchAll(PDO::FETCH_COLUMN);
            if (!empty($types)) {
                return array_values($types);
            }
        } catch (PDOException $e) {
            // fallback la logica pe nume pachet
        }

        $types = [];
        foreach ($this->getActiveSubscriptions($userId) as $sub) {
            $types = array_merge($types, self::typesForPackageName((string) $sub['type_name']));
        }
        return array_values(array_unique($types));
    }
}
