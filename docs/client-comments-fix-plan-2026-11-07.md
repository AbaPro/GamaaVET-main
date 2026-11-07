# Client comments: code review and fix plan

Reviewed: 7 November 2026. Scope: all comments and screenshots supplied in chat.
This is a plan only; no application code or database records were changed.
Existing uncommitted changes in `modules/manufacturing/lib.php` concern report logo sizing and must be preserved.

## 1. Urgent: separate delivery work from order completion

**Confirmed:** `modules/manufacturing/order.php:322` authorizes the explicit `complete_order` action using `manufacturing.view_step_delivering`. The banner uses that same permission. Separately, saving the last open step can complete the order and post finished goods (`order.php:393`, `order.php:1046`). That path must also be covered.

Plan:
- Add `manufacturing.orders.complete`. Delivery-step access must not grant it implicitly.
- Enforce it on every transition to completed and on the stock-posting operation, including crafted POST requests and the last-step save path.
- Let staff finish their permitted workflow step without closing the manufacturing order. Without completion permission, all steps may be complete while the order awaits an authorized person to finalize it and choose its receiving warehouse.
- Hide the completion banner, warehouse selector, and completion controls from unauthorized users. Preserve existing step access for delivery work.
- Add a manufacturing page access check before queries or initialization writes in `order.php`; it currently proceeds from authentication and customer scope without an explicit `manufacturing.view` check.
- Keep completion, stock posting, and linked sales status changes consistent in one transaction; lock the order and recheck permissions/state before mutations. Preserve duplicate-post protection.

Acceptance: a driver can record permitted delivery work but cannot close an order or post stock, whether using the interface, the explicit completion endpoint, or a last-step status submission. An authorized person can finalize once; repeat/concurrent submissions cannot duplicate stock.

## 2. Urgent: close formula disclosure paths

**Confirmed:** the formula component card checks `manufacturing.formula.view_all` and the password unlock state. The handoff card independently renders the formula description and sample images (`order.php:1413`). The snapshot independently renders batch size, bottle count, bottle specifications, and notes (`order.php:1278`). The order header also prints the formula name.

**Additional disclosure found:** `ajax/get_manufacturing_formulas.php` accepts general manufacturing-view or order-create permission and returns components JSON, batch information, instructions, and sample image paths. Hiding the card alone would leave this route open.

Plan:
- Centralize checks for full formula access, manufacturing technical details, and authorized step data so pages, AJAX, and exports agree.
- Keep formula description, instructions, samples, full component lists, and formula-identifying metadata under the existing formula permission and password requirement.
- Add `manufacturing.orders.technical_details.view` for batch size and technical snapshot fields. Default driver display: product identity, location, due date, overall progress, and authorized delivery information. Keep technical bottle specifications and production notes behind the technical permission; display shipment quantities through the delivery workflow as needed.
- Omit restricted data from HTML, data attributes, scripts, and JSON rather than hiding it with CSS or replacing only its label.
- Split minimal order-form lookup data from full formula data. An authorized order creator may need an ID/name selector without receiving the recipe. Require full formula access and unlock for detailed payloads.
- Review create/edit forms and manufacturing lists for the same fields and lookup dependencies.
- Protect sample files through an authenticated, record-scoped media handler and prevent bypass through their old static URLs. Samples currently live under `assets/uploads/manufacturing/formula_samples`.

Acceptance: a driver or other formula-restricted account cannot retrieve samples, recipes, production quantities, or formula descriptions via pages, HTML source, AJAX, or copied file URLs. A formula-authorized account still respects the password lock.

## 3. Urgent: permission-based manufacturing audit

**Confirmed:** `manufacturing_detect_order_audit_notes()` in `modules/manufacturing/lib.php:1246` masks names using only `manufacturing.component.name.view`. It still returns required and available quantities, units, and material counts. `order.php:1473` renders the audit without an audit permission. Full reports also call this helper.

Recommended first implementation: add `manufacturing.audit.view`, with no automatic grant to ordinary manufacturing users. Hide the entire audit card, warning count, and payload without it. Material/formula-sensitive warnings must additionally respect formula/technical permissions and the password lock; an audit grant alone must not expose a recipe.

If scoped audit visibility is required, tag each warning with its workflow step, source type, and sensitivity, then filter before computing counts or rendering. A driver's delivery permission must never expose sourcing, receipt, quality, or packaging warnings. Define “related data” by explicit step permission and existing customer/channel scope; the reviewed code does not establish a general per-user material ownership policy.

Acceptance: unauthorized users see no hidden-material rows, quantities, units, warning counts, or equivalent report content. Permitted audit viewers see only warnings they are authorized to inspect.

## 4. Hide and protect manufacturing setup sections

**Confirmed:** `includes/header.php:137–140` unconditionally shows Formulas, Bottle Sizes, and Packaging Options inside Manufacturing. Formulas already has a page-level formula permission check, but bottle-size and packaging-option list/edit pages use only `manufacturing.view`.

Plan:
- Use the existing `manufacturing.formula.view_all` for Formulas and Formula Templates navigation and enforce the existing unlock requirement for their sensitive content.
- Add separate `manufacturing.bottle_sizes.view` / `.manage` and `manufacturing.packaging_options.view` / `.manage` permissions. Reading setup data must not automatically allow editing it.
- Apply checks to navigation, list/create/edit pages, AJAX lookups, and mutation handlers. Retain the current deletion-request/approval workflow and require the appropriate resource permission as well.
- Review `modules/manufacturing/deletion_approval.php`: its extra formula/unlock check currently applies to templates; extend resource-specific access checks to formulas and the other restricted setup resources.
- Allow only the minimal lookup information required by explicitly authorized order-creation workflows; do not reopen setup access through a general manufacturing permission.

Acceptance: unauthorized sections disappear and direct URL/API/mutation attempts are denied. Authorized setup users can still perform their allowed work.

## 5. Repair inventory Actions

**Confirmed:** `modules/inventories/view.php:359` calls `event.stopPropagation()` on Remove, preventing its delegated document listener (`view.php:563`) from receiving the click and opening the modal.

**Likely additional cause for Edit:** both item Edit and warehouse Edit bind directly to currently attached buttons after `includes/footer.php` initializes DataTables. Buttons detached by pagination can miss these handlers. This needs browser confirmation on first and later pages.

Plan:
- Remove the conflicting inline handler and use delegated handlers for item Edit/Remove and warehouse Edit.
- Open modals with the Bootstrap 5 `Modal.getOrCreateInstance` API. Verify modal targets, button types, selected IDs, and click behavior on icons as well as labels.
- Keep action clicks excluded from shared row navigation; the existing footer already excludes buttons and links, so a global row-click rewrite is not indicated by this review.
- Show actions only with the corresponding inventory permission. Item update/removal handlers already check `inventories.edit` / `inventories.delete`.
- Add the missing `inventories.delete` authorization to the warehouse deletion branch in `modules/inventories/index.php`; it currently sits behind only `inventories.view`.
- Move affected delete operations to POST with CSRF protection, preserving channel checks, fixed-warehouse protection, stock logs, and the prohibition on deleting a nonempty warehouse.

Acceptance: edit/remove work after pagination, search, sorting, and page-length changes; cancel changes nothing; confirmation affects only the intended item/warehouse. Read-only users cannot mutate by submitting requests directly.

## 6. Correct مخزن الهالك to store wasted materials

**Confirmed:** `manufacturing_add_finished_goods()` in `modules/manufacturing/lib.php:1008` reads `breakage_count` and adds that number using the final product ID to the shared waste warehouse. `tests/manufacturing_waste.php` explicitly expects this behavior and excludes packaging waste. This is a business-logic change, not a display rename.

Plan:
- Record waste lines linked to manufacturing order, step, actual material product ID, quantity, and unit, with a unique posting reference to prevent duplicates.
- Use formula/sourcing and packaging-item product IDs to identify materials. Existing packaging fields such as `prints_waste`, `boxes_waste`, `stickers_waste`, and `leaflets_waste` include category/free-text information; require an explicit material mapping when ambiguous. Never guess product IDs from labels.
- Capture material waste in its proper unit, including fractional kg/L where applicable. Do not multiply final-product breakage across every recipe or infer ingredient loss from bottle counts.
- Trace existing consumption/return movements before defining posting: record transfers of already-consumed material into waste without deducting source stock twice. Reconcile issued = used + returned + waste with unit conversion where needed.
- Keep usable final-product output in the receiving warehouse; keep breakage as a production metric unless explicitly mapped to a real wasted material.
- Post inventory changes and their audit records transactionally and once only. Handle correction/reversal explicitly; do not silently repost completed orders.
- Prepare a reconciliation report for historical final-product waste entries using their linked order/stock logs. Reverse and replace only quantities supported by recorded material evidence; flag missing evidence for review. Do not delete existing balances or fabricate a recipe-based conversion.
- Replace the old waste test expectations and update snapshot/report labels to match actual material movements.

Acceptance: a recorded waste line for an actual material appears under that material SKU and unit. Usable final output remains correct. Duplicate completion, failed posting, mixed units, ambiguous mappings, and historical corrections cannot inflate or double-deduct stock.

Business detail still needed before implementing this part: whether material waste includes raw ingredients, packaging, or both, and how breakage should map to material loss. The recommended data model supports both without assuming that mapping.

## 7. Filter inventories by location

**Confirmed:** `modules/inventories/index.php` loads locations and shows a Location column, but its warehouse query filters only by the current login channel. It has no location dropdown.

Plan:
- Add a GET `location_id` dropdown above the warehouse table, with All locations and Unassigned/shared choices.
- Offer locations associated with warehouses already visible in the current channel. Validate input and combine a prepared location condition with the existing channel scope; a filter must never expand access.
- Apply the filter before DataTables initialization so search and CSV selection/download operate on the same warehouse set. Preserve selection in the URL and provide Reset.
- Keep the shared waste warehouse explicitly labeled as shared. Under a specific location, show only warehouses assigned there; retain the shared warehouse under All or Shared. Filtering movements inside a shared waste warehouse by originating order location is a separate feature and should use movement provenance, not an invented warehouse location.

Acceptance: selecting a location displays only its warehouses; All restores the authorized list; unassigned/shared records remain accessible through their explicit choice; exports match the displayed scope.

## Cross-cutting export and access checks

- `view_report.php` already checks report/step permissions, but step PDF generation and report builders still print batch size. Apply the same formula, technical-detail, and audit policies to PDF and Excel output.
- Excel links currently use `manufacturing_get_document_url()` to expose saved files. Serve protected documents by record ID through an authorized handler; prevent direct static access and cross-user reuse of privileged exports. Either generate per viewer or restrict saved exports according to their actual contents. Inspect deployment file-serving rules before claiming static paths are protected.
- Use the existing role-permission editor (`modules/roles/permissions.php`); permissions are database driven. Add an idempotent migration and clear descriptions rather than another permission system.
- New sensitive permissions should default to denied for non-admin roles. Assign them to reviewed roles explicitly, never to everyone with `manufacturing.view`. Existing admin bypass remains consistent with current application policy.
- Authentication reloads role permissions on requests (`includes/auth.php`), so verify revocation with an already logged-in account as well as a fresh login.

## Delivery order and verification

1. Implement urgent completion authorization and formula/audit/technical disclosure controls, including APIs and files.
2. Apply setup-resource permissions and inventory action repairs.
3. Implement material waste accounting and prepare the historical reconciliation separately.
4. Add the location filter.

Verify with admin, driver/delivery-only, general manufacturing without formula access, formula-authorized locked/unlocked, audit-authorized, setup-view-only, and inventory editor/deleter accounts. Exercise both successful authorized actions and denied direct requests. Check HTML/JSON/PDF/Excel/media URLs, customer/channel isolation, existing-session revocation, and first/later table pages.

Run PHP syntax checks for modified files and focused authorization/inventory integration checks. Extend the existing rollback-based manufacturing waste tests for the revised accounting contract, including duplicate/concurrent completion and transaction rollback. Use browser checks for modal/pagination and location-filter/export behavior.

Review limitation: findings are from local code and supplied screenshots. No live account permissions, production database state, or deployed browser behavior were inspected, and no tests were run for this planning-only review.
