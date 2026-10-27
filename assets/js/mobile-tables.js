(function () {
    'use strict';

    function prepareTables(root) {
        const tables = root.matches && root.matches('table') ? [root] : [];
        tables.push(...root.querySelectorAll('table'));
        tables.forEach(function (table) {
            // Nested tables share the outer table's scrolling area.
            if (table.parentElement.closest('table')) return;

            let wrapper = table.closest('.table-responsive, .table-wrapper, .overflow-x-auto, .mobile-table-scroll');
            if (!wrapper) {
                wrapper = document.createElement('div');
                wrapper.className = 'table-responsive';
                table.before(wrapper);
                wrapper.appendChild(table);
            }
            wrapper.classList.add('mobile-table-scroll');
            // Keyboard users can scroll the same region as touch users.
            if (!wrapper.hasAttribute('tabindex')) wrapper.tabIndex = 0;
        });
    }

    function init() {
        prepareTables(document);
        // Include AJAX results and tables inserted into order-detail modals.
        new MutationObserver(function (records) {
            records.forEach(function (record) {
                record.addedNodes.forEach(function (node) {
                    if (node.nodeType === 1) prepareTables(node);
                });
            });
        }).observe(document.body, { childList: true, subtree: true });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
