<?php

namespace Tests\Feature\Updates;

use App\Services\Updates\DockerCommands;
use App\Services\Updates\Updater;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UpdaterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('updater');
        Config::set('app.version', '1.0.0');
    }

    public function test_write_heartbeat_creates_heartbeat_file(): void
    {
        $docker = new FakeDockerCommands;
        $updater = new Updater($docker, '/project', 'screening-room', 'app');

        $updater->writeHeartbeat();

        $this->assertTrue(Storage::disk('updater')->exists('heartbeat.json'));

        $heartbeat = json_decode(Storage::disk('updater')->get('heartbeat.json'), true);

        $this->assertArrayHasKey('at', $heartbeat);
        $this->assertEquals('latest', $heartbeat['current_tag']);
        $this->assertEquals('1.0.0', $heartbeat['updater_version']);
    }

    public function test_process_request_does_nothing_when_no_request(): void
    {
        $docker = new FakeDockerCommands;
        $updater = new Updater($docker, '/project', 'screening-room', 'app');

        $updater->processRequest();

        $this->assertFalse(Storage::disk('updater')->exists('status.json'));
        $this->assertEmpty($docker->calls);
    }

    public function test_process_request_executes_full_happy_path(): void
    {
        $docker = new FakeDockerCommands;
        $docker->pullSuccess = true;
        $docker->recreateSuccess = true;
        $docker->containerId = 'test-container';
        $docker->containerImage = 'ghcr.io/krakero/screening-room:develop';
        $docker->containerHealth = 'healthy';

        $updater = new Updater($docker, '/project', 'screening-room', 'app');

        Storage::disk('updater')->put('request.json', json_encode([
            'id' => 'test-123',
            'action' => 'update',
            'tag' => 'develop',
            'requested_at' => now()->toIso8601String(),
        ]));

        $updater->processRequest();

        // Request should be moved and then deleted
        $this->assertFalse(Storage::disk('updater')->exists('request.json'));
        $this->assertFalse(Storage::disk('updater')->exists('request.processing.json'));

        // Status should be 'done'
        $status = json_decode(Storage::disk('updater')->get('status.json'), true);
        $this->assertEquals('done', $status['state']);
        $this->assertEquals('test-123', $status['id']);
        $this->assertEquals('latest', $status['from_tag']);
        $this->assertEquals('develop', $status['to_tag']);

        // Verify Docker commands were called with correct tag
        $this->assertContains('pull:ghcr.io/krakero/screening-room:develop', $docker->calls);
        $this->assertContains('updateEnvTag:/project:develop', $docker->calls);
        $this->assertContains('recreateService:/project:screening-room:app:develop', $docker->calls);
        $this->assertContains('getContainerId:/project:screening-room:app', $docker->calls);
        $this->assertContains('containerImage:test-container', $docker->calls);
        $this->assertContains('getContainerHealth:test-container', $docker->calls);
    }

    public function test_process_request_rolls_back_on_unhealthy_container(): void
    {
        $docker = new FakeDockerCommands;
        $docker->pullSuccess = true;
        $docker->recreateSuccess = true;
        $docker->containerId = 'test-container';
        $docker->containerHealth = 'unhealthy';
        $docker->containerRunning = false;

        $updater = new Updater($docker, '/project', 'screening-room', 'app');

        Storage::disk('updater')->put('request.json', json_encode([
            'id' => 'test-456',
            'action' => 'update',
            'tag' => 'develop',
            'requested_at' => now()->toIso8601String(),
        ]));

        Storage::disk('updater')->put('boot.json', json_encode([
            'state' => 'failed',
            'message' => 'Migration failed',
            'dump' => 'pre-update-develop-2024-01-15_120000.sql.gz',
        ]));

        $updater->processRequest();

        // Status should be 'rolled_back'
        $status = json_decode(Storage::disk('updater')->get('status.json'), true);
        $this->assertEquals('rolled_back', $status['state']);
        $this->assertStringContainsString('Migration failed', $status['message']);
        $this->assertEquals('pre-update-develop-2024-01-15_120000.sql.gz', $status['dump']);

        // Should have restored previous tag with correct parameter
        $this->assertContains('updateEnvTag:/project:latest', $docker->calls);
        $this->assertContains('recreateService:/project:screening-room:app:latest', $docker->calls);
    }

    public function test_process_request_handles_pull_failure(): void
    {
        $docker = new FakeDockerCommands;
        $docker->pullSuccess = false;
        $docker->imageExists = false;

        $updater = new Updater($docker, '/project', 'screening-room', 'app');

        Storage::disk('updater')->put('request.json', json_encode([
            'id' => 'test-789',
            'action' => 'update',
            'tag' => 'develop',
            'requested_at' => now()->toIso8601String(),
        ]));

        $updater->processRequest();

        // Status should be 'failed'
        $status = json_decode(Storage::disk('updater')->get('status.json'), true);
        $this->assertEquals('failed', $status['state']);
        $this->assertStringContainsString('does not exist locally', $status['message']);

        // Should not have attempted recreate
        $this->assertNotContains('recreateService:/project:screening-room:app:develop', $docker->calls);
    }

    public function test_process_request_continues_with_local_image_when_pull_fails(): void
    {
        $docker = new FakeDockerCommands;
        $docker->pullSuccess = false;
        $docker->imageExists = true;
        $docker->recreateSuccess = true;
        $docker->containerId = 'test-container';
        $docker->containerImage = 'ghcr.io/krakero/screening-room:develop';
        $docker->containerHealth = 'healthy';

        $updater = new Updater($docker, '/project', 'screening-room', 'app');

        Storage::disk('updater')->put('request.json', json_encode([
            'id' => 'test-local',
            'action' => 'update',
            'tag' => 'develop',
            'requested_at' => now()->toIso8601String(),
        ]));

        $updater->processRequest();

        // Status should be 'done' despite pull failure
        $status = json_decode(Storage::disk('updater')->get('status.json'), true);
        $this->assertEquals('done', $status['state']);
        $this->assertEquals('test-local', $status['id']);

        // Should have proceeded with recreate and passed correct tag
        $this->assertContains('recreateService:/project:screening-room:app:develop', $docker->calls);
        $this->assertContains('imageExists:ghcr.io/krakero/screening-room:develop', $docker->calls);
    }

    public function test_process_request_handles_recreate_failure(): void
    {
        $docker = new FakeDockerCommands;
        $docker->pullSuccess = true;
        $docker->recreateSuccess = false;

        $updater = new Updater($docker, '/project', 'screening-room', 'app');

        Storage::disk('updater')->put('request.json', json_encode([
            'id' => 'test-999',
            'action' => 'update',
            'tag' => 'develop',
            'requested_at' => now()->toIso8601String(),
        ]));

        $updater->processRequest();

        // Status should be 'failed'
        $status = json_decode(Storage::disk('updater')->get('status.json'), true);
        $this->assertEquals('failed', $status['state']);
        $this->assertStringContainsString('Failed to recreate service', $status['message']);
    }

    public function test_image_mismatch_after_recreate_triggers_rollback(): void
    {
        $docker = new FakeDockerCommands;
        $docker->pullSuccess = true;
        $docker->recreateSuccess = true;
        $docker->containerId = 'test-container';
        // Set container image to wrong tag (still on latest instead of develop)
        $docker->containerImage = 'ghcr.io/krakero/screening-room:latest';

        $updater = new Updater($docker, '/project', 'screening-room', 'app');

        Storage::disk('updater')->put('request.json', json_encode([
            'id' => 'test-mismatch',
            'action' => 'update',
            'tag' => 'develop',
            'requested_at' => now()->toIso8601String(),
        ]));

        $updater->processRequest();

        // Status should be 'rolled_back' with image mismatch message
        $status = json_decode(Storage::disk('updater')->get('status.json'), true);
        $this->assertEquals('rolled_back', $status['state']);
        $this->assertStringContainsString('Recreated container is still on', $status['message']);
        $this->assertStringContainsString('ghcr.io/krakero/screening-room:latest', $status['message']);

        // Should have attempted rollback to original tag
        $this->assertContains('updateEnvTag:/project:latest', $docker->calls);
        $this->assertContains('recreateService:/project:screening-room:app:latest', $docker->calls);
    }

    public function test_happy_path_verifies_image_and_health(): void
    {
        $docker = new FakeDockerCommands;
        $docker->pullSuccess = true;
        $docker->recreateSuccess = true;
        $docker->containerId = 'test-container';
        $docker->containerImage = 'ghcr.io/krakero/screening-room:develop';
        $docker->containerHealth = 'healthy';

        $updater = new Updater($docker, '/project', 'screening-room', 'app');

        Storage::disk('updater')->put('request.json', json_encode([
            'id' => 'test-verify',
            'action' => 'update',
            'tag' => 'develop',
            'requested_at' => now()->toIso8601String(),
        ]));

        $updater->processRequest();

        // Status should be 'done' only after both image match and health check pass
        $status = json_decode(Storage::disk('updater')->get('status.json'), true);
        $this->assertEquals('done', $status['state']);

        // Should have verified both image and health
        $this->assertContains('containerImage:test-container', $docker->calls);
        $this->assertContains('getContainerHealth:test-container', $docker->calls);
    }
}

/**
 * Fake implementation of DockerCommands for testing.
 */
class FakeDockerCommands implements DockerCommands
{
    public array $calls = [];

    public bool $pullSuccess = true;

    public bool $recreateSuccess = true;

    public ?string $containerId = null;

    public ?string $containerHealth = null;

    public bool $containerRunning = true;

    public bool $imageExists = true;

    public ?string $containerImage = null;

    public function pull(string $image): bool
    {
        $this->calls[] = "pull:{$image}";

        return $this->pullSuccess;
    }

    public function updateEnvTag(string $projectDir, string $tag): void
    {
        $this->calls[] = "updateEnvTag:{$projectDir}:{$tag}";
    }

    public function recreateService(string $projectDir, string $projectName, string $serviceName, string $tag): bool
    {
        $this->calls[] = "recreateService:{$projectDir}:{$projectName}:{$serviceName}:{$tag}";

        return $this->recreateSuccess;
    }

    public function getContainerId(string $projectDir, string $projectName, string $serviceName): ?string
    {
        $this->calls[] = "getContainerId:{$projectDir}:{$projectName}:{$serviceName}";

        return $this->containerId;
    }

    public function getContainerHealth(string $containerId): ?string
    {
        $this->calls[] = "getContainerHealth:{$containerId}";

        return $this->containerHealth;
    }

    public function isContainerRunning(string $containerId): bool
    {
        $this->calls[] = "isContainerRunning:{$containerId}";

        return $this->containerRunning;
    }

    public function imageExists(string $image): bool
    {
        $this->calls[] = "imageExists:{$image}";

        return $this->imageExists;
    }

    public function containerImage(string $containerId): ?string
    {
        $this->calls[] = "containerImage:{$containerId}";

        return $this->containerImage;
    }
}
