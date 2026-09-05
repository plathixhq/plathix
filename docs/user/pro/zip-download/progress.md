# ZIP Download Progress and Status

> **This feature requires Plathix PRO.**

## Status Values

| Status | What It Means |
|---|---|
| **pending** | The job has been queued and is waiting for Action Scheduler to pick it up. |
| **ready** | The archive is built and the download link is available. |
| **failed** | The job failed. See [Troubleshooting](troubleshooting.md) for common causes. |
| **not_found** | No job was found for this request. The job may have expired or never been created. |

## How the UI Polls for Status

After the initial request, the sidebar polls the REST API (`GET /plathix/v1/zip/status/{job_id}`) at a short interval until the status changes from `pending` to `ready` or `failed`.

When status becomes `ready`, the response contains a temporary `download_url`. The UI either navigates there automatically or shows a button to start the download.

## Notes

- Polling stops as soon as a terminal status (`ready` or `failed`) is received.
- If you close the browser tab before the job finishes, the job continues running in the background. Reopen the folder and click **Download as ZIP** again — if the job already completed, the status response will include the download link.

## Related

- [How to download](how-to-download.md)
- [Troubleshooting](troubleshooting.md)
