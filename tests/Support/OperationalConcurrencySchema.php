<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;

trait OperationalConcurrencySchema
{
    protected function withOperationalSchema(callable $test): void
    {
        $config = config('database.connections.pgsql_testing');
        $other = new PDO('pgsql:host='.$config['host'].';port='.$config['port'].';dbname='.$config['database'], $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $schema = 'assignment_concurrency_'.str_replace('-', '', (string) Str::uuid());
        $tables = ['users', 'roles', 'user_roles', 'employees', 'customers', 'liquid_products', 'operation_sites', 'fleet_assets', 'tractor_units', 'tankers', 'compartments',
            'routes', 'transport_requests', 'trips', 'transport_authorization_requests', 'transport_authorization_decisions', 'transport_changes', 'trip_product_reviews', 'trip_events', 'trip_warnings', 'trip_warning_resolutions',
            'transport_assignment_guard', 'transport_assignment_operations', 'trip_asset_assignments', 'trip_asset_releases', 'driver_assignments', 'driver_releases', 'transport_assignment_conflicts', 'transport_authorization_applications', 'trip_loads', 'load_items', 'trip_unloads', 'unload_items', 'trip_odometer_readings', 'transport_creation_receipts'];
        $other->exec('CREATE SCHEMA '.$schema);
        try {
            $other->exec('SET search_path TO '.$schema.',public');
            foreach ($tables as $table) {
                $other->exec('CREATE TABLE '.$schema.'.'.$table.' (LIKE public.'.$table.' INCLUDING ALL)');
            }
            foreach ($tables as $table) {
                foreach ($other->query("SELECT conname,pg_get_constraintdef(oid) AS definition FROM pg_constraint WHERE conrelid='public.$table'::regclass AND contype='f'")->fetchAll(PDO::FETCH_ASSOC) as $constraint) {
                    $other->exec('ALTER TABLE '.$schema.'.'.$table.' ADD CONSTRAINT '.$constraint['conname'].' '.str_replace('public.', $schema.'.', $constraint['definition']));
                }
                foreach ($other->query("SELECT pg_get_triggerdef(oid) FROM pg_trigger WHERE tgrelid='public.$table'::regclass AND NOT tgisinternal")->fetchAll(PDO::FETCH_COLUMN) as $trigger) {
                    $other->exec(str_replace(' ON public.'.$table.' ', ' ON '.$schema.'.'.$table.' ', $trigger));
                }
            }
            $other->exec('INSERT INTO transport_assignment_guard VALUES (1,1)');
            DB::statement('SET search_path TO '.$schema.',public');
            $test($other);
        } finally {
            if ($other->inTransaction()) {
                $other->rollBack();
            }
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            DB::statement('SET search_path TO public');
            $other->exec('SET search_path TO public');
            $other->exec('DROP SCHEMA '.$schema.' CASCADE');
        }
    }
}
