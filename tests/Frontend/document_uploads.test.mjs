import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import ts from 'typescript';
const source = ts.transpileModule(readFileSync('resources/js/modules/FleetAndDocuments/uploadRecovery.ts', 'utf8'), {
    compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.ESNext },
}).outputText;
const { uploadBody, uploadFingerprint } = await import(`data:text/javascript;base64,${Buffer.from(source).toString('base64')}`);
const intent = Object.freeze({ actorId: '7', requestKey: '10000000-0000-4000-8000-000000000001',
    assetId: 'asset', renewedId: undefined, typeId: 'type', issuedOn: '2026-10-20', expiresOn: '',
    file: new File(['synthetic-document-bytes'], 'private.pdf', { type: 'application/pdf' }) });
test('retries preserve key, original parameters and exact file bytes without credentials', async () => {
    const first = uploadBody(intent), retry = uploadBody(intent);
    assert.deepEqual([...first.keys()], ['request_key', 'document_type_id', 'file', 'issued_on']);
    for (const name of ['request_key', 'document_type_id', 'issued_on']) assert.equal(first.get(name), retry.get(name));
    assert.equal(await retry.get('file').text(), await first.get('file').text());
    assert.equal(retry.get('file').name, 'private.pdf');
    assert.equal(first.has('actorId'), false);
});
test('renewals keep their original key and omit the prohibited document type', () => {
    const body = uploadBody({ ...intent, renewedId: 'previous', issuedOn: '', expiresOn: '2027-01-01' });
    assert.equal(body.has('document_type_id'), false);
    assert.equal(body.has('issued_on'), false);
    assert.equal(body.get('request_key'), intent.requestKey);
    assert.equal(body.get('expires_on'), '2027-01-01');
});

test('receipt verification binds the key to file bytes and original destination parameters', async () => {
    const expected = await uploadFingerprint(intent);
    assert.equal(expected.length, 64);
    assert.equal(await uploadFingerprint({ ...intent, file: new File(['synthetic-document-bytes'], 'renamed.pdf') }), expected);
    assert.notEqual(await uploadFingerprint({ ...intent, issuedOn: '' }), expected);
    assert.notEqual(await uploadFingerprint({ ...intent, file: new File(['different'], 'private.pdf') }), expected);
    assert.notEqual(await uploadFingerprint({ ...intent, renewedId: 'previous' }), expected);
});
