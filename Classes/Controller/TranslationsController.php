<?php

declare(strict_types=1);

namespace DigitalTolk\TmsConnector\Controller;

use DigitalTolk\TmsConnector\Api\AdapterClientFactory;
use DigitalTolk\TmsConnector\Domain\DueDate;
use DigitalTolk\TmsConnector\Domain\LanguageState;
use DigitalTolk\TmsConnector\Domain\PackageRepository;
use DigitalTolk\TmsConnector\Domain\PackageState;
use DigitalTolk\TmsConnector\Domain\TargetLanguages;
use DigitalTolk\TmsConnector\Export\ContentCollector;
use DigitalTolk\TmsConnector\Import\CatxmlFields;
use DigitalTolk\TmsConnector\Service\SettingsProvider;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;

#[AsController]
final class TranslationsController
{
    private const MODULE = 'web_dttmsconnector';
    private const DEPTHS = [0 => 'This page only', 1 => 'This page and 1 level', 2 => 'This page and 2 levels', 99 => 'This page and all subpages'];

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly UriBuilder $uriBuilder,
        private readonly PackageRepository $packages,
        private readonly ContentCollector $collector,
        private readonly SiteFinder $siteFinder,
        private readonly SettingsProvider $settings,
        private readonly AdapterClientFactory $clients,
        private readonly FlashMessageService $flashMessages,
    ) {
    }

    public function indexAction(ServerRequestInterface $request): ResponseInterface
    {
        $pageUid = (int)($request->getQueryParams()['id'] ?? 0);
        $view = $this->moduleTemplateFactory->create($request);
        $view->setTitle('Translations');
        $site = $this->site($pageUid);
        $settings = $this->settings->get();
        $packages = array_values(array_filter($this->packages->recent(), fn (array $p): bool => $this->canReadPage((int)$p['page_uid'])));
        $languages = $this->packages->languagesOf(array_column($packages, 'uid'));
        foreach ($packages as &$package) {
            $package['languages'] = $languages[(int)$package['uid']] ?? [];
            $package['detailUri'] = $this->uri('detail', ['id' => $pageUid, 'package' => $package['uid']]);
        }
        unset($package);
        $view->assignMultiple([
            'pageUid' => $pageUid,
            'page' => $pageUid > 0 ? BackendUtility::getRecord('pages', $pageUid, 'uid,title') : null,
            'targetLanguages' => $site !== null && $this->canReadPage($pageUid) ? $this->targetLanguages($site) : [],
            'sourceLanguage' => $site?->getDefaultLanguage()->getTitle(),
            'depths' => self::DEPTHS,
            'packages' => $packages,
            'configured' => $settings->adapterUrl !== '' && $settings->token !== '' && $settings->connectionKey !== '',
            'importMode' => $settings->importsIntoWorkspace() ? 'workspace ' . $settings->workspaceId : 'review in this module',
            'sendUri' => $this->uri('send', ['id' => $pageUid]),
            'testUri' => $this->uri('test', ['id' => $pageUid]),
        ]);

        return $view->renderResponse('Translations/Index');
    }

    public function sendAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array)$request->getParsedBody();
        $pageUid = (int)($request->getQueryParams()['id'] ?? 0);
        $site = $this->site($pageUid);
        $back = $this->uri('', ['id' => $pageUid]);
        if ($site === null || !$this->canReadPage($pageUid)) {
            return $this->fail($back, 'Pick a page of a site in the page tree.');
        }
        $available = $this->targetLanguages($site);
        $chosen = array_values(array_intersect_key($available, array_flip(array_map('intval', (array)($body['languages'] ?? [])))));
        if ($chosen === []) {
            return $this->fail($back, 'Pick at least one target language.');
        }
        $shared = TargetLanguages::duplicateCodes($chosen);
        if ($shared !== []) {
            return $this->fail($back, sprintf('Two of the chosen languages use the code "%s". Send them in separate packages.', implode('", "', $shared)));
        }
        $languages = array_column($chosen, 'code', 'id');
        $depth = (int)($body['depth'] ?? 0);
        $depth = array_key_exists($depth, self::DEPTHS) ? $depth : 0;
        $pages = array_values(array_filter($this->collector->pages($pageUid, $depth), fn (int $uid): bool => $this->canReadPage($uid)));
        if ($pages === []) {
            return $this->fail($back, 'None of these pages can be translated. Pick a normal page, not a folder.');
        }
        $settings = $this->settings->get();
        $refusal = $this->editRefusal($pages, $settings->exportWorkspace());
        if ($refusal !== '') {
            return $this->fail($back, $refusal);
        }
        $due = DueDate::fromInput((string)($body['due'] ?? ''), new \DateTimeZone(date_default_timezone_get()));
        $this->packages->create([
            'reference' => 'typo3-' . date('YmdHis') . '-' . bin2hex(random_bytes(3)),
            'state' => PackageState::QUEUED,
            'created_by' => (int)($this->backendUser()->user['uid'] ?? 0),
            'page_uid' => $pageUid,
            'depth' => $depth,
            'page_uids' => implode(',', $pages),
            'source_language' => $site->getDefaultLanguage()->getLocale()->getLanguageCode(),
            'due_date' => $due,
            'note' => mb_substr(trim((string)($body['note'] ?? '')), 0, 2000),
            'workspace_id' => $settings->exportWorkspace(),
        ], $languages);
        $this->flash('The pages are queued. The next sync run sends them to DigitalTolk.', ContextualFeedbackSeverity::OK);

        return new RedirectResponse($back);
    }

    public function detailAction(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $pageUid = (int)($query['id'] ?? 0);
        $package = $this->packages->package((int)($query['package'] ?? 0));
        if ($package === null || !$this->canReadPage((int)$package['page_uid'])) {
            return $this->fail($this->uri('', ['id' => $pageUid]), 'The package does not exist.');
        }
        $items = json_decode((string)$package['items'], true) ?: [];
        $versions = $this->collector->currentVersions($items);
        foreach ($items as &$item) {
            $current = $versions[$item['id']] ?? null;
            $item['status'] = $current === null ? 'removed' : ($current !== (string)$item['source_version'] ? 'changed' : '');
        }
        unset($item);
        $languages = $this->packages->languages((int)$package['uid']);
        $previewUid = (int)($query['language'] ?? 0);
        $preview = [];
        foreach ($languages as &$language) {
            $language['previewUri'] = $this->uri('detail', ['id' => $pageUid, 'package' => $package['uid'], 'language' => $language['uid']]);
            $language['canImport'] = $language['state'] === LanguageState::DOWNLOADED;
            if ((int)$language['uid'] === $previewUid) {
                $preview = $this->preview((string)$package['export_xml'], (string)$language['translation_xml']);
            }
        }
        unset($language);
        $view = $this->moduleTemplateFactory->create($request);
        $view->setTitle('Translations', (string)$package['reference']);
        $view->assignMultiple([
            'package' => $package,
            'reviewReasons' => json_decode((string)$package['review_reasons'], true) ?: [],
            'items' => $items,
            'changedCount' => count(array_filter($items, static fn (array $i): bool => $i['status'] !== '')),
            'languages' => $languages,
            'preview' => $preview,
            'previewUid' => $previewUid,
            'backUri' => $this->uri('', ['id' => $pageUid]),
            'importUri' => $this->uri('import', ['id' => $pageUid, 'package' => $package['uid']]),
            'cancelUri' => $this->uri('cancel', ['id' => $pageUid, 'package' => $package['uid']]),
            'canCancel' => in_array($package['state'], [PackageState::QUEUED, PackageState::SENT], true),
        ]);

        return $view->renderResponse('Translations/Detail');
    }

    public function importAction(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $back = $this->uri('detail', ['id' => (int)($query['id'] ?? 0), 'package' => (int)($query['package'] ?? 0)]);
        $package = $this->packages->package((int)($query['package'] ?? 0));
        $language = $this->packages->language((int)(((array)$request->getParsedBody())['language'] ?? 0));
        if ($package === null || !$this->canReadPage((int)$package['page_uid'])
            || $language === null || (int)$language['package'] !== (int)$package['uid'] || $language['state'] !== LanguageState::DOWNLOADED) {
            return $this->fail($back, 'This translation cannot be imported now.');
        }
        if (!$this->backendUser()->checkLanguageAccess((int)$language['language_id'])) {
            return $this->fail($back, 'You may not edit this language.');
        }
        $refusal = $this->editRefusal(array_map('intval', explode(',', (string)$package['page_uids'])), (int)$package['workspace_id']);
        if ($refusal !== '') {
            return $this->fail($back, $refusal);
        }
        $this->packages->updateLanguage((int)$language['uid'], [
            'state' => LanguageState::IMPORT_REQUESTED,
            'import_requested_by' => (int)($this->backendUser()->user['uid'] ?? 0),
            'error' => '',
        ]);
        $this->flash('The import is queued. The next sync run imports the translation.', ContextualFeedbackSeverity::OK);

        return new RedirectResponse($back);
    }

    public function cancelAction(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $back = $this->uri('detail', ['id' => (int)($query['id'] ?? 0), 'package' => (int)($query['package'] ?? 0)]);
        $package = $this->packages->package((int)($query['package'] ?? 0));
        if ($package !== null && $package['state'] === PackageState::SENDING) {
            return $this->fail($back, 'The package is being sent now. Try again in a minute.');
        }
        if ($package === null || !$this->canReadPage((int)$package['page_uid'])
            || !in_array($package['state'], [PackageState::QUEUED, PackageState::SENT], true)) {
            return $this->fail($back, 'This package cannot be cancelled.');
        }
        $refusal = $this->editRefusal(array_map('intval', explode(',', (string)$package['page_uids'])), (int)$package['workspace_id']);
        if ($refusal !== '') {
            return $this->fail($back, $refusal);
        }
        if ($package['state'] === PackageState::SENT) {
            try {
                $this->clients->create()->cancel((string)$package['reference']);
            } catch (\Throwable $e) {
                return $this->fail($back, 'DigitalTolk did not cancel the package: ' . $e->getMessage());
            }
        }
        if (!$this->packages->transition((int)$package['uid'], (string)$package['state'], ['state' => PackageState::CANCELLED])) {
            return $this->fail($back, 'The package changed in the meantime. Open it again and check its state.');
        }
        foreach ($this->packages->languages((int)$package['uid']) as $language) {
            $this->packages->updateLanguage((int)$language['uid'], ['state' => LanguageState::CANCELLED]);
        }
        $this->flash('The package is cancelled.', ContextualFeedbackSeverity::OK);

        return new RedirectResponse($back);
    }

    public function testAction(ServerRequestInterface $request): ResponseInterface
    {
        $back = $this->uri('', ['id' => (int)($request->getQueryParams()['id'] ?? 0)]);
        try {
            $ping = $this->clients->create()->ping();
        } catch (\Throwable $e) {
            return $this->fail($back, 'The connection test failed: ' . $e->getMessage());
        }
        $this->flash(sprintf(
            'Connected to "%s" (%s). Source language: %s. Target languages: %s.',
            $ping['connection'] ?? '',
            $ping['cms_type'] ?? '',
            $ping['source_language'] ?? '',
            implode(', ', (array)($ping['target_languages'] ?? [])),
        ), ContextualFeedbackSeverity::OK);

        return new RedirectResponse($back);
    }

    private function preview(string $source, string $translation): array
    {
        $reader = new CatxmlFields();
        $sourceFields = $reader->read($source);
        $rows = [];
        foreach ($reader->read($translation) as $id => $field) {
            $rows[] = $field + ['source' => $sourceFields[$id]['text'] ?? ''];
        }

        return $rows;
    }

    private function editRefusal(array $pageUids, int $workspaceId): string
    {
        $user = $this->backendUser();
        if ((int)$user->workspace !== $workspaceId) {
            return $workspaceId === 0
                ? 'Switch to the live workspace. These translations go to live.'
                : sprintf('Switch to workspace %d. These translations go to that workspace.', $workspaceId);
        }
        if (!$user->check('tables_modify', 'pages') || !$user->check('tables_modify', 'tt_content')) {
            return 'You may not edit pages and content.';
        }
        $clause = $user->getPagePermsClause(Permission::PAGE_EDIT | Permission::CONTENT_EDIT);
        foreach ($pageUids as $uid) {
            if (BackendUtility::readPageAccess($uid, $clause) === false) {
                return sprintf('You may not edit page %d or its content.', $uid);
            }
        }

        return '';
    }

    private function canReadPage(int $uid): bool
    {
        return $uid > 0 && BackendUtility::readPageAccess($uid, $this->backendUser()->getPagePermsClause(Permission::PAGE_SHOW)) !== false;
    }

    private function targetLanguages(Site $site): array
    {
        $languages = [];
        foreach ($site->getLanguages() as $language) {
            if ($language->getLanguageId() > 0 && $this->backendUser()->checkLanguageAccess($language->getLanguageId())) {
                $languages[$language->getLanguageId()] = [
                    'id' => $language->getLanguageId(),
                    'title' => $language->getTitle(),
                    'code' => $language->getLocale()->getLanguageCode(),
                ];
            }
        }

        return $languages;
    }

    private function site(int $pageUid): ?Site
    {
        if ($pageUid <= 0) {
            return null;
        }
        try {
            return $this->siteFinder->getSiteByPageId($pageUid);
        } catch (SiteNotFoundException) {
            return null;
        }
    }

    private function uri(string $route, array $parameters): string
    {
        return (string)$this->uriBuilder->buildUriFromRoute($route === '' ? self::MODULE : self::MODULE . '.' . $route, $parameters);
    }

    private function fail(string $uri, string $message): ResponseInterface
    {
        $this->flash($message, ContextualFeedbackSeverity::ERROR);

        return new RedirectResponse($uri);
    }

    private function flash(string $message, ContextualFeedbackSeverity $severity): void
    {
        $this->flashMessages->getMessageQueueByIdentifier()->enqueue(new FlashMessage($message, '', $severity, true));
    }

    private function backendUser(): BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'];
    }
}
