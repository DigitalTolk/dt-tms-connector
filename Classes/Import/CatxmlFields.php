<?php

declare(strict_types=1);

namespace DigitalTolk\TmsConnector\Import;

final class CatxmlFields
{
    public function read(string $xml): array
    {
        if ($xml === '') {
            return [];
        }
        $doc = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $doc->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            return [];
        }
        $fields = [];
        foreach ($doc->getElementsByTagName('data') as $data) {
            $parts = explode(':', $data->getAttribute('key'), 3);
            $field = $parts[2] ?? '';
            $markup = '';
            foreach ($data->childNodes as $child) {
                $markup .= $child instanceof \DOMCdataSection ? $child->data : (string)$doc->saveXML($child);
            }
            $text = trim((string)preg_replace('/\s+/u', ' ', htmlspecialchars_decode($markup)));
            if ($text === '' || ctype_digit($text)) {
                continue;
            }
            $id = $data->getAttribute('table') . ':' . $data->getAttribute('elementUid') . ':' . $field;
            $fields[$id] = [
                'table' => $data->getAttribute('table'),
                'uid' => (int)$data->getAttribute('elementUid'),
                'field' => $field,
                'text' => $text,
            ];
        }

        return $fields;
    }
}
