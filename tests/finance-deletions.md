Finance deletion verification

`deletion_workflows.php` creates a minimal schema and verifies the original finance and manufacturing approvals. `finance_record_deletions.php` extends that schema and verifies wallet, payment, invoice, transfer, settlement, closure and reopening behavior. Both scripts refuse to modify the application database.

The workflow suite also runs `deletion_duplicates.php`: it verifies cleanup of historical duplicates, migration reruns, concurrent submissions from separate PHP processes, normal/force request collisions, resubmission after rejection, and account isolation.

Apply `20261024_unique_pending_deletion_requests.sql` to existing databases before deploying the duplicate request guard. It retains the oldest pending request per account and record and marks additional requests rejected. Pending requests created before an existing approval are also rejected; later requests (for example after reopening an account) are retained. Reviewed request rows remain as history. Automatic cleanup sets the review time and leaves the reviewer empty. The generated column and unique index require MariaDB, matching the project's MariaDB 10.4 environment.

Approved cash/bank order-payment deletions reverse the original credit even if the destination balance is negative, zero, or below the payment amount. The suite covers these balances, transfer audit reversal, and unchanged records when permissions, newer settlements, or inconsistent order totals block approval. Other reversal types retain their existing balance checks.

Use a disposable MariaDB 10.4 container with database `gammavet_deletion_tests`. Mount this repository at `/work` and mount `tests/fixtures/deletion_local.php` over `/work/config/local.php` inside the PHP test container. The PHP container needs mysqli and PDO MySQL and must share the disposable database container's network namespace, so the fixture can connect to `127.0.0.1`.

Run against a fresh disposable database:

```sh
GAMMAVET_DELETION_TESTS=1 php tests/deletion_workflows.php
GAMMAVET_DELETION_TESTS=1 php tests/finance_record_deletions.php
```

Never mount the fixture over a real application's configuration outside the test container. The fixture uses only disposable database credentials.

`finance_pages_smoke.php` makes authenticated GET requests against the local Apache app, checks action visibility and table column counts, and destroys its temporary session. It does not change financial records. Run it as the same Unix user as Apache, so the server can read the session:

```sh
docker compose exec -T --user www-data -e GAMMAVET_FINANCE_SMOKE=1 app php tests/finance_pages_smoke.php
```

For existing databases, apply the finance deletion approval and request-permission migrations before `20261013_finance_record_deletion.sql`. Expense history also requires the existing `20260930_expense_payment_attachments.sql` migration. Other roles receive the new action permissions only when an admin assigns them through Role Permissions; the request and approval permissions remain separate.
