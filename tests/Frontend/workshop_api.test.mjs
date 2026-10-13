// API contract checks use Node's installed test runner; browser interactions live in test_workshop.py.
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import ts from 'typescript';
const cache = new Map();
function moduleUrl(path) {
    if (cache.has(path)) return cache.get(path);
    let source = ts.transpileModule(readFileSync(path, 'utf8'), { compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.ESNext } }).outputText;
    source = source.replace(/from ['"]([^'"]+)['"]/g, (match, name) => `from '${moduleUrl(resolve(dirname(path), `${name}.ts`))}'`);
    const url = `data:text/javascript;base64,${Buffer.from(source).toString('base64')}`;
    cache.set(path, url); return url;
}
const api = await import(moduleUrl(resolve('resources/js/modules/Workshop/api.ts')));
const presentation = await import(moduleUrl(resolve('resources/js/modules/Workshop/presentation.ts')));
globalThis.document = { cookie: 'XSRF-TOKEN=synthetic-token' };
const pagination = (rows, number=1, last=1) => ({ data: rows, meta: { current_page:number, last_page:last, per_page:100, total:rows.length } });

test('order filters reach the existing endpoint without client search parameters', async () => {
    globalThis.fetch = async (url, init) => {
        assert.equal(init.cache, 'no-store'); assert.equal(init.credentials, 'same-origin');
        const query=new URL(url,'https://local.test').searchParams;
        assert.equal(query.get('status'),'pending_diagnosis'); assert.equal(query.get('priority'),'high'); assert.equal(query.has('search'),false);
        return Response.json(pagination([{id:'order-1',version:3,status:'pending_diagnosis'}]));
    };
    const result=await api.list('orders',{status:'pending_diagnosis',priority:'high'});
    assert.equal(result.data[0].version,3);
});

test('all traverses every page for an exact unique-vehicle metric', async () => {
    const pages=[];
    globalThis.fetch=async url=>{const number=Number(new URL(url,'https://local.test').searchParams.get('page'));pages.push(number);return Response.json(pagination([{id:`impediment-${number}`,fleet_asset_id:number===3?'other':'same'}],number,3));};
    const rows=await api.all('impediments',{open:1});
    assert.deepEqual(pages,[1,2,3]);assert.equal(new Set(rows.map(row=>row.fleet_asset_id)).size,2);
});

test('writes preserve request_key and exact decimal quantities and send CSRF', async () => {
    const payload={request_key:'10000000-0000-4000-8000-000000000001',parts:[{quantity:'999999999999.123456'}]};
    globalThis.fetch=async (url,init)=>{assert.equal(url,'/api/workshop/orders/order-1/scopes');assert.equal(init.headers.get('X-XSRF-TOKEN'),'synthetic-token');assert.deepEqual(JSON.parse(init.body),payload);return Response.json({data:{scope_id:'scope-1',request_id:'request-1'}});};
    assert.equal((await api.write('orders/order-1/scopes','POST',payload)).request_id,'request-1');
});

test('422 exposes field errors while 500 never exposes database details or automatically retries', async () => {
    let calls=0;
    globalThis.fetch=async()=>{calls++;return Response.json({message:'SQLSTATE sensitive',errors:{reason:['Motivo requerido']}},{status:422});};
    await assert.rejects(api.write('reports','POST',{}),e=>e.status===422 && e.fields.reason[0]==='Motivo requerido');
    globalThis.fetch=async()=>{calls++;return Response.json({message:'SQLSTATE sensitive',errors:{operation:['secret']}},{status:500});};
    await assert.rejects(api.write('reports','POST',{}),e=>e.status===500 && !e.message.includes('SQLSTATE') && Object.keys(e.fields).length===0);
    assert.equal(calls,2);
});

test('session, permission, missing resource and conflict errors retain their HTTP status', async () => {
    for (const status of [401,403,404,409,419]) {globalThis.fetch=async()=>Response.json({},{status});await assert.rejects(api.detail('orders','order-1'),e=>e.status===status);}
});

test('malformed records fail validation and aborted reads stay cancellable', async () => {
    globalThis.fetch=async()=>Response.json(pagination([{id:27}]));
    await assert.rejects(api.list('orders'),/Registro inválido/);
    globalThis.fetch=async()=>{throw new DOMException('Aborted','AbortError');};
    await assert.rejects(api.list('orders'),e=>e.name==='AbortError');
});

test('input instants use the approved Costa Rica timezone and exact text stays text', () => {
    assert.equal(presentation.instant('2026-10-13T08:30'),'2026-10-13T08:30:00-06:00');
    assert.equal(presentation.text('1.000001'),'1.000001');
});
