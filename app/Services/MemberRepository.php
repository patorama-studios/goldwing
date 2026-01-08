<?php
namespace App\Services;

use DateTimeImmutable;
use PDO;

class MemberRepository
{
    private static ?bool $memberAuthAvailable = null;
    private static ?bool $memberNumberAvailable = null;
    private static array $memberColumnCache = [];
    private static array $orderColumnCache = [];
    private static array $directoryPreferenceCache = [];

    private static function hasMemberAuthTable(PDO $pdo): bool
    {
        if (self::$memberAuthAvailable !== null) {
            return self::$memberAuthAvailable;
        }

        try {
            $stmt = $pdo->query("SHOW TABLES LIKE 'member_auth'");
            self::$memberAuthAvailable = (bool) $stmt->fetchColumn();
        } catch (\Throwable $e) {
            self::$memberAuthAvailable = false;
        }

        return self::$memberAuthAvailable;
    }

    public static function hasMemberNumberColumn(PDO $pdo): bool
    {
        if (self::$memberNumberAvailable !== null) {
            return self::$memberNumberAvailable;
        }

        try {
            $stmt = $pdo->query("SHOW COLUMNS FROM members LIKE 'member_number'");
            self::$memberNumberAvailable = (bool) $stmt->fetchColumn();
        } catch (\Throwable $e) {
            self::$memberNumberAvailable = false;
        }

        return self::$memberNumberAvailable;
    }

    private static function hasMemberColumn(PDO $pdo, string $column): bool
    {
        if (array_key_exists($column, self::$memberColumnCache)) {
            return self::$memberColumnCache[$column];
        }

        try {
            $stmt = $pdo->query("SHOW COLUMNS FROM members LIKE " . $pdo->quote($column));
            self::$memberColumnCache[$column] = (bool) $stmt->fetchColumn();
        } catch (\Throwable $e) {
            self::$memberColumnCache[$column] = false;
        }

        return self::$memberColumnCache[$column];
    }

    private static function hasOrderColumn(PDO $pdo, string $column): bool
    {
        if (array_key_exists($column, self::$orderColumnCache)) {
            return self::$orderColumnCache[$column];
        }

        try {
            $stmt = $pdo->query("SHOW COLUMNS FROM orders LIKE " . $pdo->quote($column));
            self::$orderColumnCache[$column] = (bool) $stmt->fetchColumn();
        } catch (\Throwable $e) {
            self::$orderColumnCache[$column] = false;
        }

        return self::$orderColumnCache[$column];
    }

    public static function search(array $filters, int $limit, int $offset): array
    {
        $pdo = Database::connection();
        $params = [];
        $whereClause = self::buildWhereClause($filters, $params);
        $memberAuthAvailable = self::hasMemberAuthTable($pdo);
        $memberAuthSelect = $memberAuthAvailable ? 'ma.last_login_at' : 'NULL AS last_login_at';
        $memberAuthJoin = $memberAuthAvailable ? 'LEFT JOIN member_auth ma ON ma.member_id = m.id ' : '';
        $memberNumberSelect = self::hasMemberNumberColumn($pdo)
            ? 'COALESCE(m.member_number, CONCAT(m.member_number_base, CASE WHEN m.member_number_suffix > 0 THEN CONCAT(".", m.member_number_suffix) ELSE "" END))'
            : 'CONCAT(m.member_number_base, CASE WHEN m.member_number_suffix > 0 THEN CONCAT(".", m.member_number_suffix) ELSE "" END)';
        $membershipTypeAvailable = self::hasMemberColumn($pdo, 'membership_type_id');
        $membershipTypeSelect = $membershipTypeAvailable ? 'mt.name AS membership_type_name' : 'NULL AS membership_type_name';
        $membershipTypeJoin = $membershipTypeAvailable ? 'LEFT JOIN membership_types mt ON mt.id = m.membership_type_id ' : '';

        $base = 'SELECT m.*, c.name AS chapter_name, ' . $membershipTypeSelect . ', ' . $memberAuthSelect . ', '
            . 'u.id AS user_id, u.email AS user_email, u2.enabled_at AS twofa_enabled_at, uo.twofa_override, '
            . '(SELECT GROUP_CONCAT(r.name) FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = u.id) AS user_roles_csv, '
            . $memberNumberSelect . ' AS member_number_display '
            . 'FROM members m '
            . 'LEFT JOIN chapters c ON c.id = m.chapter_id '
            . $membershipTypeJoin
            . $memberAuthJoin
            . 'LEFT JOIN users u ON u.id = m.user_id '
            . 'LEFT JOIN user_2fa u2 ON u2.user_id = u.id '
            . 'LEFT JOIN user_security_overrides uo ON uo.user_id = u.id';

        if ($whereClause !== '') {
            $base .= ' WHERE ' . $whereClause;
        }

        $base .= ' ORDER BY m.created_at DESC, m.last_name ASC LIMIT :limit OFFSET :offset';

        $stmt = $pdo->prepare($base);
        self::bindParams($stmt, $params);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            if (!empty($row['member_number_base'])) {
                $row['member_number_display'] = MembershipService::displayMembershipNumber(
                    (int) $row['member_number_base'],
                    (int) ($row['member_number_suffix'] ?? 0)
                );
            }
        }
        unset($row);

        $total = self::countMembers($whereClause, $params);

        return ['data' => $rows, 'total' => $total];
    }

    public static function stats(?int $chapterId = null): array
    {
        $pdo = Database::connection();
        $sql = 'SELECT status, COUNT(*) as c FROM members';
        $params = [];
        if ($chapterId !== null) {
            $sql .= ' WHERE chapter_id = :chapter_id';
            $params['chapter_id'] = $chapterId;
        }
        $sql .= ' GROUP BY status';
        $stmt = $pdo->prepare($sql);
        if ($chapterId !== null) {
            $stmt->bindValue(':chapter_id', $chapterId, PDO::PARAM_INT);
        }
        $stmt->execute();

        $result = [
            'total' => 0,
            'active' => 0,
            'pending' => 0,
            'expired' => 0,
            'cancelled' => 0,
            'suspended' => 0,
            'new_last_30_days' => 0,
            'renewals_this_month' => 0,
        ];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $status = $row['status'];
            $count = (int) $row['c'];
            $result['total'] += $count;
            if (isset($result[$status])) {
                $result[$status] = $count;
            }
        }

        $recent = (new DateTimeImmutable('now'))->modify('-30 days')->format('Y-m-d H:i:s');
        $recentSql = 'SELECT COUNT(*) FROM members WHERE created_at >= :recent';
        $recentParams = ['recent' => $recent];
        if ($chapterId !== null) {
            $recentSql .= ' AND chapter_id = :chapter_id';
            $recentParams['chapter_id'] = $chapterId;
        }
        $stmt = $pdo->prepare($recentSql);
        foreach ($recentParams as $key => $value) {
            $paramType = $key === 'chapter_id' ? PDO::PARAM_INT : PDO::PARAM_STR;
            $stmt->bindValue(':' . $key, $value, $paramType);
        }
        $stmt->execute();
        $result['new_last_30_days'] = (int) $stmt->fetchColumn();

        if (self::hasOrderColumn($pdo, 'member_id')) {
            $monthStart = (new DateTimeImmutable('first day of this month'))->setTime(0, 0, 0)->format('Y-m-d H:i:s');
            $monthEnd = (new DateTimeImmutable('last day of this month'))->setTime(23, 59, 59)->format('Y-m-d H:i:s');
            $renewalSql = 'SELECT COUNT(*) FROM orders o JOIN members m ON m.id = o.member_id WHERE o.order_type = \'membership\' AND o.status = \'paid\' AND o.paid_at BETWEEN :start AND :end';
            $renewalParams = ['start' => $monthStart, 'end' => $monthEnd];
            if ($chapterId !== null) {
                $renewalSql .= ' AND m.chapter_id = :chapter_id';
                $renewalParams['chapter_id'] = $chapterId;
            }
            $stmt = $pdo->prepare($renewalSql);
            foreach ($renewalParams as $key => $value) {
                $paramType = $key === 'chapter_id' ? PDO::PARAM_INT : PDO::PARAM_STR;
                $stmt->bindValue(':' . $key, $value, $paramType);
            }
            $stmt->execute();
            $result['renewals_this_month'] = (int) $stmt->fetchColumn();
        }

        return $result;
    }

    public static function findById(int $memberId): ?array
    {
        $pdo = Database::connection();
        $memberAuthAvailable = self::hasMemberAuthTable($pdo);
        $memberAuthSelect = $memberAuthAvailable
            ? 'ma.last_login_at, ma.failed_login_count'
            : 'NULL AS last_login_at, NULL AS failed_login_count';
        $memberAuthJoin = $memberAuthAvailable ? 'LEFT JOIN member_auth ma ON ma.member_id = m.id ' : '';
        $membershipTypeAvailable = self::hasMemberColumn($pdo, 'membership_type_id');
        $membershipTypeSelect = $membershipTypeAvailable ? 'mt.name AS membership_type_name' : 'NULL AS membership_type_name';
        $membershipTypeJoin = $membershipTypeAvailable ? 'LEFT JOIN membership_types mt ON mt.id = m.membership_type_id ' : '';
        $stmt = $pdo->prepare('SELECT m.*, c.name AS chapter_name, ' . $membershipTypeSelect . ', '
            . $memberAuthSelect
            . ', u.id AS user_id, u.email AS user_email, u2.enabled_at AS twofa_enabled_at, uo.twofa_override, '
            . '(SELECT GROUP_CONCAT(r.name) FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = u.id) AS user_roles_csv '
            . 'FROM members m '
            . 'LEFT JOIN chapters c ON c.id = m.chapter_id '
            . $membershipTypeJoin
            . $memberAuthJoin
            . 'LEFT JOIN users u ON u.id = m.user_id '
            . 'LEFT JOIN user_2fa u2 ON u2.user_id = u.id '
            . 'LEFT JOIN user_security_overrides uo ON uo.user_id = u.id '
            . 'WHERE m.id = :id LIMIT 1');
        $stmt->execute(['id' => $memberId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $row['member_number_display'] = $row['member_number_display'] ?? self::formatMemberNumber($row);
        return $row;
    }

    public static function update(int $memberId, array $payload): bool
    {
        $fields = [];
        $params = ['id' => $memberId];
        $pdo = Database::connection();
        $mapping = [
            'first_name' => 'first_name',
            'last_name' => 'last_name',
            'email' => 'email',
            'phone' => 'phone',
            'address_line1' => 'address_line1',
            'address_line2' => 'address_line2',
            'state' => 'state',
            'postcode' => 'postal_code',
            'country' => 'country',
            'chapter_id' => 'chapter_id',
            'membership_type_id' => 'membership_type_id',
            'full_member_id' => 'full_member_id',
            'status' => 'status',
        ];

        $columnValues = [];
        foreach ($mapping as $input => $column) {
            if (!array_key_exists($input, $payload)) {
                continue;
            }
            if (!self::hasMemberColumn($pdo, $column)) {
                continue;
            }
            $value = $payload[$input];
            if (is_string($value)) {
                $value = trim($value);
            }
            if ($column === 'status') {
                $mapped = self::normalizeStatus($value);
                if ($mapped === null) {
                    continue;
                }
                $value = $mapped;
            }
            $columnValues[$column] = $value === '' ? null : $value;
        }

        if (array_key_exists('suburb', $payload)) {
            $value = trim($payload['suburb']);
            $normalizedSuburb = $value === '' ? null : $value;
            if (self::hasMemberColumn($pdo, 'suburb')) {
                $columnValues['suburb'] = $normalizedSuburb;
            }
            if (self::hasMemberColumn($pdo, 'city')) {
                $columnValues['city'] = $normalizedSuburb;
            }
        }

        $prefMap = self::directoryPreferenceMap();
        foreach ($prefMap as $letter => $column) {
            $columnValues[$column] = !empty($payload['directory_pref_' . $letter]) ? 1 : 0;
        }

        if (isset($payload['notes']) && self::hasMemberColumn($pdo, 'notes')) {
            $columnValues['notes'] = trim((string) $payload['notes']);
        }

        if ($columnValues === []) {
            return false;
        }

        foreach ($columnValues as $column => $value) {
            $fields[] = "$column = :$column";
            $params[$column] = $value;
        }

        $fields[] = 'updated_at = NOW()';
        $sql = 'UPDATE members SET ' . implode(', ', $fields) . ' WHERE id = :id';
        $stmt = Database::connection()->prepare($sql);
        try {
            return $stmt->execute($params);
        } catch (\PDOException $exception) {
            $message = $exception->getMessage();
            if (stripos($message, 'Unknown column') !== false) {
                $matches = [];
                preg_match_all("/Unknown column '([^']+)'/i", $message, $matches);
                foreach ($matches[1] ?? [] as $column) {
                    unset($columnValues[$column]);
                    unset($params[$column]);
                }
                if ($columnValues === []) {
                    return false;
                }
                $fields = [];
                foreach ($columnValues as $column => $value) {
                    $fields[] = "$column = :$column";
                }
                $fields[] = 'updated_at = NOW()';
                $sql = 'UPDATE members SET ' . implode(', ', $fields) . ' WHERE id = :id';
                $stmt = Database::connection()->prepare($sql);
                return $stmt->execute($params);
            }
            throw $exception;
        }
    }

    public static function directoryPreferences(): array
    {
        if (self::$directoryPreferenceCache !== []) {
            return self::$directoryPreferenceCache;
        }
        $pdo = Database::connection();
        foreach (self::directoryPreferenceCandidates() as $letter => $info) {
            $column = self::resolveDirectoryPreferenceColumn($pdo, $info['columns']);
            if ($column === null) {
                continue;
            }
            self::$directoryPreferenceCache[$letter] = [
                'label' => $info['label'],
                'column' => $column,
            ];
        }
        return self::$directoryPreferenceCache;
    }

    public static function summarizeDirectoryPreferences(array $member): array
    {
        $summary = [];
        foreach (self::directoryPreferences() as $letter => $info) {
            $summary[$letter] = isset($member[$info['column']]) && (int) $member[$info['column']] === 1;
        }
        return $summary;
    }

    private static function normalizeStatus(string $value): ?string
    {
        $value = strtoupper(trim($value));
        $map = [
            'PENDING' => 'PENDING',
            'ACTIVE' => 'ACTIVE',
            'EXPIRED' => 'LAPSED',
            'CANCELLED' => 'INACTIVE',
            'SUSPENDED' => 'INACTIVE',
            'LAPSED' => 'LAPSED',
            'INACTIVE' => 'INACTIVE',
        ];
        return $map[$value] ?? null;
    }

    private static function buildWhereClause(array $filters, array &$params): string
    {
        $parts = [];
        if (!empty($filters['q'])) {
            $value = '%' . mb_strtolower((string) $filters['q']) . '%';
            $params['search'] = $value;
            $parts[] = '(LOWER(m.first_name) LIKE :search OR LOWER(m.last_name) LIKE :search OR LOWER(m.email) LIKE :search OR LOWER(COALESCE(m.member_number, CONCAT(m.member_number_base, CASE WHEN m.member_number_suffix > 0 THEN CONCAT(".", m.member_number_suffix) ELSE "" END))) LIKE :search)';
        }
        if (!empty($filters['chapter_id'])) {
            $parts[] = 'm.chapter_id = :chapter_id';
            $params['chapter_id'] = (int) $filters['chapter_id'];
        }
        if (!empty($filters['membership_type_id'])) {
            $parts[] = 'm.membership_type_id = :membership_type_id';
            $params['membership_type_id'] = (int) $filters['membership_type_id'];
        }
        if (!empty($filters['status'])) {
            $parts[] = 'm.status = :status';
            $params['status'] = $filters['status'];
        } elseif (!empty($filters['exclude_status'])) {
            $parts[] = 'm.status <> :exclude_status';
            $params['exclude_status'] = $filters['exclude_status'];
        }
        if (!empty($filters['role'])) {
            $parts[] = 'EXISTS (SELECT 1 FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = m.user_id AND r.name = :role)';
            $params['role'] = $filters['role'];
        }

        $prefList = self::normalizeDirectoryPreferences($filters['directory_prefs'] ?? []);
        if ($prefList !== []) {
            $prefConditions = [];
            foreach ($prefList as $letter) {
                $column = self::directoryPreferenceMap()[$letter] ?? null;
                if ($column) {
                    $prefConditions[] = "m.$column = 1";
                }
            }
            if ($prefConditions) {
                $parts[] = '(' . implode(' OR ', $prefConditions) . ')';
            }
        }

        if (!empty($filters['vehicle_type'])) {
            $value = strtolower($filters['vehicle_type']);
            if (in_array($value, ['bike', 'trike', 'sidecar', 'trailer'], true)) {
                self::addVehicleExistsCondition($parts, $params, ['mv.vehicle_type = :vehicle_type'], ['vehicle_type' => $value]);
            }
        }
        if (!empty($filters['vehicle_make'])) {
            $value = '%' . mb_strtolower((string) $filters['vehicle_make']) . '%';
            self::addVehicleExistsCondition($parts, $params, ['LOWER(mv.make) LIKE :vehicle_make'], ['vehicle_make' => $value]);
        }
        if (!empty($filters['vehicle_model'])) {
            $value = '%' . mb_strtolower((string) $filters['vehicle_model']) . '%';
            self::addVehicleExistsCondition($parts, $params, ['LOWER(mv.model) LIKE :vehicle_model'], ['vehicle_model' => $value]);
        }

        if (!empty($filters['vehicle_year_exact'])) {
            $year = (int) $filters['vehicle_year_exact'];
            $condition = '(mv.year_exact = :vehicle_year_exact OR (mv.year_from IS NOT NULL AND mv.year_to IS NOT NULL AND mv.year_from <= :vehicle_year_exact AND mv.year_to >= :vehicle_year_exact))';
            self::addVehicleExistsCondition($parts, $params, [$condition], ['vehicle_year_exact' => $year]);
        }

        $rangeConditions = [];
        if (isset($filters['vehicle_year_from']) && $filters['vehicle_year_from'] !== '') {
            $from = (int) $filters['vehicle_year_from'];
            $rangeConditions[] = '(COALESCE(mv.year_exact, mv.year_from, mv.year_to) >= :vehicle_year_from)';
            $params['vehicle_year_from'] = $from;
        }
        if (isset($filters['vehicle_year_to']) && $filters['vehicle_year_to'] !== '') {
            $to = (int) $filters['vehicle_year_to'];
            $rangeConditions[] = '(COALESCE(mv.year_exact, mv.year_from, mv.year_to) <= :vehicle_year_to)';
            $params['vehicle_year_to'] = $to;
        }
        if ($rangeConditions !== []) {
            self::addVehicleExistsCondition($parts, $params, $rangeConditions);
        }

        foreach (['trike', 'trailer', 'sidecar'] as $type) {
            $key = 'has_' . $type;
            $value = isset($filters[$key]) ? filter_var($filters[$key], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : null;
            if ($value === true) {
                self::addVehicleExistsCondition($parts, $params, ["mv.vehicle_type = :vehicle_type_{$type}"], ["vehicle_type_{$type}" => $type]);
            }
        }

        return implode(' AND ', $parts);
    }

    private static function bindParams(\PDOStatement $stmt, array $params): void
    {
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
    }

    private static function countMembers(string $whereClause, array $params): int
    {
        $pdo = Database::connection();
        $sql = 'SELECT COUNT(*) FROM members m';
        if ($whereClause !== '') {
            $sql .= ' WHERE ' . $whereClause;
        }
        $stmt = $pdo->prepare($sql);
        self::bindParams($stmt, $params);
        $stmt->execute();
        return (int) $stmt->fetchColumn();
    }

    private static function addVehicleExistsCondition(array &$parts, array &$params, array $conditions, array $extraParams = []): void
    {
        if ($conditions === []) {
            return;
        }
        $clause = 'EXISTS (SELECT 1 FROM member_vehicles mv WHERE mv.member_id = m.id';
        foreach ($conditions as $condition) {
            $clause .= ' AND ' . $condition;
        }
        $clause .= ')';
        $parts[] = $clause;
        foreach ($extraParams as $key => $value) {
            $params[$key] = $value;
        }
    }

    private static function directoryPreferenceMap(): array
    {
        $map = [];
        foreach (self::directoryPreferences() as $letter => $info) {
            $map[$letter] = $info['column'];
        }
        return $map;
    }

    private static function normalizeDirectoryPreferences($input): array
    {
        if (!is_array($input)) {
            return [];
        }
        $clean = [];
        foreach ($input as $pref) {
            $pref = strtoupper(trim((string) $pref));
            if (isset(self::directoryPreferences()[$pref])) {
                $clean[] = $pref;
            }
        }
        return array_values(array_unique($clean));
    }

    private static function directoryPreferenceCandidates(): array
    {
        return [
            'A' => ['label' => 'Collect motorcycle', 'columns' => ['directory_pref_a_collect_motorcycle', 'assist_ute']],
            'B' => ['label' => 'Accept phone calls', 'columns' => ['directory_pref_b_accept_calls', 'assist_phone']],
            'C' => ['label' => 'Provide bed or tent space', 'columns' => ['directory_pref_c_bed_or_tent', 'assist_bed']],
            'D' => ['label' => 'Provide tools or workshop', 'columns' => ['directory_pref_d_tools_or_workshop', 'assist_tools']],
            'E' => ['label' => 'Exclude Member Directory', 'columns' => ['directory_pref_e_exclude_member_directory', 'exclude_printed']],
            'F' => ['label' => 'Exclude Electronic Directory', 'columns' => ['directory_pref_f_exclude_electronic_directory', 'exclude_electronic']],
        ];
    }

    private static function resolveDirectoryPreferenceColumn(PDO $pdo, array $candidates): ?string
    {
        foreach ($candidates as $column) {
            if (self::hasMemberColumn($pdo, $column)) {
                return $column;
            }
        }
        return null;
    }


    private static function formatMemberNumber(array $member): string
    {
        $base = $member['member_number_base'] ?? null;
        $suffix = $member['member_number_suffix'] ?? 0;
        if ($base === null) {
            return '—';
        }
        return MembershipService::displayMembershipNumber((int) $base, (int) $suffix);
    }
}
