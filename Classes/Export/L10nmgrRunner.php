<?php

declare(strict_types=1);

namespace DigitalTolk\TmsConnector\Export;

use Localizationteam\L10nmgr\Utility\JobsPathUtility;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use TYPO3\CMS\Core\Console\CommandRegistry;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class L10nmgrRunner
{
    private const TABLES = 'pages,tt_content,sys_file_reference';
    private const DEPTH_PAGE_LIST = -2;

    public function __construct(
        private readonly CommandRegistry $commands,
        private readonly ConnectionPool $connectionPool,
    ) {
    }

    public function createConfiguration(int $pid, string $title, array $pageUids): int
    {
        $now = time();
        $connection = $this->connectionPool->getConnectionForTable('tx_l10nmgr_cfg');
        $connection->insert('tx_l10nmgr_cfg', [
            'pid' => $pid,
            'tstamp' => $now,
            'crdate' => $now,
            'title' => $title,
            'depth' => self::DEPTH_PAGE_LIST,
            'pages' => implode(',', array_map('intval', $pageUids)),
            'tablelist' => self::TABLES,
            'displaymode' => 0,
            'filenameprefix' => 'dttms',
        ]);

        return (int)$connection->lastInsertId();
    }

    public function export(int $configuration, int $languageId, int $workspaceId): string
    {
        $folder = rtrim(JobsPathUtility::resolvePath('jobs/out/'), '/') . '/';
        $before = $this->files($folder);
        $output = $this->run('l10nmanager:export', [
            '--config' => (string)$configuration,
            '--target' => (string)$languageId,
            '--workspace' => (string)$workspaceId,
            '--format' => 'CATXML',
            '--noHidden' => true,
        ]);
        $created = array_keys(array_diff_assoc($this->files($folder), $before));
        if (count($created) !== 1) {
            foreach ($created as $name) {
                unlink($folder . $name);
            }
            throw new \RuntimeException(sprintf('The Localization Manager export wrote %d files, expected 1. %s', count($created), trim($output)));
        }
        $path = $folder . $created[0];
        $xml = (string)file_get_contents($path);
        unlink($path);

        return $xml;
    }

    public function import(string $xml): string
    {
        $folder = Environment::getVarPath() . '/transient/';
        GeneralUtility::mkdir_deep($folder);
        $path = $folder . 'dttms-' . bin2hex(random_bytes(8)) . '.xml';
        file_put_contents($path, $xml);
        try {
            return $this->run('l10nmanager:import', ['--task' => 'importFile', '--file' => $path]);
        } finally {
            unlink($path);
        }
    }

    private function run(string $identifier, array $arguments): string
    {
        $output = new BufferedOutput();
        $code = $this->commands->getCommandByIdentifier($identifier)->run(new ArrayInput($arguments), $output);
        $text = $output->fetch();
        if ($code !== 0) {
            throw new \RuntimeException(sprintf('%s failed: %s', $identifier, trim($text)));
        }

        return $text;
    }

    private function files(string $folder): array
    {
        clearstatcache();
        $files = [];
        foreach (glob($folder . '*.xml') ?: [] as $path) {
            $files[basename($path)] = (int)filemtime($path) . ':' . (int)filesize($path);
        }

        return $files;
    }
}
