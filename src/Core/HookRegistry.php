<?php

declare(strict_types=1);

namespace Plathix\Core;

/**
 * Каталог всех `plathix/*`-слотов (WP filter/action hooks), которые Free объявляет как
 * extension points для PRO/будущих аддонов ([internal], [internal]).
 *
 * Источник истины: имя слота (без префикса `plathix/`), тип (`filter`/`action`) и файл, где
 * слот объявлен. Проверяется двусторонним PHPUnit-гейтом:
 * - `tests/HookRegistrySlotCoverageTest.php` (Free) — каждый реальный вызов apply_filters/
 *   do_action на префикс plathix/ в `src/` обязан быть здесь, и наоборот;
 * - `HookRegistrySubscriptionTest.php` (PRO) — каждая подписка add_filter/
 *   add_action на префикс plathix/ в PRO обязана ссылаться на существующее здесь имя.
 *
 * Слот, встречающийся в коде на нескольких call sites под одним именем (напр. `admin/root_slug`,
 * `folder/updated`), — одна запись здесь; `declared_in` указывает первый найденный файл, не
 * полный список мест вызова.
 */
final class HookRegistry
{
	/** @var array<string, array{type: 'filter'|'action', declared_in: string, dynamic?: bool}> */
	public const SLOTS = [
		// filters
		'edition/pro_active'                    => [ 'type' => 'filter', 'declared_in' => 'src/Edition.php' ],
		'sidebar/lazy_tree_threshold'            => [ 'type' => 'filter', 'declared_in' => 'src/Core/FolderTreeBootstrapStrategy.php' ],
		'folder/depth_limit'                     => [ 'type' => 'filter', 'declared_in' => 'src/Admin/SidebarRuntimeConfigBuilder.php' ],
		'ui/z_index_lightbox'                    => [ 'type' => 'filter', 'declared_in' => 'src/Admin/SidebarRuntimeConfigBuilder.php' ],
		'sidebar/config'                         => [ 'type' => 'filter', 'declared_in' => 'src/Admin/SidebarRuntimeConfigBuilder.php' ],
		'sidebar/root_classes'                   => [ 'type' => 'filter', 'declared_in' => 'src/Admin/SidebarRuntimeConfigBuilder.php' ],
		'sidebar/footer_content'                 => [ 'type' => 'filter', 'declared_in' => 'src/Admin/SidebarRuntimeConfigBuilder.php' ],
		'sidebar/empty_state'                    => [ 'type' => 'filter', 'declared_in' => 'src/Admin/SidebarRuntimeConfigBuilder.php' ],
		'sidebar/toolbar_extra'                  => [ 'type' => 'filter', 'declared_in' => 'src/Admin/SidebarRuntimeConfigBuilder.php' ],
		'admin/menu_pages'                       => [ 'type' => 'filter', 'declared_in' => 'src/Admin/AdminMenuManager.php' ],
		'assets/js_data'                         => [ 'type' => 'filter', 'declared_in' => 'src/Admin/Assets.php' ],
		'ui/z_index_sidebar'                     => [ 'type' => 'filter', 'declared_in' => 'src/Admin/Assets.php' ],
		'ui/z_index_overlay'                     => [ 'type' => 'filter', 'declared_in' => 'src/Admin/Assets.php' ],
		'ui/z_index_toast'                       => [ 'type' => 'filter', 'declared_in' => 'src/Admin/Assets.php' ],
		'sidebar/css_vars'                       => [ 'type' => 'filter', 'declared_in' => 'src/Admin/Assets.php' ],
		'infrastructure/temp_dir'                => [ 'type' => 'filter', 'declared_in' => 'uninstall.php' ],
		'admin/root_slug'                        => [ 'type' => 'filter', 'declared_in' => 'src/Modules/Tools/ToolsPage.php' ],
		'sidebar/i18n'                            => [ 'type' => 'filter', 'declared_in' => 'src/Admin/SidebarI18nBuilder.php' ],
		'folder/system_slugs'                    => [ 'type' => 'filter', 'declared_in' => 'src/Core/FolderRepository.php' ],
		'folder/trash_runner'                    => [ 'type' => 'filter', 'declared_in' => 'src/Core/FolderTreeService.php' ],
		'folder/trash_id'                        => [ 'type' => 'filter', 'declared_in' => 'src/Core/TrashFolder.php' ],
		'folder/list'                             => [ 'type' => 'filter', 'declared_in' => 'src/Core/FolderCountService.php' ],
		'cleanup/preview_items'                  => [ 'type' => 'filter', 'declared_in' => 'src/Modules/DataWipe/DangerZoneTab.php' ],
		'rest/post_type_allowed'                 => [ 'type' => 'filter', 'declared_in' => 'src/Http/RestController.php' ],
		'taxonomy/ensure_missing'                => [ 'type' => 'action', 'declared_in' => 'src/Core/FolderRepository.php' ],
		'sidebar/caps'                            => [ 'type' => 'filter', 'declared_in' => 'src/Http/RestController.php' ],
		'folder/hidden_ids'                      => [ 'type' => 'filter', 'declared_in' => 'src/Core/HiddenFolders.php' ],
		'infrastructure/service_token_active'    => [ 'type' => 'filter', 'declared_in' => 'src/Http/PreferencesController.php' ],
		'user/access_level'                      => [ 'type' => 'filter', 'declared_in' => 'src/User/AccessResolver.php' ],
		'dashboard/widgets'                      => [ 'type' => 'filter', 'declared_in' => 'src/Modules/Dashboard/HomeDashboardPage.php' ],
		'docs/page_url'                          => [ 'type' => 'filter', 'declared_in' => 'src/Modules/Dashboard/HomeDashboardPage.php' ],
		'feature/*'                               => [ 'type' => 'filter', 'declared_in' => 'src/Infrastructure/Features.php', 'dynamic' => true ],
		'infrastructure/temp_file_max_age'       => [ 'type' => 'filter', 'declared_in' => 'src/Infrastructure/Jobs/CleanupJobRunner.php' ],
		'infrastructure/temp_file_grace_period'  => [ 'type' => 'filter', 'declared_in' => 'src/Infrastructure/Jobs/CleanupJobRunner.php' ],
		'infrastructure/temp_dir_max_bytes'      => [ 'type' => 'filter', 'declared_in' => 'src/Infrastructure/Jobs/CleanupJobRunner.php' ],
		'infrastructure/job_result_max_age'      => [ 'type' => 'filter', 'declared_in' => 'src/Infrastructure/Jobs/CleanupJobRunner.php' ],
		'infrastructure/job_result_file_backed_max_age' => [ 'type' => 'filter', 'declared_in' => 'src/Infrastructure/Jobs/CleanupJobRunner.php' ],
		'dashboard/onboarding_cards'             => [ 'type' => 'filter', 'declared_in' => 'src/Modules/Dashboard/HomeDashboardData.php' ],
		'folder/restore_runner'                  => [ 'type' => 'filter', 'declared_in' => 'src/PublicApi/FoldersApi.php' ],
		'infrastructure/current_identity_key'    => [ 'type' => 'filter', 'declared_in' => 'src/Infrastructure/IdentityKeyResolver.php' ],
		'infrastructure/current_service_token_id' => [ 'type' => 'filter', 'declared_in' => 'src/Infrastructure/JobDispatcher.php' ],
		'infrastructure/resolve_owner_identity'  => [ 'type' => 'filter', 'declared_in' => 'src/Infrastructure/JobStatusRepository.php' ],
		'media/trash_runner'                     => [ 'type' => 'filter', 'declared_in' => 'src/PublicApi/AssignmentsApi.php' ],
		'media/restore_runner'                   => [ 'type' => 'filter', 'declared_in' => 'src/PublicApi/AssignmentsApi.php' ],
		'jobs/heavy_caps'                        => [ 'type' => 'filter', 'declared_in' => 'src/Infrastructure/Health/HealthCheckRegistry.php' ],
		'log_level'                               => [ 'type' => 'filter', 'declared_in' => 'src/Infrastructure/Logger.php' ],
		'search/only_threshold'                  => [ 'type' => 'filter', 'declared_in' => 'src/Modules/SearchFilters/Module.php' ],
		'admin/settings_tabs'                    => [ 'type' => 'filter', 'declared_in' => 'src/Modules/Settings/SettingsView.php' ],
		'settings/general_sections'              => [ 'type' => 'filter', 'declared_in' => 'src/Modules/Settings/SettingsView.php' ],
		'svg/max_upload_bytes'                   => [ 'type' => 'filter', 'declared_in' => 'src/Modules/Svg/SvgSupport.php' ],
		'svg/blocked_notice'                     => [ 'type' => 'filter', 'declared_in' => 'src/Modules/Svg/SvgSupport.php' ],
		'svg/user_override_allows_upload'        => [ 'type' => 'filter', 'declared_in' => 'src/Modules/Svg/SvgSupport.php' ],

		// actions
		'delete_plugin_data'                     => [ 'type' => 'action', 'declared_in' => 'uninstall.php' ],
		'modules/register'                       => [ 'type' => 'action', 'declared_in' => 'src/Plugin.php' ],
		'modules/boot'                            => [ 'type' => 'action', 'declared_in' => 'src/Plugin.php' ],
		'jobs/register_handlers'                 => [ 'type' => 'action', 'declared_in' => 'src/Infrastructure/JobDispatcher.php' ],
		'jobs/unschedule'                         => [ 'type' => 'action', 'declared_in' => 'src/Deactivator.php' ],
		'import/job'                              => [ 'type' => 'action', 'declared_in' => 'src/Infrastructure/Jobs/ImportJobRunner.php' ],
		'audit/record'                            => [ 'type' => 'action', 'declared_in' => 'src/Modules/Replace/AttachmentReplaceService.php' ],
		'replace/attachment_replaced'            => [ 'type' => 'action', 'declared_in' => 'src/Modules/Replace/AttachmentReplaceService.php' ],
		'tools/cards'                             => [ 'type' => 'action', 'declared_in' => 'src/Modules/Tools/ToolsPage.php' ],
		'preset/reset_wizard_button'              => [ 'type' => 'action', 'declared_in' => 'src/Modules/Preset/PresetsPage.php' ],
		'data_wipe/cleanup'                       => [ 'type' => 'action', 'declared_in' => 'src/Modules/DataWipe/DataWipeAjax.php' ],
		'favorites/changed'                       => [ 'type' => 'action', 'declared_in' => 'src/User/Preferences.php' ],
		'folder/created'                          => [ 'type' => 'action', 'declared_in' => 'src/Core/FolderTreeService.php' ],
		'folder/updated'                          => [ 'type' => 'action', 'declared_in' => 'src/Core/FolderTreeService.php' ],
		'folder/trashed'                          => [ 'type' => 'action', 'declared_in' => 'src/Core/FolderTreeService.php' ],
		'folder/deleted'                          => [ 'type' => 'action', 'declared_in' => 'src/Core/FolderTreeService.php' ],
		'taxonomy/ensure_system_terms'           => [ 'type' => 'action', 'declared_in' => 'src/Core/Taxonomy.php' ],
		'settings/register'                       => [ 'type' => 'action', 'declared_in' => 'src/Modules/Settings/SettingsPage.php' ],
		'settings/save'                           => [ 'type' => 'action', 'declared_in' => 'src/Modules/Settings/SettingsPage.php' ],
		'settings/register_tab'                   => [ 'type' => 'action', 'declared_in' => 'src/Modules/Settings/SettingsPage.php' ],
		'settings/save_failed'                    => [ 'type' => 'action', 'declared_in' => 'src/Modules/Settings/SettingsSaveHandler.php' ],
		'settings/tab_option_conflict'             => [ 'type' => 'action', 'declared_in' => 'src/Modules/Settings/SettingsSaveHandler.php' ],
		'settings/option_tab_map'                 => [ 'type' => 'filter', 'declared_in' => 'src/Modules/Settings/SettingsPage.php' ],
		'dashboard/render_onboarding'             => [ 'type' => 'action', 'declared_in' => 'src/Modules/Dashboard/HomeDashboardPage.php' ],
		'onboarding/render_modal'                 => [ 'type' => 'action', 'declared_in' => 'src/Modules/Dashboard/HomeDashboardPage.php' ],
		'license/activate'                        => [ 'type' => 'action', 'declared_in' => 'src/Modules/Pro/ProLicenseActions.php' ],
		'license/deactivate'                      => [ 'type' => 'action', 'declared_in' => 'src/Modules/Pro/ProLicenseActions.php' ],
	];
}
