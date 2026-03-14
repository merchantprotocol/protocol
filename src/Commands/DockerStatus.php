<?php
/**
 * Docker status — system-wide audit of containers, images, and volumes
 * with color-coded age indicators and space usage.
 */
namespace Gitcd\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Gitcd\Helpers\Shell;

class DockerStatus extends Command
{
    protected static $defaultName = 'docker:status';
    protected static $defaultDescription = 'Audit all Docker containers, images, and volumes — shows age, status, and disk usage';

    // Age thresholds in seconds
    private const AGE_OK      = 7  * 86400;   // < 7 days  → green
    private const AGE_STALE   = 30 * 86400;   // < 30 days → yellow
    private const AGE_OLD     = 90 * 86400;   // < 90 days → orange
    // > 90 days → red

    protected function configure(): void
    {
        $this->setHelp('Shows a full audit of every Docker container, image, and volume on this host with color-coded age and disk usage.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Verify docker is available
        $version = trim(Shell::run('docker version --format "{{.Server.Version}}" 2>/dev/null'));
        if (!$version) {
            $output->writeln('  <fg=red>Docker is not running or not installed.</>');
            return Command::FAILURE;
        }

        $output->writeln('');
        $this->writeSection($output, "Docker Status");
        $this->writeLine($output, 'Docker', "<fg=white>{$version}</>");

        // ── Disk overview ───────────────────────────────────────
        $dfRaw = Shell::run('docker system df 2>/dev/null');
        if ($dfRaw && trim($dfRaw) !== '') {
            $this->writeLine($output, 'System df', '');
            foreach (array_filter(array_map('trim', explode("\n", $dfRaw))) as $dfLine) {
                $output->writeln("      <fg=gray>{$dfLine}</>");
            }
        }

        // ── Containers ──────────────────────────────────────────
        $output->writeln('');
        $this->writeSection($output, 'Containers');

        $containerJson = Shell::run('docker ps -a --format "{{.ID}}\t{{.Names}}\t{{.Status}}\t{{.Size}}\t{{.CreatedAt}}\t{{.Image}}" 2>/dev/null');
        $running = 0;
        $stopped = 0;

        if (!$containerJson || trim($containerJson) === '') {
            $output->writeln("    <fg=gray>No containers found.</>");
        } else {
            $lines = array_filter(array_map('trim', explode("\n", $containerJson)));

            // Header
            $output->writeln(sprintf(
                "    <fg=white>%-14s %-28s %-10s %-12s %-14s %s</>",
                'ID', 'NAME', 'STATE', 'SIZE', 'AGE', 'IMAGE'
            ));
            $output->writeln("    <fg=gray>" . str_repeat('─', 96) . "</>");

            foreach ($lines as $line) {
                $parts = explode("\t", $line);
                if (count($parts) < 6) continue;

                [$id, $name, $status, $size, $createdAt, $image] = $parts;

                $isUp = stripos($status, 'Up') !== false;
                if ($isUp) {
                    $running++;
                    $stateIcon = '<fg=green>●</>';
                    $stateLabel = '<fg=green>running</>';
                } else {
                    $stopped++;
                    $stateIcon = '<fg=red>●</>';
                    $stateLabel = '<fg=red>stopped</>';
                }

                $ageSec = $this->parseAge($createdAt);
                $ageStr = $this->humanAge($ageSec);
                $ageColor = $this->ageColor($ageSec);

                $output->writeln(sprintf(
                    "    %s <fg=gray>%-12s</> <fg=white>%-28s</> %s %-12s <fg=%s>%-14s</> <fg=gray>%s</>",
                    $stateIcon,
                    $this->trunc($id, 12),
                    $this->trunc($name, 28),
                    $stateLabel,
                    $this->trunc($size, 12),
                    $ageColor,
                    $ageStr,
                    $this->trunc($image, 30)
                ));
            }

            $output->writeln('');
            $output->writeln("    <fg=green>{$running} running</>  <fg=red>{$stopped} stopped</>");
        }

        // ── Images ──────────────────────────────────────────────
        $output->writeln('');
        $this->writeSection($output, 'Images');

        $imageJson = Shell::run('docker images --format "{{.ID}}\t{{.Repository}}\t{{.Tag}}\t{{.Size}}\t{{.CreatedAt}}" 2>/dev/null');

        if (!$imageJson || trim($imageJson) === '') {
            $output->writeln("    <fg=gray>No images found.</>");
        } else {
            $lines = array_filter(array_map('trim', explode("\n", $imageJson)));

            // Header
            $output->writeln(sprintf(
                "    <fg=white>%-14s %-36s %-12s %-12s %s</>",
                'ID', 'REPOSITORY', 'TAG', 'SIZE', 'AGE'
            ));
            $output->writeln("    <fg=gray>" . str_repeat('─', 96) . "</>");

            $totalImages = 0;
            $danglingImages = 0;

            foreach ($lines as $line) {
                $parts = explode("\t", $line);
                if (count($parts) < 5) continue;

                [$id, $repo, $tag, $size, $createdAt] = $parts;
                $totalImages++;

                $isDangling = ($repo === '<none>');
                if ($isDangling) $danglingImages++;

                $ageSec = $this->parseAge($createdAt);
                $ageStr = $this->humanAge($ageSec);
                $ageColor = $this->ageColor($ageSec);

                $repoColor = $isDangling ? 'yellow' : 'white';

                $output->writeln(sprintf(
                    "    <fg=%s>●</> <fg=gray>%-12s</> <fg=%s>%-36s</> <fg=gray>%-12s</> <fg=white>%-12s</> <fg=%s>%s</>",
                    $ageColor,
                    $this->trunc($id, 12),
                    $repoColor,
                    $this->trunc($repo . ':' . $tag, 36),
                    $tag === '<none>' ? '<none>' : '',
                    $size,
                    $ageColor,
                    $ageStr
                ));
            }

            $output->writeln('');
            $output->writeln("    <fg=white>{$totalImages} images</>  " . ($danglingImages ? "<fg=yellow>{$danglingImages} dangling</>" : "<fg=green>0 dangling</>"));
        }

        // ── Volumes ─────────────────────────────────────────────
        $output->writeln('');
        $this->writeSection($output, 'Volumes');

        $volumeJson = Shell::run('docker volume ls --format "{{.Name}}\t{{.Driver}}\t{{.Mountpoint}}" 2>/dev/null');

        if (!$volumeJson || trim($volumeJson) === '') {
            $output->writeln("    <fg=gray>No volumes found.</>");
        } else {
            $lines = array_filter(array_map('trim', explode("\n", $volumeJson)));

            // Header
            $output->writeln(sprintf(
                "    <fg=white>%-40s %-10s %-12s %s</>",
                'NAME', 'DRIVER', 'SIZE', 'AGE'
            ));
            $output->writeln("    <fg=gray>" . str_repeat('─', 96) . "</>");

            $totalVolumes = 0;
            $unusedVolumes = 0;

            // Get dangling volumes for comparison
            $danglingRaw = Shell::run('docker volume ls -f dangling=true --format "{{.Name}}" 2>/dev/null');
            $danglingVolumes = $danglingRaw ? array_filter(array_map('trim', explode("\n", $danglingRaw))) : [];

            foreach ($lines as $line) {
                $parts = explode("\t", $line);
                if (count($parts) < 3) continue;

                [$name, $driver, $mountpoint] = $parts;
                $totalVolumes++;

                $isDangling = in_array($name, $danglingVolumes);
                if ($isDangling) $unusedVolumes++;

                // Get volume creation time and size via inspect
                $inspectJson = Shell::run('docker volume inspect --format "{{.CreatedAt}}" ' . escapeshellarg($name) . ' 2>/dev/null');
                $createdAt = trim($inspectJson);

                $ageSec = $this->parseAge($createdAt);
                $ageStr = $this->humanAge($ageSec);
                $ageColor = $isDangling ? $this->ageColor($ageSec) : $this->ageColor($ageSec);

                // Volume size (best effort — requires du on mountpoint)
                $volSize = '—';
                if ($mountpoint && is_dir($mountpoint)) {
                    $duResult = trim(Shell::run('du -sh ' . escapeshellarg($mountpoint) . ' 2>/dev/null'));
                    if ($duResult && preg_match('/^([\d.]+\w?)/', $duResult, $m)) {
                        $volSize = $m[1];
                    }
                }

                $nameColor = $isDangling ? 'yellow' : 'white';
                $danglingLabel = $isDangling ? ' <fg=yellow>(unused)</>' : '';

                $output->writeln(sprintf(
                    "    <fg=%s>●</> <fg=%s>%-40s</> <fg=gray>%-10s</> <fg=white>%-12s</> <fg=%s>%s</>%s",
                    $ageColor,
                    $nameColor,
                    $this->trunc($name, 40),
                    $driver,
                    $volSize,
                    $ageColor,
                    $ageStr,
                    $danglingLabel
                ));
            }

            $output->writeln('');
            $output->writeln("    <fg=white>{$totalVolumes} volumes</>  " . ($unusedVolumes ? "<fg=yellow>{$unusedVolumes} unused (dangling)</>" : "<fg=green>0 unused</>"));
        }

        // ── Summary ─────────────────────────────────────────────
        $output->writeln('');
        $this->writeSection($output, 'Cleanup Suggestions');

        $suggestions = [];

        if (isset($stopped) && $stopped > 0) {
            $suggestions[] = "<fg=yellow>→</> {$stopped} stopped container(s) — <fg=gray>docker container prune</>";
        }
        if (isset($danglingImages) && $danglingImages > 0) {
            $suggestions[] = "<fg=yellow>→</> {$danglingImages} dangling image(s) — <fg=gray>docker image prune</>";
        }
        if (isset($unusedVolumes) && $unusedVolumes > 0) {
            $suggestions[] = "<fg=yellow>→</> {$unusedVolumes} unused volume(s) — <fg=gray>docker volume prune</>";
        }

        if (empty($suggestions)) {
            $output->writeln("    <fg=green>✓</> System looks clean. No obvious garbage to reclaim.");
        } else {
            foreach ($suggestions as $s) {
                $output->writeln("    {$s}");
            }
            $output->writeln('');
            $output->writeln("    <fg=gray>Or nuke everything unused:</> <fg=white>docker system prune -a --volumes</>");
        }

        // ── Legend ───────────────────────────────────────────────
        $output->writeln('');
        $output->writeln("    <fg=gray>Age legend:</> <fg=green>● < 7d</>  <fg=yellow>● < 30d</>  <fg=#e67e22>● < 90d</>  <fg=red>● > 90d</>");
        $output->writeln('');

        return Command::SUCCESS;
    }

    // ── Display helpers ──────────────────────────────────────────

    private function writeSection(OutputInterface $output, string $title): void
    {
        $output->writeln("  <fg=white;options=bold>{$title}</>");
        $output->writeln('');
    }

    private function writeLine(OutputInterface $output, string $label, string $value): void
    {
        $padded = str_pad($label, 16);
        $output->writeln("    <fg=gray>{$padded}</> {$value}");
    }

    // ── Age helpers ──────────────────────────────────────────────

    private function parseAge(string $createdAt): int
    {
        $createdAt = trim($createdAt);
        if (!$createdAt) return PHP_INT_MAX;

        try {
            // Docker formats: "2024-01-15 10:30:00 +0000 UTC" or ISO 8601
            // Strip trailing timezone name like "UTC" that PHP can't parse
            $cleaned = preg_replace('/\s+(UTC|GMT|EST|PST|CST|MST)\s*$/', '', $createdAt);
            $then = new \DateTime($cleaned);
            $now = new \DateTime();
            return max(0, $now->getTimestamp() - $then->getTimestamp());
        } catch (\Exception $e) {
            return PHP_INT_MAX;
        }
    }

    private function humanAge(int $seconds): string
    {
        if ($seconds >= PHP_INT_MAX) return '?';
        if ($seconds < 60) return $seconds . 's';
        if ($seconds < 3600) return floor($seconds / 60) . 'm';
        if ($seconds < 86400) return floor($seconds / 3600) . 'h';
        if ($seconds < 2592000) return floor($seconds / 86400) . 'd';
        if ($seconds < 31536000) return floor($seconds / 2592000) . 'mo';
        return floor($seconds / 31536000) . 'y';
    }

    private function ageColor(int $seconds): string
    {
        if ($seconds < self::AGE_OK) return 'green';
        if ($seconds < self::AGE_STALE) return 'yellow';
        if ($seconds < self::AGE_OLD) return '#e67e22';  // orange
        return 'red';
    }

    private function trunc(string $str, int $max): string
    {
        if (strlen($str) <= $max) return $str;
        return substr($str, 0, $max - 1) . '…';
    }
}
