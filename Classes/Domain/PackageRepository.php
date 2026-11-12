<?php

declare(strict_types=1);

namespace DigitalTolk\TmsConnector\Domain;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

final class PackageRepository
{
    public const PACKAGES = 'tx_dttmsconnector_package';
    public const LANGUAGES = 'tx_dttmsconnector_language';

    public function __construct(private readonly ConnectionPool $connectionPool)
    {
    }

    public function create(array $package, array $languages): int
    {
        $now = time();
        $connection = $this->connectionPool->getConnectionForTable(self::PACKAGES);
        $connection->insert(self::PACKAGES, $package + ['tstamp' => $now, 'crdate' => $now]);
        $uid = (int)$connection->lastInsertId();
        foreach ($languages as $id => $code) {
            $this->connectionPool->getConnectionForTable(self::LANGUAGES)->insert(self::LANGUAGES, [
                'package' => $uid,
                'language_id' => (int)$id,
                'language_code' => (string)$code,
                'state' => LanguageState::QUEUED,
                'tstamp' => $now,
                'crdate' => $now,
            ]);
        }

        return $uid;
    }

    public function package(int $uid): ?array
    {
        return $this->first(self::PACKAGES, 'uid', $uid, Connection::PARAM_INT);
    }

    public function packageByReference(string $reference): ?array
    {
        return $this->first(self::PACKAGES, 'reference', $reference, Connection::PARAM_STR);
    }

    public function language(int $uid): ?array
    {
        return $this->first(self::LANGUAGES, 'uid', $uid, Connection::PARAM_INT);
    }

    public function recent(int $limit = 50): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::PACKAGES);

        return $qb->select('uid', 'crdate', 'reference', 'state', 'page_uid', 'item_count', 'error')
            ->from(self::PACKAGES)->orderBy('uid', 'DESC')->setMaxResults($limit)->executeQuery()->fetchAllAssociative();
    }

    public function languagesOf(array $packages): array
    {
        if ($packages === []) {
            return [];
        }
        $qb = $this->connectionPool->getQueryBuilderForTable(self::LANGUAGES);
        $rows = $qb->select('uid', 'package', 'language_code', 'state')->from(self::LANGUAGES)
            ->where($qb->expr()->in('package', $qb->createNamedParameter(array_map('intval', $packages), Connection::PARAM_INT_ARRAY)))
            ->orderBy('uid')
            ->executeQuery()->fetchAllAssociative();
        $byPackage = [];
        foreach ($rows as $row) {
            $byPackage[(int)$row['package']][] = $row;
        }

        return $byPackage;
    }

    public function packagesInState(string $state): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::PACKAGES);

        return $qb->select('*')->from(self::PACKAGES)
            ->where($qb->expr()->eq('state', $qb->createNamedParameter($state)))
            ->orderBy('uid')
            ->executeQuery()->fetchAllAssociative();
    }

    public function languages(int $package): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::LANGUAGES);

        return $qb->select('*')->from(self::LANGUAGES)
            ->where($qb->expr()->eq('package', $qb->createNamedParameter($package, Connection::PARAM_INT)))
            ->orderBy('uid')
            ->executeQuery()->fetchAllAssociative();
    }

    public function languagesInState(string $state): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::LANGUAGES);

        return $qb->select('*')->from(self::LANGUAGES)
            ->where($qb->expr()->eq('state', $qb->createNamedParameter($state)))
            ->orderBy('uid')
            ->executeQuery()->fetchAllAssociative();
    }

    public function updatePackage(int $uid, array $fields): void
    {
        $this->connectionPool->getConnectionForTable(self::PACKAGES)->update(self::PACKAGES, $fields + ['tstamp' => time()], ['uid' => $uid]);
    }

    public function requeueStuck(int $olderThan): int
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::PACKAGES);

        return (int)$qb->update(self::PACKAGES)
            ->set('state', PackageState::QUEUED)
            ->set('error', 'The last send stopped before it finished. The sync sends the package again.')
            ->set('tstamp', time())
            ->where(
                $qb->expr()->eq('state', $qb->createNamedParameter(PackageState::SENDING)),
                $qb->expr()->lt('tstamp', $qb->createNamedParameter($olderThan, Connection::PARAM_INT)),
            )
            ->executeStatement();
    }

    public function transition(int $uid, string $from, array $fields): bool
    {
        return $this->connectionPool->getConnectionForTable(self::PACKAGES)
            ->update(self::PACKAGES, $fields + ['tstamp' => time()], ['uid' => $uid, 'state' => $from]) > 0;
    }

    public function updateLanguage(int $uid, array $fields): void
    {
        $this->connectionPool->getConnectionForTable(self::LANGUAGES)->update(self::LANGUAGES, $fields + ['tstamp' => time()], ['uid' => $uid]);
    }

    private function first(string $table, string $field, int|string $value, mixed $type): ?array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable($table);
        $row = $qb->select('*')->from($table)
            ->where($qb->expr()->eq($field, $qb->createNamedParameter($value, $type)))
            ->executeQuery()->fetchAssociative();

        return $row === false ? null : $row;
    }
}
