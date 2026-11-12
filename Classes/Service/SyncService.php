<?php

declare(strict_types=1);

namespace DigitalTolk\TmsConnector\Service;

use DigitalTolk\TmsConnector\Api\AdapterClient;
use DigitalTolk\TmsConnector\Api\AdapterClientFactory;
use DigitalTolk\TmsConnector\Api\AdapterException;
use DigitalTolk\TmsConnector\Domain\LanguageState;
use DigitalTolk\TmsConnector\Domain\PackageRepository;
use DigitalTolk\TmsConnector\Domain\PackageState;
use DigitalTolk\TmsConnector\Domain\Settings;
use DigitalTolk\TmsConnector\Export\ContentCollector;
use DigitalTolk\TmsConnector\Export\L10nmgrRunner;
use DigitalTolk\TmsConnector\Import\TranslationImporter;
use TYPO3\CMS\Core\Site\SiteFinder;

final class SyncService
{
    public const STUCK_SECONDS = 1800;

    private array $log = [];

    public function __construct(
        private readonly PackageRepository $packages,
        private readonly AdapterClientFactory $clients,
        private readonly SettingsProvider $settings,
        private readonly ContentCollector $collector,
        private readonly L10nmgrRunner $runner,
        private readonly TranslationImporter $importer,
        private readonly SiteFinder $siteFinder,
    ) {
    }

    public function run(bool $pollAll = false): array
    {
        $this->log = [];
        $settings = $this->settings->get();
        $client = $this->clients->create();
        $this->sendQueued($client);
        $this->poll($client, $settings, $pollAll);
        $this->importRequested($settings);
        $this->reportImported($client);

        return $this->log;
    }

    private function sendQueued(AdapterClient $client): void
    {
        $requeued = $this->packages->requeueStuck(time() - self::STUCK_SECONDS);
        if ($requeued > 0) {
            $this->log[] = sprintf('%d packages were stuck while sending and are queued again.', $requeued);
        }
        foreach ($this->packages->packagesInState(PackageState::QUEUED) as $package) {
            $uid = (int)$package['uid'];
            if (!$this->packages->transition($uid, PackageState::QUEUED, ['state' => PackageState::SENDING])) {
                continue;
            }
            $started = false;
            try {
                $languages = $this->packages->languages($uid);
                $pageUids = array_map('intval', explode(',', (string)$package['page_uids']));
                $site = $this->siteFinder->getSiteByPageId((int)$package['page_uid']);
                $cfg = (int)$package['l10n_cfg'];
                if ($cfg === 0) {
                    $cfg = $this->runner->createConfiguration($site->getRootPageId(), 'DigitalTolk ' . $package['reference'], $pageUids, $this->collector->rawHtmlElements($pageUids));
                    $this->packages->updatePackage($uid, ['l10n_cfg' => $cfg]);
                }
                $items = $this->collector->items($pageUids, $site);
                $xml = $this->runner->export($cfg, (int)$languages[0]['language_id'], (int)$package['workspace_id']);
                $upload = $client->startPackage((string)$package['reference'], [['path' => ContentCollector::FILE, 'size' => strlen($xml)]]);
                $started = true;
                $client->upload($upload['uploads'][0] ?? [], $xml);
                $manifest = [
                    'source_language' => (string)$package['source_language'],
                    'target_languages' => array_column($languages, 'language_code'),
                    'items' => $items,
                ];
                if ((int)$package['due_date'] > 0) {
                    $manifest['due'] = gmdate('Y-m-d\TH:i:s\Z', (int)$package['due_date']);
                }
                if ((string)$package['note'] !== '') {
                    $manifest['note'] = (string)$package['note'];
                }
                $client->complete((string)$package['reference'], $manifest);
                $this->packages->updatePackage($uid, [
                    'state' => PackageState::SENT,
                    'items' => json_encode($items, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    'item_count' => count($items),
                    'export_xml' => $xml,
                    'sent_at' => time(),
                    'needs_poll' => 1,
                    'error' => '',
                ]);
                foreach ($languages as $language) {
                    $this->packages->updateLanguage((int)$language['uid'], ['state' => LanguageState::WAITING]);
                }
                $this->log[] = sprintf('%s: sent %d items for %s.', $package['reference'], count($items), implode(', ', array_column($languages, 'language_code')));
            } catch (\Throwable $e) {
                if (!$started && $this->isTemporary($e)) {
                    $this->packages->updatePackage($uid, ['state' => PackageState::QUEUED, 'error' => 'The next sync tries again: ' . $e->getMessage()]);
                    $this->log[] = sprintf('%s: send postponed: %s', $package['reference'], $e->getMessage());
                    continue;
                }
                $this->packages->updatePackage($uid, ['state' => PackageState::FAILED, 'error' => $e->getMessage()]);
                foreach ($this->packages->languages($uid) as $language) {
                    $this->packages->updateLanguage((int)$language['uid'], ['state' => LanguageState::FAILED]);
                }
                $this->log[] = sprintf('%s: send failed: %s', $package['reference'], $e->getMessage());
            }
        }
    }

    private function poll(AdapterClient $client, Settings $settings, bool $pollAll): void
    {
        $due = time() - $settings->pollMinutes * 60;
        foreach ($this->packages->packagesInState(PackageState::SENT) as $package) {
            $uid = (int)$package['uid'];
            $languages = $this->packages->languages($uid);
            $polled = array_filter($languages, static fn (array $l): bool => LanguageState::isPolled((string)$l['state']));
            if ($polled === []) {
                $this->closeIfDone($package);
                continue;
            }
            if (!$pollAll && !(int)$package['needs_poll'] && (int)$package['polled_at'] > $due) {
                continue;
            }
            try {
                $status = $client->status((string)$package['reference']);
                $remote = array_column((array)($status['languages'] ?? []), null, 'language');
                foreach ($polled as $language) {
                    $this->applyRemote($client, $package, $language, $remote[$language['language_code']] ?? null);
                }
                $this->packages->updatePackage($uid, [
                    'remote_state' => (string)($status['state'] ?? ''),
                    'review_reasons' => json_encode($status['review_reasons'] ?? [], JSON_UNESCAPED_UNICODE),
                    'polled_at' => time(),
                    'needs_poll' => 0,
                    'error' => '',
                ]);
            } catch (\Throwable $e) {
                $this->packages->updatePackage($uid, ['error' => $e->getMessage(), 'polled_at' => time()]);
                $this->log[] = sprintf('%s: status failed: %s', $package['reference'], $e->getMessage());
            }
            $this->closeIfDone((array)$this->packages->package($uid));
        }
    }

    private function applyRemote(AdapterClient $client, array $package, array $language, ?array $remote): void
    {
        if ($remote === null) {
            return;
        }
        $state = LanguageState::afterRemote((string)$language['state'], (string)($remote['state'] ?? ''));
        $fields = ['state' => $state, 'error' => (string)($remote['error'] ?? '')];
        if ($state === LanguageState::READY) {
            $file = null;
            foreach ((array)($remote['files'] ?? []) as $candidate) {
                if (str_ends_with(strtolower((string)($candidate['path'] ?? '')), '.xml')) {
                    $file = $candidate;
                    break;
                }
            }
            if ($file === null) {
                $fields['error'] = 'The delivery has no XML file.';
            } else {
                $fields['translation_xml'] = $client->download((string)$file['url']);
                $fields['state'] = (int)$package['workspace_id'] > 0 ? LanguageState::IMPORT_REQUESTED : LanguageState::DOWNLOADED;
            }
        }
        if ($fields['state'] !== $language['state']) {
            $this->log[] = sprintf('%s/%s: %s', $package['reference'], $language['language_code'], $fields['state']);
        }
        $this->packages->updateLanguage((int)$language['uid'], $fields);
    }

    private function importRequested(Settings $settings): void
    {
        foreach ($this->packages->languagesInState(LanguageState::IMPORT_REQUESTED) as $language) {
            $package = (array)$this->packages->package((int)$language['package']);
            try {
                $result = $this->importer->import($package, $language, $settings->hideNewTranslations);
                $this->packages->updateLanguage((int)$language['uid'], [
                    'state' => LanguageState::IMPORTED,
                    'imported_at' => time(),
                    'import_summary' => trim($result->summary . ' ' . $result->warning),
                    'error' => '',
                ]);
                $this->log[] = trim(sprintf('%s/%s: imported. %s %s', $package['reference'], $language['language_code'], $result->summary, $result->warning));
            } catch (\Throwable $e) {
                $this->packages->updateLanguage((int)$language['uid'], ['state' => LanguageState::DOWNLOADED, 'error' => 'Import failed: ' . $e->getMessage()]);
                $this->log[] = sprintf('%s/%s: import failed: %s', $package['reference'], $language['language_code'], $e->getMessage());
            }
        }
    }

    private function reportImported(AdapterClient $client): void
    {
        foreach ($this->packages->languagesInState(LanguageState::IMPORTED) as $language) {
            $package = (array)$this->packages->package((int)$language['package']);
            try {
                $client->imported((string)$package['reference'], (string)$language['language_code']);
                $this->packages->updateLanguage((int)$language['uid'], ['state' => LanguageState::COLLECTED, 'error' => '']);
                $this->log[] = sprintf('%s/%s: reported as imported.', $package['reference'], $language['language_code']);
            } catch (\Throwable $e) {
                $this->packages->updateLanguage((int)$language['uid'], ['error' => 'Report failed: ' . $e->getMessage()]);
            }
            $this->closeIfDone($package);
        }
    }

    private function isTemporary(\Throwable $e): bool
    {
        return $e instanceof AdapterException && ($e->statusCode === 0 || $e->statusCode === 429 || $e->statusCode >= 500);
    }

    private function closeIfDone(array $package): void
    {
        if ($package === []) {
            return;
        }
        $state = PackageState::afterLanguages((string)$package['state'], array_column($this->packages->languages((int)$package['uid']), 'state'));
        if ($state !== $package['state']) {
            $this->packages->updatePackage((int)$package['uid'], ['state' => $state]);
        }
    }
}
