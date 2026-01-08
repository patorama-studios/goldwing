<?php
namespace App\Services;

use DOMDocument;

class DomSnapshotService
{
    public static function sanitize(string $html, int $maxLength = 120000): string
    {
        $html = preg_replace('/data:[^\s\"\']{20,}/i', 'data:omitted', $html);

        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

        $removeTags = ['script', 'style', 'noscript'];
        foreach ($removeTags as $tag) {
            $nodes = $dom->getElementsByTagName($tag);
            for ($i = $nodes->length - 1; $i >= 0; $i--) {
                $node = $nodes->item($i);
                if ($node && $node->parentNode) {
                    $node->parentNode->removeChild($node);
                }
            }
        }

        $xpath = new \DOMXPath($dom);
        foreach ($xpath->query('//@*') as $attr) {
            if (stripos($attr->nodeName, 'on') === 0) {
                $attr->ownerElement->removeAttribute($attr->nodeName);
            }
        }

        $clean = $dom->saveHTML();
        libxml_clear_errors();

        if (strlen($clean) > $maxLength) {
            $clean = substr($clean, 0, $maxLength) . "\n<!-- truncated -->";
        }

        return $clean;
    }
}
