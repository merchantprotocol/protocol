<?php
/**
 * Health Checker.
 *
 * Runs HTTP and exec-based health checks against release containers
 * with retry logic.
 *
 * MIT License
 * Copyright (c) 2019 Merchant Protocol, LLC (https://merchantprotocol.com/)
 */
namespace Gitcd\Helpers\BlueGreen;

use Gitcd\Helpers\Shell;
use Gitcd\Helpers\BlueGreen;
use Gitcd\Helpers\ContainerName;
use Gitcd\Helpers\Log;

class HealthChecker
{
    /**
     * Run health checks against a release on the given HTTP port.
     */
    public static function runHealthChecks(string $repo_dir, int $httpPort, array $healthChecks, string $version = ''): bool
    {
        if (empty($healthChecks)) {
            Log::debug('healthcheck', "no health checks configured, skipping");
            return true;
        }

        sleep(2);
        $maxRetries = 3;
        $totalChecks = count($healthChecks);

        Log::context('healthcheck', [
            'version' => $version,
            'port'    => $httpPort,
            'checks'  => $totalChecks,
        ]);

        foreach ($healthChecks as $index => $check) {
            $type = $check['type'] ?? 'http';
            $passed = false;

            for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
                if ($type === 'http') {
                    $path = ltrim($check['path'] ?? '/health', '/');
                    $expectStatus = (int) ($check['expect_status'] ?? 200);
                    $timeout = (int) ($check['timeout'] ?? 10);

                    $result = Shell::run(
                        "curl -s -o /dev/null -w '%{http_code}' --max-time {$timeout} http://127.0.0.1:{$httpPort}/{$path} 2>/dev/null"
                    );
                    $statusCode = (int) trim($result);

                    if ($statusCode === $expectStatus) {
                        $passed = true;
                        break;
                    }

                    Log::debug('healthcheck', "http /{$path} attempt {$attempt}/{$maxRetries}: got {$statusCode}, expected {$expectStatus}");

                } elseif ($type === 'exec') {
                    $command = $check['command'] ?? '';
                    $expectExit = (int) ($check['expect_exit'] ?? 0);
                    $releaseDir = BlueGreen::getReleaseDir($repo_dir, $version);
                    $containerName = ContainerName::resolveFromDir($releaseDir);

                    if ($containerName && $command) {
                        // Validate command contains only safe characters
                        if (!preg_match('/^[a-zA-Z0-9\s\-_\/.:=]+$/', $command)) {
                            Log::warn('healthcheck', "exec check rejected — unsafe characters in command");
                            continue;
                        }
                        Shell::run("docker exec " . escapeshellarg($containerName) . " sh -c " . escapeshellarg($command) . " 2>&1", $returnVar);
                        if ($returnVar === $expectExit) {
                            $passed = true;
                            break;
                        }

                        Log::debug('healthcheck', "exec '{$command}' attempt {$attempt}/{$maxRetries}: got exit={$returnVar}, expected {$expectExit}");
                    }
                }

                if ($attempt < $maxRetries) {
                    sleep(3);
                }
            }

            if (!$passed) {
                $checkDesc = $type === 'http' ? "http /{$path}" : "exec '{$command}'";
                Log::error('healthcheck', "check " . ($index + 1) . "/{$totalChecks} FAILED after {$maxRetries} attempts: {$checkDesc}");
                return false;
            }
        }

        Log::info('healthcheck', "all {$totalChecks} check(s) passed for {$version}");
        return true;
    }
}
