<?php

declare(strict_types=1);

namespace DigitalTolk\TmsConnector\Import;

use DigitalTolk\TmsConnector\Export\L10nmgrRunner;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class TranslationImporter
{
    private const TABLES = ['pages', 'tt_content'];

    public function __construct(
        private readonly L10nmgrRunner $runner,
        private readonly CatxmlRekeyer $rekeyer,
        private readonly ConnectionPool $connectionPool,
    ) {
    }

    public function import(array $package, array $language, bool $hideNew): ImportResult
    {
        $workspace = (int)$package['workspace_id'];
        $skeleton = $this->runner->export((int)$package['l10n_cfg'], (int)$language['language_id'], $workspace);
        $result = $this->rekeyer->rekey((string)$language['translation_xml'], $skeleton);
        if ($result->filled === 0) {
            throw new \RuntimeException('No field of the translation matches the current pages. Nothing was imported.');
        }
        $sources = $this->sources(json_decode((string)$package['items'], true) ?: []);
        $before = $this->translations($sources, (int)$language['language_id'], $workspace);
        $this->runner->import($result->xml);
        $after = $this->translations($sources, (int)$language['language_id'], $workspace);

        $new = [];
        foreach ($after as $table => $parents) {
            $created = array_diff(array_keys($parents), array_keys($before[$table] ?? []));
            if ($created !== []) {
                $new[$table] = $created;
            }
        }
        $hidden = 0;
        $warning = '';
        if ($hideNew && $workspace === 0) {
            try {
                $hidden = $this->hide($new);
            } catch (\RuntimeException $e) {
                $warning = $e->getMessage();
            }
        }

        return new ImportResult(sprintf(
            'Filled %d of %d fields. %d items have a translation, %d of them new%s.',
            $result->filled,
            $result->total,
            array_sum(array_map(static fn (array $parents): int => count(array_unique($parents)), $after)),
            array_sum(array_map('count', $new)),
            $hidden > 0 ? sprintf(', %d new records hidden', $hidden) : '',
        ), trim($warning . ($result->blocked > 0 ? sprintf(' %d fields kept the source text, because the translation added scripts or active HTML.', $result->blocked) : '')));
    }

    private function sources(array $items): array
    {
        $sources = [];
        foreach ($items as $item) {
            [$table, $uid] = array_pad(explode(':', (string)($item['id'] ?? ''), 2), 2, '');
            if (in_array($table, self::TABLES, true) && ctype_digit($uid)) {
                $sources[$table][] = (int)$uid;
            }
        }

        return $sources;
    }

    private function translations(array $sources, int $languageId, int $workspace): array
    {
        $found = [];
        foreach ($sources as $table => $uids) {
            $ctrl = $GLOBALS['TCA'][$table]['ctrl'];
            $qb = $this->connectionPool->getQueryBuilderForTable($table);
            $qb->getRestrictions()->removeAll()->add(new DeletedRestriction());
            $rows = $qb->select('uid', $ctrl['transOrigPointerField'] . ' AS parent')->from($table)
                ->where(
                    $qb->expr()->in($ctrl['transOrigPointerField'], $qb->createNamedParameter($uids, Connection::PARAM_INT_ARRAY)),
                    $qb->expr()->eq($ctrl['languageField'], $qb->createNamedParameter($languageId, Connection::PARAM_INT)),
                    $qb->expr()->in('t3ver_wsid', $qb->createNamedParameter(array_unique([0, $workspace]), Connection::PARAM_INT_ARRAY)),
                )
                ->executeQuery()->fetchAllAssociative();
            $found[$table] = array_column(array_map(static fn (array $r): array => ['uid' => (int)$r['uid'], 'parent' => (int)$r['parent']], $rows), 'parent', 'uid');
        }

        return $found;
    }

    private function hide(array $new): int
    {
        $data = [];
        foreach ($new as $table => $uids) {
            $field = $GLOBALS['TCA'][$table]['ctrl']['enablecolumns']['disabled'] ?? '';
            foreach ($field === '' ? [] : $uids as $uid) {
                $data[$table][$uid] = [$field => 1];
            }
        }
        if ($data === []) {
            return 0;
        }
        $handler = GeneralUtility::makeInstance(DataHandler::class);
        $handler->start($data, []);
        $handler->process_datamap();
        if ($handler->errorLog !== []) {
            throw new \RuntimeException('The import worked, but the new records could not be hidden. They are visible now: ' . implode(' ', $handler->errorLog));
        }

        return array_sum(array_map('count', $data));
    }
}
