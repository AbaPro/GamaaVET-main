# Manufacturing Module Status

## Progress overview
- Manufacturing orders, formulas, and step documents are now stored in their own tables (`manufacturing_formulas`, `manufacturing_orders`, `manufacturing_order_steps`, and `manufacturing_step_documents`) so every provider formula and hand-off artifact is tracked end-to-end.
- The dashboard (`modules/manufacturing/index.php`) surfaces filtered lists, per-order progress bars, and document counters so operations can see the current work pile at a glance.
- Order creation now ties formulas exclusively to existing catalog products; the builder requires selecting products from the catalog and captures quantity/unit/notes for each component before saving an order. Every step automatically produces an Excel and TCPDF handoff kept under `assets/uploads/manufacturing`.

## How we describe the manufacturing steps to the client
1. **Getting materials** – Select a customer provider and pick (or build) their formula by selecting from catalog products, then stage the order. This sets the `getting` step to pending so warehouse or procurement can source the required items.
2. **Preparing & mixing** – As soon as materials arrive, the `preparing` step opens; teams update quantities/notes and mark it in progress/completed. Each save emits a pair of Excel/PDF documents that list the components, ratios, and preparation notes so the next team knows the exact recipe and QA touches.
3. **Delivery & handover** – Once preparation completes, the `delivering` step captures packaging, dispatch, and delivery notes, again generating Excel and PDF artifacts that travel with the order to the customer or logistics partner.

## Next actions
- Run the new migration (`migrations/20240110_manufacturing_module.sql`) so the schema exists.
- Walk through order creation, ensure the component dropdown only offers products from the catalog, and verify the Excel/PDF files land inside `assets/uploads/manufacturing/{order}/{step}/` with download links on the order detail page.
