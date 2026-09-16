(function (window, document, $) {
    'use strict';

    const TABLE_SELECTOR = 'table.js-datatable';
    const ACTION_HEADER_PATTERN = /^(action|actions)$/i;

    function normaliseText(value) {
        return String(value || '')
            .replace(/\u00a0/g, ' ')
            .replace(/[\t\r ]+/g, ' ')
            .replace(/\n\s+/g, '\n')
            .trim();
    }

    function isPlaceholderRow(row, columnCount) {
        if (!row || row.cells.length !== 1) {
            return false;
        }

        const colspan = parseInt(row.cells[0].getAttribute('colspan') || '1', 10);
        return colspan >= columnCount;
    }

    function prepareEmptyState(table) {
        const headerCount = table.tHead && table.tHead.rows.length
            ? table.tHead.rows[table.tHead.rows.length - 1].cells.length
            : 0;

        if (!table.tBodies.length || table.tBodies[0].rows.length !== 1) {
            return;
        }

        const row = table.tBodies[0].rows[0];
        if (!isPlaceholderRow(row, headerCount)) {
            return;
        }

        table.dataset.emptyMessage = normaliseText(row.cells[0].textContent) || 'No records found.';
        row.remove();
    }

    function firstColumnCanBeReused(table) {
        if (!table.tHead || !table.tHead.rows.length) {
            return false;
        }

        const headerCell = table.tHead.rows[table.tHead.rows.length - 1].cells[0];
        if (!headerCell) {
            return false;
        }

        if (headerCell.querySelector('input[type="checkbox"]')) {
            return true;
        }

        const rows = table.tBodies.length ? Array.from(table.tBodies[0].rows) : [];
        if (rows.some(function (row) {
            return row.cells[0] && row.cells[0].querySelector('input[type="checkbox"]');
        })) {
            return true;
        }

        return normaliseText(headerCell.textContent) === '' && (rows.length === 0 || rows.every(function (row) {
            return row.cells[0] && normaliseText(row.cells[0].textContent) === '';
        }));
    }

    function makeSelectAllCheckbox(tableLabel) {
        const checkbox = document.createElement('input');
        checkbox.type = 'checkbox';
        checkbox.className = 'form-check-input js-table-select-all';
        checkbox.setAttribute('aria-label', 'Select all filtered rows in ' + tableLabel);
        checkbox.title = 'Select all filtered rows';
        return checkbox;
    }

    function makeRowCheckbox(rowNumber, tableLabel) {
        const checkbox = document.createElement('input');
        checkbox.type = 'checkbox';
        checkbox.className = 'form-check-input js-table-row-select';
        checkbox.setAttribute('aria-label', 'Select row ' + rowNumber + ' in ' + tableLabel);
        return checkbox;
    }

    function getTableLabel(table) {
        const explicitLabel = table.getAttribute('aria-label') || table.dataset.exportName;
        if (explicitLabel) {
            return normaliseText(explicitLabel);
        }

        const card = table.closest('.card');
        const cardHeading = card ? card.querySelector('.card-header h1, .card-header h2, .card-header h3, .card-header h4, .card-header h5, .card-header h6, .card-header .card-title') : null;
        if (cardHeading && normaliseText(cardHeading.textContent)) {
            return normaliseText(cardHeading.textContent);
        }

        const pageHeading = document.querySelector('main h1, main h2, .container h1, .container h2, h1, h2');
        return pageHeading && normaliseText(pageHeading.textContent)
            ? normaliseText(pageHeading.textContent)
            : 'table';
    }

    function prepareSelectionColumn(table) {
        if (!table.tHead || !table.tHead.rows.length || !table.tBodies.length) {
            return;
        }

        const tableLabel = getTableLabel(table);
        const reuseFirstColumn = firstColumnCanBeReused(table);
        const headerRows = Array.from(table.tHead.rows);
        const bodyRows = Array.from(table.tBodies[0].rows);

        if (reuseFirstColumn) {
            const headerCell = headerRows[headerRows.length - 1].cells[0];
            headerCell.classList.add('table-select-column');
            headerCell.dataset.export = 'false';

            let selectAll = headerCell.querySelector('input[type="checkbox"]');
            if (!selectAll) {
                selectAll = makeSelectAllCheckbox(tableLabel);
                headerCell.appendChild(selectAll);
            } else {
                selectAll.classList.add('js-table-select-all');
                selectAll.setAttribute('aria-label', 'Select all filtered rows in ' + tableLabel);
                selectAll.title = 'Select all filtered rows';
            }

            bodyRows.forEach(function (row, index) {
                const cell = row.cells[0];
                if (!cell) {
                    return;
                }

                cell.classList.add('table-select-column');
                cell.dataset.export = 'false';
                let checkbox = cell.querySelector('input[type="checkbox"]');
                if (!checkbox) {
                    checkbox = makeRowCheckbox(index + 1, tableLabel);
                    cell.appendChild(checkbox);
                } else {
                    checkbox.classList.add('js-table-row-select');
                    checkbox.setAttribute('aria-label', 'Select row ' + (index + 1) + ' in ' + tableLabel);
                }
            });

            table.dataset.selectionColumn = '0';
            return;
        }

        headerRows.forEach(function (row, index) {
            const cell = document.createElement('th');
            cell.className = 'table-select-column';
            cell.dataset.export = 'false';
            cell.scope = 'col';
            if (index === headerRows.length - 1) {
                cell.appendChild(makeSelectAllCheckbox(tableLabel));
            }
            row.insertBefore(cell, row.firstChild);
        });

        bodyRows.forEach(function (row, index) {
            const cell = document.createElement('td');
            cell.className = 'table-select-column';
            cell.dataset.export = 'false';
            cell.appendChild(makeRowCheckbox(index + 1, tableLabel));
            row.insertBefore(cell, row.firstChild);
        });

        if (table.tFoot) {
            Array.from(table.tFoot.rows).forEach(function (row) {
                const cell = document.createElement('td');
                cell.className = 'table-select-column';
                cell.dataset.export = 'false';
                row.insertBefore(cell, row.firstChild);
            });
        }

        table.dataset.selectionColumn = '0';
        table.dataset.selectionColumnAdded = 'true';
    }

    function markNonExportColumns(table) {
        if (!table.tHead || !table.tHead.rows.length) {
            return [];
        }

        const headers = Array.from(table.tHead.rows[table.tHead.rows.length - 1].cells);
        const disabledIndexes = [];

        headers.forEach(function (header, index) {
            const text = normaliseText(header.textContent);
            const hasInteractiveControl = header.querySelector('input, button, select');
            const isSelection = header.classList.contains('table-select-column');
            const isAction = ACTION_HEADER_PATTERN.test(text) || (text === '' && !isSelection);

            if (isSelection || isAction || hasInteractiveControl) {
                header.dataset.export = 'false';
                disabledIndexes.push(index);
            }

            if (isAction) {
                header.classList.add('table-actions-column');
            }
        });

        return disabledIndexes;
    }

    function parseInitialOrder(table) {
        if (!table.dataset.tableOrder) {
            return [];
        }

        try {
            const order = JSON.parse(table.dataset.tableOrder);
            if (!Array.isArray(order)) {
                return [];
            }

            const offset = table.dataset.selectionColumnAdded === 'true' ? 1 : 0;
            return order.map(function (entry) {
                return [Number(entry[0]) + offset, entry[1]];
            });
        } catch (error) {
            return [];
        }
    }

    function parseAdditionalDisabledColumns(table) {
        if (!table.dataset.tableNonOrderable) {
            return [];
        }

        try {
            const indexes = JSON.parse(table.dataset.tableNonOrderable);
            const offset = table.dataset.selectionColumnAdded === 'true' ? 1 : 0;
            return Array.isArray(indexes) ? indexes.map(function (index) {
                return Number(index) + offset;
            }) : [];
        } catch (error) {
            return [];
        }
    }

    function getFilteredRowNodes(api) {
        return api.rows({ search: 'applied', order: 'current', page: 'all' }).nodes().toArray();
    }

    function getRowCheckbox(row) {
        return row.querySelector('input.js-table-row-select');
    }

    function updateSelectionUi(table, api, toolbar) {
        const filteredRows = getFilteredRowNodes(api);
        const checkboxes = filteredRows.map(getRowCheckbox).filter(Boolean);
        const selectedCount = checkboxes.filter(function (checkbox) { return checkbox.checked; }).length;
        const selectAll = table.querySelector('thead input.js-table-select-all');
        const filteredButton = toolbar.querySelector('.js-export-filtered');
        const selectedButton = toolbar.querySelector('.js-export-selected');
        const selectedCountElement = toolbar.querySelector('.js-export-selected-count');

        if (selectAll) {
            selectAll.checked = checkboxes.length > 0 && selectedCount === checkboxes.length;
            selectAll.indeterminate = selectedCount > 0 && selectedCount < checkboxes.length;
            selectAll.disabled = checkboxes.length === 0;
        }

        filteredButton.disabled = filteredRows.length === 0;
        selectedButton.disabled = selectedCount === 0;
        selectedCountElement.textContent = String(selectedCount);
    }

    function cellText(cell) {
        if (cell.dataset.exportValue !== undefined) {
            return normaliseText(cell.dataset.exportValue);
        }

        const clone = cell.cloneNode(true);
        clone.querySelectorAll('script, style, input, select, textarea, button, .no-export, .fas, .far, .fab, .fa, .bx').forEach(function (element) {
            element.remove();
        });
        clone.querySelectorAll('br').forEach(function (element) {
            element.replaceWith('\n');
        });
        clone.querySelectorAll('img').forEach(function (image) {
            const replacement = image.getAttribute('alt') || image.getAttribute('title') || '';
            image.replaceWith(replacement);
        });

        return normaliseText(clone.textContent);
    }

    function csvValue(value) {
        let safeValue = String(value == null ? '' : value);
        const trimmedValue = safeValue.trim();
        const isNegativeNumber = /^-\d+(?:[.,]\d+)?$/.test(trimmedValue);
        if (/^[=+@]/.test(trimmedValue) || (trimmedValue.startsWith('-') && !isNegativeNumber)) {
            safeValue = "'" + safeValue;
        }
        return '"' + safeValue.replace(/"/g, '""') + '"';
    }

    function safeFilename(value) {
        const cleaned = normaliseText(value)
            .replace(/[^\p{L}\p{N}._-]+/gu, '_')
            .replace(/^_+|_+$/g, '')
            .slice(0, 80);
        return cleaned || 'table';
    }

    function exportRows(table, api, selectedOnly) {
        const headerRow = table.tHead.rows[table.tHead.rows.length - 1];
        const exportIndexes = Array.from(headerRow.cells).reduce(function (indexes, header, index) {
            if (header.dataset.export !== 'false') {
                indexes.push(index);
            }
            return indexes;
        }, []);

        const filteredRows = getFilteredRowNodes(api);
        const rows = selectedOnly ? filteredRows.filter(function (row) {
            const checkbox = getRowCheckbox(row);
            return checkbox && checkbox.checked;
        }) : filteredRows;

        if (!rows.length || !exportIndexes.length) {
            return;
        }

        const csvRows = [exportIndexes.map(function (index) {
            return csvValue(cellText(headerRow.cells[index]));
        }).join(',')];

        rows.forEach(function (row) {
            csvRows.push(exportIndexes.map(function (index) {
                return csvValue(row.cells[index] ? cellText(row.cells[index]) : '');
            }).join(','));
        });

        const now = new Date();
        const dateStamp = now.getFullYear()
            + '-' + String(now.getMonth() + 1).padStart(2, '0')
            + '-' + String(now.getDate()).padStart(2, '0');
        const selectionSuffix = selectedOnly ? '_selected' : '_filtered';
        const filename = safeFilename(getTableLabel(table)) + selectionSuffix + '_' + dateStamp + '.csv';
        const blob = new Blob(['\ufeff' + csvRows.join('\r\n')], { type: 'text/csv;charset=utf-8;' });
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = filename;
        document.body.appendChild(link);
        link.click();
        link.remove();
        window.setTimeout(function () { URL.revokeObjectURL(url); }, 0);
    }

    function createToolbar(table, api) {
        const toolbar = document.createElement('div');
        toolbar.className = 'table-export-toolbar d-flex flex-wrap align-items-center gap-2 mb-3';
        toolbar.innerHTML = ''
            + '<button type="button" class="btn btn-sm btn-success js-export-filtered">'
            + '<i class="fas fa-file-csv me-1" aria-hidden="true"></i>Export filtered CSV'
            + '</button>'
            + '<button type="button" class="btn btn-sm btn-outline-success js-export-selected" disabled>'
            + '<i class="fas fa-check-square me-1" aria-hidden="true"></i>Export selected '
            + '(<span class="js-export-selected-count">0</span>)'
            + '</button>'
            + '<span class="small text-muted">Filtered export includes all matching rows, not only this page.</span>';

        const wrapper = table.closest('.dataTables_wrapper');
        if (wrapper) {
            wrapper.insertBefore(toolbar, wrapper.firstChild);
        } else {
            table.parentNode.insertBefore(toolbar, table);
        }

        toolbar.querySelector('.js-export-filtered').addEventListener('click', function () {
            exportRows(table, api, false);
        });
        toolbar.querySelector('.js-export-selected').addEventListener('click', function () {
            exportRows(table, api, true);
        });

        return toolbar;
    }

    function bindSelection(table, api, toolbar) {
        const selectAll = table.querySelector('thead input.js-table-select-all');
        if (selectAll) {
            selectAll.addEventListener('change', function () {
                getFilteredRowNodes(api).forEach(function (row) {
                    const checkbox = getRowCheckbox(row);
                    if (checkbox) {
                        checkbox.checked = selectAll.checked;
                    }
                });
                updateSelectionUi(table, api, toolbar);
            });
        }

        table.addEventListener('change', function (event) {
            if (event.target.matches('input.js-table-row-select')) {
                updateSelectionUi(table, api, toolbar);
            }
        });

        api.on('draw search', function () {
            updateSelectionUi(table, api, toolbar);
        });
        updateSelectionUi(table, api, toolbar);
    }

    function initialiseTable(table) {
        if (table.dataset.tableToolsReady === 'true') {
            return;
        }

        prepareEmptyState(table);
        prepareSelectionColumn(table);
        const nonDataIndexes = markNonExportColumns(table);
        const nonOrderableIndexes = Array.from(new Set(
            nonDataIndexes.concat(parseAdditionalDisabledColumns(table))
        ));
        let api;

        if ($.fn.dataTable.isDataTable(table)) {
            api = $(table).DataTable();
        } else {
            api = $(table).DataTable({
                pageLength: 25,
                lengthMenu: [10, 25, 50, 100],
                order: parseInitialOrder(table),
                language: {
                    emptyTable: table.dataset.emptyMessage || 'No records found.'
                },
                columnDefs: [
                    {
                        orderable: false,
                        targets: nonOrderableIndexes
                    },
                    {
                        searchable: false,
                        targets: nonDataIndexes
                    }
                ]
            });
        }

        const toolbar = createToolbar(table, api);
        bindSelection(table, api, toolbar);
        table.dataset.tableToolsReady = 'true';
    }

    function init() {
        if (!$ || !$.fn || typeof $.fn.DataTable === 'undefined') {
            return;
        }

        document.querySelectorAll(TABLE_SELECTOR).forEach(initialiseTable);
    }

    window.GamaaTableTools = { init: init };
})(window, document, window.jQuery);
