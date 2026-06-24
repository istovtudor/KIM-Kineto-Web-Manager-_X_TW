<?php
require_once dirname(__DIR__) . '/config/database.php';

class Room
{
    private PDO $db;

    public function __construct()
    {
        $this->db = getDb();
    }

    public function listAll(): array
    {
        return $this->db->query('SELECT * FROM rooms WHERE is_active = 1 ORDER BY name')->fetchAll();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM rooms WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function listRoomsFormatted(): array
    {
        $rows = $this->db->query(
            'SELECT id, name, capacity, is_active FROM rooms ORDER BY name'
        )->fetchAll();

        return array_map(static function (array $row): array {
            return [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'capacity' => (int) $row['capacity'],
                'available' => (int) ($row['is_active'] ?? 1) === 1,
            ];
        }, $rows);
    }

    public function listEquipmentFormatted(): array
    {
        $rows = $this->db->query(
            'SELECT e.id, e.name, e.status, e.room_id, r.name AS room_name
             FROM equipment e
             LEFT JOIN rooms r ON r.id = e.room_id
             ORDER BY e.name'
        )->fetchAll();

        return array_map(static function (array $row): array {
            return [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'status' => (string) $row['status'],
                'room_id' => $row['room_id'] !== null ? (int) $row['room_id'] : null,
                'room_name' => $row['room_name'] ?? null,
            ];
        }, $rows);
    }

    public function addRoom(string $name, int $capacity, bool $available): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO rooms (name, capacity, description, is_active) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$name, $capacity, '', $available ? 1 : 0]);
        return (int) $this->db->lastInsertId();
    }

    public static function mapEquipmentStatus(string $status): string
    {
        $map = [
            'bun' => 'available',
            'defect' => 'retired',
            'mentenanta' => 'maintenance',
            'available' => 'available',
            'maintenance' => 'maintenance',
            'retired' => 'retired',
        ];
        return $map[strtolower($status)] ?? 'available';
    }

    public static function equipmentStatusLabel(string $status): string
    {
        $labels = [
            'available' => 'Bun',
            'maintenance' => 'In mentenanta',
            'retired' => 'Defect',
        ];
        return $labels[$status] ?? $status;
    }

    public function addEquipment(string $name, string $status, ?int $roomId): int
    {
        $stmt = $this->db->prepare('INSERT INTO equipment (name, room_id, status) VALUES (?, ?, ?)');
        $stmt->execute([
            $name,
            $roomId ?: null,
            self::mapEquipmentStatus($status),
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function create(array $data): int
    {
        $stmt = $this->db->prepare('INSERT INTO rooms (name, capacity, description) VALUES (?, ?, ?)');
        $stmt->execute([$data['name'], $data['capacity'] ?? 10, $data['description'] ?? '']);
        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, array $data): bool
    {
        $stmt = $this->db->prepare('UPDATE rooms SET name=?, capacity=?, description=?, is_active=? WHERE id=?');
        return $stmt->execute([
            $data['name'], $data['capacity'] ?? 10, $data['description'] ?? '',
            $data['is_active'] ?? 1, $id,
        ]);
    }

    public function delete(int $id): bool
    {
        return $this->db->prepare('UPDATE rooms SET is_active = 0 WHERE id = ?')->execute([$id]);
    }

    public function listEquipment(): array
    {
        return $this->db->query(
            'SELECT e.*, r.name AS room_name FROM equipment e LEFT JOIN rooms r ON r.id = e.room_id ORDER BY e.name'
        )->fetchAll();
    }

    public function createEquipment(array $data): int
    {
        $stmt = $this->db->prepare('INSERT INTO equipment (name, room_id, status) VALUES (?, ?, ?)');
        $stmt->execute([$data['name'], $data['room_id'] ?? null, $data['status'] ?? 'available']);
        return (int) $this->db->lastInsertId();
    }

    public function updateEquipment(int $id, array $data): bool
    {
        $stmt = $this->db->prepare('UPDATE equipment SET name=?, room_id=?, status=? WHERE id=?');
        return $stmt->execute([$data['name'], $data['room_id'] ?? null, $data['status'] ?? 'available', $id]);
    }

    public function deleteEquipment(int $id): bool
    {
        return $this->db->prepare('DELETE FROM equipment WHERE id = ?')->execute([$id]);
    }
}
