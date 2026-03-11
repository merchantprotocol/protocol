<?php
/**
 * Animated staged output for protocol start.
 *
 * Displays each stage as a single status line:
 *   [protocol] Scanning codebase.............. OK
 *   [protocol] Infrastructure provisioning.... OK
 *   [protocol] Running security audit......... PASS
 */
namespace Gitcd\Helpers;

use Symfony\Component\Console\Output\OutputInterface;

class StageRunner
{
    const LINE_WIDTH = 50;

    private OutputInterface $output;
    private bool $isTty;
    private array $completedStages = [];
    private float $startTime;

    public function __construct(OutputInterface $output)
    {
        $this->output = $output;
        $this->isTty = self::isTty();
        $this->startTime = microtime(true);
    }

    /**
     * Run a stage: show progress label, execute callback, show result.
     *
     * The callback receives the repo dir. Throw an exception to signal failure.
     * Returns true on success, false on failure.
     */
    public function run(string $label, callable $callback, string $passLabel = 'OK'): bool
    {
        $stageStart = microtime(true);

        // Show the "working" line
        $this->writeProgress($label);

        $success = true;
        $errorMessage = null;

        try {
            $callback();
        } catch (\Throwable $e) {
            $success = false;
            $errorMessage = $e->getMessage();
        }

        $duration = microtime(true) - $stageStart;

        // Overwrite with the result line
        $status = $success ? $passLabel : 'FAIL';
        $color = $success ? 'green' : 'red';
        $this->writeResult($label, $status, $color);

        $this->completedStages[] = [
            'label' => $label,
            'status' => $status,
            'success' => $success,
            'duration' => $duration,
            'error' => $errorMessage,
        ];

        // If failed, show the error detail below
        if (!$success && $errorMessage) {
            $this->writeError($errorMessage);
        }

        return $success;
    }

    /**
     * Write the final summary banner with optional status info lines.
     *
     * @param array $info Key-value pairs to display (e.g. ['Environment' => 'production'])
     * @param string|null $successMessage Custom success message (default: "Deployment complete.")
     * @param string|null $failMessage Custom failure message (default: "Deployment completed with issues.")
     */
    public function writeSummary(array $info = [], ?string $successMessage = null, ?string $failMessage = null): void
    {
        $totalTime = round(microtime(true) - $this->startTime, 1);
        $allPassed = empty(array_filter($this->completedStages, fn($s) => !$s['success']));

        $successMessage = $successMessage ?? 'Deployment complete.';
        $failMessage = $failMessage ?? 'Deployment completed with issues.';

        if ($this->isTty) {
            fwrite(STDOUT, "\n");
        } else {
            $this->output->writeln('');
        }

        if ($allPassed) {
            $this->writeTty(
                "\033[32m✓\033[0m \033[1m{$successMessage}\033[0m All systems operational.\n"
            );
        } else {
            $failures = array_filter($this->completedStages, fn($s) => !$s['success']);
            $count = count($failures);
            $this->writeTty(
                "\033[31m✗\033[0m \033[1m{$failMessage}\033[0m {$count} stage(s) failed.\n"
            );
        }

        // Write status info lines
        if (!empty($info)) {
            $maxKeyLen = max(array_map('strlen', array_keys($info)));
            foreach ($info as $key => $value) {
                $padded = str_pad($key, $maxKeyLen);
                $this->writeTty("  \033[90m{$padded}\033[0m  {$value}\n");
            }
        }

        $this->writeTty("  \033[90mCompleted in {$totalTime}s\033[0m\n");
    }

    /**
     * Get results for programmatic access.
     */
    public function getResults(): array
    {
        return $this->completedStages;
    }

    /**
     * Write the progress line (no newline — will be overwritten).
     */
    private function writeProgress(string $label): void
    {
        $dots = str_repeat('.', max(1, self::LINE_WIDTH - strlen($label) - 1));

        if ($this->isTty) {
            fwrite(STDOUT, "\033[90m[protocol]\033[0m {$label}{$dots} \033[90m...\033[0m");
        } else {
            // Non-interactive: just write the label, result will follow on next line
            $this->output->write("[protocol] {$label}{$dots} ");
        }
    }

    /**
     * Overwrite the progress line with the final result.
     */
    private function writeResult(string $label, string $status, string $color): void
    {
        $dots = str_repeat('.', max(1, self::LINE_WIDTH - strlen($label) - 1));
        $colorCode = $color === 'green' ? '32' : '31';

        if ($this->isTty) {
            // Carriage return to overwrite, then write the full line
            fwrite(STDOUT, "\r\033[2K");
            fwrite(STDOUT, "\033[90m[protocol]\033[0m {$label}{$dots} \033[{$colorCode}m{$status}\033[0m\n");
        } else {
            $this->output->writeln($status);
        }
    }

    /**
     * Write an error detail line below a failed stage.
     */
    private function writeError(string $message): void
    {
        // Indent and dim the error message
        $lines = explode("\n", $message);
        foreach ($lines as $line) {
            $line = trim($line);
            if (!$line) continue;
            $this->writeTty("  \033[31m│\033[0m \033[90m{$line}\033[0m\n");
        }
    }

    /**
     * Write to STDOUT with ANSI if TTY, plain via OutputInterface otherwise.
     */
    private function writeTty(string $ansiText): void
    {
        if ($this->isTty) {
            fwrite(STDOUT, $ansiText);
        } else {
            // Strip ANSI codes for non-interactive output
            $plain = preg_replace('/\033\[[0-9;]*m/', '', $ansiText);
            $this->output->write($plain);
        }
    }

    /**
     * Check if STDOUT is a TTY (interactive terminal).
     */
    public static function isTty(): bool
    {
        if (function_exists('posix_isatty')) {
            return posix_isatty(STDOUT);
        }
        return false;
    }
}
