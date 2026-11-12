<?php

declare(strict_types=1);

namespace DigitalTolk\TmsConnector\Tests\Unit\Import;

use DigitalTolk\TmsConnector\Import\CatxmlRekeyer;
use PHPUnit\Framework\TestCase;

final class CatxmlRekeyerTest extends TestCase
{
    private function catxml(string $target, string $body): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<!DOCTYPE TYPO3L10N [ <!ENTITY nbsp " "> ]>' . "\n"
            . '<TYPO3L10N><head><t3_sysLang translate="no">' . $target . '</t3_sysLang><t3_workspaceId translate="no">0</t3_workspaceId></head>'
            . '<pageGrp id="2" sourceUrl="http://localhost/ueber-uns">' . $body . '</pageGrp></TYPO3L10N>';
    }

    public function testFillsTheSkeletonByTableUidAndField(): void
    {
        $translated = $this->catxml('1', '<data elementUid="2" key="pages:NEW/1/2:title" table="pages">About us</data>'
            . '<data key="tt_content:NEW/1/7:bodytext" table="tt_content" elementUid="7"><p>We are <strong>Muster</strong> &amp; Co.</p></data>');
        $skeleton = $this->catxml('2', '<data table="pages" elementUid="2" key="pages:12:title">Über uns</data>'
            . '<data table="tt_content" elementUid="7" key="tt_content:NEW/2/7:bodytext"><p>Wir sind <strong>Muster</strong> &amp; Co.</p></data>'
            . '<data table="tt_content" elementUid="8" key="tt_content:NEW/2/8:header">Kontakt</data>');

        $result = (new CatxmlRekeyer())->rekey($translated, $skeleton);

        self::assertSame(2, $result->filled);
        self::assertSame(3, $result->total);
        $doc = new \DOMDocument();
        $doc->loadXML($result->xml);
        $xpath = new \DOMXPath($doc);
        self::assertSame('2', $xpath->evaluate('string(//t3_sysLang)'));
        self::assertSame('About us', $xpath->evaluate('string(//data[@key="pages:12:title"])'));
        self::assertSame('Muster', $xpath->evaluate('string(//data[@key="tt_content:NEW/2/7:bodytext"]/p/strong)'));
        self::assertStringContainsString('&amp; Co.', $result->xml);
        self::assertSame(0, $xpath->query('//data[@key="tt_content:NEW/2/8:header"]')->length);
    }

    public function testKeepsCdataContent(): void
    {
        $translated = $this->catxml('1', '<data table="tt_content" elementUid="7" key="tt_content:NEW/1/7:bodytext"><![CDATA[<p>Broken <b>html</p>]]></data>');
        $skeleton = $this->catxml('1', '<data table="tt_content" elementUid="7" key="tt_content:9:bodytext"><![CDATA[<p>Kaputt <b>html</p>]]></data>');

        $result = (new CatxmlRekeyer())->rekey($translated, $skeleton);

        self::assertSame(1, $result->filled);
        self::assertStringContainsString('<data table="tt_content" elementUid="7" key="tt_content:9:bodytext"><![CDATA[<p>Broken <b>html</p>]]></data>', $result->xml);
    }

    public function testRefusesAFileThatIsNotXml(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new CatxmlRekeyer())->rekey('not xml', $this->catxml('1', ''));
    }

    public function testDoesNotLoadExternalEntities(): void
    {
        $evil = '<?xml version="1.0"?><!DOCTYPE x [ <!ENTITY e SYSTEM "file:///etc/hostname"> ]>'
            . '<TYPO3L10N><pageGrp><data table="pages" elementUid="2" key="pages:NEW/1/2:title">&e;</data></pageGrp></TYPO3L10N>';
        $skeleton = $this->catxml('1', '<data table="pages" elementUid="2" key="pages:12:title">Über uns</data>');

        $result = (new CatxmlRekeyer())->rekey($evil, $skeleton);

        self::assertStringNotContainsString(trim((string)@file_get_contents('/etc/hostname')) ?: 'unreachable-marker', $result->xml);
    }

    public function testKeepsNumberFieldsFromTheFreshExport(): void
    {
        $translated = $this->catxml('1', '<data table="sys_file_reference" elementUid="1" key="sys_file_reference:NEW/1/1:uid_local">[EN] 1</data>'
            . '<data table="pages" elementUid="2" key="pages:NEW/1/2:shortcut">[EN] 0</data>'
            . '<data table="pages" elementUid="2" key="pages:NEW/1/2:title">About us</data>');
        $skeleton = $this->catxml('1', '<data table="sys_file_reference" elementUid="1" key="sys_file_reference:NEW/1/1:uid_local">1</data>'
            . '<data table="pages" elementUid="2" key="pages:12:shortcut"> 0 </data>'
            . '<data table="pages" elementUid="2" key="pages:12:title">Über uns</data>');

        $result = (new CatxmlRekeyer())->rekey($translated, $skeleton);

        $doc = new \DOMDocument();
        $doc->loadXML($result->xml);
        $xpath = new \DOMXPath($doc);
        self::assertSame('1', $xpath->evaluate('string(//data[@key="sys_file_reference:NEW/1/1:uid_local"])'));
        self::assertSame(' 0 ', $xpath->evaluate('string(//data[@key="pages:12:shortcut"])'));
        self::assertSame('About us', $xpath->evaluate('string(//data[@key="pages:12:title"])'));
        self::assertSame(3, $result->filled);
    }
}
