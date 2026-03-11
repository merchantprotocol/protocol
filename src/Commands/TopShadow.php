<?php
/**
 * NOTICE OF LICENSE
 *
 * MIT License
 *
 * Copyright (c) 2019 Merchant Protocol
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 *
 *
 * @category   merchantprotocol
 * @package    merchantprotocol/protocol
 * @copyright  Copyright (c) 2019 Merchant Protocol, LLC (https://merchantprotocol.com/)
 * @license    MIT License
 */
namespace Gitcd\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Gitcd\Helpers\Shell;
use Gitcd\Helpers\Docker;
use Gitcd\Helpers\BlueGreen;
use Gitcd\Helpers\Dir;
use Gitcd\Helpers\Git;
use Gitcd\Helpers\AnsiColors as C;
use Gitcd\Helpers\DashboardFormatter as Fmt;
use Gitcd\Utils\Json;
use Gitcd\Utils\JsonLock;

Class TopShadow extends Command {

    protected static $defaultName = 'top:shadow';
    protected static $defaultDescription = 'Visual dashboard of all Docker containers and shadow deployments';

    private int $pw = 98;
    private int $boxW = 46; // width of each container box

    protected function configure(): void
    {
        $this
            ->setHelp('Visual container dashboard showing all Docker services and shadow deployments. Press Ctrl+C to exit.')
            ->addOption('interval', 'i', InputOption::VALUE_OPTIONAL, 'Refresh interval in seconds', 5)
            ->addOption('once', null, InputOption::VALUE_NONE, 'Run once and exit')
            ->addOption('dir', 'd', InputOption::VALUE_OPTIONAL, 'Base directory to scan', null)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $interval = max(1, (int)$input->getOption('interval'));
        $once = $input->getOption('once');
        $baseDir = $input->getOption('dir') ?: dirname(Git::getGitLocalFolder() ?: getcwd());

        $enterAlt = "\033[?1049h\033[?25l";
        $exitAlt  = "\033[?25h\033[?1049l";

        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGINT, function() use ($exitAlt) { echo $exitAlt; exit(0); });
            pcntl_signal(SIGTERM, function() use ($exitAlt) { echo $exitAlt; exit(0); });
        }

        echo $enterAlt;

        do {
            $w = (int)Shell::run('tput cols 2>/dev/null') ?: 100;
            $rows = (int)Shell::run('tput lines 2>/dev/null') ?: 40;
            $this->pw = min($w - 2, 98);
            $this->boxW = (int)floor(($this->pw - 3) / 2); // two boxes per row with gap

            echo "\033[2J\033[H";
            $this->render($baseDir, $rows);

            if ($once) break;

            for ($i = 0; $i < $interval; $i++) {
                if (function_exists('pcntl_signal_dispatch')) pcntl_signal_dispatch();
                sleep(1);
            }
        } while (true);

        echo $exitAlt;
        return Command::SUCCESS;
    }

    private function render(string $baseDir, int $rows): void
    {
        $pw = $this->pw;
        $x = C::X;
        $o = C::B;

        // ── Chrome header
        $hostname = trim(Shell::run('hostname -s 2>/dev/null') ?: Shell::run('hostname 2>/dev/null')) ?: 'server';
        $now = date('Y-m-d H:i:s');
        $hostTrunc = Fmt::trunc($hostname, 20);

        Fmt::w("{$o}╭" . str_repeat('─', $pw) . "╮{$x}");

        $titleVis = "protocol top:shadow — {$hostTrunc}";
        $innerW = $pw - 2;
        $usedChars = 5 + 3 + strlen($titleVis) + strlen($now);
        $pad = max(1, $innerW - $usedChars);

        $dots = C::R . "●" . $x . " " . C::Y . "●" . $x . " " . C::GN . "●" . $x;
        $titlePart = C::BL . "protocol top:shadow" . $x . C::D . " — {$hostTrunc}" . $x;
        $timePart = C::D . $now . $x;
        Fmt::w("{$o}│{$x} {$dots}   {$titlePart}" . str_repeat(' ', $pad) . "{$timePart} {$o}│{$x}");

        $brandVis = " MERCHANT PROTOCOL  container dashboard";
        $usedBrand = strlen($brandVis);
        $bPad = max(1, $innerW - $usedBrand);
        $brand = C::G . C::BD . " MERCHANT PROTOCOL" . $x . "  " . C::D . "container dashboard" . $x;
        Fmt::w("{$o}│{$x}{$brand}" . str_repeat(' ', $bPad) . " {$o}│{$x}");

        Fmt::w("{$o}├" . str_repeat('─', $pw) . "┤{$x}");
        $linesUsed = 4;

        // ── Discover all projects with docker-compose.yml
        $projects = $this->discoverProjects($baseDir);

        // ── Get running docker containers for status checks
        $runningContainers = $this->getRunningContainers();

        // ── Check for shadow deployment info
        $repoDir = Git::getGitLocalFolder() ?: $baseDir;
        $shadowEnabled = BlueGreen::isEnabled($repoDir);
        $activeVersion = $shadowEnabled ? BlueGreen::getActiveVersion($repoDir) : null;
        $previousVersion = $shadowEnabled ? BlueGreen::getPreviousVersion($repoDir) : null;
        $shadowVersion = $shadowEnabled ? BlueGreen::getShadowVersion($repoDir) : null;
        $releases = $shadowEnabled ? BlueGreen::listReleases($repoDir) : [];

        // ── Shadow status summary (if enabled)
        if ($shadowEnabled) {
            Fmt::sec('SHADOW DEPLOY', $this->pw);
            $linesUsed++;

            $activeStr = $activeVersion
                ? C::GN . "●" . $x . " " . C::G . C::BD . $activeVersion . $x . C::D . " active" . $x
                : C::D . "no active version" . $x;
            $prevStr = $previousVersion
                ? C::Y . "●" . $x . " " . C::M . $previousVersion . $x . C::D . " rollback" . $x
                : '';
            $shadStr = $shadowVersion
                ? C::BL . "●" . $x . " " . C::BL . $shadowVersion . $x . C::D . " shadow" . $x
                : '';

            $parts = array_filter([$activeStr, $prevStr, $shadStr]);
            Fmt::ln(implode("    ", $parts));
            $linesUsed++;
        }

        // ── Container boxes
        Fmt::sec('CONTAINERS', $this->pw);
        $linesUsed++;

        if (empty($projects)) {
            Fmt::ln(C::D . "No docker-compose projects found in " . Fmt::trunc($baseDir, 50) . $x);
            $linesUsed++;
        } else {
            // Build container info for each project
            $boxes = [];
            foreach ($projects as $proj) {
                $boxes[] = $this->buildBoxData($proj, $runningContainers, $activeVersion);
            }

            // Render boxes two per row
            $bw = $this->boxW;
            $chunks = array_chunk($boxes, 2);
            foreach ($chunks as $pair) {
                if ($linesUsed + 8 > $rows - 2) break; // leave room for footer

                $left = $pair[0];
                $right = $pair[1] ?? null;

                $leftLines = $this->renderBox($left, $bw);
                $rightLines = $right ? $this->renderBox($right, $bw) : [];

                $maxH = max(count($leftLines), count($rightLines));
                for ($i = 0; $i < $maxH; $i++) {
                    $l = $leftLines[$i] ?? Fmt::pad('', $bw);
                    $r = $rightLines[$i] ?? '';
                    Fmt::ln($l . " " . $r);
                    $linesUsed++;
                }

                // Gap between rows of boxes
                if ($linesUsed < $rows - 3) {
                    Fmt::ln('');
                    $linesUsed++;
                }
            }
        }

        // ── Release directories (if shadow enabled and releases exist)
        if ($shadowEnabled && !empty($releases) && $linesUsed + 4 < $rows - 2) {
            Fmt::sec('RELEASES', $this->pw);
            $linesUsed++;

            foreach ($releases as $release) {
                if ($linesUsed + 2 > $rows - 2) break;

                $state = BlueGreen::getReleaseState($repoDir, $release);
                $version = $state['version'] ?? $release;
                $port = $state['port'] ?? '-';
                $status = $state['status'] ?? 'unknown';
                $running = BlueGreen::isReleaseRunning($repoDir, $version);

                $ico = $running ? C::GN . "●" . $x : C::R . "●" . $x;
                $isActive = ($version === $activeVersion);

                $tag = '';
                if ($isActive) $tag = C::G . C::BD . " ACTIVE" . $x;
                elseif ($version === $previousVersion) $tag = C::Y . " ROLLBACK" . $x;
                elseif ($version === $shadowVersion) $tag = C::BL . " SHADOW" . $x;

                Fmt::ln(
                    "{$ico} "
                    . C::T . sprintf("%-16s", $version) . $x
                    . C::M . " :{$port}" . $x
                    . C::D . " {$status}" . $x
                    . ($running ? C::GN . " running" . $x : C::R . " stopped" . $x)
                    . $tag
                );
                $linesUsed++;
            }
        }

        // ── Footer
        Fmt::w("{$o}╰" . str_repeat('─', $pw) . "╯{$x}");
        echo " " . C::D . "ctrl+c exit" . $x
            . C::B . " · " . $x
            . C::D . "merchantprotocol.com" . $x;
    }

    // ── Project discovery ───────────────────────────────────────

    private function discoverProjects(string $baseDir): array
    {
        $baseDir = rtrim($baseDir, '/');
        $composeFiles = glob("{$baseDir}/*/docker-compose.yml");
        $projects = [];

        foreach ($composeFiles as $file) {
            $dir = dirname($file);
            $name = basename($dir);

            // Read container names from docker-compose.yml
            $containers = [];
            $image = null;
            $ports = [];
            $volumes = [];
            // Parse docker-compose.yml with regex (portable, no yaml extension needed)
            $content = file_get_contents($file);
            if (preg_match_all('/container_name:\s*(\S+)/', $content, $cm)) {
                $containers = $cm[1];
            }
            if (preg_match('/image:\s*(\S+)/', $content, $im)) {
                $image = $im[1];
            }
            if (preg_match_all('/-\s*"(\d+:\d+)"/', $content, $pm)) {
                $ports = $pm[1];
            }
            // Parse volumes from the volumes: section only
            if (preg_match('/volumes:\s*\n((?:\s+-\s+.+\n?)+)/', $content, $volBlock)) {
                if (preg_match_all('/-\s*"([^"]+)"/', $volBlock[1], $vm)) {
                    foreach ($vm[1] as $v) {
                        // Must have host:container format and not be a port mapping
                        if (preg_match('#^[./].*:.*$#', $v)) {
                            $volumes[] = $v;
                        }
                    }
                }
            }
            if (preg_match('/build:/', $content)) {
                $image = $image ?: '(build)';
            }

            // Read version from VERSION file or protocol.json
            $version = null;
            $versionFile = "{$dir}/VERSION";
            if (is_file($versionFile)) {
                $version = trim(file_get_contents($versionFile));
            }
            if (!$version) {
                $protocolJson = "{$dir}/protocol.json";
                if (is_file($protocolJson)) {
                    $pj = json_decode(file_get_contents($protocolJson), true);
                    $version = $pj['version'] ?? null;
                }
            }

            // Git branch
            $branch = null;
            if (is_dir("{$dir}/.git")) {
                $branch = trim(Shell::run("git -C " . escapeshellarg($dir) . " branch --show-current 2>/dev/null") ?: '');
            }

            $projects[] = [
                'name' => $name,
                'dir' => $dir,
                'containers' => $containers,
                'image' => $image,
                'ports' => $ports,
                'volumes' => $volumes,
                'version' => $version,
                'branch' => $branch,
            ];
        }

        return $projects;
    }

    private function getRunningContainers(): array
    {
        $result = Shell::run("docker ps --format '{{.Names}}|{{.Status}}|{{.Ports}}' 2>/dev/null");
        if (!$result) return [];

        $containers = [];
        foreach (array_filter(explode("\n", $result)) as $line) {
            $parts = explode('|', trim($line), 3);
            if (count($parts) >= 2) {
                $containers[$parts[0]] = [
                    'status' => $parts[1],
                    'ports' => $parts[2] ?? '',
                ];
            }
        }
        return $containers;
    }

    // ── Box building ────────────────────────────────────────────

    private function buildBoxData(array $proj, array $running, ?string $activeVersion): array
    {
        $isRunning = false;
        $containerStatus = '';
        $healthHint = '';

        foreach ($proj['containers'] as $cname) {
            if (isset($running[$cname])) {
                $isRunning = true;
                $containerStatus = $running[$cname]['status'];
                if (strpos($containerStatus, '(healthy)') !== false) {
                    $healthHint = 'healthy';
                } elseif (strpos($containerStatus, '(unhealthy)') !== false) {
                    $healthHint = 'unhealthy';
                } elseif (strpos($containerStatus, 'Up') !== false) {
                    $healthHint = 'up';
                }
                break;
            }
        }

        $isActive = false;
        if ($activeVersion && $proj['version'] && $proj['version'] === $activeVersion) {
            $isActive = true;
        }

        return [
            'name' => $proj['name'],
            'containers' => $proj['containers'],
            'running' => $isRunning,
            'status' => $containerStatus,
            'health' => $healthHint,
            'image' => $proj['image'],
            'ports' => $proj['ports'],
            'volumes' => $proj['volumes'],
            'version' => $proj['version'],
            'branch' => $proj['branch'],
            'dir' => $proj['dir'],
            'active' => $isActive,
        ];
    }

    private function renderBox(array $box, int $bw): array
    {
        $x = C::X;
        $lines = [];
        $innerW = $bw - 2;

        // Border color based on status
        $bc = $box['running'] ? C::GD : C::B;

        // Status indicator
        $statusDot = $box['running'] ? C::GN . "●" : C::R . "●";

        // ── Top border with name
        $nameDisplay = strtoupper($box['name']);
        if (mb_strwidth($nameDisplay) > $innerW - 4) {
            $nameDisplay = mb_strimwidth($nameDisplay, 0, $innerW - 4);
        }
        $topPad = max(0, $innerW - mb_strwidth($nameDisplay) - 2);
        $lines[] = $bc . "╭─" . $x . " " . C::T . C::BD . $nameDisplay . $x . " " . $bc . str_repeat('─', $topPad) . "╮" . $x;

        // ── Status line
        $statusText = $box['running'] ? 'RUNNING' : 'STOPPED';
        $statusClr = $box['running'] ? C::G : C::R;
        $healthSuffix = '';
        $healthVis = '';
        if ($box['health'] === 'healthy') {
            $healthSuffix = C::GN . " healthy" . $x;
            $healthVis = ' healthy';
        } elseif ($box['health'] === 'unhealthy') {
            $healthSuffix = C::R . " unhealthy" . $x;
            $healthVis = ' unhealthy';
        }
        $activeTag = $box['active'] ? C::G . C::BD . " [ACTIVE]" . $x : '';
        $activeVis = $box['active'] ? ' [ACTIVE]' : '';

        $statusVisLen = 4 + mb_strwidth($statusText) + mb_strwidth($healthVis) + mb_strwidth($activeVis);
        $lines[] = $bc . "│" . $x
            . "  " . $statusDot . $x . " " . $statusClr . $statusText . $x . $healthSuffix . $activeTag
            . str_repeat(' ', max(1, $innerW - $statusVisLen))
            . $bc . "│" . $x;

        // ── Version + branch
        $vStr = $box['version'] ? "v{$box['version']}" : '';
        $bStr = $box['branch'] ? ":{$box['branch']}" : '';
        if ($vStr || $bStr) {
            $vbPad = max(1, $innerW - 2 - mb_strwidth($vStr) - mb_strwidth($bStr));
            $lines[] = $bc . "│" . $x
                . "  " . C::BL . $vStr . $x . C::D . $bStr . $x
                . str_repeat(' ', $vbPad)
                . $bc . "│" . $x;
        }

        // ── Image
        if ($box['image']) {
            $imgTrunc = Fmt::trunc($box['image'], $innerW - 4);
            $imgPad = max(1, $innerW - 2 - mb_strwidth($imgTrunc));
            $lines[] = $bc . "│" . $x
                . "  " . C::M . $imgTrunc . $x
                . str_repeat(' ', $imgPad)
                . $bc . "│" . $x;
        }

        // ── Ports
        if (!empty($box['ports'])) {
            $portStr = implode(' ', array_slice($box['ports'], 0, 3));
            $portTrunc = Fmt::trunc($portStr, $innerW - 4);
            $portPad = max(1, $innerW - 2 - mb_strwidth($portTrunc));
            $lines[] = $bc . "│" . $x
                . "  " . C::Y . $portTrunc . $x
                . str_repeat(' ', $portPad)
                . $bc . "│" . $x;
        }

        // ── Container names
        foreach ($box['containers'] as $cname) {
            $cTrunc = Fmt::trunc($cname, $innerW - 6);
            $cPad = max(1, $innerW - 4 - mb_strwidth($cTrunc));
            $cDot = $box['running'] ? C::GN : C::D;
            $lines[] = $bc . "│" . $x
                . "  " . $cDot . "▸" . $x . " " . C::T . $cTrunc . $x
                . str_repeat(' ', $cPad)
                . $bc . "│" . $x;
        }

        // ── Volumes (show first 2, truncated)
        $volCount = 0;
        foreach ($box['volumes'] as $vol) {
            if ($volCount >= 2) break;
            $parts = explode(':', $vol);
            $hostPath = $parts[0] ?? '';
            $volStr = Fmt::trunc($hostPath, $innerW - 6);
            $volPad = max(1, $innerW - 4 - mb_strwidth($volStr));
            $lines[] = $bc . "│" . $x
                . "  " . C::D . "◦" . $x . " " . C::D . $volStr . $x
                . str_repeat(' ', $volPad)
                . $bc . "│" . $x;
            $volCount++;
        }

        // ── Directory
        $dirStr = Fmt::trunc($box['dir'], $innerW - 4);
        $dirPad = max(1, $innerW - 2 - mb_strwidth($dirStr));
        $lines[] = $bc . "│" . $x
            . "  " . C::D . $dirStr . $x
            . str_repeat(' ', $dirPad)
            . $bc . "│" . $x;

        // ── Bottom border
        $lines[] = $bc . "╰" . str_repeat('─', $innerW) . "╯" . $x;

        return $lines;
    }

}
