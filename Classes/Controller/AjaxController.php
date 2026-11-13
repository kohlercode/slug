<?php
/**
 * @author Simon Kohler <simon@kohlercode.com>
 * Author: Simon Köhler (KOHLERCODE LLC)
 */

declare(strict_types=1);
namespace KOHLERCODE\Slug\Controller;

use KOHLERCODE\Slug\Domain\Repository\PageRepository;
use KOHLERCODE\Slug\Domain\Repository\RecordRepository;
use KOHLERCODE\Slug\Security\RecordAccessGuard;
use KOHLERCODE\Slug\Utility\HelperUtility;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\SlugHelper;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

/**
 * Handles Ajax requests for slug and title editing.
 *
 * Every action requires the Slug backend module. Writes are limited to the
 * page slug/title or to slug and title fields configured for a table, and
 * they are stored through DataHandler.
 */
class AjaxController extends ActionController
{
    public HelperUtility $helper;

    public function __construct(
        private PageRepository $pageRepository,
        private RecordRepository $recordRepository,
        private RecordAccessGuard $recordAccessGuard,
    ) {
        $this->helper = GeneralUtility::makeInstance(HelperUtility::class);
    }

    /**
     * Returns a list of pages the current backend user may see.
     */
    public function listAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->recordAccessGuard->hasModuleAccess()) {
            return new HtmlResponse('', 403);
        }

        $params = $this->queryParams($request);
        $pages = $this->pageRepository->getPageDataForList(
            $params['maxentries'] ?? 20,
            $this->stringParam($params, 'key'),
            $this->stringParam($params, 'orderby'),
            $this->stringParam($params, 'order'),
            $this->stringParam($params, 'status')
        );
        $pages = $this->recordAccessGuard->filterReadablePages($pages);

        $view = $this->helper->createViewAndTemplatePaths('ListAjax', $request);
        $view->assign('pages', $pages);
        $view->assign('params', $params);

        return new HtmlResponse($view->render('ListAjax'));
    }

    /**
     * Returns records from one configured table the current user may see.
     */
    public function recordAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->recordAccessGuard->hasModuleAccess()) {
            return new HtmlResponse('', 403);
        }

        $params = $this->queryParams($request);
        $table = $this->stringParam($params, 'table');
        $fields = $this->recordAccessGuard->resolveRecordFields($table);
        if ($fields === null) {
            return new HtmlResponse('', 403);
        }

        $records = $this->recordRepository->getRecordDataForList(
            $table,
            $fields['slugField'],
            $fields['titleField'],
            $params['maxentries'] ?? 20,
            $this->stringParam($params, 'key'),
            $this->stringParam($params, 'orderby'),
            $this->stringParam($params, 'order'),
            $this->stringParam($params, 'status')
        );
        $records = $this->recordAccessGuard->filterReadableRecords($table, $records);

        $view = $this->helper->createViewAndTemplatePaths('RecordsAjax', $request);
        $view->assign('records', $records);
        $view->assign('params', $params);

        return new HtmlResponse($view->render('RecordsAjax'));
    }

    /**
     * Updates a page slug when the user may edit that page.
     */
    public function savePageSlug(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->recordAccessGuard->hasModuleAccess()) {
            return $this->deniedJson();
        }

        $params = $this->queryParams($request);
        $storedSlug = $this->recordAccessGuard->updateSlug(
            'pages',
            $this->intParam($params, 'uid'),
            $this->stringParam($params, 'slug')
        );
        if ($storedSlug === null) {
            return $this->deniedJson();
        }

        return new JsonResponse([
            'status' => '1',
            'slug' => $storedSlug,
        ]);
    }

    /**
     * Updates the configured slug field of one additional table.
     * The column name is taken from extension configuration, not from the request.
     */
    public function saveRecordSlug(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->recordAccessGuard->hasModuleAccess()) {
            return $this->deniedJson();
        }

        $params = $this->queryParams($request);
        $storedSlug = $this->recordAccessGuard->updateSlug(
            $this->stringParam($params, 'table'),
            $this->intParam($params, 'uid'),
            $this->stringParam($params, 'slug')
        );
        if ($storedSlug === null) {
            return $this->deniedJson();
        }

        return new JsonResponse([
            'status' => '1',
            'slug' => $storedSlug,
        ]);
    }

    /**
     * Checks whether a page slug already exists. The table is fixed to pages.
     */
    public function slugExists(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->recordAccessGuard->hasModuleAccess()) {
            return new HtmlResponse('', 403);
        }

        $params = $this->queryParams($request);
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('pages');
        $count = $queryBuilder
            ->count('slug')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq(
                    'slug',
                    $queryBuilder->createNamedParameter($this->stringParam($params, 'slug'))
                )
            )
            ->executeQuery()
            ->fetchOne();

        return new HtmlResponse((string)$count);
    }

    /**
     * Generates a page slug for a page the user may read.
     */
    public function getPageSlug(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->recordAccessGuard->hasModuleAccess()) {
            return new JsonResponse(['slug' => ''], 403);
        }

        $pageUid = $this->intParam($this->queryParams($request), 'uid');
        if (!$this->recordAccessGuard->userMayReadPage($pageUid)) {
            return new JsonResponse(['slug' => ''], 403);
        }

        $fieldConfig = $GLOBALS['TCA']['pages']['columns']['slug']['config'] ?? null;
        if (!is_array($fieldConfig)) {
            return new JsonResponse(['slug' => ''], 403);
        }

        return new JsonResponse([
            'slug' => $this->helper->generatePageSlug($pageUid, $fieldConfig),
        ]);
    }

    /**
     * Generates a record slug from the configured title field.
     */
    public function generateRecordSlug(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->recordAccessGuard->hasModuleAccess()) {
            return new JsonResponse(['success' => false, 'slug' => ''], 403);
        }

        $params = $this->queryParams($request);
        $table = $this->stringParam($params, 'table');
        $uid = $this->intParam($params, 'uid');
        $fields = $this->recordAccessGuard->resolveRecordFields($table);
        if ($fields === null || !$this->recordAccessGuard->userMayReadRecord($table, $uid)) {
            return new JsonResponse(['success' => false, 'slug' => ''], 403);
        }

        $fieldConfig = $GLOBALS['TCA'][$table]['columns'][$fields['slugField']]['config'] ?? null;
        $record = BackendUtility::getRecord($table, $uid);
        if (!is_array($fieldConfig) || !is_array($record) || !isset($record[$fields['titleField']])) {
            return new JsonResponse(['success' => false, 'slug' => ''], 403);
        }

        $slugHelper = GeneralUtility::makeInstance(
            SlugHelper::class,
            $table,
            $fields['slugField'],
            $fieldConfig
        );

        return new JsonResponse([
            'success' => true,
            'slug' => $slugHelper->sanitize((string)$record[$fields['titleField']]),
        ]);
    }

    /**
     * Renders slug details for a page the user may read.
     */
    public function slugInfo(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->recordAccessGuard->hasModuleAccess()) {
            return new HtmlResponse('', 403);
        }

        $pageUid = $this->intParam($this->queryParams($request), 'uid');
        if (!$this->recordAccessGuard->userMayReadPage($pageUid)) {
            return new HtmlResponse('', 403);
        }

        $page = BackendUtility::getRecord('pages', $pageUid);
        if (!is_array($page)) {
            return new HtmlResponse('', 404);
        }

        $site = $this->helper->getSiteByPageUid($pageUid);
        $view = $this->helper->createViewAndTemplatePaths('SlugInfo', $request);
        $view->assign('uid', $page['uid']);
        $view->assign('url', $this->helper->getAbsoluteUrl((int)$page['uid']));
        $view->assign('title', $page['seo_title'] ?: $page['title']);
        $view->assign('description', $page['description'] ?: 'n/a');
        $view->assign('sitename', is_array($site) ? ($site['base'] ?? '') : '');
        $view->assign('translate', ['feature_note' => $this->helper->getLangKey('feature_note')]);

        return new HtmlResponse($view->render('SlugInfo'));
    }

    /**
     * Updates a page title when the user may edit that page.
     */
    public function updatePageTitle(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->recordAccessGuard->hasModuleAccess()) {
            return $this->deniedTitleJson();
        }

        $params = $this->queryParams($request);
        $storedTitle = $this->recordAccessGuard->updateTitle(
            'pages',
            $this->intParam($params, 'uid'),
            $this->stringParam($params, 'title')
        );
        if ($storedTitle === null) {
            return $this->deniedTitleJson();
        }

        return new JsonResponse(['status' => 'success']);
    }

    /**
     * Updates the configured title field of one additional table.
     */
    public function updateRecordTitle(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->recordAccessGuard->hasModuleAccess()) {
            return $this->deniedTitleJson();
        }

        $params = $this->queryParams($request);
        $storedTitle = $this->recordAccessGuard->updateTitle(
            $this->stringParam($params, 'table'),
            $this->intParam($params, 'uid'),
            $this->stringParam($params, 'title')
        );
        if ($storedTitle === null) {
            return $this->deniedTitleJson();
        }

        return new JsonResponse(['status' => 'success']);
    }

    /**
     * @return array<string, mixed>
     */
    private function queryParams(ServerRequestInterface $request): array
    {
        $params = $request->getQueryParams();

        return is_array($params) ? $params : [];
    }

    /**
     * @param array<string, mixed> $params
     */
    private function intParam(array $params, string $key): int
    {
        return (int)($params[$key] ?? 0);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function stringParam(array $params, string $key): string
    {
        $value = $params[$key] ?? '';

        return is_scalar($value) ? (string)$value : '';
    }

    private function deniedJson(): JsonResponse
    {
        return new JsonResponse([
            'status' => '0',
            'slug' => '',
        ], 403);
    }

    private function deniedTitleJson(): JsonResponse
    {
        return new JsonResponse(['status' => 'error'], 403);
    }
}
