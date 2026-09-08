# Audit Log Entries

> **This feature requires Plathix PRO.**

## What Gets Recorded

The following actions are recorded automatically:

| Action | Trigger |
|---|---|
| `folder_created` | A new folder is created. |
| `folder_renamed` | A folder is renamed. |
| `folder_deleted` | A folder is deleted (including recursive delete). |
| `items_moved_bulk` | One or more files are moved to a different folder. |
| `file_uploaded` | A file is uploaded and assigned to a folder. |
| `file_replaced` | A file is replaced via Replace Media. |
| `file_deleted` | An attachment is deleted. |
| `preset_applied` | A preset is applied to the folder structure. |
| `preset_reset_started` | A folder structure reset is started. |
| `preset_reset_completed` | A folder structure reset completes. |
| `preset_uploaded` | A custom preset ZIP is uploaded. |
| `preset_deleted` | A custom preset is deleted. |
| `preset_exported` | The folder structure is exported as a preset ZIP. |

## What Each Entry Contains

| Field | Description |
|---|---|
| **Date** | UTC timestamp when the action occurred. |
| **User** | The WordPress user who performed the action. |
| **Action** | Slug identifying the action type (see table above). |
| **Object** | The primary object affected (usually a folder or file). |
| **Target** | The secondary object (e.g. the destination folder for a move). |
| **Items count** | Number of items affected (for bulk operations). |
| **Summary** | Human-readable description of what happened. |

## Notes

- Actions are fired by the Free core via the `plathix/audit/record` hook. Without PRO active, the hook fires but nothing is stored.
- Audit entries are stored in a dedicated `wp_plathix_audit_log` database table.
- The table is created when Plathix PRO is activated and is not removed on plugin deactivation.

## Related

- [Filter and search](filter-search.md)
- [Export](export.md)
- [Retention](retention.md)
