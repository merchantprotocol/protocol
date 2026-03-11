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

Class Top extends Command {

    protected static $defaultName = 'top';
    protected static $defaultDescription = 'Real-time system command center for debugging and breach detection';

    // Brand colors (true color ANSI - matching merchantprotocol.com)
    private const G   = "\033[38;2;63;185;80m";    // #3FB950 green-bright
    private const GD  = "\033[38;2;46;160;67m";    // #2EA043 green-dim
    private const T   = "\033[38;2;230;237;243m";   // #e6edf3 text
    private const M   = "\033[38;2;139;148;158m";   // #8b949e muted
    private const D   = "\033[38;2;110;118;129m";   // #6e7681 dim
    private const B   = "\033[38;2;48;54;61m";      // #30363d border
    private const R   = "\033[38;2;255;95;87m";     // #ff5f57 red
    private const Y   = "\033[38;2;254;188;46m";    // #febc2e yellow
    private const GN  = "\033[38;2;40;200;64m";     // #28c840 green-dot
    private const BL  = "\033[38;2;121;192;255m";   // #79c0ff blue
    private const X   = "\033[0m";                   // reset
    private const BD  = "\033[1m";                   // bold

    private int $maxPath = 60;

    protected function configure(): void
    {
        $this
            ->setHelp('Real-time system dashboard. Press Ctrl+C to exit.')
            ->addOption('interval', 'i', InputOption::VALUE_OPTIONAL, 'Refresh interval in seconds', 5)
            ->addOption('once', null, InputOption::VALUE_NONE, 'Run once and exit')
            ->addOption('dir', 'd', InputOption::VALUE_OPTIONAL, 'Directory to scan', '/')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $interval = max(1, (int)$input->getOption('interval'));
        $once = $input->getOption('once');
        $scanDir = $input->getOption('dir');

        // Use alternate screen buffer so we don't pollute scrollback
        $enterAlt = "\033[?1049h\033[?25l";
        $exitAlt  = "\033[?25h\033[?1049l";

        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGINT, function() use ($exitAlt) { echo $exitAlt; exit(0); });
            pcntl_signal(SIGTERM, function() use ($exitAlt) { echo $exitAlt; exit(0); });
        }

        // Enter alternate screen + hide cursor
        echo $enterAlt;

        do {
            $w = (int)Shell::run('tput cols 2>/dev/null') ?: 100;
            $rows = (int)Shell::run('tput lines 2>/dev/null') ?: 40;
            $pw = min($w - 2, 98);
            $this->maxPath = $pw - 18;

            // Clear alternate screen and move cursor home
            echo "\033[2J\033[H";

            $this->render($output, $scanDir, $pw, $rows);

            if ($once) break;

            for ($i = 0; $i < $interval; $i++) {
                if (function_exists('pcntl_signal_dispatch')) pcntl_signal_dispatch();
                sleep(1);
            }
        } while (true);

        // Restore main screen + show cursor
        echo $exitAlt;
        return Command::SUCCESS;
    }

    private function render(OutputInterface $output, string $scanDir, int $pw, int $rows): void
    {
        $isMac = Shell::getOS() === Shell::MAC;
        $linesUsed = 0;
        $maxLines = $rows - 1; // leave 1 line for footer outside the box

        // ── Chrome header (4 lines)
        $hostname = trim(Shell::run('hostname -s 2>/dev/null') ?: Shell::run('hostname 2>/dev/null')) ?: 'server';
        $now = date('Y-m-d H:i:s');
        $uptime = trim(Shell::run('uptime -p 2>/dev/null') ?: Shell::run('uptime 2>/dev/null'));
        if (preg_match('/up\s+(.+?)(?:,\s*\d+ user|$)/i', $uptime, $um)) $uptime = trim($um[1]);

        $o = self::B;
        $x = self::X;
        $this->w("{$o}╭" . str_repeat('─', $pw) . "╮{$x}");
        $dots = self::R . "●" . $x . " " . self::Y . "●" . $x . " " . self::GN . "●" . $x;
        $titlePart = self::M . "protocol top" . $x . self::D . " — {$hostname}" . $x;
        $timePart = self::D . $now . $x;
        $visLen = 5 + 3 + 12 + 3 + strlen($hostname) + 3 + strlen($now) + 2;
        $pad = max(1, $pw - $visLen);
        $this->w("{$o}│{$x} {$dots}   {$titlePart}" . str_repeat(' ', $pad) . "{$timePart}  {$o}│{$x}");

        $brand = self::G . self::BD . " MERCHANT PROTOCOL" . $x . "  " . self::D . "command center" . $x;
        $uptimeStr = self::M . "up " . $x . self::T . $uptime . $x;
        $bLen = 18 + 2 + 14;
        $uLen = 3 + strlen($uptime);
        $bPad = max(1, $pw - $bLen - $uLen - 2);
        $this->w("{$o}│{$x}{$brand}" . str_repeat(' ', $bPad) . "{$uptimeStr} {$o}│{$x}");
        $this->w("{$o}├" . str_repeat('─', $pw) . "┤{$x}");
        $linesUsed += 4;

        // ── System Resources (3-4 lines)
        $this->sec($output, 'SYSTEM', $pw); $linesUsed++;

        if ($isMac) {
            $cpuUsage = trim(Shell::run("ps -A -o %cpu | awk '{s+=\$1} END {printf \"%.1f\", s}'"));
            $cpuCores = trim(Shell::run('sysctl -n hw.ncpu 2>/dev/null'));
        } else {
            $cpuUsage = trim(Shell::run("grep 'cpu ' /proc/stat | awk '{usage=(\$2+\$4)*100/(\$2+\$4+\$5)} END {printf \"%.1f\", usage}'"));
            $cpuCores = trim(Shell::run('nproc 2>/dev/null'));
        }
        $loadAvg = trim(Shell::run('cat /proc/loadavg 2>/dev/null') ?: Shell::run("sysctl -n vm.loadavg 2>/dev/null | tr -d '{}'"));

        if ($isMac) {
            $memTotal = (int)trim(Shell::run('sysctl -n hw.memsize 2>/dev/null'));
            $memTotalGB = round($memTotal / 1073741824, 1);
            $vmStat = Shell::run('vm_stat 2>/dev/null');
            $pageSize = 16384;
            if (preg_match('/page size of (\d+)/', $vmStat, $pm)) $pageSize = (int)$pm[1];
            $fp = $ip = $sp = 0;
            if (preg_match('/Pages free:\s+(\d+)/', $vmStat, $pm)) $fp = (int)$pm[1];
            if (preg_match('/Pages inactive:\s+(\d+)/', $vmStat, $pm)) $ip = (int)$pm[1];
            if (preg_match('/Pages speculative:\s+(\d+)/', $vmStat, $pm)) $sp = (int)$pm[1];
            $memAvailGB = round(($fp + $ip + $sp) * $pageSize / 1073741824, 1);
            $memUsedGB = round($memTotalGB - $memAvailGB, 1);
        } else {
            $memInfo = Shell::run('cat /proc/meminfo 2>/dev/null');
            $memTotalGB = $memAvailGB = $memUsedGB = 0;
            if (preg_match('/MemTotal:\s+(\d+)/', $memInfo, $pm)) $memTotalGB = round($pm[1] / 1048576, 1);
            if (preg_match('/MemAvailable:\s+(\d+)/', $memInfo, $pm)) $memAvailGB = round($pm[1] / 1048576, 1);
            $memUsedGB = round($memTotalGB - $memAvailGB, 1);
        }

        $memPct = $memTotalGB > 0 ? round(($memUsedGB / $memTotalGB) * 100, 1) : 0;
        $cpuF = min((float)$cpuUsage, 100);

        $this->ln($output,
            self::M . "cpu " . $x . $this->bar($cpuF, 20) . $this->clr($cpuF) . sprintf(" %5.1f%%", $cpuF) . $x
            . self::D . " {$cpuCores}c" . $x
            . self::M . "  mem " . $x . $this->bar($memPct, 20) . $this->clr($memPct) . sprintf(" %5.1f%%", $memPct) . $x
            . self::D . " {$memUsedGB}/{$memTotalGB}G" . $x
            . self::M . "  load " . $x . self::T . $loadAvg . $x
        );
        $linesUsed++;

        // ── Disk (header + rows, compact)
        $this->sec($output, 'DISK', $pw); $linesUsed++;

        if ($isMac) {
            $dfOut = Shell::run("df -h / 2>/dev/null | tail -n +2");
        } else {
            $dfOut = Shell::run("df -h --output=source,size,used,avail,pcent,target -x tmpfs -x devtmpfs 2>/dev/null | tail -n +2");
            if (!$dfOut) $dfOut = Shell::run("df -h / 2>/dev/null | tail -n +2");
        }
        if ($dfOut) {
            $dfLines = array_filter(array_map('trim', explode("\n", $dfOut)));
            $seen = [];
            foreach ($dfLines as $dl) {
                $p = preg_split('/\s+/', $dl);
                if (count($p) < 5) continue;
                $fs = $p[0];
                if (isset($seen[$fs])) continue;
                $seen[$fs] = true;
                $pctN = (int)str_replace('%', '', $p[4]);
                $pc = $this->clr($pctN);
                if ($isMac) {
                    $mt = implode(' ', array_slice($p, 8));
                    if (empty($mt)) $mt = implode(' ', array_slice($p, 5));
                } else {
                    $mt = implode(' ', array_slice($p, 5));
                }
                $this->ln($output,
                    self::T . sprintf("%-18s", $this->trunc($fs, 18)) . $x
                    . self::M . sprintf(" %6s %6s %6s", $p[1], $p[2], $p[3]) . $x
                    . $pc . sprintf(" %5s", $p[4]) . $x
                    . self::D . "  {$mt}" . $x
                );
                $linesUsed++;
            }
        }

        // ── Users (inline)
        $this->sec($output, 'USERS', $pw); $linesUsed++;
        $users = Shell::run('who 2>/dev/null');
        if (!$users || trim($users) === '') {
            $this->ln($output, self::D . "No active sessions" . $x);
            $linesUsed++;
        } else {
            $ulines = array_filter(array_map('trim', explode("\n", $users)));
            foreach (array_slice($ulines, 0, 3) as $ul) {
                $isRoot = preg_match('/^root\s/', $ul);
                $ico = $isRoot ? self::R . "!" . $x : self::GN . ">" . $x;
                $c = $isRoot ? self::R : self::M;
                $this->ln($output, "{$ico} {$c}{$ul}{$x}");
                $linesUsed++;
            }
            if (count($ulines) > 3) {
                $this->ln($output, self::D . "  +" . (count($ulines) - 3) . " more" . $x);
                $linesUsed++;
            }
        }

        // ── Top Processes (combined, 2 cpu + 2 mem)
        $this->sec($output, 'PROCESSES', $pw); $linesUsed++;
        $this->ln($output, self::D . sprintf("  %-6s %-6s %-7s %-10s %s", '%CPU', '%MEM', 'PID', 'USER', 'COMMAND') . $x);
        $linesUsed++;

        $topCpu = Shell::run("ps -eo %cpu,%mem,pid,user,comm --sort=-%cpu 2>/dev/null | tail -n +2 | head -2");
        if (!$topCpu) $topCpu = Shell::run("ps -eo %cpu,%mem,pid,user,comm -r 2>/dev/null | tail -n +2 | head -2");
        $topMem = Shell::run("ps -eo %mem,%cpu,pid,user,comm --sort=-%mem 2>/dev/null | tail -n +2 | head -2");
        if (!$topMem) $topMem = Shell::run("ps -eo %mem,%cpu,pid,user,comm -m 2>/dev/null | tail -n +2 | head -2");

        $shown = [];
        foreach ([$topCpu, $topMem] as $block) {
            if (!$block) continue;
            foreach (array_filter(explode("\n", $block)) as $pl) {
                $pp = preg_split('/\s+/', trim($pl));
                if (count($pp) < 5) continue;
                $key = $pp[2]; // PID
                if (isset($shown[$key])) continue;
                $shown[$key] = true;
                $comm = $this->trunc(implode(' ', array_slice($pp, 4)), 30);
                $this->ln($output,
                    self::G . sprintf("  %-6s", $pp[0]) . $x
                    . self::M . sprintf(" %-6s %-7s %-10s", $pp[1], $pp[2], $this->trunc($pp[3], 10)) . $x
                    . self::T . " {$comm}" . $x
                );
                $linesUsed++;
                if (count($shown) >= 4) break 2;
            }
        }

        // ── How many lines left for files + security + footer?
        $linesLeft = $maxLines - $linesUsed - 3; // 3 = security header + 1 alert + footer line
        $fileLines = max(2, (int)floor($linesLeft / 2));
        $fileCount = max(1, $fileLines - 1); // subtract header line

        // ── Largest Files
        $this->sec($output, 'LARGEST FILES', $pw); $linesUsed++;
        $dir = escapeshellarg($scanDir);
        $exc = "-not -path '*/.git/*' -not -path '*/node_modules/*' -not -path '*/vendor/*' -not -path '/proc/*' -not -path '/sys/*' -not -path '/dev/*'";
        $fResult = Shell::run("find {$dir} -maxdepth 5 -type f {$exc} -exec ls -s {} + 2>/dev/null | sort -rn | head -{$fileCount}");
        if ($fResult) {
            foreach (array_filter(array_map('trim', explode("\n", $fResult))) as $fl) {
                if (preg_match('/^\s*(\d+)\s+(.+)$/', $fl, $fm)) {
                    $sz = $this->humanSize((int)$fm[1] * 1024);
                    $this->ln($output,
                        self::G . sprintf("%8s", $sz) . $x
                        . "  " . self::M . $this->trunc($fm[2], $this->maxPath) . $x
                    );
                    $linesUsed++;
                }
            }
        } else {
            $this->ln($output, self::D . "Unable to scan: {$scanDir}" . $x);
            $linesUsed++;
        }

        // ── Recent Files
        $this->sec($output, 'RECENT (24H)', $pw); $linesUsed++;
        $isMac2 = Shell::getOS() === Shell::MAC;
        $exc2 = "-not -path '*/.git/*' -not -path '*/node_modules/*' -not -path '*/vendor/*' -not -path '/proc/*' -not -path '/sys/*' -not -path '/dev/*' -not -path '/run/*'";
        if ($isMac2) {
            $rCmd = "find {$dir} -maxdepth 4 -type f {$exc2} -mtime -1 -exec stat -f '%m %N' {} + 2>/dev/null | sort -rn | head -{$fileCount}";
        } else {
            $rCmd = "find {$dir} -maxdepth 4 -type f {$exc2} -mtime -1 -printf '%T@ %p\n' 2>/dev/null | sort -rn | head -{$fileCount}";
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
            $this->ln($output, self::D . "No files modified" . $x);
            $linesUsed++;
        }

        // ── Security (compact)
        $this->sec($output, 'SECURITY', $pw); $linesUsed++;
        $alerts = $this->getAlerts($isMac);
        if (empty($alerts)) {
            $this->ln($output, self::GN . "●" . $x . " " . self::G . "ALL CLEAR" . $x . self::D . " — no issues detected" . $x);
            $linesUsed++;
        } else {
            // Show max 3 alerts to keep it tight
            foreach (array_slice($alerts, 0, 3) as [$lvl, $msg]) {
                $msg = $this->trunc($msg, $pw - 8);
                switch ($lvl) {
                    case 'C': $this->ln($output, self::R . "● " . self::BD . $msg . $x); break;
                    case 'W': $this->ln($output, self::Y . "● " . $msg . $x); break;
                    default:  $this->ln($output, self::BL . "● " . $x . self::D . $msg . $x); break;
                }
                $linesUsed++;
            }
            if (count($alerts) > 3) {
                $this->ln($output, self::D . "  +" . (count($alerts) - 3) . " more alerts" . $x);
                $linesUsed++;
            }
        }

        // ── Footer
        $this->w(self::B . "╰" . str_repeat('─', $pw) . "╯" . $x);
        echo " " . self::D . "ctrl+c exit" . $x
            . self::B . " · " . $x
            . self::D . "merchantprotocol.com" . $x;
    }

    // ── Security checks ─────────────────────────────────────────

    private function getAlerts(bool $isMac): array
    {
        $alerts = [];

        $rootSsh = Shell::run("who 2>/dev/null | grep -i root");
        if ($rootSsh && trim($rootSsh) !== '') {
            $alerts[] = ['C', 'Active root login session'];
        }

        $suspicious = ['xmrig', 'cryptominer', 'kinsing', 'dota', 'tsunami', 'ncrack', 'hydra'];
        $procs = strtolower(Shell::run("ps -eo comm 2>/dev/null") ?: '');
        foreach ($suspicious as $name) {
            if (strpos($procs, $name) !== false) {
                $alerts[] = ['C', "Suspicious process: {$name}"];
            }
        }

        $tmpExec = Shell::run("find /tmp /var/tmp -maxdepth 2 -type f -perm +111 -not -name '*.sh' 2>/dev/null | head -3");
        if (!$tmpExec) $tmpExec = Shell::run("find /tmp /var/tmp -maxdepth 2 -type f -executable -not -name '*.sh' 2>/dev/null | head -3");
        if ($tmpExec && trim($tmpExec) !== '') {
            $n = count(array_filter(explode("\n", $tmpExec)));
            $alerts[] = ['W', "{$n} executable(s) in /tmp"];
        }

        if (!$isMac) {
            $suid = Shell::run("find /usr/local/bin /tmp /var/tmp -perm -4000 -type f 2>/dev/null | head -3");
            if ($suid && trim($suid) !== '') {
                $alerts[] = ['W', 'SUID binary in unusual location'];
            }
        }

        $authKeys = Shell::run("find /root/.ssh /home/*/.ssh -name authorized_keys -mtime -1 2>/dev/null");
        if ($authKeys && trim($authKeys) !== '') {
            $alerts[] = ['C', 'SSH authorized_keys modified in 24h'];
        }

        if ($isMac) {
            $listeners = Shell::run("lsof -iTCP -sTCP:LISTEN -P -n 2>/dev/null | grep -v 'com.apple' | wc -l");
        } else {
            $listeners = Shell::run("ss -tlnp 2>/dev/null | tail -n +2 | wc -l");
        }
        $listenerCount = (int)trim($listeners ?: '0');
        if ($listenerCount > 0) {
            $alerts[] = ['I', "{$listenerCount} listening service(s)"];
        }

        return $alerts;
    }

    // ── Output helpers ──────────────────────────────────────────

    private function w(string $line): void
    {
        echo $line . PHP_EOL;
    }

    private function sec(OutputInterface $output, string $label, int $pw): void
    {
        $x = self::X;
        $lineR = max(4, $pw - strlen($label) - 8);
        $this->w(
            self::B . "│" . $x . " "
            . self::GD . "──── " . $x
            . self::G . self::BD . $label . $x
            . " " . self::GD . str_repeat('─', $lineR) . $x
        );
    }

    private function ln(OutputInterface $output, string $content): void
    {
        $this->w(self::B . "│" . self::X . "  {$content}");
    }

    private function bar(float $pct, int $w): string
    {
        $filled = (int)round($pct / 100 * $w);
        return $this->clr($pct) . str_repeat('━', $filled) . self::B . str_repeat('━', $w - $filled) . self::X;
    }

    private function clr(float $pct): string
    {
        if ($pct >= 90) return self::R;
        if ($pct >= 75) return self::Y;
        return self::G;
    }

    private function trunc(string $path, int $max): string
    {
        if ($max < 10) $max = 10;
        if (strlen($path) <= $max) return $path;
        // Show …/last-two-segments
        $parts = explode('/', $path);
        if (count($parts) > 2) {
            $tail = implode('/', array_slice($parts, -2));
            if (strlen($tail) + 2 <= $max) {
                return '…/' . $tail;
            }
        }
        return '…' . substr($path, -(($max) - 1));
    }

    private function humanSize(int $bytes): string
    {
        $u = ['B', 'K', 'M', 'G', 'T'];
        $i = 0;
        $s = (float)$bytes;
        while ($s >= 1024 && $i < 4) { $s /= 1024; $i++; }
        return sprintf('%.1f%s', $s, $u[$i]);
    }
}
