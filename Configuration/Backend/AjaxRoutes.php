<?php

use KOHLERCODE\Slug\Controller;

/**
 * Ajax routes for the Slug backend module.
 *
 * inheritAccessFromModule limits every route to backend users who may open
 * the Slug list module. Record and page permissions are checked again in
 * the controller before any read or write.
 *
 * @return array<string, array<string, string>>
 */
return [
    'slug_list' => [
        'path' => '/slug/list',
        'target' => Controller\AjaxController::class . '::listAction',
        'inheritAccessFromModule' => 'slug_list',
    ],
    'slug_record' => [
        'path' => '/slug/record',
        'target' => Controller\AjaxController::class . '::recordAction',
        'inheritAccessFromModule' => 'slug_list',
    ],
    'slug_save_page' => [
        'path' => '/slug/savePageSlug',
        'target' => Controller\AjaxController::class . '::savePageSlug',
        'inheritAccessFromModule' => 'slug_list',
    ],
    'slug_info' => [
        'path' => '/slug/slugInfo',
        'target' => Controller\AjaxController::class . '::slugInfo',
        'inheritAccessFromModule' => 'slug_list',
    ],
    'slug_exists' => [
        'path' => '/slug/slugExists',
        'target' => Controller\AjaxController::class . '::slugExists',
        'inheritAccessFromModule' => 'slug_list',
    ],
    'slug_generate' => [
        'path' => '/slug/generatePageSlug',
        'target' => Controller\AjaxController::class . '::getPageSlug',
        'inheritAccessFromModule' => 'slug_list',
    ],
    'slug_save_record' => [
        'path' => '/slug/saveRecordSlug',
        'target' => Controller\AjaxController::class . '::saveRecordSlug',
        'inheritAccessFromModule' => 'slug_list',
    ],
    'slug_generate_record' => [
        'path' => '/slug/generateRecordSlug',
        'target' => Controller\AjaxController::class . '::generateRecordSlug',
        'inheritAccessFromModule' => 'slug_list',
    ],
    'slug_update_page_title' => [
        'path' => '/slug/savePageTitle',
        'target' => Controller\AjaxController::class . '::updatePageTitle',
        'inheritAccessFromModule' => 'slug_list',
    ],
    'slug_update_record_title' => [
        'path' => '/slug/saveRecordTitle',
        'target' => Controller\AjaxController::class . '::updateRecordTitle',
        'inheritAccessFromModule' => 'slug_list',
    ],
];
