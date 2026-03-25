<?php
/**
 * MIT License
 * Copyright (c) 2019 Merchant Protocol, LLC (https://merchantprotocol.com/)
 */
namespace Gitcd\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Gitcd\Helpers\Shell;
use Gitcd\Helpers\Dir;
use Gitcd\Helpers\Git;
use Gitcd\Helpers\Docker;
use Gitcd\Utils\Json;

class ProtocolOpen extends Command
{
    protected static $defaultName = 'open';
    protected static $defaultDescription = 'Open the current project in the browser';

    protected function configure(): void
    {
        $this
            ->setHelp('Detects the running container ports and opens the project URL in your default browser.')
            ->addOption('dir', 'd', InputOption::VALUE_OPTIONAL, 'Directory Path', Git::getGitLocalFolder());
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $repo_dir = Dir::realpath($input->getOption('dir'));
        Git::checkInitializedRepo($output, $repo_dir);

        // Get container names for this project
        $containerNames = Docker::getContainerNamesFromDockerComposeFile($repo_dir);
        if (empty($containerNames)) {
            $output->writeln('  <fg=red>No containers defined in docker-compose.yml</>');
            return Command::FAILURE;
        }

        // Find the first running container and its ports
        $url = null;
        foreach ($containerNames as $name) {
            $portsRaw = trim(Shell::run("docker port " . escapeshellarg($name) . " 2>/dev/null"));
            if (empty($portsRaw)) {
                continue;
            }

            $httpsPort = null;
            $httpPort = null;

            foreach (explode("\n", $portsRaw) as $line) {
                // Format: "443/tcp -> 0.0.0.0:443" or "80/tcp -> 0.0.0.0:10080"
                if (preg_match('/^(\d+)\/tcp\s+->\s+\d+\.\d+\.\d+\.\d+:(\d+)/', $line, $m)) {
                    $containerPort = (int) $m[1];
                    $hostPort = (int) $m[2];

                    if ($containerPort === 443) {
                        $httpsPort = $hostPort;
                    } elseif ($containerPort === 80) {
                        $httpPort = $hostPort;
                    }
                }
            }

            // Prefer HTTPS, fall back to HTTP
            if ($httpsPort) {
                $url = ($httpsPort === 443)
                    ? "https://localhost"
                    : "https://localhost:{$httpsPort}";
            } elseif ($httpPort) {
                $url = ($httpPort === 80)
                    ? "http://localhost"
                    : "http://localhost:{$httpPort}";
            }

            if ($url) {
                break;
            }
        }

        if (!$url) {
            $output->writeln('  <fg=red>No running container with HTTP/HTTPS ports found.</>');
            $output->writeln('  <fg=gray>Is the container running? Try: protocol start</>');
            return Command::FAILURE;
        }

        $output->writeln("  Opening <fg=cyan>{$url}</>");

        // Open in default browser (macOS / Linux)
        if (PHP_OS_FAMILY === 'Darwin') {
            Shell::run("open " . escapeshellarg($url));
        } elseif (PHP_OS_FAMILY === 'Linux') {
            Shell::run("xdg-open " . escapeshellarg($url) . " 2>/dev/null &");
        } else {
            $output->writeln("  <fg=yellow>Auto-open not supported on this OS. Visit: {$url}</>");
        }

        return Command::SUCCESS;
    }
}
