<?php

use PHPUnit\Framework\TestCase;
use Gitcd\Helpers\AuditLog;

class AuditLogTest extends TestCase
{
    private string $tmpDir;
    private string $originalHome;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/protocol-audit-test-' . getmypid();
        mkdir($this->tmpDir . '/.protocol', 0700, true);

        $this->originalHome = $_SERVER['HOME'] ?? getenv('HOME');
        $_SERVER['HOME'] = $this->tmpDir;
    }

    protected function tearDown(): void
    {
        $_SERVER['HOME'] = $this->originalHome;
        $this->recursiveDelete($this->tmpDir);
    }

    private function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir)) return;
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->recursiveDelete($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    public function testLogPathIsUnderProtocolDir(): void
    {
        $path = AuditLog::logPath();
        $this->assertStringContainsString('.protocol/deployments.log', $path);
    }

    public function testLogDeployWritesEntry(): void
    {
        AuditLog::logDeploy('/opt/myapp', 'v1.0.0', 'v1.1.0');

        $lines = AuditLog::read();
        $this->assertCount(1, $lines);
        $this->assertStringContainsString('DEPLOY', $lines[0]);
        $this->assertStringContainsString('/opt/myapp', $lines[0]);
        $this->assertStringContainsString('v1.0.0', $lines[0]);
        $this->assertStringContainsString('v1.1.0', $lines[0]);
        $this->assertStringContainsString('status=', $lines[0]);
    }

    public function testLogDeployContainsTimestamp(): void
    {
        AuditLog::logDeploy('/opt/app', 'v1', 'v2');

        $lines = AuditLog::read();
        // ISO 8601 timestamp at the start
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $lines[0]);
    }

    public function testLogRollbackWritesEntry(): void
    {
        AuditLog::logRollback('/opt/myapp', 'v1.2.0', 'v1.1.0');

        $lines = AuditLog::read();
        $this->assertCount(1, $lines);
        $this->assertStringContainsString('ROLLBACK', $lines[0]);
    }

    public function testLogConfigWritesEntry(): void
    {
        AuditLog::logConfig('/opt/myapp', 'encrypt', 'encrypted 3 files');

        $lines = AuditLog::read();
        $this->assertCount(1, $lines);
        $this->assertStringContainsString('CONFIG', $lines[0]);
        $this->assertStringContainsString('encrypt', $lines[0]);
    }

    public function testLogDockerWritesEntry(): void
    {
        AuditLog::logDocker('/opt/myapp', 'rebuild', 'image=app:latest');

        $lines = AuditLog::read();
        $this->assertCount(1, $lines);
        $this->assertStringContainsString('DOCKER', $lines[0]);
    }

    public function testLogShadowWritesEntry(): void
    {
        AuditLog::logShadow('/opt/myapp', 'promote', 'blue', 'v2.0.0');

        $lines = AuditLog::read();
        $this->assertCount(1, $lines);
        $this->assertStringContainsString('SHADOW', $lines[0]);
        $this->assertStringContainsString('blue', $lines[0]);
        $this->assertStringContainsString('v2.0.0', $lines[0]);
    }

    public function testMultipleEntriesAppend(): void
    {
        AuditLog::logDeploy('/opt/app', 'v1', 'v2');
        AuditLog::logDeploy('/opt/app', 'v2', 'v3');
        AuditLog::logRollback('/opt/app', 'v3', 'v2');

        $lines = AuditLog::read();
        $this->assertCount(3, $lines);
        $this->assertStringContainsString('DEPLOY', $lines[0]);
        $this->assertStringContainsString('DEPLOY', $lines[1]);
        $this->assertStringContainsString('ROLLBACK', $lines[2]);
    }

    public function testReadLimitReturnsLatestEntries(): void
    {
        for ($i = 0; $i < 10; $i++) {
            AuditLog::logDeploy('/opt/app', "v{$i}", 'v' . ($i + 1));
        }

        $lines = AuditLog::read(3);
        $this->assertCount(3, $lines);
        // Should be the last 3 entries
        $this->assertStringContainsString('v7', $lines[0]);
        $this->assertStringContainsString('v8', $lines[1]);
        $this->assertStringContainsString('v9', $lines[2]);
    }

    public function testReadReturnsEmptyArrayWhenNoLog(): void
    {
        // Delete log file if it was created by logPath()
        $path = AuditLog::logPath();
        if (is_file($path)) unlink($path);

        $lines = AuditLog::read();
        $this->assertSame([], $lines);
    }

    public function testLogEntryEscapesSpecialCharacters(): void
    {
        AuditLog::logDeploy('/opt/my app', "v1.0; rm -rf /", 'v2.0');

        $lines = AuditLog::read();
        $this->assertCount(1, $lines);
        // escapeshellarg wraps in single quotes
        $this->assertStringContainsString("'v1.0; rm -rf /'", $lines[0]);
    }

    public function testLogDeployRecordsFailureStatus(): void
    {
        AuditLog::logDeploy('/opt/app', 'v1', 'v2', 'failure');

        $lines = AuditLog::read();
        $this->assertStringContainsString("status='failure'", $lines[0]);
    }

    public function testLogDeployRecordsUser(): void
    {
        AuditLog::logDeploy('/opt/app', 'v1', 'v2');

        $lines = AuditLog::read();
        $this->assertStringContainsString('user=', $lines[0]);
    }

    public function testLogDeployRecordsScope(): void
    {
        AuditLog::logDeploy('/opt/app', 'v1', 'v2', 'success', 'node-1');

        $lines = AuditLog::read();
        $this->assertStringContainsString("scope='node-1'", $lines[0]);
    }
}
