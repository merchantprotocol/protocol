<?php
/**
 * Port Conflict Detection for Local Development.
 *
 * When strategy is "none" (local dev), detects port conflicts with other
 * running Docker containers before starting. Offers to remap to unused
 * ports and generates a docker-compose override file.
 *
 * Preferences stored in protocol.json under docker.port_conflict:
 *   "ask"   — prompt the user each time (default)
 *   "auto"  — always remap conflicting ports without asking
 *   "fail"  — abort on conflict
 *   "ignore" — start anyway, let docker compose fail naturally
 *
 * MIT License
 * Copyright (c) 2019 Merchant Protocol, LLC (https://merchantprotocol.com/)
 */
namespace Gitcd\Helpers;

use Gitcd\Utils\Yaml;

class PortConflict
{
    /**
     * Parse host port mappings from docker-compose.yml.
     *
     * Returns array of:
     *   ['host' => int, 'container' => int, 'service' => string, 'original' => string]
     *
     * @return array[]
     */
    public static function getPortsFromCompose(string $repoDir): array
    {
        $services = Yaml::read('services', null, $repoDir);
        if (!is_array($services)) {
            return [];
        }

        $ports = [];
        foreach ($services as $serviceName => $config) {
            if (empty($config['ports']) || !is_array($config['ports'])) {
                continue;
            }
            foreach ($config['ports'] as $portSpec) {
                $parsed = self::parsePortSpec((string) $portSpec, $repoDir);
                if ($parsed) {
                    $parsed['service'] = $serviceName;
                    $parsed['original'] = (string) $portSpec;
                    $ports[] = $parsed;
                }
            }
        }

        return $ports;
    }

    /**
     * Parse a single port spec string into host/container ports.
     *
     * Handles: "80:80", "8080:80", "127.0.0.1:80:80", "80:80/tcp",
     * "${VAR:-80}:80", and plain "80" (host+container same).
     *
     * @return array|null  ['host' => int, 'container' => int] or null if unparseable
     */
    private static function parsePortSpec(string $spec, string $repoDir): ?array
    {
        // Resolve env vars first
        $spec = Docker::resolveEnvVars($spec, $repoDir);

        // Strip protocol suffix (/tcp, /udp)
        $spec = preg_replace('#/(tcp|udp)$#', '', $spec);

        $parts = explode(':', $spec);

        if (count($parts) === 1) {
            // "80" — same host and container
            $port = (int) $parts[0];
            return $port > 0 ? ['host' => $port, 'container' => $port] : null;
        }

        if (count($parts) === 2) {
            // "8080:80"
            $host = (int) $parts[0];
            $container = (int) $parts[1];
            return ($host > 0 && $container > 0) ? ['host' => $host, 'container' => $container] : null;
        }

        if (count($parts) === 3) {
            // "127.0.0.1:80:80"
            $host = (int) $parts[1];
            $container = (int) $parts[2];
            return ($host > 0 && $container > 0) ? ['host' => $host, 'container' => $container] : null;
        }

        return null;
    }

    /**
     * Get all host ports occupied by running Docker containers.
     *
     * @param string[] $excludeNames  Container names to exclude (current project)
     * @return array[]  [['port' => int, 'container' => string], ...]
     */
    public static function getOccupiedPorts(array $excludeNames = []): array
    {
        $raw = trim(Shell::run("docker ps --format '{{.Names}}\t{{.Ports}}' 2>/dev/null"));
        if (empty($raw)) {
            return [];
        }

        $occupied = [];
        foreach (explode("\n", $raw) as $line) {
            $parts = explode("\t", $line, 2);
            if (count($parts) < 2) continue;

            [$name, $portsStr] = $parts;

            if (in_array($name, $excludeNames, true)) {
                continue;
            }

            // Ports format: "0.0.0.0:80->80/tcp, [::]:80->80/tcp"
            preg_match_all('/(?:\d+\.\d+\.\d+\.\d+|\[::]):(\d+)->/', $portsStr, $matches);
            if (!empty($matches[1])) {
                foreach ($matches[1] as $port) {
                    $occupied[] = [
                        'port' => (int) $port,
                        'container' => $name,
                    ];
                }
            }
        }

        return $occupied;
    }

    /**
     * Detect port conflicts between the compose file and running containers.
     *
     * @return array[]  [['port' => int, 'service' => string, 'blocked_by' => string], ...]
     */
    public static function detectConflicts(string $repoDir): array
    {
        $desiredPorts = self::getPortsFromCompose($repoDir);
        if (empty($desiredPorts)) {
            Log::debug('port-conflict', "No ports defined in compose file");
            return [];
        }

        // Exclude current project's containers from conflict detection
        $ownContainers = Docker::getContainerNamesFromDockerComposeFile($repoDir);
        $occupied = self::getOccupiedPorts($ownContainers);

        Log::debug('port-conflict', "desired ports: " . implode(', ', array_column($desiredPorts, 'host'))
            . " | occupied ports: " . implode(', ', array_column($occupied, 'port'))
            . " | excluding: " . implode(', ', $ownContainers));

        $occupiedMap = [];
        foreach ($occupied as $entry) {
            $occupiedMap[$entry['port']][] = $entry['container'];
        }

        $conflicts = [];
        foreach ($desiredPorts as $desired) {
            if (isset($occupiedMap[$desired['host']])) {
                $conflicts[] = [
                    'port' => $desired['host'],
                    'container_port' => $desired['container'],
                    'service' => $desired['service'],
                    'blocked_by' => implode(', ', $occupiedMap[$desired['host']]),
                ];
            }
        }

        Log::debug('port-conflict', count($conflicts) . " conflicts detected");
        return $conflicts;
    }

    /**
     * Find an unused alternative port.
     *
     * Starts at originalPort + 10000 (e.g., 80 → 10080) then increments.
     *
     * @param int   $originalPort
     * @param int[] $avoid  Ports to avoid (already allocated alternatives)
     * @return int
     */
    public static function findAlternativePort(int $originalPort, array $avoid = []): int
    {
        $candidate = $originalPort + 10000;
        $maxAttempts = 100;
        $allOccupied = array_column(self::getOccupiedPorts(), 'port');
        $unavailable = array_merge($allOccupied, $avoid);

        for ($i = 0; $i < $maxAttempts; $i++) {
            if (!in_array($candidate, $unavailable, true) && !self::isPortInUse($candidate)) {
                return $candidate;
            }
            $candidate++;
        }

        // Fallback: random high port
        return random_int(30000, 60000);
    }

    /**
     * Check if a port is in use on the host (works on macOS and Linux).
     */
    private static function isPortInUse(int $port): bool
    {
        // Try lsof first (macOS + Linux)
        $result = trim(Shell::run("lsof -i :{$port} -sTCP:LISTEN 2>/dev/null | head -2"));
        if (!empty($result) && stripos($result, 'COMMAND') !== false) {
            // Has header + at least one process
            $lines = explode("\n", $result);
            return count($lines) > 1;
        }

        // Fallback: try ss (Linux only)
        $result = trim(Shell::run("ss -tlnp 'sport = :{$port}' 2>/dev/null | tail -n +2"));
        return !empty($result);
    }

    /**
     * Suggest alternative ports for each conflict.
     *
     * @param array[] $conflicts  From detectConflicts()
     * @return array  [originalPort => alternativePort, ...]
     */
    public static function suggestAlternatives(array $conflicts): array
    {
        $alternatives = [];
        $allocated = [];
        foreach ($conflicts as $conflict) {
            $alt = self::findAlternativePort($conflict['port'], $allocated);
            $alternatives[$conflict['port']] = $alt;
            $allocated[] = $alt;
        }
        return $alternatives;
    }

    /**
     * Generate a docker-compose override file that remaps conflicting ports.
     *
     * @param string $repoDir
     * @param array  $remappings  [originalHostPort => newHostPort, ...]
     * @return string  Path to the generated override file
     */
    public static function generateOverrideFile(string $repoDir, array $remappings): string
    {
        $services = Yaml::read('services', null, $repoDir);
        $override = ['services' => []];

        foreach ($services as $serviceName => $config) {
            if (empty($config['ports']) || !is_array($config['ports'])) {
                continue;
            }

            $newPorts = [];
            $changed = false;
            foreach ($config['ports'] as $portSpec) {
                $parsed = self::parsePortSpec((string) $portSpec, $repoDir);
                if ($parsed && isset($remappings[$parsed['host']])) {
                    $newPort = $remappings[$parsed['host']];
                    $newPorts[] = "{$newPort}:{$parsed['container']}";
                    $changed = true;
                } else {
                    $newPorts[] = (string) $portSpec;
                }
            }

            if ($changed) {
                $override['services'][$serviceName] = ['ports' => $newPorts];
            }
        }

        $overridePath = rtrim($repoDir, '/') . '/docker-compose.port-override.yml';
        $yaml = \Symfony\Component\Yaml\Yaml::dump($override, 4, 2);
        file_put_contents($overridePath, $yaml);

        Log::info('port-conflict', "Generated override file: {$overridePath}");
        return $overridePath;
    }

    /**
     * Get the stored preference for port conflict handling.
     *
     * @return string  "ask", "auto", "fail", or "ignore"
     */
    public static function getPreference(string $repoDir): string
    {
        $prefs = self::readPreferences($repoDir);
        $pref = $prefs['docker']['port_conflict'] ?? null;
        if (in_array($pref, ['ask', 'auto', 'fail', 'ignore'], true)) {
            return $pref;
        }
        return 'ask';
    }

    /**
     * Save the port conflict preference to .protocol/preferences.json.
     */
    public static function setPreference(string $repoDir, string $preference): void
    {
        $prefs = self::readPreferences($repoDir);
        $prefs['docker']['port_conflict'] = $preference;
        self::writePreferences($repoDir, $prefs);
        Log::info('port-conflict', "preference set to '{$preference}'");
    }

    // ─── .protocol/preferences.json helpers ──────────────────

    private static function readPreferences(string $repoDir): array
    {
        $file = rtrim($repoDir, '/') . '/.protocol/preferences.json';
        if (!is_file($file)) {
            return [];
        }
        $data = json_decode(file_get_contents($file), true);
        return is_array($data) ? $data : [];
    }

    private static function writePreferences(string $repoDir, array $data): void
    {
        $dir = rtrim($repoDir, '/') . '/.protocol';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        file_put_contents(
            $dir . '/preferences.json',
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
            LOCK_EX
        );
    }

    /**
     * Prompt the user to resolve port conflicts.
     *
     * @param array  $conflicts     From detectConflicts()
     * @param array  $alternatives  From suggestAlternatives() [original => new]
     * @param \Symfony\Component\Console\Input\InputInterface  $input
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     * @param string $repoDir
     * @return string|null  "remap", "force", or null (abort)
     */
    public static function promptUser(
        array $conflicts,
        array $alternatives,
        $input,
        $output,
        string $repoDir
    ): ?string {
        $preference = self::getPreference($repoDir);

        if ($preference === 'auto') {
            return 'remap';
        }
        if ($preference === 'ignore') {
            return 'force';
        }
        if ($preference === 'fail') {
            return null;
        }

        // preference === 'ask'
        $output->writeln('');
        $output->writeln('  <fg=yellow>Port conflicts detected:</>');
        foreach ($conflicts as $c) {
            $alt = $alternatives[$c['port']] ?? '?';
            $output->writeln("    Port <fg=white>{$c['port']}</> is blocked by <fg=cyan>{$c['blocked_by']}</> → suggested: <fg=green>{$alt}</>");
        }
        $output->writeln('');

        $portSummary = [];
        foreach ($alternatives as $old => $new) {
            $portSummary[] = "{$old} → {$new}";
        }
        $remapLabel = 'Use alternative ports (' . implode(', ', $portSummary) . ')';

        $choices = [
            'remap'        => $remapLabel,
            'force'        => 'Try to start anyway (may fail)',
            'abort'        => 'Abort startup',
            'always_remap' => 'Always remap conflicting ports (remember)',
            'always_ignore'=> 'Always ignore conflicts (remember)',
        ];

        $helper = new \Symfony\Component\Console\Helper\QuestionHelper();
        $question = new \Symfony\Component\Console\Question\ChoiceQuestion(
            '  How would you like to handle port conflicts?',
            array_values($choices),
            0
        );
        $question->setErrorMessage('Invalid choice: %s');

        $answer = $helper->ask($input, $output, $question);
        $selected = array_search($answer, $choices, true);

        // Save remembered preferences
        if ($selected === 'always_remap') {
            self::setPreference($repoDir, 'auto');
            $selected = 'remap';
        } elseif ($selected === 'always_ignore') {
            self::setPreference($repoDir, 'ignore');
            $selected = 'force';
        }

        $output->writeln('');

        if ($selected === 'abort') {
            return null;
        }

        return $selected;
    }
}
