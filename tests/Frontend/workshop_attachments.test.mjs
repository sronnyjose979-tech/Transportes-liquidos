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
    const url = `data:text/javascript;base64,${Buffer.from(source).toString('base64')}`; cache.set(path, url); return url;
}
const api = await import(moduleUrl(resolve('resources/js/modules/Workshop/attachments.ts')));
globalThis.document = { cookie: 'XSRF-TOKEN=synthetic-token' };

test('attachment pagination omits forbidden per_page and preserves privacy headers', async () => {
    globalThis.fetch = async (url, options) => {
        assert.equal(url, '/api/workshop/orders/order-1/attachments?page=2');
        assert.equal(options.cache, 'no-store'); assert.equal(options.credentials, 'same-origin');
        return Response.json({ data: [{ id: 'attachment-1' }], meta: { current_page: 2, last_page: 2, per_page: 25, total: 26 } });
    };
    assert.equal((await api.listAttachments('order-1', 2)).meta.total, 26);
});

test('metadata computes the actual checksum and rejects unsupported files', async () => {
    const file = new File(['%PDF-1.4\n%%EOF'], 'evidence.pdf', { type: 'application/pdf' });
    const metadata = await api.describeFile(file, 'document', 'Inspection');
    const expected = Array.from(new Uint8Array(await crypto.subtle.digest('SHA-256', await file.arrayBuffer())), byte => byte.toString(16).padStart(2, '0')).join('');
    assert.equal(metadata.checksum, expected); assert.equal(metadata.size_bytes, file.size);
    await assert.rejects(api.describeFile(new File(['binary'], 'secret.exe', { type: 'application/octet-stream' }), 'document', 'Inspection'), error => error.status === 422);
});

test('reservation retries keep the identical key and immutable file metadata', async () => {
    const payload = await api.describeFile(new File(['%PDF-1.4\n%%EOF'], 'evidence.pdf', { type: 'application/pdf' }), 'document', 'Inspection');
    const requests = [];
    globalThis.fetch = async (url, options) => { requests.push(JSON.parse(options.body)); return Response.json({ data: { reservation_id: 'reservation-1' } }); };
    await api.reserveAttachment('order-1', 'same-key', payload); await api.reserveAttachment('order-1', 'same-key', payload);
    assert.deepEqual(requests[0], requests[1]); assert.equal(requests[0].request_key, 'same-key');
});

test('multipart confirmation reports real progress and preserves server validation errors', async () => {
    globalThis.XMLHttpRequest = class {
        upload = {}; status = 422; responseText = JSON.stringify({ errors: { file: ['Contenido distinto.'] } });
        headers = {};
        open(method, url) { assert.equal(method, 'POST'); assert.equal(url, '/api/workshop/attachment-reservations/reservation-1/confirm'); }
        setRequestHeader(name, value) { this.headers[name] = value; }
        send(data) {
            assert.equal(data.get('request_key'), 'same-key'); assert.equal(data.get('file').name, 'evidence.pdf');
            assert.equal(this.headers['X-XSRF-TOKEN'], 'synthetic-token'); assert.equal(this.headers['Content-Type'], undefined);
            this.upload.onprogress({ lengthComputable: true, loaded: 7, total: 10 }); this.onload();
        }
    };
    const values = [];
    await assert.rejects(api.confirmAttachment('reservation-1', 'same-key', new File(['%PDF-1.4\n%%EOF'], 'evidence.pdf', { type: 'application/pdf' }), progress => values.push(progress)), error => error.status === 422 && error.fields.file[0] === 'Contenido distinto.');
    assert.deepEqual(values, [70]);
});

test('recovery is actor and order scoped, contains no file bytes, and refuses changed roles', async () => {
    globalThis.sessionStorage = {
        getItem(key) { return this[key] ?? null; },
        setItem(key, value) { this[key] = value; },
        removeItem(key) { delete this[key]; },
    };
    const metadata = await api.describeFile(new File(['%PDF-1.4\n%%EOF'], 'evidence.pdf', { type: 'application/pdf' }), 'document', 'Inspection');
    const operation = { actor: 'mechanic-1', roles: 'mechanic', order: 'order-1', metadata, reserveKey: 'reserve', confirmKey: 'confirm', releaseKey: 'release', reservation: 'reservation' };
    api.saveAttachmentRecovery(operation);
    assert.deepEqual(api.readAttachmentRecovery('mechanic-1', 'order-1', 'mechanic'), operation);
    assert.equal(api.readAttachmentRecovery('mechanic-2', 'order-1', 'mechanic'), undefined);
    assert.equal(api.readAttachmentRecovery('mechanic-1', 'order-1', 'administrator'), undefined);
    assert.equal(JSON.stringify(globalThis.sessionStorage).includes('%PDF'), false);
    api.clearAttachmentRecovery('mechanic-1');
    assert.equal(api.readAttachmentRecovery('mechanic-1', 'order-1', 'mechanic'), undefined);
});

test('approved attachment revision requires exactly the authorized file and business metadata', async () => {
    const metadata = await api.describeFile(new File(['%PDF-1.4\n%%EOF'], 'evidence.pdf', { type: 'application/pdf' }), 'document', 'Inspection');
    const correction = { decisionId: 'decision-1', factId: 'attachment-1', reason: 'Correction approved', after: api.attachmentChanges(metadata) };
    const revised = api.authorizedAttachmentMetadata(metadata, correction);
    assert.equal(revised.previous_attachment_id, 'attachment-1'); assert.equal(revised.decision_id, 'decision-1'); assert.equal(revised.reason, 'Correction approved');
    assert.throws(() => api.authorizedAttachmentMetadata({ ...metadata, checksum: '0'.repeat(64) }, correction), error => error.status === 422);
    assert.throws(() => api.authorizedAttachmentMetadata({ ...metadata, category: 'invoice' }, correction), error => error.status === 422);
});

test('attachment rectification request uses existing endpoint with version, executor, exact changes and stable key', async () => {
    const metadata = await api.describeFile(new File(['%PDF-1.4\n%%EOF'], 'evidence.pdf', { type: 'application/pdf' }), 'document', 'Inspection');
    const operation = { actor: '1', roles: 'administrator', order: 'order-1', metadata, reserveKey: 'same-key', confirmKey: 'confirm', releaseKey: 'release', rectificationRequest: { factId: 'attachment-1', version: 4, executor: 1 } };
    const requests = [];
    globalThis.fetch = async (url, options) => {
        assert.equal(url, '/api/workshop/facts/attachment/attachment-1/rectifications');
        requests.push(JSON.parse(options.body)); return Response.json({ data: { request_id: 'request-1' } });
    };
    await api.requestAttachmentCorrection(operation); await api.requestAttachmentCorrection(operation);
    assert.deepEqual(requests[0], requests[1]); assert.equal(requests[0].version, 4); assert.equal(requests[0].executor_id, 1);
    assert.deepEqual(requests[0].changes, api.attachmentChanges(metadata)); assert.equal('reason' in requests[0].changes, false); assert.equal(requests[0].changes.external_repair_id, null);
});
