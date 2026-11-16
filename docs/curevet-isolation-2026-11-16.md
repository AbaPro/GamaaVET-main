# CureVet isolation — 16 November 2026

The client requested that CureVet locations and stock appear only within CureVet. The manufacturing creation screenshot showed the legacy `Cure Vet` location among Factory locations.

## Implemented

- Added shared location visibility checks for the legacy CureVet names, including spacing, dash, underscore, and case variations. Ordinary locations retain their existing availability; CureVet locations remain available within `curva`.
- Applied the checks to manufacturing create/edit and sourcing filters, warehouse forms, location management, purchase-order destinations, and safe-location selectors.
- Added server-side location validation to manufacturing forms/AJAX, warehouse create/edit, and location mutations.
- Extended inventory channel scoping to exclude warehouses at CureVet locations outside CureVet, including incorrectly unassigned or tagged warehouses.
- Scoped product warehouse columns, assembly selectors, stock exports, customer product balances, product inventory details, and sales/manufacturing stock calculations. Assembly and component lookup requests validate warehouse access too.

No data was deleted, no migrations were needed, and production was not deployed. Login/company selection and administrative permission choices retain the CureVet brand so authorized users can access and manage that channel.

## Verification

`tests/curevet_scope.php` rolls back its temporary records and checks Factory, Primer, Naturous, Activita, and CureVet boundaries, legacy name variants, ordinary locations, and malformed warehouse channel assignments.

`tests/client_comments_access.php` checks rendered manufacturing/sourcing/warehouse location selectors and rejects forged manufacturing submissions targeting CureVet. Existing stock workflow regression checks remain included.
