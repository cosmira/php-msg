<?php

declare(strict_types=1);

namespace MsgViewer\Utils;

use DOMDocument;
use DOMDocumentFragment;

final class HtmlTemplate
{
    /**
     * @param array<string, scalar|\Stringable|null> $data
     */
    public static function fillHtmlTemplate(string $html, array $data): string
    {
        return preg_replace_callback(
            '/{{(.*?)}}/',
            static function (array $matches) use ($data): string {
                $key = trim($matches[1]);
                $value = $data[$key] ?? '';

                return (string) $value;
            },
            $html
        ) ?? $html;
    }

    /**
     * @param array<string, scalar|\Stringable|null> $data
     */
    public static function createFragmentFromTemplate(string $html, array $data): DOMDocumentFragment
    {
        $filled = self::fillHtmlTemplate($html, $data);

        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML(
            '<?xml encoding="utf-8"?><div>' . $filled . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_use_internal_errors($previous);

        $container = $document->getElementsByTagName('div')->item(0);
        $fragment = $document->createDocumentFragment();

        if ($container !== null) {
            while ($container->firstChild !== null) {
                $fragment->appendChild($container->firstChild);
            }
            $container->parentNode?->removeChild($container);
        }

        return $fragment;
    }
}

