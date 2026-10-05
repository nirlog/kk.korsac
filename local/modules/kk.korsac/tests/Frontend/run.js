'use strict';

const assert = require('node:assert/strict');
const {ACTIONS, ApiError, BitrixTransport, ConfiguratorCore} = require('../../install/js/kk/korsac/configurator-core/configurator-core.js');
const tests = [];
const test = (name, callback) => tests.push([name, callback]);

test('Bitrix transport uses the native envelope and correct parameter channel', async () => {
    const calls = [];
    const transport = new BitrixTransport({ajax: {runAction(action, options) {
        calls.push([action, options]);
        return Promise.resolve({status: 'success', data: {ok: true}});
    }}});
    assert.deepEqual(await transport.request(ACTIONS.get, {productId: 4}, 'GET'), {ok: true});
    assert.deepEqual(await transport.request(ACTIONS.calculate, {selection: {}}, 'POST'), {ok: true});
    assert.deepEqual(calls, [
        [ACTIONS.get, {getParameters: {productId: 4}}],
        [ACTIONS.calculate, {data: {selection: {}}}]
    ]);
});

test('Bitrix errors are normalized without losing public diagnostics', async () => {
    const errors = [{code: 'option_not_allowed', message: 'Not allowed', customData: {group: 'RAM'}}];
    const transport = new BitrixTransport({ajax: {runAction() { return Promise.reject({errors}); }}});
    await assert.rejects(transport.request(ACTIONS.calculate, {}, 'POST'), error => {
        assert.ok(error instanceof ApiError);
        assert.equal(error.code, 'option_not_allowed');
        assert.deepEqual(error.customData, {group: 'RAM'});
        return true;
    });
});

test('core stores identity and selection but never caches server price data', async () => {
    const calls = [];
    const transport = {request(action, payload, method) {
        calls.push([action, payload, method]);
        return Promise.resolve({selection: {RAM: 'RAM_64', SOFTWARE: []}, price: {finalPriceMinor: 123456}});
    }};
    const core = new ConfiguratorCore({transport, iblockId: 2, productId: 123});
    const result = await core.load();
    assert.equal(result.price.finalPriceMinor, 123456);
    assert.deepEqual(core.getState(), {iblockId: 2, productId: 123, selection: {RAM: 'RAM_64', SOFTWARE: []}});
    assert.equal(JSON.stringify(core).includes('finalPriceMinor'), false);
    core.setGroup('HDD', null);
    await core.addToCart();
    assert.deepEqual(calls[1], [ACTIONS.add, {iblockId: 2, productId: 123, selection: {RAM: 'RAM_64', SOFTWARE: [], HDD: null}}, 'POST']);
});

test('only the newest calculation updates selection and emits UI data', async () => {
    const resolvers = [];
    const core = new ConfiguratorCore({transport: {request() { return new Promise(resolve => resolvers.push(resolve)); }}, iblockId: 2, productId: 123, selection: {RAM: 'RAM_32'}});
    const events = [];
    core.subscribe(event => events.push(event));
    const oldRequest = core.calculate();
    core.setGroup('RAM', 'RAM_64');
    const newRequest = core.calculate();
    resolvers[1]({selection: {RAM: 'RAM_64'}, price: {finalPriceMinor: 200}});
    await newRequest;
    resolvers[0]({selection: {RAM: 'RAM_32'}, price: {finalPriceMinor: 100}});
    await oldRequest;
    assert.deepEqual(core.getState().selection, {RAM: 'RAM_64'});
    assert.equal(events.filter(event => event.type === 'calculated').length, 1);
});

test('a response cannot undo a selection edited while calculation was in flight', async () => {
    let resolve;
    const core = new ConfiguratorCore({transport: {request() { return new Promise(done => { resolve = done; }); }}, iblockId: 2, productId: 123, selection: {RAM: 'RAM_32'}});
    const events = [];
    core.subscribe(event => events.push(event));
    const request = core.calculate();
    core.setGroup('RAM', 'RAM_64');
    resolve({selection: {RAM: 'RAM_32'}, price: {finalPriceMinor: 100}});
    await request;
    assert.deepEqual(core.getState().selection, {RAM: 'RAM_64'});
    assert.equal(events.filter(event => event.type === 'calculated').length, 0);
});

test('a stale calculation error is not emitted after selection changes', async () => {
    let reject;
    const core = new ConfiguratorCore({transport: {request() { return new Promise((resolve, fail) => { reject = fail; }); }}, iblockId: 2, productId: 123, selection: {RAM: 'RAM_32'}});
    const events = [];
    core.subscribe(event => events.push(event));
    const request = core.calculate();
    core.setGroup('RAM', 'RAM_64');
    reject(new Error('stale request failed'));
    await assert.rejects(request, /stale request failed/);
    assert.deepEqual(core.getState().selection, {RAM: 'RAM_64'});
    assert.equal(events.filter(event => event.type === 'error').length, 0);
});

test('selection shape is checked locally while server remains whitelist authority', () => {
    const transport = {request() { return Promise.resolve({}); }};
    const core = new ConfiguratorCore({transport, iblockId: 2, productId: 123});
    assert.throws(() => core.setGroup('PRICE', 1), /Unknown configuration group/);
    assert.throws(() => core.setGroup('RAM', []), /RAM must/);
    assert.throws(() => core.setGroup('SOFTWARE', 'OFFICE'), /SOFTWARE must/);
    assert.throws(() => new ConfiguratorCore({transport, iblockId: 0, productId: 1}), /positive integer/);
});

(async () => {
    let failed = 0;
    for (const [name, callback] of tests) {
        try { await callback(); process.stdout.write(`PASS ${name}\n`); }
        catch (error) { failed += 1; process.stderr.write(`FAIL ${name}: ${error.stack || error.message}\n`); }
    }
    process.stdout.write(`${tests.length} tests, ${failed} failures\n`);
    process.exitCode = failed === 0 ? 0 : 1;
    if (failed === 0) {
        require('./renderer.run.js');
    }
})();
