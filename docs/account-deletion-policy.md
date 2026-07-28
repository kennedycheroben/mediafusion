# MediaFusion Account Deletion Policy

## Dependency Map

`users.id`
-> `oauth_tokens.user_id` (delete; sensitive OAuth credentials)
-> `uploads.user_id` (delete; publishing queue/history and media path metadata)
-> `studio_media.user_id` (delete; media library metadata and storage keys)
-> `studio_projects.user_id` (delete; project/timeline JSON)
-> `incomplete_uploads.user_id` (delete; resumable upload state and temp chunks)
-> `contact_inquiries.user_id` (retain support record, unlink/anonymize user identity)
-> `rate_limits.identifier` containing authenticated user key (delete)
-> local/R2 objects discovered from DB-owned rows and user-specific brand/profile/job files
-> active/queued JSON jobs in `/tmp/*_meta.json` and `uploads/tracking_jobs/*.json` (mark cancelled, then clean owned files)

## Storage Cleanup Strategy

The browser never supplies file paths or object keys for deletion. `AccountDeletionService` derives a snapshot from database rows owned by the authenticated session user, profile columns, user-specific brand kit files, and job metadata files that contain the same `user_id`.

Local files are deleted only after canonicalization and only when the resolved path remains inside the configured `uploads` root. Symlinks, path traversal, files outside `uploads`, and paths shared by another upload record are skipped or treated as failures. Object keys are deleted only when they are canonical MediaFusion keys under `users/{user_id}/...` or legacy DB-owned upload keys under `videos/...`.

## Job Handling

Before deleting database rows, the service marks pending/uploading/processing upload records as failed with a cancellation reason, marks export metadata JSON for the user as cancelled, and marks tracking job JSON for the user as cancelled. Python workers now select uploads only when the owning `users` row still exists, preventing orphan jobs from processing after deletion.

## Retention

OAuth tokens, user media metadata, user uploads, studio media, studio projects, incomplete uploads, password reset rows for the user's email, user-specific rate limit rows, local user-owned files, and object-storage keys are removed.

Contact inquiries are not deleted automatically because they may represent support history. They are unlinked from the account and their name/email fields are replaced with deletion placeholders.

## Failure Behavior

Storage deletion is attempted before database deletion so the database can still be used as the source of truth if object cleanup fails. If any confirmed storage object still exists after a failed delete attempt, the endpoint returns an error and does not claim account deletion completed. Database deletion runs in a transaction and deletes the `users` row last.

The implemented workflow is synchronous because the audited schema has a small number of direct user-owned tables and no persistent deletion-job table. If accounts grow to large media libraries, replace the endpoint call with an asynchronous workflow that records deletion snapshots and retry state.
