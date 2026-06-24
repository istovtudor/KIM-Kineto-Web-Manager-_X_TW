<?php
require_once dirname(__DIR__) . '/config/database.php';

class SessionModel
{
    private PDO $db;

    public const BOOKING_ACTIVE = 'active';
    public const BOOKING_CANCELLED = 'cancelled';

    public function __construct()
    {
        $this->db = getDb();
    }

    /** Conditie SQL: rezervare activa (suporta si 'confirmed' legacy). */
    private function sqlBookingIsActive(?string $alias = null): string
    {
        $col = ($alias !== null && $alias !== '') ? "{$alias}.status" : 'status';
        return "($col = 'active' OR $col = 'confirmed')";
    }

    private function trainerJoinSql(string $sessionAlias = 's'): string
    {
        return "JOIN users tu ON tu.id = {$sessionAlias}.trainer_id
                LEFT JOIN user_profiles tp ON tp.user_id = tu.id";
    }

    private function trainerNameSql(): string
    {
        return "COALESCE(NULLIF(tp.full_name, ''), tu.email) AS trainer_name";
    }

    public function listAll(?string $type = null, ?string $from = null, ?string $to = null): array
    {
        $active = $this->sqlBookingIsActive('b');
        $trainerJoin = $this->trainerJoinSql('s');
        $trainerName = $this->trainerNameSql();
        $sql = "SELECT s.*, {$trainerName}, r.name AS room_name,
                (SELECT COUNT(*) FROM session_bookings b WHERE b.session_id = s.id AND $active) AS booked_count
                FROM sessions s
                {$trainerJoin}
                JOIN rooms r ON r.id = s.room_id
                WHERE s.status != 'cancelled'";
        $params = [];
        if ($type) {
            $sql .= ' AND s.type = ?';
            $params[] = $type;
        }
        if ($from) {
            $sql .= ' AND s.start_time >= ?';
            $params[] = $from;
        }
        if ($to) {
            $sql .= ' AND s.end_time <= ?';
            $params[] = $to;
        }
        $sql .= ' ORDER BY s.start_time ASC';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function findById(int $id): ?array
    {
        $trainerJoin = $this->trainerJoinSql('s');
        $trainerName = $this->trainerNameSql();
        $stmt = $this->db->prepare(
            "SELECT s.*, {$trainerName}, r.name AS room_name
             FROM sessions s
             {$trainerJoin}
             JOIN rooms r ON r.id = s.room_id WHERE s.id = ?"
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function create(array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO sessions (title, type, trainer_id, room_id, start_time, end_time, max_participants, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $data['title'], $data['type'], $data['trainer_id'], $data['room_id'],
            $data['start_time'], $data['end_time'], $data['max_participants'] ?? 1,
            $data['created_by'] ?? null,
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, array $data): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE sessions SET title=?, type=?, trainer_id=?, room_id=?, start_time=?, end_time=?, max_participants=? WHERE id=?'
        );
        return $stmt->execute([
            $data['title'], $data['type'], $data['trainer_id'], $data['room_id'],
            $data['start_time'], $data['end_time'], $data['max_participants'] ?? 1, $id,
        ]);
    }

    public function cancel(int $id): bool
    {
        return $this->db->prepare('UPDATE sessions SET status = \'cancelled\' WHERE id = ?')->execute([$id]);
    }

    public function hasConflict(int $trainerId, int $roomId, string $start, string $end, ?int $excludeId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM sessions
                WHERE status = \'scheduled\'
                AND ((trainer_id = ? OR room_id = ?)
                AND start_time < ? AND end_time > ?)';
        $params = [$trainerId, $roomId, $end, $start];
        if ($excludeId) {
            $sql .= ' AND id != ?';
            $params[] = $excludeId;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn() > 0;
    }

    public function book(int $sessionId, int $userId): array
    {
        $session = $this->findById($sessionId);
        if (!$session || $session['status'] === 'cancelled') {
            return ['ok' => false, 'error' => 'Sedinta inexistenta sau anulata'];
        }

        if ($this->hasActiveBookingForSession($sessionId)) {
            $mine = $this->db->prepare(
                'SELECT id FROM session_bookings WHERE session_id = ? AND user_id = ? AND ' . $this->sqlBookingIsActive()
            );
            $mine->execute([$sessionId, $userId]);
            if ($mine->fetch()) {
                return ['ok' => false, 'error' => 'Deja inscris la aceasta sedinta'];
            }
            return ['ok' => false, 'error' => 'Ora este deja rezervata de alt membru'];
        }

        $nameStmt = $this->db->prepare('SELECT p.full_name FROM user_profiles p WHERE p.user_id = ?');
        $nameStmt->execute([$userId]);
        $bookedByName = (string) ($nameStmt->fetchColumn() ?: '');

        $existing = $this->db->prepare(
            'SELECT id, status FROM session_bookings WHERE session_id = ? AND user_id = ?'
        );
        $existing->execute([$sessionId, $userId]);
        $row = $existing->fetch();

        if ($row) {
            if ($row['status'] === self::BOOKING_ACTIVE || $row['status'] === 'confirmed') {
                return ['ok' => false, 'error' => 'Deja inscris la aceasta sedinta'];
            }
            $this->db->prepare(
                'UPDATE session_bookings SET status = ?, booked_by_name = ?, booked_at = NOW() WHERE id = ?'
            )->execute([self::BOOKING_ACTIVE, $bookedByName !== '' ? $bookedByName : null, $row['id']]);
            return ['ok' => true];
        }

        $this->db->prepare(
            'INSERT INTO session_bookings (session_id, user_id, booked_by_name, status) VALUES (?, ?, ?, ?)'
        )->execute([$sessionId, $userId, $bookedByName !== '' ? $bookedByName : null, self::BOOKING_ACTIVE]);
        return ['ok' => true];
    }

    public function cancelBooking(int $sessionId, int $userId): bool
    {
        return $this->db->prepare(
            'UPDATE session_bookings SET status = ? WHERE session_id = ? AND user_id = ? AND ' . $this->sqlBookingIsActive()
        )->execute([self::BOOKING_CANCELLED, $sessionId, $userId]);
    }

    public function isBookingStatusActive(string $status): bool
    {
        return $status === self::BOOKING_ACTIVE || $status === 'confirmed';
    }

    public function getBookingById(int $bookingId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT b.*, s.trainer_id, s.title AS session_title
             FROM session_bookings b
             JOIN sessions s ON s.id = b.session_id
             WHERE b.id = ?'
        );
        $stmt->execute([$bookingId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function getActiveBookingForSession(int $sessionId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT b.* FROM session_bookings b
             WHERE b.session_id = ? AND ' . $this->sqlBookingIsActive('b') . '
             LIMIT 1'
        );
        $stmt->execute([$sessionId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function getActiveBookingForSessionUser(int $sessionId, int $userId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT b.* FROM session_bookings b
             WHERE b.session_id = ? AND b.user_id = ? AND ' . $this->sqlBookingIsActive('b') . '
             LIMIT 1'
        );
        $stmt->execute([$sessionId, $userId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function cancelBookingById(int $bookingId): bool
    {
        return $this->db->prepare(
            'UPDATE session_bookings SET status = ? WHERE id = ? AND ' . $this->sqlBookingIsActive()
        )->execute([self::BOOKING_CANCELLED, $bookingId]);
    }

    public function getBookings(int $sessionId): array
    {
        $stmt = $this->db->prepare(
            'SELECT b.*, p.full_name, u.email FROM session_bookings b
             JOIN users u ON u.id = b.user_id
             LEFT JOIN user_profiles p ON p.user_id = u.id
             WHERE b.session_id = ? AND ' . $this->sqlBookingIsActive('b') . ' ORDER BY b.booked_at'
        );
        $stmt->execute([$sessionId]);
        return $stmt->fetchAll();
    }

    public function hasBookingOnSameDay(int $userId, int $sessionId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM session_bookings b
             JOIN sessions s ON s.id = b.session_id
             JOIN sessions target ON target.id = ?
             WHERE b.user_id = ? AND ' . $this->sqlBookingIsActive('b') . '
             AND DATE(s.start_time) = DATE(target.start_time)'
        );
        $stmt->execute([$sessionId, $userId]);
        return (int) $stmt->fetchColumn() > 0;
    }

    public function hasActiveBookingForSession(int $sessionId): bool
    {
        return $this->getBookedCount($sessionId) >= 1;
    }

    public function countByPeriod(string $period): int
    {
        $formats = [
            'day' => 'DATE(start_time) = CURDATE()',
            'week' => 'start_time >= DATE_SUB(NOW(), INTERVAL 7 DAY)',
            'month' => 'start_time >= DATE_SUB(NOW(), INTERVAL 30 DAY)',
        ];
        $where = $formats[$period] ?? $formats['month'];
        return (int) $this->db->query("SELECT COUNT(*) FROM sessions WHERE status != 'cancelled' AND $where")->fetchColumn();
    }

    /** Numar rezervari active pe sedinte, filtrat dupa data sedintei. */
    public function countBookingsByPeriod(string $period): int
    {
        $formats = [
            'day' => 'DATE(s.start_time) = CURDATE()',
            'week' => 's.start_time >= DATE_SUB(NOW(), INTERVAL 7 DAY)',
            'month' => 's.start_time >= DATE_SUB(NOW(), INTERVAL 30 DAY)',
        ];
        $where = $formats[$period] ?? $formats['month'];
        return (int) $this->db->query(
            "SELECT COUNT(*) FROM session_bookings b
             INNER JOIN sessions s ON s.id = b.session_id
             WHERE b.status != 'cancelled'
               AND s.status != 'cancelled'
               AND {$where}"
        )->fetchColumn();
    }

    public function getBookedCount(int $sessionId): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM session_bookings WHERE session_id = ? AND ' . $this->sqlBookingIsActive()
        );
        $stmt->execute([$sessionId]);
        return (int) $stmt->fetchColumn();
    }

    public function findSlotSession(int $trainerId, string $type, string $date, string $time): ?int
    {
        $start = $date . ' ' . $time . ':00';
        $stmt = $this->db->prepare(
            'SELECT id FROM sessions
             WHERE trainer_id = ? AND type = ? AND start_time = ? AND status != \'cancelled\' LIMIT 1'
        );
        $stmt->execute([$trainerId, $type, $start]);
        $id = $stmt->fetchColumn();
        return $id ? (int) $id : null;
    }

    public function getDefaultRoomId(string $type): int
    {
        $map = ['fitness' => 'Sala Fitness', 'forta' => 'Sala Forta', 'kineto' => 'Sala Kineto'];
        $name = $map[$type] ?? 'Sala Fitness';
        $stmt = $this->db->prepare('SELECT id FROM rooms WHERE name = ? AND is_active = 1 LIMIT 1');
        $stmt->execute([$name]);
        $id = $stmt->fetchColumn();
        if ($id) {
            return (int) $id;
        }
        return (int) $this->db->query('SELECT id FROM rooms WHERE is_active = 1 ORDER BY id LIMIT 1')->fetchColumn();
    }

    public function getSlotBookingStatus(int $sessionId, int $currentUserId, bool $showBookedNames): array
    {
        $stmt = $this->db->prepare(
            'SELECT b.user_id,
                    COALESCE(NULLIF(b.booked_by_name, \'\'), p.full_name, u.email) AS booked_name
             FROM session_bookings b
             JOIN users u ON u.id = b.user_id
             LEFT JOIN user_profiles p ON p.user_id = u.id
             WHERE b.session_id = ? AND ' . $this->sqlBookingIsActive('b')
        );
        $stmt->execute([$sessionId]);
        $rows = $stmt->fetchAll();

        if (empty($rows)) {
            return ['status' => 'free', 'booked_by' => null, 'booked_names' => []];
        }

        $names = array_map(fn($r) => $r['booked_name'], $rows);
        foreach ($rows as $row) {
            if ((int) $row['user_id'] === $currentUserId) {
                return [
                    'status' => 'mine',
                    'booked_by' => $showBookedNames ? $row['booked_name'] : null,
                    'booked_names' => $showBookedNames ? $names : [],
                ];
            }
        }

        return [
            'status' => 'occupied',
            'booked_by' => $showBookedNames ? implode(', ', $names) : null,
            'booked_names' => $showBookedNames ? $names : [],
        ];
    }

    public function listBookingsForDate(string $date, ?int $trainerUserId = null): array
    {
        $trainerJoin = $this->trainerJoinSql('s');
        $trainerName = $this->trainerNameSql();
        $sql = "SELECT b.id AS booking_id, s.id AS session_id, s.title, s.type, s.start_time, s.end_time,
                       {$trainerName},
                       b.user_id, COALESCE(NULLIF(b.booked_by_name, ''), p.full_name, u.email) AS member_name
                FROM sessions s
                {$trainerJoin}
                JOIN session_bookings b ON b.session_id = s.id AND " . $this->sqlBookingIsActive('b') . "
                JOIN users u ON u.id = b.user_id
                LEFT JOIN user_profiles p ON p.user_id = u.id
                WHERE DATE(s.start_time) = ? AND s.status != 'cancelled'";
        $params = [$date];
        if ($trainerUserId) {
            $sql .= ' AND s.trainer_id = ?';
            $params[] = $trainerUserId;
        }
        $sql .= ' ORDER BY s.start_time ASC';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function findOrCreateSlotSession(
        int $trainerId,
        string $type,
        string $date,
        string $time,
        int $capacity,
        ?int $createdBy
    ): int {
        $existing = $this->findSlotSession($trainerId, $type, $date, $time);
        if ($existing) {
            $this->db->prepare('UPDATE sessions SET max_participants = 1 WHERE id = ?')->execute([$existing]);
            return $existing;
        }
        $start = $date . ' ' . $time . ':00';
        $end = date('Y-m-d H:i:s', strtotime($start . ' +1 hour'));
        $labels = ['fitness' => 'Fitness', 'forta' => 'Forta', 'kineto' => 'Kineto'];
        return $this->create([
            'title' => ($labels[$type] ?? 'Sedinta') . ' ' . $time,
            'type' => $type,
            'trainer_id' => $trainerId,
            'room_id' => $this->getDefaultRoomId($type),
            'start_time' => $start,
            'end_time' => $end,
            'max_participants' => 1,
            'created_by' => $createdBy,
        ]);
    }
}
