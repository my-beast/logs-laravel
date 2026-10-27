# Payload contract

Every payload a host and this service exchange has a JSON Schema here, by version (`v1/`). The service is the
source; the SDK (mybeast/logs-laravel) keeps a copy under its own `schemas/` and a test fails when the copy differs
from this one (when the service sits beside it). Change a schema only together with the service and the SDK.

| File | What |
|---|---|
| `v1/log-batch.schema.json` | A batch of a host server's log lines (`POST /v1/logs`) |
| `v1/fixtures/log-batch.json` | One batch, fixed: the SDK's lines must fit the schema it does, the service must take it |

## Sending lines

`POST /v1/logs` with `Authorization: Bearer sk_….…` (the project's server secret), `Content-Type: application/json`,
and `Content-Encoding: gzip` when the body is gzip (the SDK always gzips).

| Answer | Means | The SDK |
|---|---|---|
| 202 `{"accepted": n, "duplicates": n, "refused": [{"id", "reason"}]}` | Stored; `duplicates` had been stored before (a batch sent again); `refused` lines never fit (too old, a level that is not one; `monthly-limit`: past the project's lines this month, its package's, until the 1st UTC) and are not sent again | removes the batch from its spool, warns about refused lines - about `monthly-limit` once a month |
| 401 | No secret, or one that does not hold (revoked, wrong) | keeps the lines, warns once, tries again |
| 413 | Too many lines or bytes at once | sends the same lines in smaller batches |
| 422 | The body is not a batch (not gzip, not JSON) | moves the batch aside (`failed/` in its spool), so it never blocks the rest |
| 429, 5xx, no answer | Too many at once, or the service is away | keeps the lines and tries again on its next round |

Lines are kept per project and environment, 14 days unless the project or the environment says otherwise.
