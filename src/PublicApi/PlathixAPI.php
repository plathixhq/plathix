<?php

declare(strict_types=1);

namespace Plathix\PublicApi;

final class PlathixAPI
{
	private static ?FoldersApi $folders = null;
	private static ?AssignmentsApi $assignments = null;
	private static ?ImportExportApi $import_export = null;
	private static ?FolderMaintenanceApi $folder_maintenance = null;
	private static ?MediaApi $media = null;
	private static ?PresetsApi $presets = null;

	public static function folders(): FoldersApi
	{
		if ( ! self::$folders instanceof FoldersApi ) {
			self::$folders = new FoldersApi();
		}

		return self::$folders;
	}

	public static function assignments(): AssignmentsApi
	{
		if ( ! self::$assignments instanceof AssignmentsApi ) {
			self::$assignments = new AssignmentsApi();
		}

		return self::$assignments;
	}

	public static function importExport(): ImportExportApi
	{
		if ( ! self::$import_export instanceof ImportExportApi ) {
			self::$import_export = new ImportExportApi();
		}

		return self::$import_export;
	}

	public static function folderMaintenance(): FolderMaintenanceApi
	{
		if ( ! self::$folder_maintenance instanceof FolderMaintenanceApi ) {
			self::$folder_maintenance = new FolderMaintenanceApi();
		}

		return self::$folder_maintenance;
	}

	public static function media(): MediaApi
	{
		if ( ! self::$media instanceof MediaApi ) {
			self::$media = new MediaApi();
		}

		return self::$media;
	}

	public static function presets(): PresetsApi
	{
		if ( ! self::$presets instanceof PresetsApi ) {
			self::$presets = new PresetsApi();
		}

		return self::$presets;
	}
}
