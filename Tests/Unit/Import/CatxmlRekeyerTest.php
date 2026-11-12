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

    public function testKeepsTheSourceWhenATranslationAddsScriptOrActiveHtml(): void
    {
        $translated = $this->catxml(
            '1',
            '<data table="tt_content" elementUid="1" key="tt_content:NEW/1/1:bodytext"><p>Hi</p><script>alert(1)</script></data>'
            . '<data table="tt_content" elementUid="2" key="tt_content:NEW/1/2:bodytext">&lt;script&gt;alert(1)&lt;/script&gt;</data>'
            . '<data table="tt_content" elementUid="3" key="tt_content:NEW/1/3:bodytext"><p onclick="steal()">Hi</p></data>'
            . '<data table="tt_content" elementUid="4" key="tt_content:NEW/1/4:bodytext"><a href="javascript:steal()">Hi</a></data>'
            . '<data table="tt_content" elementUid="5" key="tt_content:NEW/1/5:bodytext"><![CDATA[<iframe src="https://evil.example"></iframe>]]></data>'
            . '<data table="tt_content" elementUid="6" key="tt_content:NEW/1/6:bodytext"><p>We are <strong>Muster</strong> <a href="t3://page?uid=2">here</a></p></data>'
            . '<data table="tt_content" elementUid="7" key="tt_content:NEW/1/7:bodytext">&amp;lt;b&amp;gt; stays text</data>'
        );
        $skeleton = $this->catxml(
            '1',
            '<data table="tt_content" elementUid="1" key="tt_content:NEW/1/1:bodytext"><p>Hallo</p></data>'
            . '<data table="tt_content" elementUid="2" key="tt_content:NEW/1/2:bodytext">Hallo</data>'
            . '<data table="tt_content" elementUid="3" key="tt_content:NEW/1/3:bodytext"><p>Hallo</p></data>'
            . '<data table="tt_content" elementUid="4" key="tt_content:NEW/1/4:bodytext"><a href="https://example.com">Hallo</a></data>'
            . '<data table="tt_content" elementUid="5" key="tt_content:NEW/1/5:bodytext"><![CDATA[<p>Hallo</p>]]></data>'
            . '<data table="tt_content" elementUid="6" key="tt_content:NEW/1/6:bodytext"><p>Wir sind <strong>Muster</strong> <a href="t3://page?uid=2">hier</a></p></data>'
            . '<data table="tt_content" elementUid="7" key="tt_content:NEW/1/7:bodytext">Text</data>'
        );

        $result = (new CatxmlRekeyer())->rekey($translated, $skeleton);

        self::assertSame(5, $result->blocked);
        self::assertSame(7, $result->filled);
        self::assertStringNotContainsString('alert', $result->xml);
        self::assertStringNotContainsString('onclick', $result->xml);
        self::assertStringNotContainsString('javascript:', $result->xml);
        self::assertStringNotContainsString('iframe', $result->xml);
        self::assertStringContainsString('We are <strong>Muster</strong>', $result->xml);
        self::assertStringContainsString('&amp;lt;b&amp;gt; stays text', $result->xml);
    }

    public function testAllowsActiveHtmlThatTheSourceAlreadyHas(): void
    {
        $translated = $this->catxml('1', '<data table="tt_content" elementUid="1" key="tt_content:NEW/1/1:bodytext"><a href="https://example.com" onclick="track()">Hi</a></data>');
        $skeleton = $this->catxml('1', '<data table="tt_content" elementUid="1" key="tt_content:NEW/1/1:bodytext"><a href="https://example.com" onclick="track()">Hallo</a></data>');

        $result = (new CatxmlRekeyer())->rekey($translated, $skeleton);

        self::assertSame(0, $result->blocked);
        self::assertStringContainsString('>Hi</a>', $result->xml);
    }

    public function testKeepsTheTargetOfExternalUrlPages(): void
    {
        $translated = $this->catxml('1', '<data table="pages" elementUid="5" key="pages:NEW/1/5:url">https://phishing.example</data>');
        $skeleton = $this->catxml('1', '<data table="pages" elementUid="5" key="pages:NEW/1/5:url">https://www.example.com</data>');

        $result = (new CatxmlRekeyer())->rekey($translated, $skeleton);

        self::assertStringContainsString('https://www.example.com', $result->xml);
        self::assertStringNotContainsString('phishing', $result->xml);
    }
}
