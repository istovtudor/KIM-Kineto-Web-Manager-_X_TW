<?php
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/models/Session.php';

class TrainerInterval
{
    private PDO $db;

    private const DAY_NAMES = [
        0 => 'Duminica',
        1 => 'Luni',
        2 => 'Marti',
        3 => 'Miercuri',
        4 => 'Joi',
        5 => 'Vineri',
        6 => 'Sambata',
    ];

    public function __construct()
    {
        $this->db = getDb();
    }

    public static function dayName(int $dayOfWeek): string
    {
        return self::DAY_NAMES[$dayOfWeek] ?? 'Necunoscut';
    }

    public static function generateHourlySlots(string $startTime, string $endTime): array
    {
        $slots = [];
        $start = strtotime('1970-01-01 ' . substr($startTime, 0, 8));
        $end = strtotime('1970-01-01 ' . substr($endTime, 0, 8));
        if ($start === false || $end === false || $start >= $end) {
            return $slots;
        }
        for ($t = $start; $t < $end; $t += 3600) {
            $slots[] = date('H:i', $t);
        }
        return $slots;
    }

    public function saveForTrainer(int $trainerId, array $intervals): int
    {
        $this->db->prepare('DELETE FROM trainer_intervals WHERE trainer_id = ?')->execute([$trainerId]);
        $stmt = $this->db->prepare(
            'INSERT INTO trainer_intervals (trainer_id, day_of_week, start_time, end_time, type, capacity)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $count = 0;
        foreach ($intervals as $row) {
            $stmt->execute([
                $trainerId,
                (int) $row['day_of_week'],
                $row['start_time'],
                $row['end_time'],
                $row['type'],
                (int) ($row['capacity'] ?? 10),
            ]);
            $count++;
        }
        return $count;
    }

    private function trainerUserJoinSql(string $intervalAlias = 'ti'): string
    {
        return "JOIN users tu ON tu.id = {$intervalAlias}.trainer_id
                LEFT JOIN user_profiles tp ON tp.user_id = tu.id";
    }

    private function trainerNameSql(): string
    {
        return "COALESCE(NULLIF(tp.full_name, ''), tu.email) AS trainer_name";
    }

    public function listByTrainer(int $trainerUserId): array
    {
        $join = $this->trainerUserJoinSql('ti');
        $name = $this->trainerNameSql();
        $stmt = $this->db->prepare(
            "SELECT ti.*, {$name}
             FROM trainer_intervals ti
             {$join}
             WHERE ti.trainer_id = ?
             ORDER BY ti.day_of_week, ti.start_time"
        );
        $stmt->execute([$trainerUserId]);
        return $stmt->fetchAll();
    }

    public function listAll(): array
    {
        $join = $this->trainerUserJoinSql('ti');
        $name = $this->trainerNameSql();
        return $this->db->query(
            "SELECT ti.*, {$name}
             FROM trainer_intervals ti
             {$join}
             WHERE tu.is_active = 1 AND tu.role IN ('trainer', 'admin')
             ORDER BY ti.day_of_week, ti.start_time"
        )->fetchAll();
    }

    public function listFiltered(array $allowedTypes): array
    {
        if (empty($allowedTypes)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($allowedTypes), '?'));
        $join = $this->trainerUserJoinSql('ti');
        $name = $this->trainerNameSql();
        $stmt = $this->db->prepare(
            "SELECT ti.*, {$name}
             FROM trainer_intervals ti
             {$join}
             WHERE tu.is_active = 1 AND ti.type IN ($placeholders)
             ORDER BY ti.day_of_week, ti.start_time"
        );
        $stmt->execute($allowedTypes);
        return $stmt->fetchAll();
    }

    /**
     * @return array<int, array{day_of_week: int, day_name: string, date: string, intervals: array}>
     */
    public function buildWeeklySchedule(
        array $intervals,
        string $weekStart,
        SessionModel $sessionModel,
        ?int $createdBy,
        int $currentUserId = 0,
        bool $isTrainerView = false
    ): array {
        $days = [];
        for ($d = 0; $d < 7; $d++) {
            $date = date('Y-m-d', strtotime($weekStart . " +$d days"));
            $dow = (int) date('w', strtotime($date));
            $days[$dow] = [
                'day_of_week' => $dow,
                'day_name' => self::dayName($dow),
                'date' => $date,
                'intervals' => [],
            ];
        }

        foreach ($intervals as $interval) {
            $dow = (int) $interval['day_of_week'];
            if (!isset($days[$dow])) {
                continue;
            }
            $times = self::generateHourlySlots($interval['start_time'], $interval['end_time']);
            $slots = [];
            foreach ($times as $time) {
                $sessionId = $sessionModel->findOrCreateSlotSession(
                    (int) $interval['trainer_id'],
                    $interval['type'],
                    $days[$dow]['date'],
                    $time,
                    (int) $interval['capacity'],
                    $createdBy
                );
                $booked = $sessionModel->getBookedCount($sessionId);
                $slotStatus = $sessionModel->getSlotBookingStatus($sessionId, $currentUserId, $isTrainerView);
                $capacity = 1;
                $slots[] = [
                    'time' => $time,
                    'session_id' => $sessionId,
                    'booked_count' => $booked,
                    'capacity' => $capacity,
                    'is_full' => $booked >= $capacity,
                    'status' => $slotStatus['status'],
                    'booked_by' => $slotStatus['booked_by'],
                    'booked_names' => $slotStatus['booked_names'] ?? [],
                ];
            }
            if (empty($slots)) {
                continue;
            }
            $days[$dow]['intervals'][] = [
                'interval_id' => (int) $interval['id'],
                'trainer_id' => (int) $interval['trainer_id'],
                'trainer_name' => $interval['trainer_name'],
                'type' => $interval['type'],
                'start_time' => substr($interval['start_time'], 0, 5),
                'end_time' => substr($interval['end_time'], 0, 5),
                'capacity' => 1,
                'slots' => $slots,
            ];
        }

        $ordered = [];
        foreach ([1, 2, 3, 4, 5, 6, 0] as $dow) {
            if (!empty($days[$dow]['intervals'])) {
                $ordered[] = $days[$dow];
            }
        }
        return $ordered;
    }
}
