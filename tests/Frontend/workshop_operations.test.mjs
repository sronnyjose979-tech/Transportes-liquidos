import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {resolve,dirname} from 'node:path';
import ts from 'typescript';
const cache=new Map();
function moduleUrl(path){if(cache.has(path))return cache.get(path);let source=ts.transpileModule(readFileSync(path,'utf8'),{compilerOptions:{target:ts.ScriptTarget.ES2022,module:ts.ModuleKind.ESNext}}).outputText;source=source.replace(/from ['"]([^'"]+)['"]/g,(_,name)=>`from '${moduleUrl(resolve(dirname(path),`${name}.ts`))}'`);const url=`data:text/javascript;base64,${Buffer.from(source).toString('base64')}`;cache.set(path,url);return url;}
const ops=await import(moduleUrl(resolve('resources/js/modules/Workshop/operations.ts')));
test('command values preserve exact material quantities and financial decimals',()=>{assert.deepEqual(ops.operationValues({quantity:'1.000001',amount:'99999999999999.123456',reason:'Registro',source_attachment_id:'',performed_at:'2026-10-13T08:15'}),{quantity:'1.000001',amount:'99999999999999.123456',reason:'Registro',performed_at:'2026-10-13T08:15:00-06:00'});});
test('time summary retains confirmed fractional seconds and never accumulates open intervals locally',()=>{assert.deepEqual(ops.timeSummary({participants:{assignment:{confirmed_seconds:'1.000001',open_since:'2026-10-13T08:15:00-06:00'}},person_seconds:'1.000001'}),{total:'1.000001',participants:[{id:'assignment',seconds:'1.000001',open:'2026-10-13T08:15:00-06:00'}]});assert.equal(ops.timeSummary(null),null);});
test('financial totals retain separate bases and currencies without double counting client-side',()=>{const totals=[{basis:'actual',currency:'CRC',amount:'99999999999999.123456'},{basis:'estimated',currency:'USD',amount:'2.000001'}];assert.deepEqual(ops.costSummary(totals),totals);});
test('historical or unapplied records do not enter effective selections',()=>{assert.deepEqual(ops.effective([{id:'old',is_effective:false},{id:'current',is_effective:true},{id:'unknown'}]),[{id:'current',is_effective:true}]);});

test('time inputs preserve explicit seconds and fractional precision',()=>{assert.equal(ops.operationValues({occurred_at:'2026-10-13T08:15:37.100001'}).occurred_at,'2026-10-13T08:15:37.100001-06:00');});

const presentation=await import(moduleUrl(resolve('resources/js/modules/Workshop/presentation.ts')));
test('PostgreSQL timestamps become valid API instants without rounding microseconds',()=>{assert.equal(presentation.serverInstant('2026-10-13 15:15:37.100001+00'),'2026-10-13T15:15:37.100001+00:00');});
