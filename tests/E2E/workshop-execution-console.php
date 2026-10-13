<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

if (PHP_SAPI !== 'cli') {
    throw new RuntimeException('Instrumentación exclusiva de pruebas CLI.');
}
$app = require __DIR__.'/bootstrap.php';
$app->make(Kernel::class)->bootstrap();
$input = json_decode(stream_get_contents(STDIN) ?: '{}', true, flags: JSON_THROW_ON_ERROR);
if (($argv[1] ?? '') !== 'snapshot') {
    throw new RuntimeException('Comando no admitido.');
}
$order = $input['order_id'];
$result = [];
foreach (['times' => 'workshop_time_events', 'costs' => 'workshop_cost_records', 'external' => 'workshop_external_repairs', 'attachments' => 'workshop_attachments', 'receipts' => 'workshop_operations'] as $key => $table) {
    $result[$key] = DB::table($table)->where('work_order_id', $order)->get();
}
$result['deliveries'] = DB::table('workshop_part_deliveries as d')->join('workshop_scope_parts as p', 'p.id', '=', 'd.scope_part_id')->join('workshop_scopes as s', 's.id', '=', 'p.scope_id')->where('s.work_order_id', $order)->select('d.*')->get();
$result['usages'] = DB::table('workshop_part_usages as u')->join('workshop_mechanic_assignments as a', 'a.id', '=', 'u.mechanic_assignment_id')->where('a.work_order_id', $order)->select('u.*')->get();
$result['activities'] = DB::table('workshop_activities as a')->join('workshop_scope_tasks as t', 't.id', '=', 'a.task_id')->join('workshop_scopes as s', 's.id', '=', 't.scope_id')->where('s.work_order_id', $order)->select('a.*')->get();
$result['reservations'] = DB::table('workshop_operations')->where('work_order_id', $order)->where('operation_type', 'attachment_reserve')->get();
$result['order'] = DB::table('workshop_work_orders')->where('id', $order)->firstOrFail();
echo json_encode($result, JSON_THROW_ON_ERROR);
