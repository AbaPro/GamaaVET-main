## Client Feedback Responses - 25 Dec 2025

### System-Level
| Request / Concern | Status | Notes |
| --- | --- | --- |
| Back button undoing actions | Done | Every module uses the Post/Redirect/Get flow (see `modules/sales/order_details.php:154-201`), so browser history never rolls back committed data. |
| Codes for raw materials except print/packing | Pending | Need the desired SKU convention and the rule for skipping print/packing items before implementing. |
| "White screen" after actions | Pending | Unable to reproduce; please share the affected page/role so we can trace any PHP/runtime errors. |
| Separate logins for Factory vs Representative sales | Done | Added dedicated roles (`factory_sales`, `representative_sales`) that inherit salesman permissions; manage them via Roles > Manage. |

### Sales
| Request | Status | Notes |
| --- | --- | --- |
| Order-status permission limited to status-only users | Done | Introduced the `sales.orders.update_status` permission and enforced it in `modules/sales/order_details.php`; only roles with this key can change workflow status. |
| Dropdown preview when clicking order number | Done | Order IDs open a Bootstrap quick-view modal that pulls details from `ajax/get_order_summary.php`. |
| Date format Day/Month/Year | Done | `modules/sales/order_list.php` renders `d/m/Y`. |
| Sort-by options (customer/date/etc.) | Done | Added a validated sort selector wired into the SQL ORDER BY clause. |
| Invoice prints Arabic correctly | Done | Switched invoice fonts to `aealarabiya` in `modules/sales/generate_invoice.php`. |
| Discount details only when applied | Done | Discount block renders only when there's a discount/free sample (shipping details still always visible). |
| Link quotation contact to customer | Done (existing) | The quotation form already loads contacts per customer (see `modules/sales/quotations/create_quotation.php`). |
| Viewing Omar Magdy's orders from customer record | Done | Links now point to `modules/sales/order_details.php`. |
| "Why do we need a contact person in add factory?" | Info | Contact name/phone remain optional and only assist invoice context; they can be left blank. |
| Attach scans for tax/commercial registration | Done | The Add Customer modal gained a "Documents" tab plus backend storage in `customer_documents`. |

### Customers
| Request | Status | Notes |
| --- | --- | --- |
| Bulk upload customers | Done (template) | Added `modules/customers/sample_customers.csv` and a "Sample CSV" download button so teams can prep data for the upcoming importer. |

### Products
| Request | Status | Notes |
| --- | --- | --- |
| Sort & search in product list | Done | `#productsTable` now uses DataTables for search/sort. |
| Search bar | Done | Covered by DataTables' global search. |
| View page "Edit" button bounced back | Done | Added a dedicated edit page (`modules/products/edit.php`) so view->edit flows without dumping back to the list. |
| Description shows `\r\n` | Done | `modules/products/view.php` normalizes both literal CR/LF strings and newlines before rendering. |
| Show price for finals / cost for raw materials | Done (UI) | Add/edit forms dynamically hide irrelevant price fields based on type. |
| Manufacturing total cost per final product | Pending | Still need BOM cost capture in the assembly module. |

### Purchasing
| Request | Status | Notes |
| --- | --- | --- |
| Search bar while building PO | Done | The product modal now includes search/typeahead plus a quick search box on the main UI (`modules/purchases/create_po.php`). |
| Warehouse destination on PO | Done | Added a `warehouse_location` field to the PO form and schema (`migrations/20240106_purchase_order_warehouse.sql`). |
| Quick-view dropdown on PO/vendor lists | Done | PO IDs open in-page previews on both the PO list and vendor view via `ajax/get_po_summary.php`. |
| Purchasing officer cannot update PO status | Done | Added the `purchases.update_status` permission and a `purchasing_officer` role so authorized users can change status (`modules/purchases/po_details.php`). |

### Vendors
| Request | Status | Notes |
| --- | --- | --- |
| City/province/postal optional | Done | UI labels updated and backend allows null values. |
| Wallet transactions clarity & images | Done | Wallet form now describes each transaction type and supports image attachments; history rows expose download links (`modules/vendors/wallet.php`). |

### Tickets
| Request | Status | Notes |
| --- | --- | --- |
| Attach images | Done | Ticket creation now accepts multiple images (stored under `ticket_attachments`); attachments display on the view page. |
| Allow users to update status | Done | Added the `tickets.update_status` permission; non-admins with this key can change status/priority while assignment remains admin-only. |
| PO-specific request form | Pending | Still waiting on field/logic requirements for a PO template. |

### Additional Notes
- Recent migrations: `20240104_add_customer_document_paths.sql`, `20240104_ticket_attachments_and_permissions.sql`, `20240105_vendor_wallet_and_purchases.sql`, and `20240106_purchase_order_warehouse.sql`.
- Customer onboarding now has a downloadable CSV template and optional document uploads.
- Ticket attachments live under `assets/uploads/tickets/`; each record is linked via the `ticket_attachments` table.
- Sales and purchasing status updates, plus ticket status changes, are now governed by explicit permissions for finer control per role.
