<?php

declare(strict_types=1);

namespace Plathix\DevContracts;

/**
 * Каталог точек публичного контракта Core, от которых зависит PRO ([internal]).
 * Источник истины Free-стороны — симметрично `HookRegistry::SLOTS` для hook-слотов
 * ([internal]). Единственная существующая точечная защита сегодня —
 * `ForbiddenFreeInternalsRule` (PRO), allowlist ЦЕЛЫХ пространств имён; этот каталог
 * даёт конкретный, проверяемый список классов/интерфейсов/trait'ов, не только
 * неймспейсов.
 *
 * `PublicContractRegistryCoverageTest` (Free) сверяет каждую запись с реально
 * существующим классом/интерфейсом/trait'ом — ловит "класс переименован/удалён во
 * Free, каталог не обновлён".
 *
 * `behavior_test` ([internal]): реестр изначально проверял только СУЩЕСТВОВАНИЕ точки
 * контракта, не её ПОВЕДЕНИЕ — не поймал бы изменение семантики метода при неизменной
 * сигнатуре (см. #349: `Edition::is_pro()` перестал доверять статусу без ключа, реестр
 * промолчал, PRO узнал постфактум через 15 красных тестов, [internal]). Поле —
 * относительный путь к тест-файлу, доказывающему поведение этой точки, либо `'n/a'`,
 * если для точки такого теста ещё нет. `'n/a'` — не пустая строка: гейт
 * (`PublicContractRegistryCoverageTest::testBehaviorTestFileExists`) сравнивает строго,
 * опечатка вроде `'N/A'` уходит в проверку `assertFileExists` и красится сама.
 */
final class PublicContractRegistry
{
	/**
	 * @var array<string, array{declared_in: string, behavior_test: string}>
	 */
	public const CLASSES = [
		'Contracts\\ModuleInterface' => [ 'declared_in' => 'src/Contracts/ModuleInterface.php', 'behavior_test' => 'n/a' ],

		'User\\AccessLevel' => [ 'declared_in' => 'src/User/AccessLevel.php', 'behavior_test' => 'n/a' ],
		'User\\AccessResolver' => [ 'declared_in' => 'src/User/AccessResolver.php', 'behavior_test' => 'n/a' ],

		'Core\\Taxonomy' => [ 'declared_in' => 'src/Core/Taxonomy.php', 'behavior_test' => 'n/a' ],
		'Core\\TaxonomyResolver' => [ 'declared_in' => 'src/Core/TaxonomyResolver.php', 'behavior_test' => 'n/a' ],
		'Core\\FolderColumnContract' => [ 'declared_in' => 'src/Core/FolderColumnContract.php', 'behavior_test' => 'n/a' ],
		'Core\\FolderRepository' => [ 'declared_in' => 'src/Core/FolderRepository.php', 'behavior_test' => 'n/a' ],
		'Core\\AdminLayout' => [ 'declared_in' => 'src/Core/AdminLayout.php', 'behavior_test' => 'n/a' ],
		'Core\\TrashFolder' => [ 'declared_in' => 'src/Core/TrashFolder.php', 'behavior_test' => 'n/a' ],
		'Core\\AttachmentVisibility' => [ 'declared_in' => 'src/Core/AttachmentVisibility.php', 'behavior_test' => 'n/a' ],
		'Core\\GalleryItemDTO' => [ 'declared_in' => 'src/Core/GalleryItemDTO.php', 'behavior_test' => 'n/a' ],
		// BOUNDDTO-002 ([internal]): PRO ImportCommand::start() читает свойства этого DTO
		// напрямую — точка контракта Core↔PRO, не внутренний тип Free.
		'Core\\ImportJobDTO' => [ 'declared_in' => 'src/Core/ImportJobDTO.php', 'behavior_test' => 'tests/ImportManagerStartImportTest.php' ],
		'Core\\FolderCountService' => [ 'declared_in' => 'src/Core/FolderCountService.php', 'behavior_test' => 'n/a' ],

		// CEC-301 ([internal]): точки, на которые опирается PRO-модуль
		// ContentTypes. Раньше разрешение выписывал себе сам потребитель — поимённый
		// allowlist в двух PhpstanRules PRO; направление объявления инвертировано обратно:
		// объявляет владелец (Free), гейт PRO сверяется с этим реестром.
		'Modules\\ListScreen\\ListScreenFragmentsController' => [ 'declared_in' => 'src/Modules/ListScreen/ListScreenFragmentsController.php', 'behavior_test' => 'tests/ListScreenFragmentsControllerTest.php' ],
		'Modules\\ListScreen\\FolderColumn' => [ 'declared_in' => 'src/Modules/ListScreen/FolderColumn.php', 'behavior_test' => 'tests/FolderColumnTest.php' ],
		'Modules\\ListScreen\\ListScreenQueryContext' => [ 'declared_in' => 'src/Modules/ListScreen/ListScreenQueryContext.php', 'behavior_test' => 'n/a' ],
		'Admin\\Assets' => [ 'declared_in' => 'src/Admin/Assets.php', 'behavior_test' => 'n/a' ],
		'Http\\Authorization' => [ 'declared_in' => 'src/Http/Authorization.php', 'behavior_test' => 'tests/AuthorizationTest.php' ],
		'Http\\RestRouteRegistry' => [ 'declared_in' => 'src/Http/RestRouteRegistry.php', 'behavior_test' => 'tests/RestRouteRegistryTest.php' ],
		'Http\\RestRoutePermissions' => [ 'declared_in' => 'src/Http/RestRoutePermissions.php', 'behavior_test' => 'n/a' ],
		'Http\\RestControllerHelpers' => [ 'declared_in' => 'src/Http/RestControllerHelpers.php', 'behavior_test' => 'n/a' ],
		'Http\\RestAuditPayloadBuilders' => [ 'declared_in' => 'src/Http/RestAuditPayloadBuilders.php', 'behavior_test' => 'n/a' ],
		'Http\\RestFolderResponseBuilders' => [ 'declared_in' => 'src/Http/RestFolderResponseBuilders.php', 'behavior_test' => 'n/a' ],
		'Http\\RestController' => [ 'declared_in' => 'src/Http/RestController.php', 'behavior_test' => 'n/a' ],
		'Http\\Rest' => [ 'declared_in' => 'src/Http/Rest.php', 'behavior_test' => 'n/a' ],
		'Http\\Nonce' => [ 'declared_in' => 'src/Http/Nonce.php', 'behavior_test' => 'n/a' ],

		'Infrastructure\\JobDispatcher' => [ 'declared_in' => 'src/Infrastructure/JobDispatcher.php', 'behavior_test' => 'n/a' ],
		'Infrastructure\\Cache' => [ 'declared_in' => 'src/Infrastructure/Cache.php', 'behavior_test' => 'n/a' ],
		'Infrastructure\\Features' => [ 'declared_in' => 'src/Infrastructure/Features.php', 'behavior_test' => 'n/a' ],
		'Infrastructure\\Keys' => [ 'declared_in' => 'src/Infrastructure/Keys.php', 'behavior_test' => 'n/a' ],
		'Infrastructure\\IdentityKeyResolver' => [ 'declared_in' => 'src/Infrastructure/IdentityKeyResolver.php', 'behavior_test' => 'n/a' ],
		'Infrastructure\\RateLimiter' => [ 'declared_in' => 'src/Infrastructure/RateLimiter.php', 'behavior_test' => 'n/a' ],
		'Infrastructure\\Logger' => [ 'declared_in' => 'src/Infrastructure/Logger.php', 'behavior_test' => 'n/a' ],

		'Loader' => [ 'declared_in' => 'src/Loader.php', 'behavior_test' => 'n/a' ],
		// [internal]/#357/#360: Edition::is_pro() — единственная точка контракта с
		// подтверждённым инцидентом рассинхронизации (PRO узнал о смене семантики
		// постфактум через 15 красных тестов). tests/EditionTest.php уже покрывает
		// поведение построчно, включая точно #349-кейс; поле здесь делает эту защиту
		// частью каталога, а не только фактом, известным по памяти.
		'Edition' => [ 'declared_in' => 'src/Edition.php', 'behavior_test' => 'tests/EditionTest.php' ],

		'Modules\\Preset\\PresetOnboarding' => [ 'declared_in' => 'src/Modules/Preset/PresetOnboarding.php', 'behavior_test' => 'n/a' ],

		'PublicApi\\PlathixAPI' => [ 'declared_in' => 'src/PublicApi/PlathixAPI.php', 'behavior_test' => 'n/a' ],
		'PublicApi\\SettingsApi' => [ 'declared_in' => 'src/PublicApi/SettingsApi.php', 'behavior_test' => 'n/a' ],
		'PublicApi\\ToolsApi' => [ 'declared_in' => 'src/PublicApi/ToolsApi.php', 'behavior_test' => 'n/a' ],
		'PublicApi\\SystemInfoApi' => [ 'declared_in' => 'src/PublicApi/SystemInfoApi.php', 'behavior_test' => 'n/a' ],
	];
}
