<?php

declare(strict_types=1);

namespace DigitalTolk\TmsConnector\Import;

final class CatxmlRekeyer
{
    private const ACTIVE_TAGS = '/<\s*(script|iframe|frame|frameset|object|embed|applet|style|link|meta|base|form|svg|math|template|noscript)\b/i';
    private const EVENT_ATTRIBUTES = '/[\s"\'\/]on([a-z]+)\s*=/i';
    private const ACTIVE_SCHEMES = '/=\s*["\']?\s*(javascript|vbscript|data)\s*:/i';
    private const KEPT_FIELDS = ['pages|url'];

    public function rekey(string $translated, string $skeleton): RekeyResult
    {
        $source = $this->load($translated);
        $target = $this->load($skeleton);
        $found = [];
        foreach ($source->getElementsByTagName('data') as $data) {
            $found[$this->identity($data)] = $data;
        }
        $filled = 0;
        $total = 0;
        $blocked = 0;
        $unmatched = [];
        foreach ($target->getElementsByTagName('data') as $data) {
            $total++;
            $match = $found[$this->identity($data)] ?? null;
            if ($match === null) {
                $unmatched[] = $data;
                continue;
            }
            if (ctype_digit(trim($data->textContent)) || $this->isKeptField($data)) {
                $filled++;
                continue;
            }
            if (array_diff($this->activeContent($this->inner($match)), $this->activeContent($this->inner($data))) !== []) {
                $blocked++;
                $filled++;
                continue;
            }
            while ($data->firstChild !== null) {
                $data->removeChild($data->firstChild);
            }
            foreach ($match->childNodes as $child) {
                $data->appendChild($target->importNode($child, true));
            }
            $filled++;
        }
        foreach ($unmatched as $data) {
            $data->parentNode?->removeChild($data);
        }

        return new RekeyResult((string)$target->saveXML(), $filled, $total, $blocked);
    }

    private function isKeptField(\DOMElement $data): bool
    {
        $parts = explode(':', $data->getAttribute('key'), 3);

        return in_array($data->getAttribute('table') . '|' . ($parts[2] ?? ''), self::KEPT_FIELDS, true);
    }

    private function inner(\DOMElement $data): string
    {
        $xml = '';
        foreach ($data->childNodes as $child) {
            $xml .= $child instanceof \DOMCdataSection ? $child->data : (string)$data->ownerDocument?->saveXML($child);
        }

        return $xml;
    }

    private function activeContent(string $xml): array
    {
        $text = htmlspecialchars_decode($xml);
        for ($i = 0; $i < 2; $i++) {
            $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        $text = (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text);
        $found = [];
        foreach ([self::ACTIVE_TAGS => 'tag', self::EVENT_ATTRIBUTES => 'on', self::ACTIVE_SCHEMES => 'uri'] as $pattern => $kind) {
            if (preg_match_all($pattern, $text, $matches) > 0) {
                foreach ($matches[1] as $name) {
                    $found[] = $kind . ':' . strtolower($name);
                }
            }
        }

        return array_values(array_unique($found));
    }

    private function load(string $xml): \DOMDocument
    {
        $doc = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $xml !== '' && $doc->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            throw new \InvalidArgumentException('The file is not valid XML.');
        }

        return $doc;
    }

    private function identity(\DOMElement $data): string
    {
        $parts = explode(':', $data->getAttribute('key'), 3);

        return $data->getAttribute('table') . '|' . $data->getAttribute('elementUid') . '|' . ($parts[2] ?? '');
    }
}
