# TALA Deployment and Recovery Runbook

## Purpose and boundary

This runbook defines the provider-neutral operating sequence needed to create, verify, and restore an independent encrypted TALA recovery generation. It does not select a provider, configure production automation, hold credentials, execute a backup or restore, certify compliance, or prove that the prospective recovery targets are achieved.

TALA records only validated safe evidence. The external job or isolated restore process performs the privileged infrastructure work. System Health never exposes credentials, paths, personal data, provider payloads, recovery keys, or raw diagnostic output.

## Production prerequisites

Before activation, the institution must approve and own:

- the primary host and independent off-host repository;
- billing and provider terms;
- privacy/DPO and processor or transfer decisions;
- scoped machine credentials and controlled recovery-key copies;
- the named Infrastructure Custodian and escalation path;
- cadence, retention, retry, overdue, alert, and isolated-restore schedules.

Cloudflare R2/restic and the client-owned ORICO enclosure remain evaluated candidates. Neither is selected or operating merely because this runbook exists.

## External backup job

Use one scheduled runner with a non-overlap lock. A second run must stop while the first lock is current; it must never create a competing generation.

1. Record the application revision and migration state.
2. Produce a transaction-consistent database export.
3. Capture required private documents and reproducible official-output source files from the same bounded generation window.
4. Exclude sessions, caches, temporary exports, replaceable builds, Git-held source, and recovery secrets.
5. Build a manifest containing opaque generation reference, timestamps, application revision, migration result, component inventory, and integrity digests.
6. Encrypt client-side with institution-controlled recovery material.
7. Transfer through repository-scoped credentials to the approved independent destination.
8. Verify the manifest, database export, private-file set, and off-host generation.
9. Retry and alert according to the approved operating schedule. Do not replace the preceding successful evidence when a run fails.
10. Submit one safe versioned evidence object through:

```powershell
php artisan tala:operations:record-backup-evidence --input=-
```

The command may instead read one local JSON file no larger than 64 KiB. The local file is an operator handoff and must be removed according to the approved operating procedure after ingestion.

## Backup evidence schema version 1

Required fields are:

- `schema_version` with value `1`;
- opaque `external_reference`, `generation_reference`, `application_revision`, and `operator_reference` values;
- `outcome` as `SUCCEEDED` or `FAILED`;
- RFC 3339 `started_at` and `completed_at`;
- `migration_result` as `MATCHED`, `MISMATCHED`, or `NOT_CHECKED`;
- `integrity_result` as `PASSED`, `FAILED`, or `NOT_CHECKED`;
- `database_export_result`, `private_files_result`, `manifest_result`, and `off_host_result` as `PASSED`, `FAILED`, or `NOT_CHECKED`;
- optional `supersedes_external_reference` for an append-only correction.

A successful outcome requires matched migrations and every required integrity/component check to pass. Unknown fields, paths, URLs, credentials, secrets, and personal-data payloads are rejected. Identical evidence is idempotent; conflicting reuse of a reference is rejected; corrections append and reference the superseded entry.

## Isolated restore verification

Never restore over the live system while proving recovery.

1. Declare the drill or incident and identify the accountable operator.
2. Provision or validate clean isolated infrastructure.
3. Select the newest valid independent generation. If it is unavailable, use the next approved verified source.
4. Verify the manifest and client-side decryption before data admission.
5. Restore the database and required private files.
6. Apply or verify the recorded application revision and migrations.
7. Verify authentication and the critical Applicant, admissions, scheduling, enrollment, academics, finance, TOR, and administration journeys relevant to the restored state.
8. Invalidate restored sessions and clear rebuildable caches.
9. Reconcile queued and integration work idempotently; external providers may remain explicitly degraded.
10. Reapply any authorized lawful disposition recorded after the selected generation.
11. Measure elapsed restoration duration and observed data loss. Record remediation for every failed or degraded check.
12. Submit one safe evidence object through:

```powershell
php artisan tala:operations:record-restore-evidence --input=-
```

Restore schema version 1 uses the common fields above plus `measured_duration_minutes`, `observed_data_loss_minutes`, `manifest_result`, `database_restore_result`, `private_files_restore_result`, `authentication_result`, `critical_journeys_result`, `session_cache_result`, `queue_integration_result`, and `lawful_disposition_result`.

A successful restore claim cannot contain a failed reconciliation check. `DEGRADED` or `NOT_CHECKED` reconciliation is retained as evidence requiring attention, while `NOT_APPLICABLE` is valid only where the check genuinely does not apply; none of these classifications certify production recovery.

## Evidence interpretation

System Health may show `Available`, `Attention`, `Unavailable`, or `Unknown` only.

- Accepted current local evidence may be `Available`.
- Failed, degraded, stale, backlogged, or configured-overdue evidence is `Attention`.
- An invalid required local configuration or a local check that cannot run is `Unavailable`.
- Missing evidence and facts owned by provider dashboards, primary-host backups, an independent provider, or physical custody remain `Unknown — Not checked by TALA`.

The prospective six-hour RPO and eight-hour RTO are planning targets. Configuration, a retained generation, or one successful drill does not establish achievement across time.

## Deployment, rollback, and activation

Application deployment, provider configuration, backup execution, isolated restore, and production acceptance are separate authorizations.

For an application deployment, retain the last accepted application revision and database rollback decision before migration. If the evidence-ingestion or display revision is defective, stop new ingestion, preserve every existing operational event, restore the preceding application revision, and apply only an evidence-safe database rollback approved for that deployment. Never delete evidence to make a rollback appear successful.

Provider or dashboard work may be performed technically through an authorized CLI, API, or dashboard session. Institutional consent, billing, legal/privacy decisions, custody, and account ownership remain human decisions. No production activation is complete until ownership, monitoring, current generations, alert delivery, and measured isolated-restore evidence are approved.
