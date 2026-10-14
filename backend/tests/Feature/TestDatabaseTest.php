<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Guards the test environment itself: the bid engine relies on
 * SELECT ... FOR UPDATE, which only means something on MySQL/InnoDB.
 */
class TestDatabaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_suite_runs_on_the_mysql_test_database(): void
    {
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('auction_test', DB::connection()->getDatabaseName());
    }

    public function test_a_row_locked_for_update_blocks_a_second_connection(): void
    {
        $user = User::factory()->create();

        // The test transaction (opened by RefreshDatabase) takes the row lock.
        DB::table('users')->where('id', $user->id)->lockForUpdate()->first();

        // A separate connection must not be able to lock the same row.
        config(['database.connections.lock_probe' => config('database.connections.mysql')]);

        try {
            DB::connection('lock_probe')->transaction(function ($probe) use ($user) {
                $probe->select('SELECT id FROM users WHERE id = ? FOR UPDATE NOWAIT', [$user->id]);
            });
            $this->fail('Second connection acquired a row lock that should have been held.');
        } catch (QueryException $e) {
            // 3572: "Statement aborted because lock(s) could not be acquired immediately and NOWAIT is set."
            $this->assertSame(3572, $e->errorInfo[1]);
        } finally {
            DB::purge('lock_probe');
        }
    }
}
