# Export the Audit Log

> **This feature requires Plathix PRO.**

## What You Can Export

You can export the entire log or a filtered subset as **JSON** or **CSV**.

## How To Export

1. Go to **Plathix → Audit Log**.
2. Optionally apply filters to narrow the export to a specific date range, action, or user.
3. Click **Export JSON** or **Export CSV**.
4. Your browser downloads the file.

## Export Formats

### CSV

Each row is one log entry. Columns:

```
id, created_at, user_id, action, object_type, object_id, target_type, target_id, items_count, summary
```

CSV exports can be opened directly in Excel, LibreOffice Calc, or any spreadsheet tool.

### JSON

An array of objects, one object per log entry with the same fields as CSV.

## Notes

- The export respects any active filters. To export the full log, clear all filters before exporting.
- Large exports (tens of thousands of entries) may take a moment to generate. The request goes directly to the REST API — no background job is needed.
- Exports are scoped to the current site on multisite installations.

## Related

- [Filter and search](filter-search.md)
- [Log entries](log-entries.md)
