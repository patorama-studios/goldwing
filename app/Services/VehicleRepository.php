<?php
namespace App\Services;

use PDO;

class VehicleRepository
{
    public const ALLOWED_TYPES = ['bike', 'trike', 'sidecar', 'trailer'];
    private static ?bool $vehicleTableAvailable = null;

    private static function hasVehiclesTable(PDO $pdo): bool
    {
        if (self::$vehicleTableAvailable !== null) {
            return self::$vehicleTableAvailable;
        }

        try {
            $stmt = $pdo->query("SHOW TABLES LIKE 'member_vehicles'");
            self::$vehicleTableAvailable = (bool) $stmt->fetchColumn();
        } catch (\Throwable $e) {
            self::$vehicleTableAvailable = false;
        }

        return self::$vehicleTableAvailable;
    }

    public static function listByMember(int $memberId): array
    {
        $pdo = Database::connection();
        if (!self::hasVehiclesTable($pdo)) {
            return [];
        }
        $stmt = $pdo->prepare('SELECT * FROM member_vehicles WHERE member_id = :member_id ORDER BY is_primary DESC, created_at DESC');
        $stmt->execute(['member_id' => $memberId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function getById(int $vehicleId): ?array
    {
        $pdo = Database::connection();
        if (!self::hasVehiclesTable($pdo)) {
            return null;
        }
        $stmt = $pdo->prepare('SELECT * FROM member_vehicles WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $vehicleId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function create(int $memberId, array $payload): int
    {
        $pdo = Database::connection();
        if (!self::hasVehiclesTable($pdo)) {
            return 0;
        }
        $data = self::normalize($payload);
        $stmt = $pdo->prepare('INSERT INTO member_vehicles (member_id, vehicle_type, make, model, year_from, year_to, year_exact, is_primary, created_at) VALUES (:member_id, :vehicle_type, :make, :model, :year_from, :year_to, :year_exact, :is_primary, NOW())');
        $stmt->execute([
            'member_id' => $memberId,
            'vehicle_type' => $data['vehicle_type'],
            'make' => $data['make'],
            'model' => $data['model'],
            'year_from' => $data['year_from'],
            'year_to' => $data['year_to'],
            'year_exact' => $data['year_exact'],
            'is_primary' => $data['is_primary'],
        ]);
        $vehicleId = (int) $pdo->lastInsertId();
        if ($data['is_primary']) {
            self::setPrimary($memberId, $vehicleId);
        }
        return $vehicleId;
    }

    public static function update(int $vehicleId, array $payload): bool
    {
        $pdo = Database::connection();
        if (!self::hasVehiclesTable($pdo)) {
            return false;
        }
        $data = self::normalize($payload);
        $memberId = null;
        $existing = self::getById($vehicleId);
        if ($existing) {
            $memberId = (int) ($existing['member_id'] ?? 0);
        }
        $fields = [
            'vehicle_type = :vehicle_type',
            'make = :make',
            'model = :model',
            'year_from = :year_from',
            'year_to = :year_to',
            'year_exact = :year_exact',
            'is_primary = :is_primary',
        ];
        $stmt = $pdo->prepare('UPDATE member_vehicles SET ' . implode(', ', $fields) . ' WHERE id = :id');
        $result = $stmt->execute([
            'vehicle_type' => $data['vehicle_type'],
            'make' => $data['make'],
            'model' => $data['model'],
            'year_from' => $data['year_from'],
            'year_to' => $data['year_to'],
            'year_exact' => $data['year_exact'],
            'is_primary' => $data['is_primary'],
            'id' => $vehicleId,
        ]);
        if ($result && $data['is_primary'] && $memberId) {
            self::setPrimary($memberId, $vehicleId);
        }
        return $result !== false;
    }

    public static function delete(int $vehicleId): bool
    {
        $pdo = Database::connection();
        if (!self::hasVehiclesTable($pdo)) {
            return false;
        }
        $stmt = $pdo->prepare('DELETE FROM member_vehicles WHERE id = :id');
        return $stmt->execute(['id' => $vehicleId]);
    }

    public static function setPrimary(int $memberId, int $vehicleId): void
    {
        $pdo = Database::connection();
        if (!self::hasVehiclesTable($pdo)) {
            return;
        }
        $pdo->beginTransaction();
        try {
            $reset = $pdo->prepare('UPDATE member_vehicles SET is_primary = 0 WHERE member_id = :member_id');
            $reset->execute(['member_id' => $memberId]);
            $set = $pdo->prepare('UPDATE member_vehicles SET is_primary = 1 WHERE id = :id');
            $set->execute(['id' => $vehicleId]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    private static function normalize(array $payload): array
    {
        $data = [
            'vehicle_type' => null,
            'make' => null,
            'model' => null,
            'year_from' => null,
            'year_to' => null,
            'year_exact' => null,
            'is_primary' => 0,
        ];

        if (!empty($payload['vehicle_type']) && self::isAllowedType($payload['vehicle_type'])) {
            $data['vehicle_type'] = strtolower($payload['vehicle_type']);
        }
        if (isset($payload['make'])) {
            $make = trim((string) $payload['make']);
            $data['make'] = $make === '' ? null : $make;
        }
        if (isset($payload['model'])) {
            $model = trim((string) $payload['model']);
            $data['model'] = $model === '' ? null : $model;
        }
        if (isset($payload['year_from']) && $payload['year_from'] !== '') {
            $data['year_from'] = (int) $payload['year_from'];
        }
        if (isset($payload['year_to']) && $payload['year_to'] !== '') {
            $data['year_to'] = (int) $payload['year_to'];
        }
        if (isset($payload['year_exact']) && $payload['year_exact'] !== '') {
            $data['year_exact'] = (int) $payload['year_exact'];
        }
        if (!empty($payload['is_primary'])) {
            $data['is_primary'] = 1;
        }
        return $data;
    }

    private static function isAllowedType($value): bool
    {
        return in_array(strtolower((string) $value), self::ALLOWED_TYPES, true);
    }
}
