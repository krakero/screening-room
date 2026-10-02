<?php

namespace Tests\Feature\Updates;

use App\Enums\UpdateChannel;
use App\Services\Updates\UpdaterClient;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class UpdaterClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('updater');
    }

    public function test_is_available_returns_true_when_heartbeat_is_fresh(): void
    {
        $client = new UpdaterClient;

        Storage::disk('updater')->put('heartbeat.json', json_encode([
            'at' => now()->toIso8601String(),
            'current_tag' => 'latest',
            'updater_version' => '1.0.0',
        ]));

        $this->assertTrue($client->isAvailable());
    }

    public function test_is_available_returns_false_when_heartbeat_is_stale(): void
    {
        $client = new UpdaterClient;

        Storage::disk('updater')->put('heartbeat.json', json_encode([
            'at' => now()->subMinutes(2)->toIso8601String(),
            'current_tag' => 'latest',
            'updater_version' => '1.0.0',
        ]));

        $this->assertFalse($client->isAvailable());
    }

    public function test_is_available_returns_false_when_no_heartbeat(): void
    {
        $client = new UpdaterClient;

        $this->assertFalse($client->isAvailable());
    }

    public function test_heartbeat_returns_data(): void
    {
        $client = new UpdaterClient;

        $data = [
            'at' => now()->toIso8601String(),
            'current_tag' => 'latest',
            'updater_version' => '1.0.0',
        ];

        Storage::disk('updater')->put('heartbeat.json', json_encode($data));

        $this->assertEquals($data, $client->heartbeat());
    }

    public function test_heartbeat_returns_null_when_file_missing(): void
    {
        $client = new UpdaterClient;

        $this->assertNull($client->heartbeat());
    }

    public function test_status_returns_data(): void
    {
        $client = new UpdaterClient;

        $data = [
            'id' => 'test-id',
            'state' => 'done',
            'message' => 'Update completed',
        ];

        Storage::disk('updater')->put('status.json', json_encode($data));

        $this->assertEquals($data, $client->status());
    }

    public function test_status_returns_null_when_file_missing(): void
    {
        $client = new UpdaterClient;

        $this->assertNull($client->status());
    }

    public function test_request_update_writes_request_file(): void
    {
        $client = new UpdaterClient;

        Carbon::setTestNow('2024-01-15 12:00:00');

        $id = $client->requestUpdate(UpdateChannel::Stable, 'update');

        $this->assertTrue(Storage::disk('updater')->exists('request.json'));

        $request = json_decode(Storage::disk('updater')->get('request.json'), true);

        $this->assertEquals($id, $request['id']);
        $this->assertEquals('update', $request['action']);
        $this->assertEquals('latest', $request['tag']);
        $this->assertEquals('2024-01-15T12:00:00+00:00', $request['requested_at']);
    }

    public function test_request_update_throws_when_request_pending(): void
    {
        $client = new UpdaterClient;

        Storage::disk('updater')->put('request.json', json_encode([
            'id' => 'existing',
            'action' => 'update',
            'tag' => 'latest',
            'requested_at' => now()->toIso8601String(),
        ]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('An update request is already pending');

        $client->requestUpdate(UpdateChannel::Stable, 'update');
    }

    public function test_request_update_throws_when_processing_request_exists(): void
    {
        $client = new UpdaterClient;

        Storage::disk('updater')->put('request.processing.json', json_encode([
            'id' => 'processing',
            'action' => 'update',
            'tag' => 'latest',
            'requested_at' => now()->toIso8601String(),
        ]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('An update request is already pending');

        $client->requestUpdate(UpdateChannel::Stable, 'update');
    }

    public function test_current_tag_returns_tag_from_heartbeat(): void
    {
        $client = new UpdaterClient;

        Storage::disk('updater')->put('heartbeat.json', json_encode([
            'at' => now()->toIso8601String(),
            'current_tag' => 'develop',
            'updater_version' => '1.0.0',
        ]));

        $this->assertEquals('develop', $client->currentTag());
    }

    public function test_current_tag_returns_null_when_no_heartbeat(): void
    {
        $client = new UpdaterClient;

        $this->assertNull($client->currentTag());
    }

    public function test_pre_update_dumps_lists_dumps_newest_first(): void
    {
        $client = new UpdaterClient;

        // Create test dumps using real filesystem since preUpdateDumps uses glob
        $backupsDir = storage_path('app/backups');
        @mkdir($backupsDir, 0755, true);

        file_put_contents($backupsDir.'/regular-backup.zip', 'not a dump');
        file_put_contents($backupsDir.'/pre-update-1.0.0-2024-01-01_120000.sql.gz', 'old dump');
        sleep(1); // Ensure different timestamp
        file_put_contents($backupsDir.'/pre-update-2.0.0-2024-01-02_120000.sql.gz', 'new dump');

        $dumps = $client->preUpdateDumps();

        // Clean up
        @unlink($backupsDir.'/regular-backup.zip');
        @unlink($backupsDir.'/pre-update-1.0.0-2024-01-01_120000.sql.gz');
        @unlink($backupsDir.'/pre-update-2.0.0-2024-01-02_120000.sql.gz');

        $this->assertCount(2, $dumps);
        $this->assertEquals('pre-update-2.0.0-2024-01-02_120000.sql.gz', $dumps[0]['filename']);
        $this->assertEquals('pre-update-1.0.0-2024-01-01_120000.sql.gz', $dumps[1]['filename']);
        $this->assertArrayHasKey('size', $dumps[0]);
        $this->assertArrayHasKey('created_at', $dumps[0]);
    }

    public function test_pre_update_dumps_returns_empty_when_no_dumps(): void
    {
        $client = new UpdaterClient;

        $dumps = $client->preUpdateDumps();

        $this->assertEmpty($dumps);
    }
}
