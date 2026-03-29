<?php
/**
 * Docker cleanup — prune stopped containers, dangling images, and optionally volumes.
 */
namespace Gitcd\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Gitcd\Helpers\Shell;
use Gitcd\Helpers\Dir;
use Gitcd\Helpers\BlueGreen;

class DockerCleanup extends Command
{
    protected static $defaultName = 'docker:cleanup';
    protected static $defaultDescription = 'Prune stopped containers and unused images. Pass "full" to also wipe unused volumes.';

    protected function configure(): void
    {
        $this
            ->setHelp(<<<HELP
            Default mode: removes stopped containers, dangling images, and unused networks.
            Full mode:    also removes all unused volumes.

            Usage:
              protocol docker:cleanup        Safe cleanup (no volumes)
              protocol docker:cleanup full   Full cleanup including volumes
            HELP)
            ->addArgument('mode', InputArgument::OPTIONAL, '"full" to also prune volumes', 'safe')
            ->addOption('dir', 'd', InputOption::VALUE_OPTIONAL, 'Directory Path', false);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $mode = strtolower(trim($input->getArgument('mode')));
        $full = ($mode === 'full');

        // Verify docker is available
        $version = trim(Shell::run('docker version --format "{{.Server.Version}}" 2>/dev/null'));
        if (!$version) {
            $output->writeln('  <fg=red>Docker is not running or not installed.</>');
            return Command::FAILURE;
        }

        $output->writeln('');
        $this->writeSection($output, 'Docker Cleanup' . ($full ? ' (full)' : ''));

        // ── Before: snapshot ────────────────────────────────────
        $output->writeln('  <fg=gray>Gathering current usage...</>');
        $dfBefore = trim(Shell::run('docker system df 2>/dev/null'));

        // ── Step 1: Stopped containers ──────────────────────────
        $output->writeln('');
        $output->writeln('  <fg=white>Pruning stopped containers...</>');

        $stoppedCount = trim(Shell::run('docker ps -a -f status=exited -f status=created -f status=dead -q 2>/dev/null | wc -l'));
        $result = Shell::run('docker container prune -f 2>/dev/null');
        $reclaimed = $this->parseReclaimed($result);
        $output->writeln("    <fg=green>✓</> {$stoppedCount} stopped container(s) removed" . ($reclaimed ? " — reclaimed {$reclaimed}" : ''));

        // ── Step 2: Dangling images ─────────────────────────────
        $output->writeln('');
        $output->writeln('  <fg=white>Pruning dangling images...</>');

        $danglingCount = trim(Shell::run('docker images -f dangling=true -q 2>/dev/null | wc -l'));
        $result = Shell::run('docker image prune -f 2>/dev/null');
        $reclaimed = $this->parseReclaimed($result);
        $output->writeln("    <fg=green>✓</> {$danglingCount} dangling image(s) removed" . ($reclaimed ? " — reclaimed {$reclaimed}" : ''));

        // ── Step 3: Unused images (not referenced by any container) ─
        $output->writeln('');
        $output->writeln('  <fg=white>Pruning unused images (not referenced by any container)...</>');

        $result = Shell::run('docker image prune -a -f 2>/dev/null');
        $reclaimed = $this->parseReclaimed($result);
        $output->writeln("    <fg=green>✓</> Unused images removed" . ($reclaimed ? " — reclaimed {$reclaimed}" : ''));

        // ── Step 4: Unused networks ─────────────────────────────
        $output->writeln('');
        $output->writeln('  <fg=white>Pruning unused networks...</>');

        $result = Shell::run('docker network prune -f 2>/dev/null');
        $output->writeln("    <fg=green>✓</> Unused networks removed");

        // ── Step 5: Volumes (full mode only) ────────────────────
        if ($full) {
            $output->writeln('');
            $output->writeln('  <fg=white>Pruning unused volumes...</>');

            $unusedCount = trim(Shell::run('docker volume ls -f dangling=true -q 2>/dev/null | wc -l'));
            $result = Shell::run('docker volume prune -f 2>/dev/null');
            $reclaimed = $this->parseReclaimed($result);
            $output->writeln("    <fg=green>✓</> {$unusedCount} unused volume(s) removed" . ($reclaimed ? " — reclaimed {$reclaimed}" : ''));
        } else {
            $output->writeln('');
            $output->writeln('  <fg=gray>Skipping volumes (run with "full" to include).</>');
        }

        // ── Build cache ─────────────────────────────────────────
        $output->writeln('');
        $output->writeln('  <fg=white>Pruning build cache...</>');

        $result = Shell::run('docker builder prune -f 2>/dev/null');
        $reclaimed = $this->parseReclaimed($result);
        $output->writeln("    <fg=green>✓</> Build cache cleared" . ($reclaimed ? " — reclaimed {$reclaimed}" : ''));

        // ── Step: Old release directories ────────────────────────
        $this->cleanupOldReleases($input, $output);

        // ── After: comparison ───────────────────────────────────
        $output->writeln('');
        $this->writeSection($output, 'Result');

        $dfAfter = trim(Shell::run('docker system df 2>/dev/null'));
        if ($dfAfter) {
            foreach (array_filter(array_map('trim', explode("\n", $dfAfter))) as $line) {
                $output->writeln("    <fg=white>{$line}</>");
            }
        }

        $output->writeln('');
        if ($full) {
            $output->writeln("  <fg=green>✓</> Full cleanup complete.");
        } else {
            $output->writeln("  <fg=green>✓</> Safe cleanup complete. Volumes untouched.");
            $output->writeln("  <fg=gray>Run</> <fg=white>protocol docker:cleanup full</> <fg=gray>to also remove unused volumes.</>");
        }
        $output->writeln('');

        return Command::SUCCESS;
    }

    // ── Helpers ──────────────────────────────────────────────────

    private function writeSection(OutputInterface $output, string $title): void
    {
        $output->writeln("  <fg=white;options=bold>{$title}</>");
        $output->writeln('');
    }

    /**
     * Remove old release directories that are not running, not the current
     * release, and not the previous release.
     */
    private function cleanupOldReleases(InputInterface $input, OutputInterface $output): void
    {
        $repo_dir = Dir::realpath($input->getOption('dir'));
        if (!$repo_dir || !BlueGreen::isEnabled($repo_dir)) {
            return;
        }

        $releasesDir = BlueGreen::getReleasesDir($repo_dir);
        if (!is_dir($releasesDir)) {
            return;
        }

        $activeVersion = BlueGreen::getActiveVersion($repo_dir);
        $previousVersion = BlueGreen::getPreviousVersion($repo_dir);

        $output->writeln('');
        $output->writeln('  <fg=white>Pruning old release directories...</>');
        $output->writeln("    <fg=gray>Active: {$activeVersion}, Previous: {$previousVersion}</>");

        $keep = array_filter([$activeVersion, $previousVersion]);
        $removed = 0;

        $entries = scandir($releasesDir);
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $fullPath = $releasesDir . $entry;
            if (!is_dir($fullPath)) continue;

            // Skip non-version directories (e.g. enterprise-gateway-config)
            if (!preg_match('/^v?\d+\.\d+/', $entry)) continue;

            // Keep current and previous releases
            if (in_array($entry, $keep)) continue;

            // Skip if containers are still running
            if (BlueGreen::isReleaseRunning($repo_dir, $entry)) {
                $output->writeln("    <fg=yellow>⊘</> {$entry} — skipped (still running)");
                continue;
            }

            // Remove the old release directory
            Shell::run("rm -rf " . escapeshellarg($fullPath));
            $removed++;
            $output->writeln("    <fg=green>✓</> {$entry} removed");
        }

        if ($removed === 0) {
            $output->writeln("    <fg=green>✓</> No old releases to remove");
        } else {
            $output->writeln("    <fg=green>✓</> {$removed} old release(s) removed");
        }
    }

    private function parseReclaimed(string $result): ?string
    {
        // Docker outputs "Total reclaimed space: 1.2GB"
        if (preg_match('/reclaimed space:\s*(.+)/i', $result, $m)) {
            return trim($m[1]);
        }
        return null;
    }
}
