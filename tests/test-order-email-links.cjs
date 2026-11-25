const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const context = { URL, window: { location: { origin: 'https://example.test', protocol: 'https:' } } };
vm.runInNewContext(fs.readFileSync('assets/order-email-links.js', 'utf8'), context);
const links = context.window.AVBKOrderEmailLinks;
const auth = 'https://auth.example.test';
assert.equal(links.href('https://auth.example.test/reset-password/step1?username=anna%40example.test', auth), 'https://auth.example.test/reset-password/step1?username=anna%40example.test');
assert.equal(links.href('/avpvh-login/?redirect_to=%2Fboek%2F', auth), 'https://example.test/avpvh-login/?redirect_to=%2Fboek%2F');
for (const url of ['javascript:alert(1)', 'data:text/html,test', 'https://other.test/reset', 'https://auth.example.test.attacker.test/', 'https://user:password@auth.example.test/', 'http://auth.example.test/']) {
    assert.equal(links.href(url, auth), '/avpvh-login/');
}
assert.equal(links.text('" onclick="x()\' <tag>&'), '&quot; onclick=&quot;x()&#39; &lt;tag&gt;&amp;');
assert.ok(!links.href("https://auth.example.test/reset?name='test'&next=1", auth).includes("'"));
context.window.location = { origin: 'http://localhost:8080', protocol: 'http:' };
assert.equal(links.href('/avpvh-login/', auth), 'http://localhost:8080/avpvh-login/');
assert.equal(links.href('https://auth.example.test/reset', auth), 'https://auth.example.test/reset');
console.log('Order account links preserve configured authentication and local development URLs.');
