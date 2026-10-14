import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import ts from 'typescript';
const source = ts.transpileModule(readFileSync('resources/js/modules/Administration/purchasingRecovery.ts', 'utf8'), {
    compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.ESNext },
}).outputText;
const recovery = await import(`data:text/javascript;base64,${Buffer.from(source).toString('base64')}`);
test('uncertain purchase preserves its exact command and isolates actors until explicit confirmation', () => {
    const intent = { actor: '7', path: '/purchasing/requests', title: 'registrar solicitud', body: {
        request_key: 'one-original-key', supplier_id: 'supplier', reason: 'Reparación',
        items: [{ spare_part_id: 'part', quantity: '2.000001' }],
    } };
    recovery.rememberPurchase(intent);
    assert.equal(recovery.pendingPurchase('7'), intent);
    assert.equal(recovery.pendingPurchase('8'), null);
    assert.equal(recovery.pendingPurchase('7').body.request_key, 'one-original-key');
    assert.equal(recovery.pendingPurchase('7').body.items[0].quantity, '2.000001');
    recovery.clearPurchase('8');
    assert.equal(recovery.pendingPurchase('7'), intent);
    recovery.clearPurchase('7');
    assert.equal(recovery.pendingPurchase('7'), null);
});
