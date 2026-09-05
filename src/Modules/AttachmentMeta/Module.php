<?php

declare(strict_types=1);

namespace Plathix\Modules\AttachmentMeta;

use Plathix\Contracts\ModuleInterface;

/**
 * AttachmentMeta module — интеграция папок Plathix в экран редактирования одного файла/записи WP.
 * Три класса:
 * - AttachmentSideMetaBox — метабокс «Папка и замена файла» в правой колонке страницы вложения;
 * - AttachmentDetails — поле «Папка» (split-control: переход + смена) в попапе «Детали вложения»;
 * - FolderSwitchUi — энкью скрипта/стилей split-control (popover-дерево, смена папки на месте).
 *
 * FolderMetaBox (метабокс на post.php + quick-edit) перенесён в PRO ([internal]):
 * во Free типы = только attachment, метабокс для записей/страниц — PRO-территория.
 *
 * Регистрируется из plathix.php под `plathix/modules/register`.
 * Двухфазный bootstrap: register() подписывается на `plathix/modules/boot`;
 * runtime-хуки навешиваются в boot() под is_admin().
 *
 * AttachmentReplaceUi (чужой модуль Replace) создаётся внутри render AttachmentSideMetaBox —
 * 1 место, DI-триггер стандарта (3-5 мест) не достигнут → tolerated.
 *
 * Файлы классов пока в namespace Plathix\Admin (legacy) — перенос отложен в [internal].
 */
class Module implements ModuleInterface
{
	/** Фаза 1: только подписка на фазу 2. */
	public function register(): void
	{
		add_action( 'plathix/modules/boot', [ $this, 'boot' ] );
	}

	public function boot(): void
	{
		if ( is_admin() ) {
			new AttachmentSideMetaBox();
			( new AttachmentDetails() )->register();
			( new FolderSwitchUi() )->register();
		}
	}
}
