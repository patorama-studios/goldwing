<?php
namespace App\Services;

use App\Services\Database;
use PDO;

class ActivityRepository
{
    public static function listByMember(int $memberId, array $filters = [], int $limit = 50): array
    {
        $pdo = Database::connection();
        $sql = 'SELECT * FROM activity_log WHERE member_id = :member_id';
        $params = ['member_id' => $memberId];

        if (!empty($filters['actor_type'])) {
            $actor = $filters['actor_type'];
            $sql .= ' AND actor_type = :actor_type';
            $params['actor_type'] = $actor;
        }

        if (!empty($filters['action'])) {
            $sql .= ' AND action LIKE :action_filter';
            $params['action_filter'] = '%' . $filters['action'] . '%';
        }

        if (!empty($filters['start'])) {
            $sql .= ' AND created_at >= :start_date';
            $params['start_date'] = $filters['start'];
        }

        if (!empty($filters['end'])) {
            $sql .= ' AND created_at <= :end_date';
            $params['end_date'] = $filters['end'];
        }

        $sql .= ' ORDER BY created_at DESC LIMIT :limit';

        $stmt = $pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $paramType = $key === 'member_id' ? PDO::PARAM_INT : PDO::PARAM_STR;
            $stmt->bindValue(':' . $key, $value, $paramType);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
