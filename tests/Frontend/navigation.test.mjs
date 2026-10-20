import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import ts from 'typescript';

const source = ts.transpileModule(readFileSync('resources/js/modules/System/navigation.ts', 'utf8'), {
    compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.ESNext },
}).outputText;
const { createNavigationLoader } = await import(`data:text/javascript;base64,${Buffer.from(source).toString('base64')}`);
const actor = () => ({ id: '1', roles: ['administrator'] });
const data = { home: '/dashboard', modules: [{ key: 'dashboard', label: 'Inicio', href: '/dashboard' }] };

test('home, sidebar and delayed overview share one request for the same session', async () => {
    let calls = 0, finish;
    const loader = createNavigationLoader(() => { calls++; return new Promise(resolve => { finish = resolve; }); });
    const session = actor();
    const home = loader.get(session), sidebar = loader.get(session);
    assert.equal(home, sidebar);
    finish(data);
    assert.deepEqual(await home, data);
    assert.deepEqual(await loader.get(session), data);
    assert.equal(calls, 1);
});

test('renewed identity, changed roles and page instances never reuse old links', async () => {
    let calls = 0;
    const load = async () => { calls++; return data; };
    const loader = createNavigationLoader(load);
    const session = actor();
    await loader.get(session);
    await loader.get(actor()); // a successful session refresh must fetch again
    const other = actor(); other.id = '2';
    await loader.get(other);
    other.roles = ['driver'];
    await loader.get(other);
    loader.clear();
    await loader.get(other);
    await createNavigationLoader(load).get(other);
    assert.equal(calls, 6);
});

test('a failed request can be retried and an old failure cannot evict a new session', async () => {
    let rejectOld, calls = 0;
    const loader = createNavigationLoader(() => {
        calls++;
        if (calls === 1) return new Promise((_resolve, reject) => { rejectOld = reject; });
        if (calls === 3) return Promise.reject(new Error('offline'));
        return Promise.resolve(data);
    });
    const old = loader.get(actor());
    const session = actor();
    await loader.get(session);
    rejectOld(new Error('old session'));
    await assert.rejects(old);
    await loader.get(session);
    assert.equal(calls, 2);
    loader.clear();
    await assert.rejects(loader.get(session), /offline/);
    await loader.get(session);
    assert.equal(calls, 4);
});
