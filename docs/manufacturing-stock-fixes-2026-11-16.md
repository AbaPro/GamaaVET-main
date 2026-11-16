# Manufacturing stock corrections — 16 November 2026

Implemented locally following the client workflow review. No production deployment or historical balance correction was performed.

## Changes

- Sourcing consumes the required total across active, normal warehouses at the order's location and in the current channel. It no longer subtracts the full requirement from every matching warehouse. System warehouses, including waste, are excluded.
- Quantities are converted from sourcing units into the material's native stock unit. Incompatible units, fractional pieces, or quantities below the stock ledger's two-decimal precision are rejected.
- Each component withdrawal records its exact warehouse allocation in `inventory_stock_logs`, with source type `manufacturing_sourcing_component` and the sourcing component ID as `source_id`. The order ID is included in the movement notes.
- Receipt rejection and order cancellation reverse the recorded allocations using `manufacturing_return_component` entries. They do not add the full requirement to every warehouse. Recorded material waste must be cleared before returning that component.
- Stock changes, allocation logs, component deduction markers, and workflow state changes share a transaction. Order/component/stock rows are locked and duplicate submissions cannot post the same movement twice.
- Sourcing availability and audit warnings exclude system/inactive/out-of-channel warehouses, convert compatible units, and flag incompatible units. Audit shortage checks exclude already-consumed components.

## Existing orders

Old deductions have order-level logs rather than component allocations. Automatic rejection/cancellation returns for those deductions stop with an explicit reconciliation message. This prevents speculative stock additions. Historical balances and logs are preserved.

No new database migration is needed on the current schema. Deploy `stock.php` along with the changed manufacturing `lib.php`, `order.php`, and `edit.php`. Existing stock-log, material-waste, and deducted-at migrations must be present, as on the current local database.

## Validation

- `tests/manufacturing_stock.php`: rollback-only integration coverage for multiple warehouses, repeated/duplicate components, native-unit conversion, excluded warehouses, shortage rollback, exact returns, duplicate returns, and legacy deductions.
- `tests/client_comments_access.php`: HTTP checks for sourcing, repeated sourcing, failed receipt completion rollback, rejection, repeated rejection, resourcing, and cancellation; temporary fixtures are cleaned up. Existing access checks remain included.
- Existing sourcing, material-waste, and packaging/report regression suites; PHP syntax checks and `git diff --check`.

The fixed per-location warehouses, earlier finished-stock posting, customer delivery orders, and packaging-capacity dashboard remain separate workflow work.
