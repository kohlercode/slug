<?php
namespace KOHLERCODE\Slug\Utility;
use KOHLERCODE\Slug\Security\RecordAccessGuard;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\DataHandling\SlugHelper;
use TYPO3\CMS\Core\DataHandling\Model\RecordState;
use TYPO3\CMS\Core\DataHandling\Model\RecordStateFactory;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\View\ViewFactoryData;
use TYPO3\CMS\Core\View\ViewFactoryInterface;

class HelperUtility {


    public function createViewAndTemplatePaths($templateName,$request){
        $viewFactory = GeneralUtility::makeInstance(ViewFactoryInterface::class);
        $viewFactoryData = new ViewFactoryData(
            templateRootPaths: ['EXT:slug/Resources/Private/Templates'],
            partialRootPaths: ['EXT:slug/Resources/Private/Partials'],
            layoutRootPaths: ['EXT:slug/Resources/Private/Layouts'],
            request: $request,
        );
        return $viewFactory->create($viewFactoryData);
    }

    // Get Extension Manager configuration from the ext_emconf.php of any extension
    public function getEmConfiguration($extKey) {

        $fileName = 'EXT:'.$extKey.'/ext_emconf.php';
        $filePath = GeneralUtility::getFileAbsFileName($fileName);

        if(file_exists($filePath)){
            include $filePath;
            return $EM_CONF[$extKey];
        }
        else{
            print_r('File does not exist: '.$filePath);
            return false;
        }

    }

    /**
     * Builds the URL by determining if the language base is a domain override.
     */
    public function getPageUrl(string $base, string $baseLanguage, string $slug): string
    {
        // 1. Sanitize the slug (remove leading slash)
        $slug = ltrim($slug, '/');

        // 2. Logic for domain replacement or path concatenation
        if (str_starts_with($baseLanguage, 'http')) {
            // If it's a URL and different from the main base, it becomes the new base
            $finalBase = rtrim($baseLanguage, '/');
        } else {
            // Otherwise, combine main base and the language segment (e.g., /es/)
            $mainBase = rtrim($base, '/');
            $langPath = trim($baseLanguage, '/');
            
            // Use array_filter to ignore the language segment if it's empty
            $finalBase = implode('/', array_filter([$mainBase, $langPath]));
        }

        // 3. Return the final joined string
        return $finalBase . '/' . $slug;
    }

    /**
     * Generates an absolute frontend URL for a given page UID (can be a translation UID).
     * * @param int $pageUid The UID of the page or the translation
     * @return string The absolute URL
     */
    public function getAbsoluteUrl(int $pageUid): string
    {
        $siteFinder = GeneralUtility::makeInstance(SiteFinder::class);

        try {
            // 1. Find the site and the specific language for this UID
            // getSiteByPageId is smart: if you pass a translation UID, 
            // it finds the correct site and language.
            $site = $siteFinder->getSiteByPageId($pageUid);
            
            // 2. We need to find which language this specific UID belongs to.
            // If it's a translation, we need to pass that language ID to the router.
            $pageRecord = GeneralUtility::makeInstance(\TYPO3\CMS\Core\Domain\Repository\PageRepository::class)
                ->getPage($pageUid);
                
            $languageId = (int)($pageRecord['sys_language_uid'] ?? 0);

            // 3. Generate the URI
            $uri = $site->getRouter()->generateUri(
                $pageUid,
                ['_language' => $languageId]
            );

            // Ensure it's absolute (important for cross-domain or backend context)
            return (string)$uri;

        } catch (\Exception $e) {
            // Fallback or log error if page doesn't exist/isn't in a site tree
            return '';
        }
    }

    // Finds and returns the base URL of the website
    public function getSiteByPageUid($pageUid){
        $sitefinder = GeneralUtility::makeInstance(SiteFinder::class);
        try {
            $site = $sitefinder->getSiteByPageId($pageUid);
            $output = $site->getConfiguration();
        }
        catch (SiteNotFoundException $e) {
           $output = false;
        }
        return $output;
    }

    // Gets the correct flag icon for any given language uid
    public function getFlagIconByLanguageUid($sys_language_uid) {
        foreach ($this->getLanguages() as $value) {
            if($value['uid'] === $sys_language_uid){
                $path = '/typo3/sysext/core/Resources/Public/Icons/Flags/'.strtoupper($value['flag']).'.png';
                break;
            }
            else{
                $path = 'typo3conf/ext/slug/Resources/Public/Icons/Flags/default.png';
                break;
            }
        }
        return '<img src="'.$path.'">';
    }

    public function returnUniqueSlug($type,$slug,$recordUid,$table,$slugField) {
        $recordUid = (int)$recordUid;
        $table = (string)$table;
        $slugField = (string)$slugField;

        switch ($type) {
            case 'page':
                $table = 'pages';
                $slugField = 'slug';
                break;
            case 'news':
                $table = 'tx_news_domain_model_news';
                $slugField = 'path_segment';
                break;
            case 'record':
                break;
            default:
                return '';
        }

        $fieldConfig = $GLOBALS['TCA'][$table]['columns'][$slugField]['config'] ?? null;
        if (!is_array($fieldConfig) || ($fieldConfig['type'] ?? '') !== 'slug') {
            return '';
        }

        $record = $this->getRecordForSlugBuilding($recordUid, $table);
        if ($record === [] || !isset($record['pid'])) {
            return '';
        }

        $slugHelper = GeneralUtility::makeInstance(SlugHelper::class, $table, $slugField, $fieldConfig);
        $state = RecordStateFactory::forName($table)->fromArray($record, $record['pid'], $recordUid);

        return $slugHelper->buildSlugForUniqueInSite((string)$slug, $state);
    }

    public function getIsoCodeByLanguageUid($sys_language_uid) {
        foreach ($this->getLanguages() as $value) {
            if($value['uid'] === $sys_language_uid){
                $output = $value['language_isocode'];
                break;
            }
            elseif($sys_language_uid === 0){
                $output = '';
                break;
            }
        }
        return $output;
    }


    public function getPageTranslationsByUid($uid){
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('pages');
        $statement = $queryBuilder
            ->select('*')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('l10n_parent', $queryBuilder->createNamedParameter($uid, \PDO::PARAM_INT))
             )
            ->executeQuery();
        $output = array();
        while ($row = $statement->fetch()) {
            array_push($output, $row);
        }
        return $output;
    }


    public function getLangKey($key) {
        return \TYPO3\CMS\Extbase\Utility\LocalizationUtility::translate($key,'slug');
    }


    public function getRecordForSlugBuilding($uid,$table){
        $uid = (int)$uid;
        $table = (string)$table;
        if ($uid <= 0 || !$this->isDatabaseIdentifier($table) || !isset($GLOBALS['TCA'][$table])) {
            return [];
        }

        $accessGuard = GeneralUtility::makeInstance(RecordAccessGuard::class);
        if (!$accessGuard->userMayReadRecord($table, $uid)) {
            return [];
        }

        $row = BackendUtility::getRecord($table, $uid);

        return is_array($row) ? $row : [];
    }

    public function generatePageSlug($pageUid,$fieldConfig){
        $pageUid = (int)$pageUid;
        if ($pageUid <= 0 || !is_array($fieldConfig)) {
            return '';
        }
        $accessGuard = GeneralUtility::makeInstance(RecordAccessGuard::class);
        if (!$accessGuard->userMayReadPage($pageUid)) {
            return '';
        }

        $record = BackendUtility::getRecord('pages', $pageUid);
        if (!is_array($record) || !isset($record['pid'])) {
            return '';
        }

        $slugHelper = GeneralUtility::makeInstance(SlugHelper::class, 'pages', 'slug', $fieldConfig);

        return $slugHelper->generate($record, $record['pid']);
    }

    /**
     * function getTotalRecords
     *
     * @return void
     */
    public function getTotalRecords($table){
        $table = (string)$table;
        if (!$this->isDatabaseIdentifier($table) || !isset($GLOBALS['TCA'][$table])) {
            return 0;
        }
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication || !$backendUser->check('tables_select', $table)) {
            return 0;
        }

        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($table);
        $count = $queryBuilder
           ->count('uid')
           ->from($table)
           ->executeQuery()
           ->fetchOne();
        return $count;
    }

    private function isDatabaseIdentifier(string $name): bool
    {
        return (bool)preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name);
    }

}
