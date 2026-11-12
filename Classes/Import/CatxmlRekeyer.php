<?php

declare(strict_types=1);

namespace DigitalTolk\TmsConnector\Import;

final class CatxmlRekeyer
{
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
        $unmatched = [];
        foreach ($target->getElementsByTagName('data') as $data) {
            $total++;
            $match = $found[$this->identity($data)] ?? null;
            if ($match === null) {
                $unmatched[] = $data;
                continue;
            }
            if (ctype_digit(trim($data->textContent))) {
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

        return new RekeyResult((string)$target->saveXML(), $filled, $total);
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
