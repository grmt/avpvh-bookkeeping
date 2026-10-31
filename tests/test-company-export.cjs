const vm = require('node:vm');
const fs = require('node:fs');
const assert = require('node:assert/strict');
let handler;
let posted;
const status = {textContent: ''};
const button = {textContent: 'Export', disabled: false};
const form = {
    action: {name: 'action', value: 'avbk_company'},
    getAttribute: name => name === 'action' ? 'https://example.test/wp-admin/admin-post.php' : null,
    querySelector: selector => selector.includes('operation') ? {value:'export'} : selector.includes('button') ? button : status,
    addEventListener: (type, callback) => { handler = callback; }
};
const sandbox = {
    document: {addEventListener: (type, callback) => callback(), querySelectorAll: () => [form]},
    window: {fetch: true},
    FormData: class {},
    fetch: async (url, options) => { posted = {url, options}; return {ok: false, headers: {get: () => 'application/json'}, json: async () => ({data:{message:'Expected server validation error'}})}; }
};
vm.runInNewContext(fs.readFileSync('assets/company.js','utf8'), sandbox);
(async () => {
    await handler({preventDefault(){}});
    assert.equal(posted.url, 'https://example.test/wp-admin/admin-post.php');
    assert.equal(posted.options.method, 'POST');
    assert.equal(status.textContent, 'Expected server validation error');
    assert.equal(button.disabled, false);
    console.log('Workbook upload uses the endpoint despite a named action input; server errors remain visible.');
})();
