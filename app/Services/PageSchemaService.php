<?php
namespace App\Services;

class PageSchemaService
{
    private const ALLOWED_LAYOUTS = ['default', 'full-width', 'landing'];
    private const ALLOWED_BLOCK_TYPES = [
        'hero',
        'text',
        'image',
        'gallery',
        'video',
        'button',
        'cta',
        'quote',
        'faq',
        'pricing',
        'testimonial',
        'form',
        'section',
        'columns',
        'spacer',
        'divider',
        'latest_posts',
        'upcoming_events',
        'user_profile',
        'membership_status',
        'notifications',
    ];

    public static function decode(string $raw, ?string &$error = null): ?array
    {
        $raw = trim($raw);
        if ($raw === '') {
            $error = 'Empty AI response.';
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $error = 'AI response was not valid JSON.';
            return null;
        }
        return $decoded;
    }

    public static function normalizeResponse(array $response): array
    {
        $summary = trim((string) ($response['summary'] ?? ''));
        if ($summary === '') {
            $summary = 'AI page update';
        }
        $result = [
            'summary' => $summary,
        ];

        if (isset($response['page']) && is_array($response['page'])) {
            $result['page'] = ['page' => $response['page']];
            return $result;
        }

        if (isset($response['blocks'])) {
            $blocks = is_array($response['blocks']) ? $response['blocks'] : [];
            $result['blocks'] = $blocks;
            return $result;
        }

        if (isset($response['block']) && is_array($response['block'])) {
            $result['blocks'] = [$response['block']];
            return $result;
        }

        return $result;
    }

    public static function applyBlockUpdates(array $schema, array $updatedBlocks): array
    {
        if (empty($schema['page']['blocks']) || !is_array($schema['page']['blocks'])) {
            return $schema;
        }
        $lookup = [];
        foreach ($updatedBlocks as $block) {
            if (is_array($block) && !empty($block['id'])) {
                $lookup[$block['id']] = $block;
            }
        }
        if (!$lookup) {
            return $schema;
        }
        $schema['page']['blocks'] = self::applyBlocksRecursive($schema['page']['blocks'], $lookup);
        return $schema;
    }

    private static function applyBlocksRecursive(array $blocks, array $lookup): array
    {
        $updated = [];
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            $id = $block['id'] ?? '';
            if ($id !== '' && isset($lookup[$id])) {
                $block = $lookup[$id];
            }
            $type = $block['type'] ?? '';
            if ($type === 'section' && isset($block['content']['blocks']) && is_array($block['content']['blocks'])) {
                $block['content']['blocks'] = self::applyBlocksRecursive($block['content']['blocks'], $lookup);
            }
            if ($type === 'columns' && isset($block['content']['columns']) && is_array($block['content']['columns'])) {
                $columns = [];
                foreach ($block['content']['columns'] as $column) {
                    if (!is_array($column)) {
                        continue;
                    }
                    if (isset($column['blocks']) && is_array($column['blocks'])) {
                        $column['blocks'] = self::applyBlocksRecursive($column['blocks'], $lookup);
                    }
                    $columns[] = $column;
                }
                $block['content']['columns'] = $columns;
            }
            $updated[] = $block;
        }
        return $updated;
    }

    public static function normalizePage(array $schema, array $fallbackPage = []): array
    {
        $page = $schema['page'] ?? [];
        if (!is_array($page)) {
            $page = [];
        }

        $page['id'] = $page['id'] ?? ($fallbackPage['id'] ?? self::uuid());
        $page['slug'] = $fallbackPage['slug'] ?? ($page['slug'] ?? 'page');
        $page['title'] = $page['title'] ?? ($fallbackPage['title'] ?? 'Untitled Page');
        $page['layout'] = $page['layout'] ?? ($fallbackPage['layout'] ?? 'default');
        if (!in_array($page['layout'], self::ALLOWED_LAYOUTS, true)) {
            $page['layout'] = 'default';
        }

        $seo = $page['seo'] ?? [];
        if (!is_array($seo)) {
            $seo = [];
        }
        $seo['meta_title'] = $seo['meta_title'] ?? ($page['title'] ?? '');
        $seo['meta_description'] = $seo['meta_description'] ?? '';
        $page['seo'] = $seo;

        $blocks = $page['blocks'] ?? [];
        $page['blocks'] = self::normalizeBlocks(is_array($blocks) ? $blocks : []);

        return ['page' => $page];
    }

    public static function validatePage(array $schema): array
    {
        $errors = [];
        if (empty($schema['page']) || !is_array($schema['page'])) {
            return ['Missing page object.'];
        }
        $page = $schema['page'];
        if (empty($page['id'])) {
            $errors[] = 'Page id is required.';
        }
        if (empty($page['slug'])) {
            $errors[] = 'Page slug is required.';
        }
        if (empty($page['title'])) {
            $errors[] = 'Page title is required.';
        }
        if (!in_array($page['layout'] ?? 'default', self::ALLOWED_LAYOUTS, true)) {
            $errors[] = 'Invalid page layout.';
        }
        if (!isset($page['blocks']) || !is_array($page['blocks'])) {
            $errors[] = 'Page blocks must be an array.';
        }

        $ids = [];
        self::collectBlockIds($page['blocks'] ?? [], $ids, $errors);
        return $errors;
    }

    private static function collectBlockIds(array $blocks, array &$ids, array &$errors): void
    {
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                $errors[] = 'Invalid block structure.';
                continue;
            }
            $id = $block['id'] ?? '';
            $type = $block['type'] ?? '';
            if ($id === '') {
                $errors[] = 'Block id missing.';
            } elseif (isset($ids[$id])) {
                $errors[] = 'Duplicate block id: ' . $id;
            } else {
                $ids[$id] = true;
            }
            if ($type === '' || !in_array($type, self::ALLOWED_BLOCK_TYPES, true)) {
                $errors[] = 'Unsupported block type: ' . ($type ?: 'unknown');
            }
            if (($block['content']['blocks'] ?? null) && is_array($block['content']['blocks'])) {
                self::collectBlockIds($block['content']['blocks'], $ids, $errors);
            }
            if (($block['content']['columns'] ?? null) && is_array($block['content']['columns'])) {
                foreach ($block['content']['columns'] as $column) {
                    if (is_array($column) && isset($column['blocks']) && is_array($column['blocks'])) {
                        self::collectBlockIds($column['blocks'], $ids, $errors);
                    }
                }
            }
        }
    }

    private static function normalizeBlocks(array $blocks): array
    {
        $normalized = [];
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            if (empty($block['id'])) {
                $block['id'] = self::uuid();
            }
            $block['type'] = $block['type'] ?? 'text';
            $block['settings'] = is_array($block['settings'] ?? null) ? $block['settings'] : [];
            $block['content'] = is_array($block['content'] ?? null) ? $block['content'] : [];
            $block['style'] = is_array($block['style'] ?? null) ? $block['style'] : [];
            $block['visibility'] = is_array($block['visibility'] ?? null) ? $block['visibility'] : [
                'roles' => [],
                'logged_in_only' => false,
            ];
            if ($block['type'] === 'section' && is_array($block['content']['blocks'] ?? null)) {
                $block['content']['blocks'] = self::normalizeBlocks($block['content']['blocks']);
            }
            if ($block['type'] === 'columns' && is_array($block['content']['columns'] ?? null)) {
                $columns = [];
                foreach ($block['content']['columns'] as $column) {
                    if (!is_array($column)) {
                        continue;
                    }
                    $column['blocks'] = self::normalizeBlocks(is_array($column['blocks'] ?? null) ? $column['blocks'] : []);
                    $columns[] = $column;
                }
                $block['content']['columns'] = $columns;
            }
            $normalized[] = $block;
        }
        return $normalized;
    }

    public static function renderPage(array $schema): string
    {
        $page = $schema['page'] ?? [];
        $layout = $page['layout'] ?? 'default';
        $layoutClass = match ($layout) {
            'full-width' => 'pb-layout-full',
            'landing' => 'pb-layout-landing',
            default => 'pb-layout-default',
        };
        $blocks = $page['blocks'] ?? [];
        $html = '<div class="pb-page ' . $layoutClass . '">';
        $html .= self::renderBlocks($blocks);
        $html .= '</div>';
        return $html;
    }

    private static function renderBlocks(array $blocks): string
    {
        $html = '';
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            if (!self::isBlockVisible($block)) {
                continue;
            }
            $html .= self::renderBlock($block);
        }
        return $html;
    }

    private static function renderBlock(array $block): string
    {
        $type = $block['type'] ?? '';
        $styleClasses = self::styleClasses($block['style'] ?? []);
        $classAttr = trim('pb-block pb-' . $type . ' ' . $styleClasses);
        $content = $block['content'] ?? [];
        $html = '';

        switch ($type) {
            case 'section':
                $innerBlocks = $content['blocks'] ?? [];
                $html .= '<section class="' . $classAttr . '"><div class="pb-container">';
                $html .= self::renderBlocks(is_array($innerBlocks) ? $innerBlocks : []);
                $html .= '</div></section>';
                break;
            case 'columns':
                $columns = is_array($content['columns'] ?? null) ? $content['columns'] : [];
                $html .= '<section class="' . $classAttr . '"><div class="pb-container"><div class="pb-columns-grid">';
                foreach ($columns as $column) {
                    if (!is_array($column)) {
                        continue;
                    }
                    $widthClass = self::columnWidthClass($column['width'] ?? '');
                    $html .= '<div class="pb-column ' . $widthClass . '">';
                    $html .= self::renderBlocks(is_array($column['blocks'] ?? null) ? $column['blocks'] : []);
                    $html .= '</div>';
                }
                $html .= '</div></div></section>';
                break;
            case 'spacer':
                $sizeClass = self::spacingClass($content['size'] ?? 'md', 'pb-space-');
                $html .= '<div class="' . $classAttr . ' ' . $sizeClass . '"></div>';
                break;
            case 'divider':
                $html .= '<hr class="' . $classAttr . '">';
                break;
            case 'hero':
                $html .= '<section class="' . $classAttr . '"><div class="pb-container">';
                $html .= '<div class="pb-hero-content">';
                $eyebrow = self::escape((string) ($content['eyebrow'] ?? ''));
                if ($eyebrow !== '') {
                    $html .= '<span class="pb-eyebrow">' . $eyebrow . '</span>';
                }
                $heading = self::escape((string) ($content['heading'] ?? ''));
                if ($heading !== '') {
                    $html .= '<h1>' . $heading . '</h1>';
                }
                $subheading = self::escape((string) ($content['subheading'] ?? ''));
                if ($subheading !== '') {
                    $html .= '<p class="pb-lead">' . $subheading . '</p>';
                }
                $html .= self::renderCtas($content);
                $html .= '</div></div></section>';
                break;
            case 'text':
                $html .= '<section class="' . $classAttr . '"><div class="pb-container pb-content">';
                $heading = self::escape((string) ($content['heading'] ?? ''));
                if ($heading !== '') {
                    $html .= '<h2>' . $heading . '</h2>';
                }
                $html .= self::renderBody($content['body'] ?? '');
                $html .= '</div></section>';
                break;
            case 'image':
                $src = self::escape((string) ($content['src'] ?? ''));
                if ($src !== '') {
                    $alt = self::escape((string) ($content['alt'] ?? ''));
                    $caption = self::escape((string) ($content['caption'] ?? ''));
                    $html .= '<figure class="' . $classAttr . '"><img src="' . $src . '" alt="' . $alt . '">';
                    if ($caption !== '') {
                        $html .= '<figcaption>' . $caption . '</figcaption>';
                    }
                    $html .= '</figure>';
                }
                break;
            case 'gallery':
                $items = is_array($content['items'] ?? null) ? $content['items'] : [];
                $html .= '<section class="' . $classAttr . '"><div class="pb-container"><div class="pb-gallery-grid">';
                foreach ($items as $item) {
                    if (!is_array($item) || empty($item['src'])) {
                        continue;
                    }
                    $src = self::escape((string) $item['src']);
                    $alt = self::escape((string) ($item['alt'] ?? ''));
                    $html .= '<figure class="pb-gallery-item"><img src="' . $src . '" alt="' . $alt . '"></figure>';
                }
                $html .= '</div></div></section>';
                break;
            case 'video':
                $url = self::escape((string) ($content['url'] ?? ''));
                if ($url !== '') {
                    $title = self::escape((string) ($content['title'] ?? 'Video'));
                    $html .= '<section class="' . $classAttr . '"><div class="pb-container">';
                    $html .= '<div class="pb-video"><iframe src="' . $url . '" title="' . $title . '" allowfullscreen loading="lazy"></iframe></div>';
                    $html .= '</div></section>';
                }
                break;
            case 'button':
                $label = self::escape((string) ($content['label'] ?? 'Learn more'));
                $url = self::escape((string) ($content['url'] ?? '#'));
                $html .= '<div class="' . $classAttr . '"><a class="pb-btn" href="' . $url . '">' . $label . '</a></div>';
                break;
            case 'cta':
                $html .= '<section class="' . $classAttr . '"><div class="pb-container pb-card">';
                $heading = self::escape((string) ($content['heading'] ?? ''));
                if ($heading !== '') {
                    $html .= '<h2>' . $heading . '</h2>';
                }
                $body = self::escape((string) ($content['body'] ?? ''));
                if ($body !== '') {
                    $html .= '<p>' . $body . '</p>';
                }
                $html .= self::renderCtas($content);
                $html .= '</div></section>';
                break;
            case 'quote':
                $quote = self::escape((string) ($content['text'] ?? ''));
                if ($quote !== '') {
                    $author = self::escape((string) ($content['author'] ?? ''));
                    $role = self::escape((string) ($content['role'] ?? ''));
                    $html .= '<section class="' . $classAttr . '"><div class="pb-container">';
                    $html .= '<blockquote><p>' . $quote . '</p>';
                    if ($author !== '') {
                        $html .= '<footer>' . $author;
                        if ($role !== '') {
                            $html .= ' · ' . $role;
                        }
                        $html .= '</footer>';
                    }
                    $html .= '</blockquote></div></section>';
                }
                break;
            case 'faq':
                $items = is_array($content['items'] ?? null) ? $content['items'] : [];
                $html .= '<section class="' . $classAttr . '"><div class="pb-container">';
                $heading = self::escape((string) ($content['heading'] ?? ''));
                if ($heading !== '') {
                    $html .= '<h2>' . $heading . '</h2>';
                }
                $html .= '<div class="pb-faq">';
                foreach ($items as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $question = self::escape((string) ($item['question'] ?? ''));
                    $answer = self::escape((string) ($item['answer'] ?? ''));
                    if ($question === '' || $answer === '') {
                        continue;
                    }
                    $html .= '<details><summary>' . $question . '</summary><p>' . $answer . '</p></details>';
                }
                $html .= '</div></div></section>';
                break;
            case 'pricing':
                $plans = is_array($content['plans'] ?? null) ? $content['plans'] : [];
                $html .= '<section class="' . $classAttr . '"><div class="pb-container">';
                $heading = self::escape((string) ($content['heading'] ?? ''));
                if ($heading !== '') {
                    $html .= '<h2>' . $heading . '</h2>';
                }
                $html .= '<div class="pb-pricing-grid">';
                foreach ($plans as $plan) {
                    if (!is_array($plan)) {
                        continue;
                    }
                    $name = self::escape((string) ($plan['name'] ?? ''));
                    $price = self::escape((string) ($plan['price'] ?? ''));
                    $details = is_array($plan['features'] ?? null) ? $plan['features'] : [];
                    $html .= '<div class="pb-pricing-card">';
                    if ($name !== '') {
                        $html .= '<h3>' . $name . '</h3>';
                    }
                    if ($price !== '') {
                        $html .= '<div class="pb-price">' . $price . '</div>';
                    }
                    if ($details) {
                        $html .= '<ul>';
                        foreach ($details as $feature) {
                            $featureText = self::escape((string) $feature);
                            if ($featureText !== '') {
                                $html .= '<li>' . $featureText . '</li>';
                            }
                        }
                        $html .= '</ul>';
                    }
                    $html .= '</div>';
                }
                $html .= '</div></div></section>';
                break;
            case 'testimonial':
                $items = is_array($content['items'] ?? null) ? $content['items'] : [];
                $html .= '<section class="' . $classAttr . '"><div class="pb-container">';
                $heading = self::escape((string) ($content['heading'] ?? ''));
                if ($heading !== '') {
                    $html .= '<h2>' . $heading . '</h2>';
                }
                $html .= '<div class="pb-testimonials">';
                foreach ($items as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $text = self::escape((string) ($item['text'] ?? ''));
                    if ($text === '') {
                        continue;
                    }
                    $name = self::escape((string) ($item['name'] ?? ''));
                    $role = self::escape((string) ($item['role'] ?? ''));
                    $html .= '<blockquote><p>' . $text . '</p>';
                    if ($name !== '') {
                        $html .= '<footer>' . $name;
                        if ($role !== '') {
                            $html .= ' · ' . $role;
                        }
                        $html .= '</footer>';
                    }
                    $html .= '</blockquote>';
                }
                $html .= '</div></div></section>';
                break;
            case 'form':
                $fields = is_array($content['fields'] ?? null) ? $content['fields'] : [];
                $submitLabel = self::escape((string) ($content['submit_label'] ?? 'Submit'));
                $html .= '<section class="' . $classAttr . '"><div class="pb-container pb-card">';
                $heading = self::escape((string) ($content['heading'] ?? ''));
                if ($heading !== '') {
                    $html .= '<h2>' . $heading . '</h2>';
                }
                $html .= '<form class="pb-form">';
                foreach ($fields as $field) {
                    if (!is_array($field)) {
                        continue;
                    }
                    $label = self::escape((string) ($field['label'] ?? ''));
                    $name = self::escape((string) ($field['name'] ?? ''));
                    $type = self::escape((string) ($field['type'] ?? 'text'));
                    if ($name === '') {
                        continue;
                    }
                    $required = !empty($field['required']) ? ' required' : '';
                    $html .= '<label>';
                    if ($label !== '') {
                        $html .= '<span>' . $label . '</span>';
                    }
                    if ($type === 'textarea') {
                        $html .= '<textarea name="' . $name . '"' . $required . '></textarea>';
                    } else {
                        $html .= '<input type="' . $type . '" name="' . $name . '"' . $required . '>';
                    }
                    $html .= '</label>';
                }
                $html .= '<button type="submit" class="pb-btn">' . $submitLabel . '</button>';
                $html .= '</form></div></section>';
                break;
            case 'latest_posts':
            case 'upcoming_events':
            case 'user_profile':
            case 'membership_status':
            case 'notifications':
                $label = self::escape((string) ($content['label'] ?? 'Dynamic content'));
                $html .= '<section class="' . $classAttr . '"><div class="pb-container pb-dynamic">';
                $html .= '<p>' . $label . '</p>';
                $html .= '</div></section>';
                break;
        }

        return $html;
    }

    private static function renderBody($body): string
    {
        if (is_array($body)) {
            $html = '';
            foreach ($body as $paragraph) {
                $text = self::escape((string) $paragraph);
                if ($text !== '') {
                    $html .= '<p>' . $text . '</p>';
                }
            }
            return $html;
        }
        $text = self::escape((string) $body);
        return $text !== '' ? '<p>' . $text . '</p>' : '';
    }

    private static function renderCtas(array $content): string
    {
        $primary = $content['primary_cta'] ?? null;
        $secondary = $content['secondary_cta'] ?? null;
        $html = '';
        if (is_array($primary) || is_array($secondary)) {
            $html .= '<div class="pb-actions">';
            if (is_array($primary)) {
                $label = self::escape((string) ($primary['label'] ?? 'Get started'));
                $url = self::escape((string) ($primary['url'] ?? '#'));
                $html .= '<a class="pb-btn" href="' . $url . '">' . $label . '</a>';
            }
            if (is_array($secondary)) {
                $label = self::escape((string) ($secondary['label'] ?? 'Learn more'));
                $url = self::escape((string) ($secondary['url'] ?? '#'));
                $html .= '<a class="pb-btn pb-btn--ghost" href="' . $url . '">' . $label . '</a>';
            }
            $html .= '</div>';
        }
        return $html;
    }

    private static function styleClasses(array $style): string
    {
        $classes = [];
        $background = $style['background'] ?? '';
        if ($background !== '') {
            $classes[] = self::tokenClass($background, 'pb-bg-');
        }
        $textColor = $style['text_color'] ?? '';
        if ($textColor !== '') {
            $classes[] = self::tokenClass($textColor, 'pb-text-');
        }
        $padding = $style['padding'] ?? '';
        if ($padding !== '') {
            $classes[] = self::spacingClass($padding, 'pb-pad-');
        }
        $alignment = $style['alignment'] ?? '';
        if ($alignment !== '') {
            $classes[] = 'pb-align-' . preg_replace('/[^a-z-]/', '', strtolower((string) $alignment));
        }
        $maxWidth = $style['max_width'] ?? '';
        if ($maxWidth !== '') {
            $classes[] = self::maxWidthClass((string) $maxWidth);
        }
        return trim(implode(' ', array_filter($classes)));
    }

    private static function tokenClass(string $token, string $prefix): string
    {
        $token = trim($token);
        if (preg_match('/var\\(--color-([a-z0-9-]+)\\)/i', $token, $matches)) {
            return $prefix . strtolower($matches[1]);
        }
        $name = strtolower(preg_replace('/[^a-z0-9-]+/', '-', $token));
        $name = trim($name, '-');
        return $prefix . $name;
    }

    private static function spacingClass(string $token, string $prefix): string
    {
        $name = strtolower(preg_replace('/[^a-z0-9-]+/', '-', $token));
        $name = trim($name, '-');
        return $prefix . $name;
    }

    private static function maxWidthClass(string $token): string
    {
        $normalized = preg_replace('/[^0-9]+/', '', $token);
        return $normalized !== '' ? 'pb-max-' . $normalized : '';
    }

    private static function columnWidthClass(string $token): string
    {
        $normalized = strtolower(trim($token));
        if ($normalized === '') {
            return 'pb-col-auto';
        }
        $normalized = str_replace('/', '-', $normalized);
        $normalized = preg_replace('/[^0-9-]+/', '', $normalized);
        return $normalized !== '' ? 'pb-col-' . $normalized : 'pb-col-auto';
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    private static function isBlockVisible(array $block): bool
    {
        $visibility = $block['visibility'] ?? [];
        if (!is_array($visibility)) {
            return true;
        }
        $loggedInOnly = !empty($visibility['logged_in_only']);
        $roles = is_array($visibility['roles'] ?? null) ? $visibility['roles'] : [];
        $user = function_exists('current_user') ? current_user() : null;
        if ($loggedInOnly && !$user) {
            return false;
        }
        if ($roles && $user) {
            $userRoles = $user['roles'] ?? [];
            foreach ($roles as $role) {
                if (in_array($role, $userRoles, true)) {
                    return true;
                }
            }
            return false;
        }
        return true;
    }

    private static function uuid(): string
    {
        return bin2hex(random_bytes(16));
    }
}
