<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;

/** Each service test owns a disposable schema and can commit real transactions. */
trait WorkshopSchema
{
    private ?PDO $workshopDdl = null;

    private ?string $workshopSchema = null;

    protected function prepareWorkshopSchema(): void
    {
        // PostgresTestCase has already verified the temporary cluster identity.
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        $config = config('database.connections.pgsql_testing');
        $this->workshopDdl = new PDO('pgsql:host='.$config['host'].';port='.$config['port'].';dbname='.$config['database'], $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $ddl = $this->workshopDdl;
        $schema = $this->workshopSchema = 'workshop_service_'.str_replace('-', '', (string) Str::uuid());
        $tables = $ddl->query("SELECT tablename FROM pg_tables WHERE schemaname='public' AND tablename<>'migrations' ORDER BY tablename")->fetchAll(PDO::FETCH_COLUMN);
        $functions = $ddl->query("SELECT pg_get_functiondef(p.oid) FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace WHERE n.nspname='public' AND p.prokind='f' ORDER BY p.oid")->fetchAll(PDO::FETCH_COLUMN);
        $ddl->exec('CREATE SCHEMA '.$schema);
        $ddl->exec('SET search_path TO '.$schema.',public');
        foreach ($tables as $table) {
            $ddl->exec('CREATE TABLE '.$schema.'.'.$table.' (LIKE public.'.$table.' INCLUDING ALL)');
        }
        foreach ($functions as $function) {
            // Include explicitly qualified decimal writers and their trusted path.
            $ddl->exec(str_replace(['public.', "'public'"], [$schema.'.', "'".$schema."'"], $function));
        }
        foreach ($tables as $table) {
            foreach ($ddl->query("SELECT conname,pg_get_constraintdef(oid) AS definition FROM pg_constraint WHERE conrelid='public.$table'::regclass AND contype='f'")->fetchAll(PDO::FETCH_ASSOC) as $constraint) {
                $ddl->exec('ALTER TABLE '.$schema.'.'.$table.' ADD CONSTRAINT '.$constraint['conname'].' '.str_replace('public.', $schema.'.', $constraint['definition']));
            }
            foreach ($ddl->query("SELECT pg_get_triggerdef(oid) FROM pg_trigger WHERE tgrelid='public.$table'::regclass AND NOT tgisinternal")->fetchAll(PDO::FETCH_COLUMN) as $trigger) {
                $ddl->exec(str_replace('public.', $schema.'.', $trigger));
            }
        }
        $ddl->exec('INSERT INTO transport_assignment_guard VALUES (1,1)');
        DB::statement('SET search_path TO '.$schema.',public');
    }

    protected function removeWorkshopSchema(): void
    {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        DB::statement('SET search_path TO public');
        if ($this->workshopSchema !== null && $this->workshopDdl !== null) {
            $this->workshopDdl->exec('SET search_path TO public');
            $this->workshopDdl->exec('DROP SCHEMA '.$this->workshopSchema.' CASCADE');
        }
        $this->workshopSchema = null;
        $this->workshopDdl = null;
    }
}
