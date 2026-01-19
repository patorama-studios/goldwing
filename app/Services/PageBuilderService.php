<?php
namespace App\Services;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

class PageBuilderService
{
    private const EDITABLE_TAG_BLACKLIST = [
        'html',
        'head',
        'body',
        'meta',
        'link',
        'script',
        'style',
        'title',
    ];

    public static function ensureDraftHtml(string $html): string
    {
        return self::ensureElementIds($html);
    }

    public static function stripElementIds(string $html): string
    {
        $doc = self::loadFragment($html);
        $xpath = new DOMXPath($doc);
        $nodes = $xpath->query('//*[@data-gw-el]');
        if ($nodes) {
            foreach ($nodes as $node) {
                if ($node instanceof DOMElement) {
                    $node->removeAttribute('data-gw-el');
                }
            }
        }
        return self::extractFragment($doc);
    }

    public static function replaceElementHtml(string $html, string $elementId, string $replacementHtml): array
    {
        $doc = self::loadFragment($html);
        $xpath = new DOMXPath($doc);
        $node = $xpath->query('//*[@data-gw-el="' . self::escapeXpath($elementId) . '"]')->item(0);
        if (!$node || !$node instanceof DOMElement) {
            return [false, $html, 'Selected element not found.'];
        }

        $replacementNodes = self::loadNodesFragment($replacementHtml);
        if (!$replacementNodes) {
            return [false, $html, 'Replacement HTML could not be parsed.'];
        }

        if (count($replacementNodes) === 1 && $replacementNodes[0] instanceof DOMElement) {
            if (!$replacementNodes[0]->hasAttribute('data-gw-el')) {
                $replacementNodes[0]->setAttribute('data-gw-el', $elementId);
            }
        }

        $parent = $node->parentNode;
        if (!$parent) {
            return [false, $html, 'Selected element has no parent.'];
        }

        foreach ($replacementNodes as $replacementNode) {
            $imported = $doc->importNode($replacementNode, true);
            $parent->insertBefore($imported, $node);
        }
        $parent->removeChild($node);

        $updated = self::extractFragment($doc);
        $updated = self::ensureElementIds($updated);
        return [true, $updated, null];
    }

    public static function buildAccessLevel(string $value, array $roles): string
    {
        $value = trim($value);
        if ($value === 'public') {
            return 'public';
        }
        if (str_starts_with($value, 'role:')) {
            $role = substr($value, 5);
            $role = strtolower(trim($role));
            if ($role !== '' && in_array($role, $roles, true)) {
                return 'role:' . $role;
            }
        }
        return 'public';
    }

    public static function canAccessPage(array $page, ?array $user): bool
    {
        $access = $page['access_level'] ?? '';
        if ($access === '' || $access === 'public') {
            return true;
        }
        if (!$user) {
            return false;
        }
        if (str_starts_with($access, 'role:')) {
            $role = substr($access, 5);
            return $role !== '' && in_array($role, $user['roles'] ?? [], true);
        }
        return false;
    }

    public static function ensureElementIds(string $html): string
    {
        $doc = self::loadFragment($html);
        $root = $doc->getElementById('gw-root');
        if ($root) {
            self::applyElementIds($root);
        }
        return self::extractFragment($doc);
    }

    public static function ensureEditableBody(array $page, string $draftHtml): string
    {
        if (strpos($draftHtml, 'data-gw-body') !== false) {
            return $draftHtml;
        }

        $pageSlug = $page['slug'] ?? 'home';
        $pageTitle = $page['title'] ?? 'Australian Goldwing Association';
        $plainContent = trim(strip_tags($draftHtml));
        $heroLead = $plainContent !== '' ? $plainContent : 'Rides, events, and member services for Goldwing riders across Australia.';
        if (strlen($heroLead) > 200) {
            $heroLead = substr($heroLead, 0, 200) . '...';
        }
        $heroClass = $pageSlug === 'home' ? 'hero hero--home' : 'hero hero--compact';
        $heroActions = '';
        if ($pageSlug === 'home') {
            $heroActions = '<div class="hero__actions">'
                . '<a class="button primary" href="/?page=membership">Join the Association</a>'
                . '<a class="button ghost" href="/?page=ride-calendar">Ride Calendar</a>'
                . '</div>';
        }

        $heroHtml = '<section class="' . $heroClass . '">'
            . '<div class="container hero__inner">'
            . '<span class="hero__eyebrow">Australian Goldwing Association</span>'
            . '<h1>' . htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') . '</h1>'
            . '<p class="hero__lead">' . htmlspecialchars($heroLead, ENT_QUOTES, 'UTF-8') . '</p>'
            . $heroActions
            . '</div>'
            . '</section>';

        $bodyHtml = '<section class="page-section">'
            . '<div class="container">'
            . '<div class="page-card reveal">'
            . '<div class="page-content">' . $draftHtml . '</div>'
            . '</div>'
            . '</div>'
            . '</section>';

        return '<div id="gw-content-root" class="page-body" data-gw-body="true">' . $heroHtml . $bodyHtml . '</div>';
    }

    private static function applyElementIds(DOMNode $node): void
    {
        if ($node instanceof DOMElement) {
            $tag = strtolower($node->tagName);
            if (!in_array($tag, self::EDITABLE_TAG_BLACKLIST, true)) {
                if (!$node->hasAttribute('data-gw-el')) {
                    $node->setAttribute('data-gw-el', self::uuid());
                }
            }
        }

        if ($node->hasChildNodes()) {
            foreach ($node->childNodes as $child) {
                self::applyElementIds($child);
            }
        }
    }

    private static function loadFragment(string $html): DOMDocument
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        $html = self::normalizeHtml($html);
        libxml_use_internal_errors(true);
        $doc->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        return $doc;
    }

    private static function normalizeHtml(string $html): string
    {
        return '<?xml encoding="UTF-8"><div id="gw-root">' . $html . '</div>';
    }

    private static function extractFragment(DOMDocument $doc): string
    {
        $root = $doc->getElementById('gw-root');
        if (!$root) {
            return '';
        }
        $html = '';
        foreach ($root->childNodes as $child) {
            $html .= $doc->saveHTML($child);
        }
        return $html;
    }

    private static function loadNodesFragment(string $html): array
    {
        $fragmentDoc = self::loadFragment($html);
        $root = $fragmentDoc->getElementById('gw-root');
        if (!$root) {
            return [];
        }
        $nodes = [];
        foreach ($root->childNodes as $child) {
            $nodes[] = $child;
        }
        return $nodes;
    }

    private static function escapeXpath(string $value): string
    {
        return str_replace('"', '\"', $value);
    }

    private static function uuid(): string
    {
        return bin2hex(random_bytes(8));
    }
}
