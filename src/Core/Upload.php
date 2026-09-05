<?php

declare(strict_types=1);

namespace Plathix\Core;

use Plathix\Loader;

class Upload
{
	// [internal]: FolderCountService-зависимость удалена — дельты счётчика ведёт
	// FolderCountLifecycle по событию set_object_terms, этому классу сервис не нужен.
	public function __construct(
		private readonly Loader $loader
	) {
		$this->loader->add_action( 'add_attachment', $this, 'assign_folder_on_upload' );
	}

	public function assign_folder_on_upload(int $attachment_id): void {
		if ( $attachment_id <= 0 ) {
			return;
		}

		// Целевая папка приходит ЯВНО из JS upload-патча (upload-events.js), залоченная в
		// момент старта загрузки, ЛИБО из query-параметра, который тот же сайдбар дописывает
		// в ссылку "Добавить медиафайл" на media-new.php ([internal], [internal]):
		// эта страница не имеет sidebar/XHR-патча (отдельный JS-контекст), но её loader
		// читает свой query string как контекст. Отсутствие обоих полей = загрузка из
		// системной папки/root → root остаётся корректным детерминированным дефолтом.
		//
		// Раньше здесь был fallback на Preferences::get_open_folder_id (последняя открытая
		// папка). Убран ([internal]): last-opened читается в момент ЗАВЕРШЕНИЯ загрузки
		// (add_attachment), а не старта — если пользователь ушёл в другую папку во время
		// загрузки из «Медиафайлы», файл уезжал туда. $_POST (grid/XHR-путь) имеет приоритет
		// над $_GET/$_REQUEST (media-new.php-путь), если оба почему-то присутствуют.
		//
		// Опция plathix_default_folder_mode (last_opened/none/fixed) убрана целиком
		// ([internal], [internal]): в Free все три значения
		// физически давали одно и то же поведение — read ниже, никакой развилки не было.
		// Последним звеном цепочки — папка по умолчанию из настроек ([internal],
		// plathix_default_folder_id). Она СТРОГО после явных полей: target лочится на
		// старте загрузки (см. #91 выше), и перебивать его статичной настройкой значило бы
		// вернуть ровно тот баг, из-за которого убран last_opened. При незаданной опции
		// (0 — дефолт register_setting) цепочка вырождается в прежнее поведение: root.
		//
		// Обёртка чтения в apply_filters-контракт ([internal]) рассмотрена и отклонена дважды:
		// #354 отклонил именно такой filter под будущий Folder Lock как преждевременное
		// обобщение (нет потребителя); тот же вывод подтверждён architecture-скептиком по
		// #381 — PRO (FolderUpload) не подписывается ни на что здесь, назначает папку своим
		// REST-путём (moveItemsBulk) после add_attachment. Публичный hook без потребителя —
		// мёртвый контракт, не архитектурное решение. Прямое чтение остаётся намеренным.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- WP core verifies the upload nonce in async-upload.php/media-new.php before firing add_attachment
		$folder_id = absint( wp_unslash( $_POST['plathix_folder'] ?? $_REQUEST['plathix_folder'] ?? get_option( 'plathix_default_folder_id', FolderId::ROOT ) ) );
		if ( $folder_id <= 0 ) {
			return;
		}

		if ( ! term_exists( $folder_id, PLATHIX_TAXONOMY ) ) {
			return;
		}

		// «Несортированные» (uncategorized) = файлы БЕЗ термина taxonomy. Счётчик и фильтры
		// grid/list/rest трактуют эту папку как NOT EXISTS (FolderQuery::build_tax_query_for_folder).
		// Присвоение файлу термина uncategorized-папки выкинуло бы его из этой выборки: счётчик
		// «Несортированные» не растёт, файл не виден в сетке без reload ([internal]). Весь продукт
		// (FolderAssignmentService::set_items/move_items_bulk через dnd, MediaDeleteService::bulk_restore,
		// unassign_items) для uncategorized-target СНИМАЕТ термины (wp_set_object_terms($id, [])), а
		// не присваивает — upload приводится к тому же инварианту. Audit тоже пропускаем: move в
		// «Несортированные» отдельного audit-следа не пишет, консистентно.
		$repository = new FolderRepository();
		if ( $repository->is_uncategorized_folder( $folder_id, PLATHIX_TAXONOMY ) ) {
			return;
		}

		// Служебная папка «Корзина» — не валидный target для назначения ([internal],
		// тот же инвариант, что #235/[internal] установил для move/
		// drag-drop путей): «Корзина» — чистый post_status-alias, term-связь с ней нигде
		// не читается (FolderQuery/FolderCountService/ListScreenFragmentsController
		// фильтруют по post_status, не taxonomy). Новый файл, загруженный при открытой
		// «Корзине», остаётся без term-назначения (root/Несортированные) — та же
		// graceful-деградация, что uncategorized-ветка выше, не отдельный код.
		if ( $folder_id === TrashFolder::id( PLATHIX_TAXONOMY ) ) {
			return;
		}

		// Папка в корзине — тоже невалидный target, но поймать её проверкой term_exists()
		// выше НЕЛЬЗЯ: удаление папки в Plathix мягкое, `FolderTrashService` лишь пишет мету
		// `_plathix_folder_trashed`, а термин остаётся жив («relation файл↔term НЕ рвётся»).
		// Без этой ветки файл, загруженный при действующей настройке на удалённую папку,
		// уехал бы в невидимую в дереве папку — хуже, чем деградация в root: его не найти
		// ни там, ни здесь, а retention-очистка через N дней порвала бы связь молча.
		// Источник списка — фильтр `plathix/folder/hidden_ids` (подписчик — Modules\Trash):
		// он же покрывает каскад «предок в корзине» и не привязывает Upload к модулю Trash.
		// Опция при этом НЕ зануляется: restore папки возвращает тот же term_id, и настройка
		// оживает сама ([internal]).
		if ( in_array( $folder_id, HiddenFolders::ids( PLATHIX_TAXONOMY ), true ) ) {
			return;
		}

		// [internal]: +1 счётчика здесь больше не шлётся — wp_set_object_terms()
		// синхронно стреляет хук set_object_terms, и дельту (с полным visibility-предикатом,
		// зеркальным SQL-истине) применяет единственный владелец FolderCountLifecycle.
		wp_set_object_terms( $attachment_id, [ $folder_id ], PLATHIX_TAXONOMY, false );

		do_action(
			'plathix/audit/record',
			'attachment_uploaded',
			[
				'objectType' => 'attachment',
				'objectId'   => $attachment_id,
				'targetType' => 'folder',
				'targetId'   => $folder_id,
				'summary'     => sprintf( 'Uploaded attachment %d', $attachment_id ),
				'context'     => [
					'file_name' => basename( (string) get_attached_file( $attachment_id ) ),
				],
			]
		);
	}
}
