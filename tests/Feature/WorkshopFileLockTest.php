<?php

namespace Tests\Feature;

use App\Modules\Workshop\Infrastructure\PrivateWorkshopFiles;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class WorkshopFileLockTest extends TestCase
{
    public function test_other_process_cannot_take_a_reservation_lock_until_its_owner_exits(): void
    {
        $root = sys_get_temp_dir().'/workshop_lock_'.Str::uuid();
        $reservation = (string) Str::uuid();
        config(['workshop.storage_root' => $root, 'workshop.flock_timeout_ms' => 50]);
        $files = app(PrivateWorkshopFiles::class);
        $files->locked($reservation, fn () => true);
        $lock = $root.'/locks/'.$reservation.'.lock';
        $inode = fileinode($lock);
        $script = '$handle=fopen($argv[1],"c"); if (!$handle || !flock($handle,LOCK_EX)) exit(1); echo "ready\n"; fflush(STDOUT); fread(STDIN,1); flock($handle,LOCK_UN); fclose($handle);';
        $process = proc_open([PHP_BINARY, '-r', $script, $lock], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        try {
            stream_set_timeout($pipes[1], 5);
            $this->assertSame("ready\n", fgets($pipes[1]));
            try {
                $files->locked($reservation, fn () => $this->fail('Exclusión del otro proceso eludida.'));
                $this->fail('Lock ocupado aceptado.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('ocupada', $exception->getMessage());
            }
        } finally {
            fwrite($pipes[0], 'x');
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            $exit = proc_close($process);
        }
        try {
            $this->assertSame(0, $exit);
            $this->assertTrue($files->locked($reservation, fn () => true));
            clearstatcache(true, $lock);
            $this->assertSame($inode, fileinode($lock));
        } finally {
            File::deleteDirectory($root);
        }
    }
}
