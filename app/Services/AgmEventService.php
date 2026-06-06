<?php
namespace App\Services;

use PDO;

class AgmEventService
{
    public static function getCurrentEvent(): ?array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM agm_events WHERE is_current = 1 AND status IN ("published","closed") ORDER BY year DESC LIMIT 1');
        $stmt->execute();
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function getEventById(int $eventId): ?array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM agm_events WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $eventId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function getEventBySlug(int $year, string $slug): ?array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM agm_events WHERE year = :year AND slug = :slug LIMIT 1');
        $stmt->execute(['year' => $year, 'slug' => $slug]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function listEvents(array $filters = []): array
    {
        $pdo = Database::connection();
        $where = [];
        $params = [];
        if (!empty($filters['status'])) {
            $where[] = 'status = :status';
            $params['status'] = $filters['status'];
        }
        if (!empty($filters['year'])) {
            $where[] = 'year = :year';
            $params['year'] = (int) $filters['year'];
        }
        $sql = 'SELECT * FROM agm_events';
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY year DESC, id DESC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function createEvent(int $actorUserId, array $payload): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('INSERT INTO agm_events (year, slug, title, subtitle, hosting_chapter, venue_name, venue_address, venue_phone, start_date, end_date, registration_opens_at, registration_closes_at, late_fee_starts_at, description_html, cover_image_path, contact_name, contact_phone, contact_email, bank_transfer_instructions, allow_bank_transfer, allow_stripe, status, stripe_account_key, created_by_user_id, created_at) VALUES (:year, :slug, :title, :subtitle, :hosting_chapter, :venue_name, :venue_address, :venue_phone, :start_date, :end_date, :registration_opens_at, :registration_closes_at, :late_fee_starts_at, :description_html, :cover_image_path, :contact_name, :contact_phone, :contact_email, :bank_transfer_instructions, :allow_bank_transfer, :allow_stripe, :status, :stripe_account_key, :created_by_user_id, NOW())');
        $stmt->execute(self::eventBindings($payload, $actorUserId));
        $eventId = (int) $pdo->lastInsertId();
        ActivityLogger::log('admin', null, $actorUserId, 'agm.event_created', ['event_id' => $eventId, 'year' => $payload['year'] ?? null]);
        return $eventId;
    }

    public static function updateEvent(int $actorUserId, int $eventId, array $payload): bool
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('UPDATE agm_events SET year = :year, slug = :slug, title = :title, subtitle = :subtitle, hosting_chapter = :hosting_chapter, venue_name = :venue_name, venue_address = :venue_address, venue_phone = :venue_phone, start_date = :start_date, end_date = :end_date, registration_opens_at = :registration_opens_at, registration_closes_at = :registration_closes_at, late_fee_starts_at = :late_fee_starts_at, description_html = :description_html, cover_image_path = :cover_image_path, contact_name = :contact_name, contact_phone = :contact_phone, contact_email = :contact_email, bank_transfer_instructions = :bank_transfer_instructions, allow_bank_transfer = :allow_bank_transfer, allow_stripe = :allow_stripe, status = :status, stripe_account_key = :stripe_account_key, updated_at = NOW() WHERE id = :id');
        $bindings = self::eventBindings($payload, $actorUserId);
        unset($bindings['created_by_user_id']);
        $bindings['id'] = $eventId;
        $ok = $stmt->execute($bindings);
        if ($ok) {
            ActivityLogger::log('admin', null, $actorUserId, 'agm.event_updated', ['event_id' => $eventId]);
        }
        return $ok;
    }

    public static function setCurrentEvent(int $actorUserId, int $eventId): void
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $pdo->exec('UPDATE agm_events SET is_current = 0 WHERE is_current = 1');
            $stmt = $pdo->prepare('UPDATE agm_events SET is_current = 1, updated_at = NOW() WHERE id = :id');
            $stmt->execute(['id' => $eventId]);
            $pdo->commit();
            ActivityLogger::log('admin', null, $actorUserId, 'agm.event_set_current', ['event_id' => $eventId]);
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function archiveEvent(int $actorUserId, int $eventId): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('UPDATE agm_events SET status = "archived", is_current = 0, updated_at = NOW() WHERE id = :id');
        $stmt->execute(['id' => $eventId]);
        ActivityLogger::log('admin', null, $actorUserId, 'agm.event_archived', ['event_id' => $eventId]);
    }

    public static function getProducts(int $eventId, bool $activeOnly = false): array
    {
        $pdo = Database::connection();
        $sql = 'SELECT * FROM agm_products WHERE agm_event_id = :event_id';
        if ($activeOnly) {
            $sql .= ' AND is_active = 1';
        }
        $sql .= ' ORDER BY FIELD(category, "registration","merchandise","meal","custom"), sort_order, id';
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['event_id' => $eventId]);
        return $stmt->fetchAll();
    }

    public static function saveProduct(int $actorUserId, int $eventId, array $payload): int
    {
        $pdo = Database::connection();
        $bindings = [
            'agm_event_id' => $eventId,
            'category' => $payload['category'] ?? 'custom',
            'name' => trim((string) ($payload['name'] ?? '')),
            'description' => $payload['description'] ?? null,
            'early_price' => (float) ($payload['early_price'] ?? 0),
            'late_price' => isset($payload['late_price']) && $payload['late_price'] !== '' ? (float) $payload['late_price'] : null,
            'member_only' => !empty($payload['member_only']) ? 1 : 0,
            'non_member_only' => !empty($payload['non_member_only']) ? 1 : 0,
            'requires_choice' => !empty($payload['requires_choice']) ? 1 : 0,
            'choices_json' => !empty($payload['choices']) ? json_encode(array_values($payload['choices'])) : null,
            'quantity_limit' => isset($payload['quantity_limit']) && $payload['quantity_limit'] !== '' ? (int) $payload['quantity_limit'] : null,
            'per_registration_limit' => isset($payload['per_registration_limit']) && $payload['per_registration_limit'] !== '' ? (int) $payload['per_registration_limit'] : null,
            'sort_order' => (int) ($payload['sort_order'] ?? 0),
            'is_active' => isset($payload['is_active']) ? (!empty($payload['is_active']) ? 1 : 0) : 1,
        ];

        if (!empty($payload['id'])) {
            $bindings['id'] = (int) $payload['id'];
            $stmt = $pdo->prepare('UPDATE agm_products SET category = :category, name = :name, description = :description, early_price = :early_price, late_price = :late_price, member_only = :member_only, non_member_only = :non_member_only, requires_choice = :requires_choice, choices_json = :choices_json, quantity_limit = :quantity_limit, per_registration_limit = :per_registration_limit, sort_order = :sort_order, is_active = :is_active, updated_at = NOW() WHERE id = :id AND agm_event_id = :agm_event_id');
            $stmt->execute($bindings);
            $productId = (int) $bindings['id'];
            ActivityLogger::log('admin', null, $actorUserId, 'agm.product_updated', ['product_id' => $productId, 'event_id' => $eventId]);
            return $productId;
        }

        $stmt = $pdo->prepare('INSERT INTO agm_products (agm_event_id, category, name, description, early_price, late_price, member_only, non_member_only, requires_choice, choices_json, quantity_limit, per_registration_limit, sort_order, is_active, created_at) VALUES (:agm_event_id, :category, :name, :description, :early_price, :late_price, :member_only, :non_member_only, :requires_choice, :choices_json, :quantity_limit, :per_registration_limit, :sort_order, :is_active, NOW())');
        $stmt->execute($bindings);
        $productId = (int) $pdo->lastInsertId();
        ActivityLogger::log('admin', null, $actorUserId, 'agm.product_created', ['product_id' => $productId, 'event_id' => $eventId]);
        return $productId;
    }

    public static function deleteProduct(int $actorUserId, int $productId): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('DELETE FROM agm_products WHERE id = :id');
        $stmt->execute(['id' => $productId]);
        ActivityLogger::log('admin', null, $actorUserId, 'agm.product_deleted', ['product_id' => $productId]);
    }

    public static function getFormFields(int $eventId, bool $activeOnly = false): array
    {
        $pdo = Database::connection();
        $sql = 'SELECT * FROM agm_form_fields WHERE agm_event_id = :event_id';
        if ($activeOnly) {
            $sql .= ' AND is_active = 1';
        }
        $sql .= ' ORDER BY sort_order, id';
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['event_id' => $eventId]);
        return $stmt->fetchAll();
    }

    public static function saveFormField(int $actorUserId, int $eventId, array $payload): int
    {
        $pdo = Database::connection();
        $bindings = [
            'agm_event_id' => $eventId,
            'field_key' => preg_replace('/[^a-z0-9_]+/', '_', strtolower((string) ($payload['field_key'] ?? ''))),
            'label' => trim((string) ($payload['label'] ?? '')),
            'helper_text' => $payload['helper_text'] ?? null,
            'field_group' => $payload['field_group'] ?? 'other',
            'field_type' => $payload['field_type'] ?? 'text',
            'options_json' => !empty($payload['options']) ? json_encode(array_values($payload['options'])) : null,
            'is_required' => !empty($payload['is_required']) ? 1 : 0,
            'sort_order' => (int) ($payload['sort_order'] ?? 0),
            'is_active' => isset($payload['is_active']) ? (!empty($payload['is_active']) ? 1 : 0) : 1,
        ];

        if (!empty($payload['id'])) {
            $bindings['id'] = (int) $payload['id'];
            $stmt = $pdo->prepare('UPDATE agm_form_fields SET field_key = :field_key, label = :label, helper_text = :helper_text, field_group = :field_group, field_type = :field_type, options_json = :options_json, is_required = :is_required, sort_order = :sort_order, is_active = :is_active, updated_at = NOW() WHERE id = :id AND agm_event_id = :agm_event_id');
            $stmt->execute($bindings);
            $fieldId = (int) $bindings['id'];
            ActivityLogger::log('admin', null, $actorUserId, 'agm.form_field_updated', ['field_id' => $fieldId, 'event_id' => $eventId]);
            return $fieldId;
        }

        $stmt = $pdo->prepare('INSERT INTO agm_form_fields (agm_event_id, field_key, label, helper_text, field_group, field_type, options_json, is_required, sort_order, is_active, created_at) VALUES (:agm_event_id, :field_key, :label, :helper_text, :field_group, :field_type, :options_json, :is_required, :sort_order, :is_active, NOW())');
        $stmt->execute($bindings);
        $fieldId = (int) $pdo->lastInsertId();
        ActivityLogger::log('admin', null, $actorUserId, 'agm.form_field_created', ['field_id' => $fieldId, 'event_id' => $eventId]);
        return $fieldId;
    }

    public static function deleteFormField(int $actorUserId, int $fieldId): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('DELETE FROM agm_form_fields WHERE id = :id');
        $stmt->execute(['id' => $fieldId]);
        ActivityLogger::log('admin', null, $actorUserId, 'agm.form_field_deleted', ['field_id' => $fieldId]);
    }

    public static function cloneFromPrevious(int $actorUserId, int $sourceEventId, int $targetEventId): void
    {
        $sourceProducts = self::getProducts($sourceEventId);
        foreach ($sourceProducts as $product) {
            $payload = $product;
            unset($payload['id'], $payload['agm_event_id'], $payload['created_at'], $payload['updated_at']);
            $payload['choices'] = !empty($product['choices_json']) ? (json_decode($product['choices_json'], true) ?: []) : [];
            self::saveProduct($actorUserId, $targetEventId, $payload);
        }
        $sourceFields = self::getFormFields($sourceEventId);
        foreach ($sourceFields as $field) {
            $payload = $field;
            unset($payload['id'], $payload['agm_event_id'], $payload['created_at'], $payload['updated_at']);
            $payload['options'] = !empty($field['options_json']) ? (json_decode($field['options_json'], true) ?: []) : [];
            self::saveFormField($actorUserId, $targetEventId, $payload);
        }
        ActivityLogger::log('admin', null, $actorUserId, 'agm.event_cloned_from', [
            'source_event_id' => $sourceEventId,
            'target_event_id' => $targetEventId,
        ]);
    }

    private static function eventBindings(array $payload, int $actorUserId): array
    {
        return [
            'year' => (int) ($payload['year'] ?? 0),
            'slug' => trim((string) ($payload['slug'] ?? '')),
            'title' => trim((string) ($payload['title'] ?? '')),
            'subtitle' => $payload['subtitle'] ?? null,
            'hosting_chapter' => $payload['hosting_chapter'] ?? null,
            'venue_name' => $payload['venue_name'] ?? null,
            'venue_address' => $payload['venue_address'] ?? null,
            'venue_phone' => $payload['venue_phone'] ?? null,
            'start_date' => !empty($payload['start_date']) ? $payload['start_date'] : null,
            'end_date' => !empty($payload['end_date']) ? $payload['end_date'] : null,
            'registration_opens_at' => !empty($payload['registration_opens_at']) ? $payload['registration_opens_at'] : null,
            'registration_closes_at' => !empty($payload['registration_closes_at']) ? $payload['registration_closes_at'] : null,
            'late_fee_starts_at' => !empty($payload['late_fee_starts_at']) ? $payload['late_fee_starts_at'] : null,
            'description_html' => $payload['description_html'] ?? null,
            'cover_image_path' => $payload['cover_image_path'] ?? null,
            'contact_name' => $payload['contact_name'] ?? null,
            'contact_phone' => $payload['contact_phone'] ?? null,
            'contact_email' => $payload['contact_email'] ?? null,
            'bank_transfer_instructions' => $payload['bank_transfer_instructions'] ?? null,
            'allow_bank_transfer' => !empty($payload['allow_bank_transfer']) ? 1 : 0,
            'allow_stripe' => !empty($payload['allow_stripe']) ? 1 : 0,
            'status' => $payload['status'] ?? 'draft',
            'stripe_account_key' => $payload['stripe_account_key'] ?? 'agm',
            'created_by_user_id' => $actorUserId > 0 ? $actorUserId : null,
        ];
    }
}
