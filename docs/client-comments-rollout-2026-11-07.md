# Client comments implementation and rollout

Implemented against the 7 November client review. The application changes are local; production has not been deployed or modified.

## Deploy

1. Ensure the existing migrations are current, including `20261007_factory_login_permission.sql` (the local validation database was missing it).
2. Apply `migrations/20261107_manufacturing_access.sql` and `migrations/20261107_manufacturing_material_waste.sql` before deploying the PHP changes. Both are idempotent and have been applied twice locally.
3. Deploy the changed PHP files **and the root `.htaccess`**. Apache must allow rewrite rules, as the repository Docker configuration does. For a different web server, configure equivalent denials for `/assets/uploads/manufacturing/formula_samples/` and manufacturing PDF/XLS/XLSX/XLSM files before making the application available. PHP serves authorized samples and regenerates reports for the current viewer.
4. Grant the new permissions to the intended staff through Users → Roles → Permissions. Existing non-admin roles receive none automatically. Current administrator behavior is preserved.

## Permission assignments

| Permission | Grants |
| --- | --- |
| `manufacturing.orders.complete` | Finalize a manufacturing order and post finished stock. Delivery-step access alone cannot do this. |
| `manufacturing.orders.technical_details.view` | Technical snapshot, batch size, production notes and bottle specifications. Also required for quality/packaging details and full reports. |
| `manufacturing.audit.view` | Full manufacturing audit, additionally requiring technical access and an unlocked formula permission. No warnings/counts are returned otherwise. |
| `manufacturing.bottle_sizes.view` / `.manage` | Read catalog / create, edit, and request deletion. Management also requires catalog view. Existing deletion-request permissions still apply. |
| `manufacturing.packaging_options.view` / `.manage` | Equivalent controls for packaging options. |
| `manufacturing.waste.manage` | Record explicit material waste on an open order; also requires technical access and unlocked formula access. |

Keep driver roles limited to the required workflow permissions, such as `manufacturing.view_step_delivering`, plus manufacturing and Factory access. They can finish their delivery step while the order awaits authorized finalization. Do not grant drivers completion, formula, technical, audit, or setup permissions unless explicitly intended.

Sourcing, receipt, and preparation contain recipe quantities: these require their existing step permission, technical access, and unlocked `manufacturing.formula.view_all`. Quality/packaging require their step permission and technical access. Dispatch/delivery retain their step permissions. Authorized order creators receive minimal formula ID/name lookups without recipe payloads unless they also have unlocked formula access.

## Material waste

The order page now has an explicit **Material waste / هالك الخامات** form for consumed ingredient and packaging rows. Enter each actual wasted material quantity in its sourcing unit. Completion converts it to the product's stock unit and posts it once, with a linked stock log. Already-consumed material is not deducted again. Whole pieces and the stock ledger's existing two-decimal precision are enforced; unsupported units or quantities are rejected.

Final-product `breakage_count` remains a production metric. It no longer adds the final-product SKU to the waste warehouse. Named packaging-category fields are not automatically mapped to SKUs; record the corresponding actual material in the waste form. Manual additions/uploads/transfers also reject final products in the fixed waste warehouse.

Historical final-product waste balances are preserved. Open **Legacy waste reconciliation** from the waste warehouse (audit access and unlocked formula required) to review linked order movements and export the report. Corrections require evidence of actual material losses; the application does not invent a conversion or silently reverse balances.

## Validation

- PHP syntax checks across manufacturing, inventory, includes, and AJAX files.
- Rollback-only tests for material waste, sourcing, packaging reconciliation, and report content.
- HTTP tests using a temporary non-admin account: driver completion attempts, last-step saving, setup URLs, formula AJAX and samples, static file denial, formula lock, authorized completion, existing-session revocation, location filtering, read-only deletion attempts, and CSRF checks.
- Chromium checks: Edit/Remove from icons after pagination, quantity persistence, cancel/confirm removal, warehouse Edit, location filter, CSV download, manufacturing list without an audit column, and JavaScript errors.

Run local HTTP tests with `docker compose exec -T -e GAMMAVET_MANUFACTURING_TEST=1 app php tests/client_comments_access.php`. For browser checks, use `--setup`, copy its `/tmp/gammavet-client-comments-fixture.json` to the host, run `tests/client_comments_ui.cjs` with Playwright available, then run the PHP test with `--cleanup`. Test accounts and records are temporary; the ordinary run cleans up automatically.
