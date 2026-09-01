<?php

namespace App\Services\Compliance;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * Keeps administrator-authored memorandum content limited to the small,
 * email-safe formatting vocabulary supported by the settings editor.
 */
final class ComplianceRichTextSanitizer
{
    /** @var array<string, string> */
    public const COLORS = [
        'black' => '#000000',
        'green' => '#14532d',
        'red' => '#b42318',
    ];

    /** @var list<string> */
    private const BLOCKED_TAGS = ['script', 'style', 'iframe', 'object', 'embed', 'template'];

    public function sanitize(mixed $value): string
    {
        $value = (string) ($value ?? '');
        if ($value === '') {
            return '';
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8"><div>'.$value.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $document->getElementsByTagName('div')->item(0);
        if (! $root) {
            return strip_tags($value);
        }

        return $this->children($root);
    }

    public function plainText(mixed $value): string
    {
        $value = (string) ($value ?? '');
        $value = preg_replace('/[\r\n]+/', ' ', strip_tags($value)) ?? '';

        return trim($value);
    }

    /**
     * Convert either legacy plain text or sanitized HTML into body HTML.
     * The optional paragraph style is code-owned and never sourced from the
     * administrator-authored value.
     */
    public function render(mixed $value, string $paragraphStyle = ''): string
    {
        $safe = $this->sanitize($value);
        if ($safe === '') {
            return '';
        }

        if (! str_contains($safe, '<')) {
            $html = nl2br(e($safe), false);
        } else {
            $html = $safe;
        }

        return $paragraphStyle === ''
            ? $html
            : str_replace('<p>', '<p style="'.$paragraphStyle.'">', $html);
    }

    private function children(DOMNode $parent): string
    {
        $html = '';
        foreach ($parent->childNodes as $child) {
            $html .= $this->node($child);
        }

        return $html;
    }

    private function node(DOMNode $node): string
    {
        if ($node instanceof DOMText) {
            return htmlspecialchars($node->nodeValue ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        if (! $node instanceof DOMElement) {
            return '';
        }

        $tag = strtolower($node->tagName);
        if (in_array($tag, self::BLOCKED_TAGS, true)) {
            return '';
        }

        $children = $this->children($node);
        return match ($tag) {
            'br' => '<br>',
            'p', 'div' => '<p>'.$children.'</p>',
            'strong', 'b' => '<strong>'.$children.'</strong>',
            'em', 'i' => '<em>'.$children.'</em>',
            'u' => '<u>'.$children.'</u>',
            'span', 'font' => $this->colored($node, $children),
            default => $children,
        };
    }

    private function colored(DOMElement $node, string $children): string
    {
        $color = $this->color($node);
        if ($color === null) {
            return $children;
        }

        return '<span style="color:'.$color.'">'.$children.'</span>';
    }

    private function color(DOMElement $node): ?string
    {
        $raw = $node->getAttribute('style');
        if ($raw === '' && strtolower($node->tagName) === 'font') {
            $raw = 'color:'.$node->getAttribute('color');
        }

        foreach (explode(';', $raw) as $declaration) {
            [$property, $value] = array_pad(explode(':', $declaration, 2), 2, null);
            if (strtolower(trim((string) $property)) !== 'color') {
                continue;
            }

            $value = strtolower(trim((string) $value));
            foreach (self::COLORS as $allowed) {
                if ($value === $allowed) {
                    return $allowed;
                }
            }

            return null;
        }

        return null;
    }
}
