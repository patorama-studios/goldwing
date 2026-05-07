-- Rename the chapter_leader role slug and name to area_rep across the system
UPDATE roles
SET name        = 'area_rep',
    slug        = 'area_rep',
    description = 'Area Representative',
    updated_at  = NOW()
WHERE slug = 'chapter_leader';

-- Update the role_permissions references (stored by role_id FK, no text change needed)
-- Update any display/description in settings that reference the old name as a string literal
UPDATE role_permissions rp
JOIN roles r ON r.id = rp.role_id
SET rp.updated_at = NOW()
WHERE r.slug = 'area_rep';
