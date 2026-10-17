const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

// Exercise the actual page handlers with Bootstrap unavailable, including a
// click on a nested button element as used by icons or responsive tables.
const page = fs.readFileSync(path.join(__dirname, '../modules/sales/order_list.php'), 'utf8');
const script = page.match(/<script>([\s\S]*?)<\/script>/)[1]
    .replace(/<\?=[\s\S]*?\?>/g, '"test-token"');
const handlers = {};
const forms = [];
const selected = [{ value: '42', checked: true }, { value: '43', checked: true }];
const bulkButton = { addEventListener(event, callback) { this[event] = callback; } };
let confirmed = true;
const document = {
    addEventListener(event, callback) { (handlers[event] ||= []).push(callback); },
    getElementById(id) { return id === 'btnBulkDelete' ? bulkButton : null; },
    querySelectorAll(selector) { return selector === '.row-select:checked' ? selected : []; },
    createElement(tag) {
        return {
            tag, children: [],
            appendChild(child) { this.children.push(child); },
            submit() { forms.push(this); }
        };
    },
    body: { appendChild() {} }
};
vm.runInNewContext(script, { document, confirm() { return confirmed; } });
handlers.DOMContentLoaded.forEach(callback => callback());
assert.equal(handlers.click.length, 1, 'Deletion initializes without Bootstrap or a preview modal');

let prevented = false;
const button = { dataset: { id: '42', number: 'ORD-42' } };
const event = {
    target: { closest(selector) { return selector === '.js-delete-order' ? button : null; } },
    preventDefault() { prevented = true; }
};
handlers.click[0](event);
assert.ok(prevented);
assert.equal(forms.length, 1);
assert.equal(forms[0].method, 'POST');
assert.equal(forms[0].action, 'bulk_delete.php');
assert.deepEqual(forms[0].children.map(input => [input.name, input.value]), [
    ['deletion_token', 'test-token'], ['order_ids[]', '42']
]);

bulkButton.click();
assert.equal(forms.length, 2);
assert.deepEqual(forms[1].children.map(input => [input.name, input.value]), [
    ['deletion_token', 'test-token'], ['order_ids[]', '42'], ['order_ids[]', '43']
]);

confirmed = false;
handlers.click[0](event);
bulkButton.click();
assert.equal(forms.length, 2, 'Canceling either confirmation does not submit');
handlers.click[0]({ target: { closest() { return null; } } });
assert.equal(forms.length, 2, 'Unrelated clicks do not submit');
console.log('PASS: individual and bulk deletion work without preview dependencies, include CSRF tokens, and honor cancellation.');
