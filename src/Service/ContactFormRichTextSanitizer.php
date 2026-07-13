<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Service;

use DOMAttr;
use DOMDocument;
use DOMElement;

use function in_array;
use function sprintf;

use const ENT_QUOTES;
use const ENT_SUBSTITUTE;
use const LIBXML_HTML_NODEFDTD;
use const LIBXML_HTML_NOIMPLIED;

/**
 * Sanitizes limited HTML fragments used in public contact form copy.
 */
final class ContactFormRichTextSanitizer
{
    /** @var array<string, list<string>> */
    private const ALLOWED_ELEMENTS = [
        'a'      => ['href', 'title', 'target', 'rel'],
        'strong' => [],
        'b'      => [],
        'em'     => [],
        'i'      => [],
        'u'      => [],
        'br'     => [],
        'span'   => [],
    ];

    public function sanitize(?string $html): string
    {
        if ($html === null || trim($html) === '') {
            return '';
        }

        $document       = new DOMDocument();
        $internalErrors = libxml_use_internal_errors(true);

        $document->loadHTML(
            sprintf('<?xml encoding="UTF-8"><div>%s</div>', $html),
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
        );

        libxml_clear_errors();
        libxml_use_internal_errors($internalErrors);

        $container = $document->getElementsByTagName('div')->item(0);

        if (!$container instanceof DOMElement) {
            return htmlspecialchars($html, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        $this->sanitizeNode($container);

        $result = '';

        foreach ($container->childNodes as $child) {
            $result .= $document->saveHTML($child) ?: '';
        }

        return $result;
    }

    private function sanitizeNode(DOMElement $node): void
    {
        $child = $node->firstChild;

        while ($child !== null) {
            $next = $child->nextSibling;

            if ($child instanceof DOMElement) {
                $tag = strtolower($child->nodeName);

                if (!isset(self::ALLOWED_ELEMENTS[$tag])) {
                    while ($child->firstChild !== null) {
                        $node->insertBefore($child->firstChild, $child);
                    }

                    $node->removeChild($child);
                } else {
                    $this->sanitizeAttributes($child, $tag);
                    $this->sanitizeNode($child);
                }
            }

            $child = $next;
        }
    }

    private function sanitizeAttributes(DOMElement $element, string $tag): void
    {
        $allowed = self::ALLOWED_ELEMENTS[$tag];

        if ($element->hasAttributes()) {
            /** @var DOMAttr $attribute */
            foreach (iterator_to_array($element->attributes) as $attribute) {
                if (!in_array(strtolower($attribute->name), $allowed, true)) {
                    $element->removeAttribute($attribute->name);
                }
            }
        }

        if ($tag !== 'a') {
            return;
        }

        $href = trim($element->getAttribute('href'));

        if (!$this->isAllowedHref($href)) {
            $element->removeAttribute('href');

            return;
        }

        $element->setAttribute('rel', 'noopener noreferrer');

        if (!$element->hasAttribute('target')) {
            $element->setAttribute('target', '_blank');
        }
    }

    private function isAllowedHref(string $href): bool
    {
        if ($href === '') {
            return false;
        }

        if (str_starts_with($href, '/')) {
            return !str_starts_with($href, '//');
        }

        return (bool) preg_match('#^https?://#i', $href) || str_starts_with($href, 'mailto:');
    }
}
