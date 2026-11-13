<?php

declare(strict_types=1);

/**
 * Author: Simon Köhler (KOHLERCODE LLC)
 *
 * Backend permission checks for slug and title changes.
 * Writes go through DataHandler so table, field, page and workspace
 * restrictions from TYPO3 are applied.
 */

namespace KOHLERCODE\Slug\Security;

use TYPO3\CMS\Backend\Module\ModuleProvider;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class RecordAccessGuard
{
    /**
     * Account and system tables that must never be written or listed
     * through this extension, even if additionalTables is misconfigured.
     *
     * @var list<string>
     */
    private const DENIED_TABLES = [
        'be_users',
        'be_groups',
        'be_sessions',
        'fe_users',
        'fe_groups',
        'fe_sessions',
        'sys_log',
        'sys_history',
        'sys_registry',
        'sys_file',
        'sys_file_storage',
        'sys_lockedrecords',
    ];

    /**
     * Columns that are never a slug or a record title.
     *
     * @var list<string>
     */
    private const DENIED_FIELDS = [
        'uid',
        'pid',
        'tstamp',
        'crdate',
        'deleted',
        'hidden',
        'starttime',
        'endtime',
        'sorting',
        'perms_userid',
        'perms_groupid',
        'perms_user',
        'perms_group',
        'perms_everybody',
        'admin',
        'password',
        'usergroup',
        'disable',
        'lockToDomain',
        'TSconfig',
        'db_mountpoints',
        'options',
        'file_mountpoints',
        'workspace_perms',
        'category_perms',
    ];

    /**
     * @var array<int, bool>
     */
    private array $pageReadCache = [];

    /**
     * @var array<string, bool>
     */
    private array $recordReadCache = [];

    private readonly ?ModuleProvider $moduleProvider;

    public function __construct(?ModuleProvider $moduleProvider = null)
    {
        // The container injects ModuleProvider. Building it here fatals in
        // TYPO3 14, because that class requires ModuleRegistry.
        $this->moduleProvider = $moduleProvider;
    }

    public function hasModuleAccess(): bool
    {
        $backendUser = $this->backendUser();
        if ($backendUser === null || !$this->moduleProvider instanceof ModuleProvider) {
            return false;
        }

        foreach (['slug_list', 'slug_page'] as $moduleIdentifier) {
            if ($this->moduleProvider->accessGranted($moduleIdentifier, $backendUser)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Slug and title columns for a table configured in EXTCONF additionalTables.
     * Pages are handled by the dedicated page actions.
     *
     * @return array{slugField: string, titleField: string}|null
     */
    public function resolveRecordFields(string $table): ?array
    {
        if ($table === 'pages') {
            return null;
        }

        $slugField = $this->resolveSlugField($table);
        $titleField = $this->resolveTitleField($table);
        if ($slugField === null || $titleField === null) {
            return null;
        }

        return [
            'slugField' => $slugField,
            'titleField' => $titleField,
        ];
    }

    public function resolveSlugField(string $table): ?string
    {
        if (!$this->isTablePermitted($table)) {
            return null;
        }

        if ($table === 'pages') {
            $field = 'slug';
        } else {
            $configuration = $this->configuredTable($table);
            if ($configuration === null) {
                return null;
            }
            $field = (string)($configuration['slugField'] ?? '');
        }

        if (!$this->isSlugColumn($table, $field)) {
            return null;
        }

        return $field;
    }

    public function resolveTitleField(string $table): ?string
    {
        if (!$this->isTablePermitted($table)) {
            return null;
        }

        if ($table === 'pages') {
            $field = 'title';
        } else {
            $configuration = $this->configuredTable($table);
            if ($configuration === null) {
                return null;
            }
            $field = (string)($configuration['titleField'] ?? '');
        }

        if (!$this->isTitleColumn($table, $field)) {
            return null;
        }

        return $field;
    }

    /**
     * Persists a slug through DataHandler. Returns the stored value, or null
     * when the user may not edit that record.
     */
    public function updateSlug(string $table, int $uid, string $slug): ?string
    {
        $field = $this->resolveSlugField($table);
        if ($field === null) {
            return null;
        }

        return $this->updateField($table, $uid, $field, $slug);
    }

    /**
     * Persists a title through DataHandler. Returns null when editing is denied.
     */
    public function updateTitle(string $table, int $uid, string $title): ?string
    {
        $field = $this->resolveTitleField($table);
        if ($field === null) {
            return null;
        }

        return $this->updateField($table, $uid, $field, $title);
    }

    public function userMayReadPage(int $pageUid): bool
    {
        if ($pageUid <= 0) {
            return false;
        }
        if (array_key_exists($pageUid, $this->pageReadCache)) {
            return $this->pageReadCache[$pageUid];
        }

        $backendUser = $this->backendUser();
        if ($backendUser === null) {
            return $this->pageReadCache[$pageUid] = false;
        }

        $page = BackendUtility::getRecord('pages', $pageUid, '*', '', false);
        if (!is_array($page)) {
            return $this->pageReadCache[$pageUid] = false;
        }
        if ($backendUser->isAdmin()) {
            return $this->pageReadCache[$pageUid] = true;
        }
        if ((int)($page['deleted'] ?? 0) === 1) {
            return $this->pageReadCache[$pageUid] = false;
        }
        if (!$backendUser->checkLanguageAccess((int)($page['sys_language_uid'] ?? 0))) {
            return $this->pageReadCache[$pageUid] = false;
        }

        $permissionPage = $this->defaultLanguagePage($page);
        if (!$backendUser->isInWebMount($permissionPage)) {
            return $this->pageReadCache[$pageUid] = false;
        }

        return $this->pageReadCache[$pageUid] = $backendUser->doesUserHaveAccess(
            $permissionPage,
            Permission::PAGE_SHOW
        );
    }

    public function userMayReadRecord(string $table, int $uid): bool
    {
        if ($table === 'pages') {
            return $this->userMayReadPage($uid);
        }

        $cacheKey = $table . ':' . $uid;
        if (array_key_exists($cacheKey, $this->recordReadCache)) {
            return $this->recordReadCache[$cacheKey];
        }
        if ($uid <= 0 || $this->resolveRecordFields($table) === null) {
            return $this->recordReadCache[$cacheKey] = false;
        }

        $backendUser = $this->backendUser();
        if ($backendUser === null || !$backendUser->check('tables_select', $table)) {
            return $this->recordReadCache[$cacheKey] = false;
        }

        $record = BackendUtility::getRecord($table, $uid, '*', '', false);
        if (!is_array($record)) {
            return $this->recordReadCache[$cacheKey] = false;
        }
        if (!$backendUser->isAdmin() && (int)($record['deleted'] ?? 0) === 1) {
            return $this->recordReadCache[$cacheKey] = false;
        }

        $languageField = (string)($GLOBALS['TCA'][$table]['ctrl']['languageField'] ?? '');
        if ($languageField !== '' && isset($record[$languageField]) && !$backendUser->checkLanguageAccess($record[$languageField])) {
            return $this->recordReadCache[$cacheKey] = false;
        }

        return $this->recordReadCache[$cacheKey] = $this->userMayReadPid((int)($record['pid'] ?? 0));
    }

    /**
     * @param array<int|string, array<string, mixed>> $pages
     * @return array<int|string, array<string, mixed>>
     */
    public function filterReadablePages(array $pages): array
    {
        $filtered = [];
        foreach ($pages as $uid => $page) {
            if (!is_array($page) || !$this->userMayReadPage((int)$uid)) {
                continue;
            }
            if (isset($page['_translations']) && is_array($page['_translations'])) {
                $page['_translations'] = $this->filterTranslations($page['_translations']);
            }
            $filtered[$uid] = $page;
        }

        return $filtered;
    }

    /**
     * @param array<int|string, array<string, mixed>> $records
     * @return array<int|string, array<string, mixed>>
     */
    public function filterReadableRecords(string $table, array $records): array
    {
        $filtered = [];
        foreach ($records as $uid => $record) {
            if (!is_array($record) || !$this->userMayReadRecord($table, (int)$uid)) {
                continue;
            }
            if (isset($record['_translations']) && is_array($record['_translations'])) {
                $record['_translations'] = $this->filterTranslations($record['_translations']);
            }
            $filtered[$uid] = $record;
        }

        return $filtered;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public function filterPageRows(array $rows): array
    {
        $filtered = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            if ($this->userMayReadPage((int)($row['uid'] ?? 0))) {
                $filtered[] = $row;
            }
        }

        return $filtered;
    }

    private function updateField(string $table, int $uid, string $field, string $value): ?string
    {
        if ($uid <= 0 || !$this->userMayEditField($table, $uid, $field)) {
            return null;
        }

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([
            $table => [
                $uid => [
                    $field => $value,
                ],
            ],
        ], []);
        $dataHandler->process_datamap();
        if ($dataHandler->errorLog !== []) {
            return null;
        }

        $updated = BackendUtility::getRecordWSOL($table, $uid, [$field], '', false);
        if (!is_array($updated) || !array_key_exists($field, $updated)) {
            return null;
        }

        return (string)$updated[$field];
    }

    private function userMayEditField(string $table, int $uid, string $field): bool
    {
        $backendUser = $this->backendUser();
        if ($backendUser === null || $uid <= 0 || !$this->isTablePermitted($table)) {
            return false;
        }
        if (!$backendUser->isAdmin() && !empty($GLOBALS['TCA'][$table]['ctrl']['adminOnly'])) {
            return false;
        }
        if (!$backendUser->check('tables_modify', $table)) {
            return false;
        }
        if (!$this->userMayAccessExcludedField($table, $field)) {
            return false;
        }

        $record = BackendUtility::getRecord($table, $uid, '*', '', false);
        if (!is_array($record)) {
            return false;
        }
        if (!$backendUser->recordEditAccessInternals($table, $record)) {
            return false;
        }

        return $this->userMayModifyRecord($table, $record);
    }

    /**
     * @param array<string, mixed> $record
     */
    private function userMayModifyRecord(string $table, array $record): bool
    {
        $backendUser = $this->backendUser();
        if ($backendUser === null) {
            return false;
        }
        if ($backendUser->isAdmin()) {
            return true;
        }

        if ($table === 'pages') {
            $permissionPage = $this->defaultLanguagePage($record);
            if (!$backendUser->isInWebMount($permissionPage)) {
                return false;
            }

            return $backendUser->doesUserHaveAccess($permissionPage, Permission::PAGE_EDIT);
        }

        $pid = (int)($record['pid'] ?? 0);
        if ($pid <= 0) {
            return false;
        }
        $page = BackendUtility::getRecord('pages', $pid);
        if (!is_array($page) || !$backendUser->isInWebMount($page)) {
            return false;
        }

        return $backendUser->doesUserHaveAccess($page, Permission::CONTENT_EDIT);
    }

    private function userMayReadPid(int $pid): bool
    {
        $backendUser = $this->backendUser();
        if ($backendUser === null) {
            return false;
        }
        if ($backendUser->isAdmin()) {
            return true;
        }
        if ($pid <= 0) {
            return false;
        }

        return $this->userMayReadPage($pid);
    }

    private function userMayAccessExcludedField(string $table, string $field): bool
    {
        $backendUser = $this->backendUser();
        if ($backendUser === null) {
            return false;
        }
        if ($backendUser->isAdmin()) {
            return true;
        }

        $exclude = (bool)($GLOBALS['TCA'][$table]['columns'][$field]['exclude'] ?? false);
        if (!$exclude) {
            return true;
        }

        return (bool)$backendUser->check('non_exclude_fields', $table . ':' . $field);
    }

    /**
     * @param array<string, mixed> $page
     * @return array<string, mixed>
     */
    private function defaultLanguagePage(array $page): array
    {
        $languageId = (int)($page['sys_language_uid'] ?? 0);
        $pointerField = (string)($GLOBALS['TCA']['pages']['ctrl']['transOrigPointerField'] ?? 'l10n_parent');
        $parent = (int)($page[$pointerField] ?? 0);
        if ($languageId > 0 && $parent > 0) {
            $defaultLanguagePage = BackendUtility::getRecord('pages', $parent, '*', '', false);
            if (is_array($defaultLanguagePage)) {
                return $defaultLanguagePage;
            }
        }

        return $page;
    }

    /**
     * @param array<int|string, mixed> $translations
     * @return array<int|string, mixed>
     */
    private function filterTranslations(array $translations): array
    {
        $backendUser = $this->backendUser();
        if ($backendUser === null) {
            return [];
        }
        if ($backendUser->isAdmin()) {
            return $translations;
        }

        foreach ($translations as $languageId => $translation) {
            if (!$backendUser->checkLanguageAccess((int)$languageId)) {
                unset($translations[$languageId]);
            }
        }

        return $translations;
    }

    private function isTablePermitted(string $table): bool
    {
        if (!$this->isSafeIdentifier($table) || in_array($table, self::DENIED_TABLES, true)) {
            return false;
        }

        return isset($GLOBALS['TCA'][$table]['columns']) && is_array($GLOBALS['TCA'][$table]['columns']);
    }

    private function isSlugColumn(string $table, string $field): bool
    {
        if (!$this->isSafeIdentifier($field) || in_array($field, self::DENIED_FIELDS, true)) {
            return false;
        }

        $configuration = $GLOBALS['TCA'][$table]['columns'][$field]['config'] ?? null;

        return is_array($configuration) && ($configuration['type'] ?? '') === 'slug';
    }

    private function isTitleColumn(string $table, string $field): bool
    {
        if (!$this->isSafeIdentifier($field) || in_array($field, self::DENIED_FIELDS, true)) {
            return false;
        }

        $type = (string)($GLOBALS['TCA'][$table]['columns'][$field]['config']['type'] ?? '');

        return in_array($type, ['input', 'text'], true);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function configuredTable(string $table): ?array
    {
        $tables = $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['slug']['additionalTables'] ?? [];
        if (!is_array($tables) || !isset($tables[$table]) || !is_array($tables[$table])) {
            return null;
        }

        return $tables[$table];
    }

    private function isSafeIdentifier(string $name): bool
    {
        return (bool)preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name);
    }

    private function backendUser(): ?BackendUserAuthentication
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;

        return $backendUser instanceof BackendUserAuthentication ? $backendUser : null;
    }
}
