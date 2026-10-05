'use strict';

const assert = require('node:assert/strict');
const {ConfiguratorRenderer, formatMinor, orderedMultiple, presentationMode} = require('../../install/js/kk/korsac/configurator-renderer/configurator-renderer.js');

class Classes {
    constructor(node) { this.node = node; }
    values() { return this.node.className.split(/\s+/).filter(Boolean); }
    add(value) { if (!this.contains(value)) this.node.className = [...this.values(), value].join(' '); }
    remove(value) { this.node.className = this.values().filter(item => item !== value).join(' '); }
    contains(value) { return this.values().includes(value); }
    toggle(value, force) { (force === undefined ? !this.contains(value) : force) ? this.add(value) : this.remove(value); }
}
class Node {
    constructor(tag, document) { this.tagName = tag.toUpperCase(); this.ownerDocument = document; this.children = []; this.dataset = {}; this.attributes = {}; this.listeners = {}; this.className = ''; this.classList = new Classes(this); this.textContent = ''; this.disabled = false; this.hidden = false; this.type = ''; this.value = ''; this.checked = false; this.selected = false; }
    appendChild(node) { this.children.push(node); node.parentNode = this; return node; }
    removeChild(node) { this.children.splice(this.children.indexOf(node), 1); }
    get firstChild() { return this.children[0] || null; }
    get options() { return this.children; }
    setAttribute(name, value) { this.attributes[name] = String(value); }
    removeAttribute(name) { delete this.attributes[name]; }
    addEventListener(type, callback) { (this.listeners[type] ||= []).push(callback); }
    removeEventListener(type, callback) { this.listeners[type] = (this.listeners[type] || []).filter(item => item !== callback); }
    dispatchEvent(event) { event.target ||= this; (this.listeners[event.type] || []).slice().forEach(callback => callback(event)); return true; }
    matches(selector) {
        if (selector === 'input[type="checkbox"]') return this.tagName === 'INPUT' && this.type === 'checkbox';
        const match = selector.match(/^\[data-korsac-group="(.+)"\]$/); return match ? this.dataset.korsacGroup === match[1] : false;
    }
    querySelectorAll(selector) { const result = []; const visit = node => { node.children.forEach(child => { if (child.matches(selector)) result.push(child); visit(child); }); }; visit(this); return result; }
}
class CustomEvent { constructor(type, options) { this.type = type; this.detail = options.detail; } }
class Document { constructor() { this.defaultView = {CustomEvent}; } createElement(tag) { return new Node(tag, this); } }

class Core {
    constructor(payload) { this.payload = payload; this.state = {selection: {CPU: 'A', HDD: null, SOFTWARE: []}}; this.listeners = []; this.calculations = 0; this.adds = 0; }
    subscribe(listener) { this.listeners.push(listener); return () => { this.listeners = this.listeners.filter(item => item !== listener); }; }
    emit(type, detail) { this.listeners.slice().forEach(listener => listener({type, detail, state: {selection: JSON.parse(JSON.stringify(this.state.selection))}})); }
    getState() { return {selection: JSON.parse(JSON.stringify(this.state.selection))}; }
    load() { this.emit('loaded', this.payload); return Promise.resolve(this.payload); }
    setGroup(code, value) { this.state.selection[code] = value; this.emit('selection', {selection: this.getState().selection}); }
    calculate() { this.calculations++; return Promise.resolve({}); }
    addToCart() { this.adds++; return new Promise(resolve => { this.resolveAdd = result => { this.emit('added', result); resolve(result); }; }); }
}
const choice = (xmlId, name = xmlId, image = null, deltaMinor = 0) => ({xmlId, name, image, description: null, deltaMinor});
const payload = {
    groups: {
        CPU: {mode: 'single', allowNull: false, presentation: {mode: 'select'}, choices: [choice('A'), choice('B')]},
        HDD: {mode: 'single', allowNull: true, presentation: {mode: 'text_buttons'}, choices: [choice('H1')]},
        SOFTWARE: {mode: 'multiple', presentation: {mode: 'image_buttons'}, choices: [choice('A', '<img src=x onerror=alert(1)>'), choice('B'), choice('C')]}
    },
    selection: {CPU: 'A', HDD: null, SOFTWARE: []}, price: {finalPriceMinor: 24974800, configurationDeltaMinor: 0, currency: 'RUB'}
};
const tests = [];
const test = (name, callback) => tests.push([name, callback]);
const delay = milliseconds => new Promise(resolve => setTimeout(resolve, milliseconds));
function fixture(debounceMs = 5, testPayload = payload) { const document = new Document(), root = new Node('div', document), core = new Core(testPayload); return {document, root, core, renderer: new ConfiguratorRenderer({root, core, document, debounceMs})}; }

test('renderer exports its public class and presentation helpers', () => {
    assert.equal(typeof ConfiguratorRenderer, 'function');
    assert.equal(presentationMode({mode: 'single', presentation: {mode: 'checkboxes'}}), 'select');
    assert.equal(presentationMode({mode: 'multiple', presentation: {mode: 'checkboxes'}}), 'checkboxes');
    assert.deepEqual(orderedMultiple([choice('A'), choice('B'), choice('C')], ['C', 'A']), ['A', 'C']);
});
test('minor-unit helper formats positive, negative, and zero deltas', () => {
    const labels = {noExtra: 'ZERO'};
    assert.match(formatMinor(4800000, 'RUB', 'ru-RU', true, labels).replace(/\s/g, ''), /^\+48000/);
    assert.match(formatMinor(-840000, 'RUB', 'ru-RU', true, labels).replace(/\s/g, ''), /^−8400/);
    assert.equal(formatMinor(0, 'RUB', 'ru-RU', true, labels), 'ZERO');
});
test('single select, nullable text button, and multiple image button keep correct shapes', async () => {
    const {renderer, root, core} = fixture(); await renderer.mount();
    const controls = root.querySelectorAll('[data-korsac-group="CPU"]'); const select = controls.find(item => item.tagName === 'SELECT');
    select.value = 'B'; select.dispatchEvent({type: 'change'}); assert.equal(core.state.selection.CPU, 'B');
    const nullButton = root.querySelectorAll('[data-korsac-group="HDD"]').find(item => item.tagName === 'BUTTON' && item.dataset.korsacXmlId === undefined);
    nullButton.dispatchEvent({type: 'click'}); assert.equal(core.state.selection.HDD, null);
    const software = root.querySelectorAll('[data-korsac-group="SOFTWARE"]').filter(item => item.tagName === 'BUTTON');
    software[2].dispatchEvent({type: 'click'}); software[0].dispatchEvent({type: 'click'});
    assert.ok(Array.isArray(core.state.selection.SOFTWARE)); assert.deepEqual(core.state.selection.SOFTWARE, ['A', 'C']);
    assert.equal(software[0].children.some(item => item.tagName === 'IMG'), false, 'null image remains a text control');
    assert.equal(software[0].children[0].textContent, '<img src=x onerror=alert(1)>', 'server text is not parsed as HTML');
});
test('multiple select and checkboxes always submit arrays in API order', async () => {
    for (const mode of ['select', 'checkboxes']) {
        const testPayload = JSON.parse(JSON.stringify(payload)); testPayload.groups.SOFTWARE.presentation.mode = mode;
        const {renderer, root, core} = fixture(5, testPayload); await renderer.mount();
        if (mode === 'select') {
            const select = root.querySelectorAll('[data-korsac-group="SOFTWARE"]').find(item => item.tagName === 'SELECT');
            select.options[0].selected = true; select.options[2].selected = true; select.dispatchEvent({type: 'change'});
        } else {
            const inputs = root.querySelectorAll('[data-korsac-group="SOFTWARE"]').filter(item => item.tagName === 'INPUT');
            inputs[2].checked = true; inputs[2].dispatchEvent({type: 'change'}); inputs[0].checked = true; inputs[0].dispatchEvent({type: 'change'});
        }
        assert.ok(Array.isArray(core.state.selection.SOFTWARE)); assert.deepEqual(core.state.selection.SOFTWARE, ['A', 'C']); renderer.destroy();
    }
});
test('rapid selection changes debounce calculate and disable Add until accepted event', async () => {
    const {renderer, core} = fixture(10); await renderer.mount(); core.setGroup('CPU', 'B'); core.setGroup('CPU', 'A'); core.setGroup('CPU', 'B');
    assert.equal(renderer.addButton.disabled, true); await delay(20); assert.equal(core.calculations, 1);
    core.emit('calculated', {selection: core.state.selection, price: {finalPriceMinor: 10, currency: 'RUB'}}); assert.equal(renderer.addButton.disabled, false);
});
test('stale promise data and rejection do not commit UI state or visible error', async () => {
    const {renderer, core} = fixture(0); await renderer.mount(); const original = renderer.priceNode.children[0].textContent;
    core.calculate = () => Promise.resolve({price: {finalPriceMinor: 1, currency: 'RUB'}}); core.setGroup('CPU', 'B'); await delay(5);
    assert.equal(renderer.priceNode.children[0].textContent, original);
    core.calculate = () => Promise.reject(new Error('stale')); core.setGroup('CPU', 'A'); await delay(5);
    assert.equal(renderer.errorNode.textContent, '');
});
test('duplicate Add clicks share the active operation boundary', async () => {
    const {renderer, core} = fixture(); await renderer.mount(); const first = renderer.addToCart(); const second = renderer.addToCart();
    assert.equal(core.adds, 1); assert.equal(await second, null); core.resolveAdd({basketItemId: 7}); assert.deepEqual(await first, {basketItemId: 7});
});
test('destroy cancels pending calculations, subscription, and DOM listeners idempotently', async () => {
    const {renderer, core} = fixture(10); await renderer.mount(); core.setGroup('CPU', 'B'); renderer.destroy(); renderer.destroy(); await delay(20);
    assert.equal(core.calculations, 0); assert.equal(core.listeners.length, 0); assert.equal(renderer.listeners.length, 0);
});

(async () => {
    let failed = 0;
    for (const [name, callback] of tests) {
        try { await callback(); process.stdout.write(`PASS ${name}\n`); }
        catch (error) { failed++; process.stderr.write(`FAIL ${name}: ${error.stack || error.message}\n`); }
    }
    process.stdout.write(`${tests.length} renderer tests, ${failed} failures\n`);
    process.exitCode = failed ? 1 : process.exitCode;
})();
