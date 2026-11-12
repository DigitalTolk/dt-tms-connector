<?php

declare(strict_types=1);

namespace DigitalTolk\TmsConnector\Tests\Unit\Import;

use DigitalTolk\TmsConnector\Import\CatxmlFields;
use PHPUnit\Framework\TestCase;

final class CatxmlFieldsTest extends TestCase
{
    public function testReadsTextFieldsAndSkipsNumbers(): void
    {
        $xml = '<TYPO3L10N><pageGrp>'
            . '<data table="pages" elementUid="2" key="pages:NEW/1/2:title">About us</data>'
            . '<data table="pages" elementUid="2" key="pages:NEW/1/2:shortcut">0</data>'
            . '<data table="tt_content" elementUid="7" key="tt_content:9:bodytext"><p>We are <strong>Muster</strong>  &amp; Co.</p></data>'
            . '<data table="tt_content" elementUid="8" key="tt_content:NEW/1/8:bodytext"><![CDATA[<p>Old <b>html</p>]]></data>'
            . '</pageGrp></TYPO3L10N>';

        $fields = (new CatxmlFields())->read($xml);

        self::assertSame(['pages:2:title', 'tt_content:7:bodytext', 'tt_content:8:bodytext'], array_keys($fields));
        self::assertSame('We are Muster & Co.', $fields['tt_content:7:bodytext']['text']);
        self::assertSame('Old html', $fields['tt_content:8:bodytext']['text']);
        self::assertSame(7, $fields['tt_content:7:bodytext']['uid']);
    }

    public function testReturnsNothingForInvalidXml(): void
    {
        self::assertSame([], (new CatxmlFields())->read('<broken'));
        self::assertSame([], (new CatxmlFields())->read(''));
    }
}
