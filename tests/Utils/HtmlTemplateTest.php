<?php

declare(strict_types=1);

namespace MsgViewer\Tests\Utils;

use DOMDocumentFragment;
use MsgViewer\Utils\HtmlTemplate;
use PHPUnit\Framework\TestCase;

final class HtmlTemplateTest extends TestCase
{
    public function testFillHtmlTemplate(): void
    {
        $result = HtmlTemplate::fillHtmlTemplate(
            '<div>{{ name }}</div>',
            ['name' => 'John']
        );

        self::assertSame('<div>John</div>', $result);
    }

    public function testCreateFragmentFromTemplate(): void
    {
        $fragment = HtmlTemplate::createFragmentFromTemplate(
            '<p>{{greeting}}</p>',
            ['greeting' => 'Hello']
        );

        self::assertInstanceOf(DOMDocumentFragment::class, $fragment);
        $document = new \DOMDocument();
        $document->appendChild($document->importNode($fragment, true));

        self::assertStringContainsString('<p>Hello</p>', $document->saveHTML() ?: '');
    }

    public function testMissingPlaceholderReplacedWithEmptyString(): void
    {
        $result = HtmlTemplate::fillHtmlTemplate(
            '<div>{{ known }} and {{ missing }}</div>',
            ['known' => 'value']
        );

        self::assertSame('<div>value and </div>', $result);
    }
}

