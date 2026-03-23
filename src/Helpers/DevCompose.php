<?php
/**
 * Dev Compose File Management.
 *
 * Handles detection, preference storage, and execution of dev-specific
 * docker-compose files (docker-compose-dev.yml, docker-compose.dev.yml, etc.)
 * for the "none" deployment strategy (local development).
 *
 * Preferences are stored in protocol.json under docker.dev_compose:
 *   "always" — always start/stop dev services without asking
 *   "never"  — never start/stop dev services
 *   "ask"    — prompt the user each time (default when not set)
 *
 * MIT License
 * Copyright (c) 2019 Merchant Protocol, LLC (https://merchantprotocol.com/)
 */
namespace Gitcd\Helpers;

use Gitcd\Utils\Yaml;

class DevCompose
{
    /**
     * Known dev compose file patterns, checked in order.
     */
    private const DEV_COMPOSE_FILES = [
        'docker-compose-dev.yml',
        'docker-compose-dev.yaml',
        'docker-compose.dev.yml',
        'docker-compose.dev.yaml',
    ];

    /**
     * Find the dev compose file in a directory, if one exists.
     *
     * @return string|null  Full path to the dev compose file, or null
     */
    public static function find(string $repoDir): ?string
    {
        $dir = rtrim($repoDir, '/');
        foreach (self::DEV_COMPOSE_FILES as $filename) {
            $path = $dir . '/' . $filename;
            if (is_file($path)) {
                return $path;
            }
        }
        return null;
    }

    /**
     * Get the stored preference for dev compose handling.
     *
     * @return string  "always", "never", or "ask"
     */
    public static function getPreference(string $repoDir): string
    {
        $prefs = self::readPreferences($repoDir);
        $pref = $prefs['docker']['dev_compose'] ?? null;
        if (in_array($pref, ['always', 'never', 'ask'], true)) {
            return $pref;
        }
        return 'ask';
    }

    /**
     * Save the dev compose preference to .protocol/preferences.json.
     */
    public static function setPreference(string $repoDir, string $preference): void
    {
        $prefs = self::readPreferences($repoDir);
        $prefs['docker']['dev_compose'] = $preference;
        self::writePreferences($repoDir, $prefs);
        Log::info('dev-compose', "preference set to '{$preference}'");
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
     * Get container names from the dev compose file.
     *
     * @return string[]
     */
    public static function getContainerNames(string $devComposePath): array
    {
        $content = file_get_contents($devComposePath);
        $parsed = \Symfony\Component\Yaml\Yaml::parse($content);
        if (!is_array($parsed) || empty($parsed['services'])) {
            return [];
        }

        $names = [];
        foreach ($parsed['services'] as $serviceName => $config) {
            if (!empty($config['container_name'])) {
                $names[] = $config['container_name'];
            } else {
                $names[] = $serviceName;
            }
        }
        return $names;
    }

    /**
     * Check if any containers from the dev compose file are running.
     *
     * @return string[]  Names of running dev containers
     */
    public static function getRunningContainers(string $devComposePath): array
    {
        $names = self::getContainerNames($devComposePath);
        $running = [];
        foreach ($names as $name) {
            if (Docker::isDockerContainerRunning($name)) {
                $running[] = $name;
            }
        }
        return $running;
    }

    /**
     * Start dev compose services.
     */
    public static function start(string $repoDir, string $devComposePath): string
    {
        $dockerCommand = Docker::getDockerCommand();
        $result = Shell::run(
            "cd " . escapeshellarg($repoDir)
            . " && {$dockerCommand} -f " . escapeshellarg($devComposePath)
            . " up -d 2>&1"
        );
        Log::info('dev-compose', "started: " . trim($result));
        return $result;
    }

    /**
     * Stop dev compose services.
     */
    public static function stop(string $repoDir, string $devComposePath): string
    {
        $dockerCommand = Docker::getDockerCommand();
        $result = Shell::run(
            "cd " . escapeshellarg($repoDir)
            . " && {$dockerCommand} -f " . escapeshellarg($devComposePath)
            . " down 2>&1"
        );
        Log::info('dev-compose', "stopped: " . trim($result));
        return $result;
    }

    /**
     * Determine if dev compose should be acted on, prompting if needed.
     *
     * @param string $repoDir
     * @param string $action  "start" or "stop"
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     * @param string $devComposePath
     * @return bool  True if the action should proceed
     */
    public static function shouldAct(
        string $repoDir,
        string $action,
        $input,
        $output,
        string $devComposePath
    ): bool {
        $preference = self::getPreference($repoDir);

        if ($preference === 'always') {
            return true;
        }
        if ($preference === 'never') {
            return false;
        }

        // preference === 'ask' — prompt the user
        $names = self::getContainerNames($devComposePath);
        $serviceList = implode(', ', $names);
        $filename = basename($devComposePath);

        $output->writeln('');
        $output->writeln("  <fg=cyan>Dev services detected</> in <fg=white>{$filename}</>: <fg=yellow>{$serviceList}</>");

        $choices = [
            'yes'    => "Yes, {$action} dev services this time",
            'no'     => "No, skip dev services this time",
            'always' => "Always {$action} dev services (remember)",
            'never'  => "Never {$action} dev services (remember)",
        ];

        $helper = new \Symfony\Component\Console\Helper\QuestionHelper();
        $question = new \Symfony\Component\Console\Question\ChoiceQuestion(
            "  {$action} dev services?",
            array_values($choices),
            0
        );
        $question->setErrorMessage('Invalid choice: %s');

        $answer = $helper->ask($input, $output, $question);

        // Map display text back to key
        $selected = array_search($answer, $choices, true);

        if ($selected === 'always' || $selected === 'never') {
            self::setPreference($repoDir, $selected);
        }

        $output->writeln('');

        return in_array($selected, ['yes', 'always'], true);
    }
}
