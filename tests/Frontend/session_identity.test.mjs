import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import ts from 'typescript';

const source = ts.transpileModule(readFileSync('resources/js/modules/System/sessionIdentity.ts', 'utf8'), {
    compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.ESNext },
}).outputText;
const actor = (id = '1') => ({ id, roles: ['administrator'] });
const moduleForTest = () => import(`data:text/javascript;base64,${Buffer.from(source).toString('base64')}#${crypto.randomUUID()}`);
function browser() {
    globalThis.window = new EventTarget();
    globalThis.document = Object.assign(new EventTarget(), { visibilityState: 'visible' });
    Object.defineProperty(globalThis, 'navigator', { configurable: true, value: { onLine: true } });
}
const tick = () => new Promise(resolve => setImmediate(resolve));

test('an outstanding response cannot restore the previous identity after login/logout', async () => {
    const api = await moduleForTest(); api.rememberSession(actor());
    let finish;
    globalThis.fetch = async (_url, options) => {
        assert.equal(options.headers.get('X-Session-User'), '1');
        return new Promise(resolve => { finish = resolve; });
    };
    const pending = api.sessionFetch('/api/users');
    api.rememberSession(actor('2'), true);
    finish(Response.json({ data: ['private old response'] }, { headers: { 'X-Session-User': '1' } }));
    await assert.rejects(pending, /sesión cambió/);
});

test('a different server identity hides the page and blocks further requests', async () => {
    browser(); const api = await moduleForTest(); api.rememberSession(actor());
    let state, calls = 0; const stop = api.watchSession(value => { state = value; });
    globalThis.fetch = async () => { calls++; return Response.json({}, { status: 409, headers: { 'X-Session-User': '2' } }); };
    await assert.rejects(api.sessionFetch('/api/users'), /sesión cambió/);
    assert.equal(state.changed, true);
    await assert.rejects(api.sessionFetch('/api/users', { method: 'POST' }), /sesión/);
    assert.equal(calls, 1); stop();
});

test('a cross-tab notification immediately conceals data until the real session is checked', async () => {
    browser(); const api = await moduleForTest(); api.rememberSession(actor());
    let state, finish; const stop = api.watchSession(value => { state = value; });
    globalThis.fetch = async () => new Promise(resolve => { finish = resolve; });
    window.dispatchEvent(Object.assign(new Event('storage'), { key: 'transport-session-changed-v1' }));
    assert.equal(state.checking, true);
    finish(Response.json({ data: actor('2') })); await tick();
    assert.equal(state.changed, true); assert.equal(state.checking, false); stop();
});

test('focus with the same session preserves work and network loss preserves offline mode', async () => {
    browser(); const api = await moduleForTest(); api.rememberSession(actor());
    let state, calls = 0; const stop = api.watchSession(value => { state = value; });
    globalThis.fetch = async () => { calls++; return Response.json({ data: actor() }); };
    window.dispatchEvent(new Event('focus')); await tick();
    assert.deepEqual(state, { checking: false, changed: false });
    navigator.onLine = false; window.dispatchEvent(new Event('focus')); await tick();
    assert.equal(calls, 1);
    navigator.onLine = true; globalThis.fetch = async () => { throw new Error('offline'); };
    window.dispatchEvent(new Event('focus')); await tick();
    assert.deepEqual(state, { checking: false, changed: false });
    window.dispatchEvent(Object.assign(new Event('storage'), { key: 'transport-session-changed-v1' })); await tick();
    assert.equal(state.changed, true); stop();
});

test('a second notification during verification triggers another check before revealing data', async () => {
    browser(); const api = await moduleForTest(); api.rememberSession(actor());
    let state, finish, calls = 0; const stop = api.watchSession(value => { state = value; });
    globalThis.fetch = async () => {
        calls++;
        if (calls === 1) return new Promise(resolve => { finish = resolve; });
        return Response.json({ data: actor('2') });
    };
    window.dispatchEvent(new Event('focus'));
    window.dispatchEvent(Object.assign(new Event('storage'), { key: 'transport-session-changed-v1' }));
    finish(Response.json({ data: actor() })); await tick();
    assert.equal(calls, 2); assert.equal(state.changed, true); stop();
});

test('a request waits for focus verification without losing work when identity is unchanged', async () => {
    browser(); const api = await moduleForTest(); api.rememberSession(actor());
    const stop = api.watchSession(() => {});
    let finish, sent = false;
    globalThis.fetch = async url => {
        if (url === '/api/session') return new Promise(resolve => { finish = resolve; });
        sent = true; return Response.json({ data: [] }, { headers: { 'X-Session-User': '1' } });
    };
    window.dispatchEvent(new Event('focus'));
    const pending = api.sessionFetch('/api/users');
    assert.equal(sent, false);
    finish(Response.json({ data: actor() }));
    assert.equal((await pending).status, 200); assert.equal(sent, true); stop();
});
