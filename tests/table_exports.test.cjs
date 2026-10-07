const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

async function run() {
    let downloadedBlob;
    let filename;
    const document = {
        body: { appendChild() {} },
        createElement() {
            return { click() { filename = this.download; }, remove() {} };
        }
    };
    const window = { jQuery: null, setTimeout() {} };
    const source = fs.readFileSync(path.join(__dirname, '../assets/js/table-tools.js'), 'utf8')
        .replace('window.GamaaTableTools = { init: init };',
            'window.GamaaTableTools = { init: init, exportRows: exportRows, normaliseText: normaliseText };');
    vm.runInNewContext(source, {
        window, document, Blob,
        URL: { createObjectURL(blob) { downloadedBlob = blob; return 'blob:test'; } }
    });
    const cell = value => ({ dataset: { exportValue: value } });
    const header = [cell('Order Reference'), cell('Total'), cell('Notes'),
        { dataset: { export: 'false' } }];
    const makeRow = (reference, total, notes, lines, selected) => ({
        cells: [cell(reference), cell(total), cell(notes), cell('Delete')],
        dataset: { exportLines: JSON.stringify(lines) },
        querySelector() { return { checked: selected }; }
    });
    const rows = [
        makeRow('000123', 100, 'ملاحظات, "quoted"\nSecond line', [['Product A', 2], ['Product B', 3]], true),
        makeRow('000124', 0, '=SUM(A1:A2)', [], false)
    ];
    const table = {
        tHead: { rows: [{ cells: header }] },
        dataset: { exportName: 'Sales', exportLineHeaders: '["Product","Quantity"]', exportOnceColumns: '["Total"]' },
        getAttribute() { return null; }
    };
    const api = {
        rows(options) {
            assert.deepEqual(JSON.parse(JSON.stringify(options)), { search: 'applied', order: 'current', page: 'all' });
            return { nodes() { return { toArray() { return rows; } }; } };
        }
    };
    window.GamaaTableTools.exportRows(table, api, false);
    const csv = await downloadedBlob.text();
    const bytes = new Uint8Array(await downloadedBlob.arrayBuffer());
    assert.deepEqual(Array.from(bytes.slice(0, 3)), [239, 187, 191], 'Arabic-compatible UTF-8 BOM');
    assert.ok(csv.startsWith('"Order Reference","Total","Notes","Product","Quantity"'));
    assert.ok(csv.includes('"000123","100","ملاحظات, ""quoted""\nSecond line","Product A","2"'));
    assert.ok(csv.includes('"000123","","ملاحظات, ""quoted""\nSecond line","Product B","3"'), 'Order total appears once');
    assert.ok(csv.includes('"000124","0","\'=SUM(A1:A2)","",""'), 'Zero and orders without items survive; formula text is escaped');
    assert.ok(!csv.includes('Delete'), 'Action controls are omitted');
    assert.match(filename, /^Sales_filtered_\d{4}-\d{2}-\d{2}\.csv$/);
    window.GamaaTableTools.exportRows(table, api, true);
    assert.ok(!(await downloadedBlob.text()).includes('000124'), 'Only selected matching rows are exported');
    assert.match(filename, /^Sales_selected_/);
    assert.equal(window.GamaaTableTools.normaliseText(0), '0');
    console.log('PASS: filters across pages, selection, product lines, single order totals, empty orders, zero values, Arabic, multiline quoting and formula escaping.');
}
run().catch(error => { console.error(error); process.exitCode = 1; });
