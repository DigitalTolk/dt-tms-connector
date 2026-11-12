<?php

declare(strict_types=1);

namespace DigitalTolk\TmsConnector\Export;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\HiddenRestriction;
use TYPO3\CMS\Core\Site\Entity\Site;

final class ContentCollector
{
    public const MAX_ITEMS = 2000;
    public const FILE = 'export.xml';
    public const RAW_HTML_TYPES = ['html'];
    private const MAX_CHARS = 500;
    private const SKIPPED_DOKTYPES = [199, 254, 255];

    public function __construct(private readonly ConnectionPool $connectionPool)
    {
    }

    public function pages(int $pageUid, int $depth): array
    {
        $found = $this->pageRows('uid', [$pageUid]);
        $level = array_keys($found);
        for ($i = 0; $i < $depth && $level !== []; $i++) {
            $children = $this->pageRows('pid', $level);
            $found += $children;
            $level = array_keys($children);
        }

        return array_keys(array_filter($found, static fn (int $doktype): bool => !in_array($doktype, self::SKIPPED_DOKTYPES, true)));
    }

    private function pageRows(string $field, array $values): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('pages');
        $qb->getRestrictions()->removeAll()->add(new DeletedRestriction());
        $rows = $qb->select('uid', 'doktype')->from('pages')
            ->where(
                $qb->expr()->in($field, $qb->createNamedParameter(array_map('intval', $values), Connection::PARAM_INT_ARRAY)),
                $qb->expr()->eq('sys_language_uid', 0),
                $qb->expr()->eq('t3ver_wsid', 0),
            )
            ->orderBy('sorting')
            ->executeQuery()->fetchAllAssociative();
        $pages = [];
        foreach ($rows as $row) {
            $pages[(int)$row['uid']] = (int)$row['doktype'];
        }

        return $pages;
    }

    public function items(array $pageUids, Site $site): array
    {
        $items = [];
        $inPackage = array_flip($pageUids);
        foreach ($this->rows('pages', 'uid', $pageUids, 'uid,pid,title,tstamp', false) as $page) {
            $item = [
                'id' => 'pages:' . $page['uid'],
                'type' => 'page',
                'title' => $this->cut((string)$page['title']),
                'source_version' => (string)$page['tstamp'],
                'files' => [self::FILE],
            ];
            try {
                $item['url'] = (string)$site->getRouter()->generateUri((int)$page['uid']);
            } catch (\Throwable) {
            }
            if (isset($inPackage[(int)$page['pid']])) {
                $item['parent_id'] = 'pages:' . $page['pid'];
            }
            $items[] = $item;
        }
        foreach ($this->rows('tt_content', 'pid', $pageUids, 'uid,pid,header,CType,tstamp', true) as $content) {
            if (in_array((string)$content['CType'], self::RAW_HTML_TYPES, true)) {
                continue;
            }
            $items[] = [
                'id' => 'tt_content:' . $content['uid'],
                'type' => $this->cut((string)$content['CType']),
                'title' => $this->cut((string)$content['header'] !== '' ? (string)$content['header'] : $content['CType'] . ' #' . $content['uid']),
                'parent_id' => 'pages:' . $content['pid'],
                'source_version' => (string)$content['tstamp'],
                'files' => [self::FILE],
            ];
        }
        if (count($items) > self::MAX_ITEMS) {
            throw new \LengthException(sprintf('The package has %d items. The limit is %d. Pick fewer pages.', count($items), self::MAX_ITEMS));
        }

        return $items;
    }

    public function rawHtmlElements(array $pageUids): array
    {
        $excluded = [];
        foreach ($this->rows('tt_content', 'pid', $pageUids, 'uid,CType', false) as $content) {
            if (in_array((string)$content['CType'], self::RAW_HTML_TYPES, true)) {
                $excluded[] = 'tt_content:' . $content['uid'];
            }
        }

        return $excluded;
    }

    public function currentVersions(array $items): array
    {
        $byTable = [];
        foreach ($items as $item) {
            [$table, $uid] = array_pad(explode(':', (string)($item['id'] ?? ''), 2), 2, '');
            if (in_array($table, ['pages', 'tt_content'], true) && ctype_digit($uid)) {
                $byTable[$table][] = (int)$uid;
            }
        }
        $versions = [];
        foreach ($byTable as $table => $uids) {
            foreach ($this->rows($table, 'uid', $uids, 'uid,tstamp', false) as $row) {
                $versions[$table . ':' . $row['uid']] = (string)$row['tstamp'];
            }
        }

        return $versions;
    }

    private function rows(string $table, string $field, array $values, string $columns, bool $visibleOnly): array
    {
        if ($values === []) {
            return [];
        }
        $qb = $this->connectionPool->getQueryBuilderForTable($table);
        $qb->getRestrictions()->removeAll()->add(new DeletedRestriction());
        if ($visibleOnly) {
            $qb->getRestrictions()->add(new HiddenRestriction());
        }
        $qb->select(...explode(',', $columns))->from($table)
            ->where(
                $qb->expr()->in($field, $qb->createNamedParameter(array_map('intval', $values), Connection::PARAM_INT_ARRAY)),
                $qb->expr()->eq('sys_language_uid', 0),
                $qb->expr()->eq('t3ver_wsid', 0),
            )
            ->orderBy('pid')
            ->addOrderBy('sorting');

        return $qb->executeQuery()->fetchAllAssociative();
    }

    private function cut(string $value): string
    {
        return mb_substr($value, 0, self::MAX_CHARS);
    }
}
