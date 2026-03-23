<?php
/**
 * Incident status dashboard — live view of detected issues, changed files,
 * logged-in users, container state, and security signals.
 */
namespace Gitcd\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Gitcd\Helpers\Dir;
use Gitcd\Helpers\Git;
use Gitcd\Helpers\Shell;
use Gitcd\Helpers\Docker;
use Gitcd\Helpers\IncidentDetector;
use Gitcd\Utils\Json;

class IncidentStatus extends Command
{
    protected static $defaultName = 'incident:status';
    protected static $defaultDescription = 'Live incident dashboard — shows detected issues, changed files, users, and security signals';

    // Brand colors (matching Top.php)
    private const G   = "\033[38;2;63;185;80m";
    private const GD  = "\033[38;2;46;160;67m";
    private const T   = "\033[38;2;230;237;243m";
    private const M   = "\033[38;2;139;148;158m";
    private const D   = "\033[38;2;110;118;129m";
    private const B   = "\033[38;2;48;54;61m";
    private const R   = "\033[38;2;255;95;87m";
    private const Y   = "\033[38;2;254;188;46m";
    private const GN  = "\033[38;2;40;200;64m";
    private const BL  = "\033[38;2;121;192;255m";
    private const X   = "\033[0m";
    private const BD  = "\033[1m";

    private int $maxPath = 60;

    protected function configure(): void
    {
        $this
            ->setHelp(<<<HELP
            Live incident dashboard. Shows all detected issues, recently changed files,
            logged-in users, container health, and security signals in a single view.

            Use this immediately when Protocol alerts you to an incident.
            Press Ctrl+C to exit. Follow up with: protocol incident:report
            HELP)
            ->addOption('interval', 'i', InputOption::VALUE_OPTIONAL, 'Refresh interval in seconds', 5)
            ->addOption('once', null, InputOption::VALUE_NONE, 'Run once and exit')
            ->addOption('dir', 'd', InputOption::VALUE_OPTIONAL, 'Directory Path', Git::getGitLocalFolder())
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $interval = max(1, (int)$input->getOption('interval'));
        $once = $input->getOption('once');
        $repo_dir = Dir::realpath($input->getOption('dir'));

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
            $pw = min($w - 2, 98);
            $this->maxPath = $pw - 18;

            echo "\033[2J\033[H";
            $this->render($output, $repo_dir, $pw, $rows);

            if ($once) break;

            for ($i = 0; $i < $interval; $i++) {
                if (function_exists('pcntl_signal_dispatch')) pcntl_signal_dispatch();
                sleep(1);
            }
        } while (true);

        echo $exitAlt;
        return Command::SUCCESS;
    }

    private function render(OutputInterface $output, string $repoDir, int $pw, int $rows): void
    {
        $isMac = Shell::getOS() === Shell::MAC;
        $linesUsed = 0;
        $maxLines = $rows - 1;

        $hostname = trim(Shell::run('hostname -s 2>/dev/null') ?: Shell::run('hostname 2>/dev/null')) ?: 'server';
        $now = date('Y-m-d H:i:s');

        $o = self::B;
        $x = self::X;

        // ── Header
        $this->w("{$o}╭" . str_repeat('─', $pw) . "╮{$x}");

        $hostTrunc = $this->trunc($hostname, 20);
        $titleVis = "INCIDENT STATUS — {$hostTrunc}";
        $innerW = $pw - 2;
        $usedChars = 5 + 3 + strlen($titleVis) + strlen($now);
        $pad = max(1, $innerW - $usedChars);

        $dots = self::R . "●" . $x . " " . self::R . "●" . $x . " " . self::R . "●" . $x;
        $titlePart = self::R . self::BD . "INCIDENT STATUS" . $x . self::D . " — {$hostTrunc}" . $x;
        $timePart = self::D . $now . $x;
        $this->w("{$o}│{$x} {$dots}   {$titlePart}" . str_repeat(' ', $pad) . "{$timePart} {$o}│{$x}");

        $brandVis = " MERCHANT PROTOCOL  incident response";
        $uptimeRaw = trim(Shell::run('uptime 2>/dev/null') ?: '');
        $uptime = '?';
        if (preg_match('/up\s+(.+?)(?:,\s*\d+ user|\s*$)/i', $uptimeRaw, $um)) {
            $uptime = trim(rtrim(trim($um[1]), ','));
        }
        if (strlen($uptime) > 20) $uptime = substr($uptime, 0, 20);
        $uptimeVis = "up {$uptime}";
        $usedBrand = strlen($brandVis) + strlen($uptimeVis);
        $bPad = max(1, $innerW - $usedBrand);

        $brand = self::R . self::BD . " MERCHANT PROTOCOL" . $x . "  " . self::D . "incident response" . $x;
        $uptimeStr = self::M . "up " . $x . self::T . $uptime . $x;
        $this->w("{$o}│{$x}{$brand}" . str_repeat(' ', $bPad) . "{$uptimeStr} {$o}│{$x}");

        $this->w("{$o}├" . str_repeat('─', $pw) . "┤{$x}");
        $linesUsed += 4;

        // ── Detected Issues
        $this->sec($output, 'DETECTED ISSUES', $pw); $linesUsed++;

        $issues = IncidentDetector::detect($repoDir);
        if (empty($issues)) {
            $this->ln($output, self::GN . "●" . $x . " " . self::G . "ALL CLEAR" . $x . self::D . " — no incidents detected" . $x);
            $linesUsed++;
        } else {
            $severity = IncidentDetector::highestSeverity($issues);
            $this->ln($output, self::R . self::BD . "  ACTIVE INCIDENT — {$severity}" . $x . self::R . "  (" . count($issues) . " issue(s) detected)" . $x);
            $linesUsed++;
            foreach ($issues as $issue) {
                $icon = match($issue['level']) {
                    'P1' => self::R . "▸" . $x,
                    'P2' => self::Y . "▸" . $x,
                    'P3' => self::BL . "▸" . $x,
                    default => self::D . "▸" . $x,
                };
                $lvlColor = match($issue['level']) {
                    'P1' => self::R,
                    'P2' => self::Y,
                    'P3' => self::BL,
                    default => self::D,
                };
                $this->ln($output, "  {$icon} {$lvlColor}[{$issue['level']}]{$x} " . self::T . $this->trunc($issue['message'], $pw - 16) . $x);
                $linesUsed++;
            }
        }

        // ── Container Health
        $this->sec($output, 'CONTAINERS', $pw); $linesUsed++;
        $dockerPs = Shell::run("docker ps -a --format '{{.Names}}\t{{.Status}}\t{{.Ports}}' 2>/dev/null");
        if (!$dockerPs || trim($dockerPs) === '') {
            $this->ln($output, self::D . "No containers found" . $x);
            $linesUsed++;
        } else {
            foreach (array_filter(array_map('trim', explode("\n", $dockerPs))) as $line) {
                $parts = explode("\t", $line);
                $name = $parts[0] ?? '';
                $status = $parts[1] ?? '';
                $ports = $parts[2] ?? '';

                $isUp = stripos($status, 'up') !== false;
                $icon = $isUp ? self::GN . "●" . $x : self::R . "●" . $x;
                $nameColor = $isUp ? self::T : self::R;
                $statusColor = $isUp ? self::G : self::R;
                $portsStr = $ports ? self::D . " " . $this->trunc($ports, 30) . $x : '';

                $this->ln($output, "{$icon} {$nameColor}" . sprintf("%-20s", $this->trunc($name, 20)) . "{$x} {$statusColor}{$status}{$x}{$portsStr}");
                $linesUsed++;
            }
        }

        // ── Logged-in Users
        $this->sec($output, 'ACTIVE USERS', $pw); $linesUsed++;
        $users = Shell::run('who 2>/dev/null');
        if (!$users || trim($users) === '') {
            $this->ln($output, self::D . "No active sessions" . $x);
            $linesUsed++;
        } else {
            $ulines = array_filter(array_map('trim', explode("\n", $users)));
            foreach ($ulines as $ul) {
                $isRoot = preg_match('/^root\s/', $ul);
                $icon = $isRoot ? self::R . "!" . $x : self::GN . ">" . $x;
                $c = $isRoot ? self::R : self::M;
                $this->ln($output, "{$icon} {$c}{$ul}{$x}");
                $linesUsed++;
            }
        }

        // ── How many lines left for files sections?
        $linesLeft = $maxLines - $linesUsed - 4; // footer + next-steps
        $fileLines = max(2, (int)floor($linesLeft / 3));
        $fileCount = max(1, $fileLines - 1);

        // ── Changed Files (git)
        $this->sec($output, 'CHANGED FILES (GIT)', $pw); $linesUsed++;
        $escapedDir = escapeshellarg($repoDir);
        $gitChanges = Shell::run("git -C {$escapedDir} status --short 2>/dev/null");
        if (!$gitChanges || trim($gitChanges) === '') {
            $this->ln($output, self::D . "Working tree clean" . $x);
            $linesUsed++;
        } else {
            $lines = array_filter(array_map('trim', explode("\n", $gitChanges)));
            foreach (array_slice($lines, 0, $fileCount) as $fl) {
                $statusChar = substr($fl, 0, 2);
                $filePath = trim(substr($fl, 2));
                $color = (strpos($statusChar, 'D') !== false) ? self::R :
                         ((strpos($statusChar, '?') !== false) ? self::Y : self::G);
                $this->ln($output, "{$color}" . sprintf("%-3s", $statusChar) . $x . self::M . $this->trunc($filePath, $this->maxPath) . $x);
                $linesUsed++;
            }
            if (count($lines) > $fileCount) {
                $this->ln($output, self::D . "  +" . (count($lines) - $fileCount) . " more files" . $x);
                $linesUsed++;
            }
        }

        // ── Recently Modified Files (filesystem)
        $this->sec($output, 'RECENTLY MODIFIED (24H)', $pw); $linesUsed++;
        $exc = "-not -path '*/.git/*' -not -path '*/node_modules/*' -not -path '*/vendor/*'";
        if ($isMac) {
            $rCmd = "find {$escapedDir} -maxdepth 4 -type f {$exc} -mtime -1 -exec stat -f '%m %N' {} + 2>/dev/null | sort -rn | head -{$fileCount}";
        } else {
            $rCmd = "find {$escapedDir} -maxdepth 4 -type f {$exc} -mtime -1 -printf '%T@ %p\n' 2>/dev/null | sort -rn | head -{$fileCount}";
        }
        $rResult = Shell::run($rCmd);
        if ($rResult && trim($rResult) !== '') {
            foreach (array_filter(array_map('trim', explode("\n", $rResult))) as $rl) {
                if (preg_match('/^([\d.]+)\s+(.+)$/', $rl, $rm)) {
                    $ts = date('m-d H:i', (int)$rm[1]);
                    $this->ln($output,
                        self::D . sprintf("%-11s", $ts) . $x
                        . " " . self::M . $this->trunc($rm[2], $this->maxPath) . $x
                    );
                    $linesUsed++;
                }
            }
        } else {
            $this->ln($output, self::D . "No files modified in last 24h" . $x);
            $linesUsed++;
        }

        // ── Recently Added Files
        $this->sec($output, 'RECENTLY ADDED (24H)', $pw); $linesUsed++;
        if ($isMac) {
            $aCmd = "find {$escapedDir} -maxdepth 4 -type f {$exc} -newer {$escapedDir} -mtime -1 -exec stat -f '%B %N' {} + 2>/dev/null | sort -rn | head -{$fileCount}";
        } else {
            $aCmd = "find {$escapedDir} -maxdepth 4 -type f {$exc} -ctime -1 -printf '%C@ %p\n' 2>/dev/null | sort -rn | head -{$fileCount}";
        }
        $aResult = Shell::run($aCmd);
        if ($aResult && trim($aResult) !== '') {
            foreach (array_filter(array_map('trim', explode("\n", $aResult))) as $al) {
                if (preg_match('/^([\d.]+)\s+(.+)$/', $al, $am)) {
                    $ts = date('m-d H:i', (int)$am[1]);
                    $this->ln($output,
                        self::Y . sprintf("%-11s", $ts) . $x
                        . " " . self::M . $this->trunc($am[2], $this->maxPath) . $x
                    );
                    $linesUsed++;
                }
            }
        } else {
            $this->ln($output, self::D . "No new files in last 24h" . $x);
            $linesUsed++;
        }

        // ── Next Steps
        $this->w(self::B . "├" . str_repeat('─', $pw) . "┤" . $x);
        $nextLabel = "NEXT: protocol incident:report \"description\"";
        $nextPad = max(1, $innerW - strlen($nextLabel));
        $this->w(self::B . "│" . $x . " " . self::Y . self::BD . $nextLabel . $x . str_repeat(' ', $nextPad) . " " . self::B . "│" . $x);

        // ── Footer
        $this->w(self::B . "╰" . str_repeat('─', $pw) . "╯" . $x);
        echo " " . self::D . "ctrl+c exit" . $x
            . self::B . " · " . $x
            . self::D . "merchantprotocol.com" . $x;
    }

    // ── Output helpers (matching Top.php) ──────────────────────

    private function w(string $line): void
    {
        echo $line . PHP_EOL;
    }

    private function sec(OutputInterface $output, string $label, int $pw): void
    {
        $lineR = max(4, $pw - strlen($label) - 8);
        $this->w(
            self::B . "│" . self::X . " "
            . self::GD . "──── " . self::X
            . self::G . self::BD . $label . self::X
            . " " . self::GD . str_repeat('─', $lineR) . self::X
        );
    }

    private function ln(OutputInterface $output, string $content): void
    {
        $this->w(self::B . "│" . self::X . "  {$content}");
    }

    private function trunc(string $path, int $max): string
    {
        if ($max < 10) $max = 10;
        if (strlen($path) <= $max) return $path;
        $parts = explode('/', $path);
        if (count($parts) > 2) {
            $tail = implode('/', array_slice($parts, -2));
            if (strlen($tail) + 2 <= $max) {
                return '…/' . $tail;
            }
        }
        return '…' . substr($path, -(($max) - 1));
    }
}
