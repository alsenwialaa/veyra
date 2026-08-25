'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '../..');
const customerTemplate = fs.readFileSync(path.join(root, 'templates/customer/chat-shell.php'), 'utf8');
const adminTemplate = fs.readFileSync(path.join(root, 'templates/admin/studio-shell.php'), 'utf8');
const customerCss = fs.readFileSync(path.join(root, 'assets/customer/veyra-chat.css'), 'utf8');
const adminCss = fs.readFileSync(path.join(root, 'assets/admin/veyra-admin.css'), 'utf8');
const customerJs = fs.readFileSync(path.join(root, 'assets/customer/veyra-chat.js'), 'utf8');
const adminJs = fs.readFileSync(path.join(root, 'assets/admin/veyra-admin.js'), 'utf8');

assert.match(customerTemplate, /role="<\?php echo \$veyraIsModal \? 'dialog' : 'region'; \?>"/);
assert.match(customerTemplate, /aria-modal="true"/);
const scrollRegion = customerTemplate.match(/<div\s+class="veyra-chat__main"[\s\S]*?>/)?.[0] || '';
assert.match(scrollRegion, /role="log"/);
assert.match(scrollRegion, /tabindex="0"/);
assert.match(scrollRegion, /aria-label=/);
const timelineList = customerTemplate.match(/<ol\s+[\s\S]*?data-veyra-timeline[\s\S]*?>/)?.[0] || '';
assert.doesNotMatch(timelineList, /role="log"/, 'list items keep an implicit list parent inside the log region');
assert.match(customerTemplate, /aria-live="polite"/);
assert.match(customerTemplate, /aria-live="assertive"/);
assert.match(customerTemplate, /<label class="veyra-chat__sr-only"/);
assert.match(customerTemplate, /veyra-chat__disclosure/);
assert.match(customerTemplate, /data-veyra-confirm-dialog/);

assert.match(adminTemplate, /data-required-capability=/);
assert.match(adminTemplate, /data-admin-live role="status"/);
assert.match(adminTemplate, /data-admin-error role="alert"/);
assert.match(adminTemplate, /<caption class="screen-reader-text">/);
assert.match(adminTemplate, /<th scope="col">/);
assert.match(adminTemplate, /<dialog class="veyra-admin__dialog"/);
assert.match(adminTemplate, /Viewing is read-only/);
assert.match(adminTemplate, /type="password"/);
assert.match(adminTemplate, /autocomplete="new-password"/);
assert.match(adminTemplate, /manage_veyra_models/);

for (const css of [customerCss, adminCss]) {
    assert.match(css, /:focus-visible/);
    assert.match(css, /outline:\s*3px/);
    assert.match(css, /@media \(prefers-reduced-motion: reduce\)/);
    assert.match(css, /@media \(forced-colors: active\)/);
    assert.match(css, /min-block-size:\s*2\.75rem/);
}
assert.match(customerCss, /env\(safe-area-inset-bottom/);
assert.match(customerCss, /\.veyra-chat\[dir="rtl"\][\s\S]*safe-area-inset-left/);
assert.match(customerCss, /inset-inline-end:\s*max\(1rem, var\(--veyra-safe-inline-end\)\)/);
assert.match(customerCss, /100dvh/);
assert.match(customerCss, /overflow-x:\s*hidden/);
const customerControlDefaults = customerCss.match(/\.veyra-chat button,[\s\S]*?\}/)?.[0] || '';
assert.doesNotMatch(customerControlDefaults, /color\s*:/, 'control defaults must not override component contrast colors');
const customerLauncher = customerCss.match(/\.veyra-chat__launcher\s*\{[\s\S]*?\}/)?.[0] || '';
assert.match(customerLauncher, /color:\s*#ffffff/, 'launcher text retains AA contrast against the dark brand surface');
assert.match(adminCss, /overflow-x:\s*auto/);

assert.doesNotMatch(customerJs, /\.innerHTML\s*=/, 'customer payloads never enter innerHTML');
assert.doesNotMatch(adminJs, /\.innerHTML\s*=/, 'admin payloads never enter innerHTML');
assert.match(customerJs, /createElement\('bdi'/, 'mixed-direction facts use bdi');
assert.match(customerJs, /trapFocus\(event\)/, 'dialog focus is trapped');
assert.match(customerJs, /if \(this\.confirmDialog\?\.open\) \{\s*return;/, 'the outer focus trap yields to the native confirmation modal');
assert.match(customerJs, /node\.inert = true/, 'background is isolated while modal chat is open');
assert.match(customerJs, /global\.addEventListener\('online', \(\) => this\.reconnect\(\)\)/);
assert.match(customerJs, /Refreshing without resending anything|without resending anything/i);
assert.match(customerJs, /this\.loadHistory\(true\)/, 'reconnection refreshes authoritative history');
assert.match(customerJs, /headers\['X-Veyra-CSRF'\] = guestCsrf/, 'guest mutations carry the separately readable CSRF token');
assert.match(customerJs, /Object\.assign\(headers, requestSecurityHeaders/, 'the request path uses the tested nonce/guest CSRF selection');
assert.match(customerJs, /shouldReplaceHistorical/, 'historical rendering has an immutable guard');
assert.match(customerJs, /reconciliation_required/, 'uncertain sends survive reload and block replay until reconciliation');
assert.match(adminJs, /No success was recorded/);
assert.match(adminJs, /refreshed authoritative state could not be verified/);
assert.match(adminJs, /provider_credential/);
assert.match(adminJs, /provider_readiness/);
assert.match(adminJs, /select\.setAttribute\('aria-label', featureAccessibleLabel\(feature\)\)/);
assert.doesNotMatch(adminJs, /data-provider-secret[^\n]+data-field/, 'provider credentials never join draft hydration');

console.log('Veyra static accessibility contracts passed.');
