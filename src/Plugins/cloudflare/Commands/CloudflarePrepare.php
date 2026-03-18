<?php
namespace Gitcd\Plugins\cloudflare\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Gitcd\Plugins\cloudflare\CloudflareHelper;
use Gitcd\Helpers\Git;

class CloudflarePrepare extends Command
{
    protected static $defaultName = 'cf:prepare';
    protected static $defaultDescription = 'Prepare static output for Cloudflare Pages deployment';

    /**
     * File extensions to scan for localhost URL replacements.
     */
    const SCANNABLE_EXTENSIONS = ['html', 'js', 'css', 'json', 'xml', 'txt', 'svg'];

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be changed without modifying files');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $repoDir = Git::getGitLocalFolder() ?: WORKING_DIR;
        $staticDir = CloudflareHelper::staticDir($repoDir);
        $localOrigin = CloudflareHelper::localOrigin($repoDir);
        $productionUrl = CloudflareHelper::productionUrl($repoDir);
        $dryRun = $input->getOption('dry-run');

        $output->writeln('');
        $label = $dryRun ? 'Prepare Static Output (dry run)' : 'Prepare Static Output';
        $output->writeln("<fg=cyan>  ── Cloudflare · {$label} ─────────────────────</>");
        $output->writeln('');

        if (!is_dir($staticDir)) {
            $output->writeln("    <fg=red>FAIL:</> Static output directory does not exist");
            $output->writeln("    <fg=gray>Expected:</> <fg=white>{$staticDir}</>");
            $output->writeln('');
            return Command::FAILURE;
        }

        $steps = 0;

        // ── Step 1: Handle 404.html ──────────────────────────────────
        $fourOhFour = $staticDir . '/404.html';
        $fourOhFourIndex = $staticDir . '/404/index.html';

        if (!file_exists($fourOhFour)) {
            if (file_exists($fourOhFourIndex)) {
                if ($dryRun) {
                    $output->writeln("    <fg=yellow>→</> Would copy 404/index.html → 404.html");
                } else {
                    copy($fourOhFourIndex, $fourOhFour);
                    $output->writeln("    <fg=green>✓</> Copied 404/index.html → 404.html");
                }
                $steps++;
            } else {
                $output->writeln("    <fg=yellow>!</> No 404.html found — consider generating one");
            }
        } else {
            $output->writeln("    <fg=green>✓</> 404.html already exists");
        }

        // ── Step 2: Replace localhost URLs ───────────────────────────
        $output->writeln('');
        $output->writeln("    <fg=gray>Scanning for localhost URLs...</>");
        $output->writeln("    <fg=gray>Replacing:</> <fg=white>{$localOrigin}</> → <fg=white>{$productionUrl}</>");
        $output->writeln('');

        // Build the list of patterns to replace
        // Handle both plain and escaped-slash variants (JSON often has \/ escaping)
        $localOriginEscaped = str_replace('/', '\\/', $localOrigin);
        $productionUrlEscaped = str_replace('/', '\\/', $productionUrl);

        // Also handle protocol-relative //localhost
        $localHost = parse_url($localOrigin, PHP_URL_HOST);
        $productionHost = parse_url($productionUrl, PHP_URL_HOST);

        $replacements = [
            $localOrigin => $productionUrl,
            $localOriginEscaped => $productionUrlEscaped,
            '//' . $localHost => '//' . $productionHost,
        ];

        $filesChanged = 0;
        $totalReplacements = 0;

        $cfIgnorePatterns = CloudflareHelper::loadCfIgnore($staticDir);
        $baseLen = strlen(rtrim($staticDir, '/')) + 1;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($staticDir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $rel = substr($file->getPathname(), $baseLen);
            if (CloudflareHelper::isExcluded($rel, $cfIgnorePatterns)) {
                continue;
            }

            $ext = strtolower(pathinfo($file->getFilename(), PATHINFO_EXTENSION));
            if (!in_array($ext, self::SCANNABLE_EXTENSIONS)) {
                continue;
            }

            $contents = file_get_contents($file->getPathname());
            $newContents = ($ext === 'html')
                ? $this->replaceInHtmlContexts($contents, $replacements)
                : str_replace(array_keys($replacements), array_values($replacements), $contents);

            if ($newContents !== $contents) {
                $fileReplacements = $this->countReplacements($contents, $newContents, $replacements, $ext);
                $totalReplacements += $fileReplacements;
                $filesChanged++;

                if ($dryRun) {
                    $output->writeln("      <fg=yellow>~</> {$rel} <fg=gray>({$fileReplacements} replacements)</>");
                } else {
                    file_put_contents($file->getPathname(), $newContents);
                }
            }
        }

        $output->writeln('');
        if ($filesChanged > 0) {
            $verb = $dryRun ? 'Would replace' : 'Replaced';
            $output->writeln("    <fg=green>✓</> {$verb} {$totalReplacements} occurrences across {$filesChanged} files");
        } else {
            $output->writeln("    <fg=green>✓</> No localhost URLs found — output is clean");
        }

        $steps += $filesChanged;

        // ── Summary ──────────────────────────────────────────────────
        $output->writeln('');
        if ($dryRun) {
            $output->writeln("    <fg=yellow;options=bold>Dry run complete.</> No files were modified.");
        } elseif ($steps > 0) {
            $output->writeln("    <fg=green;options=bold>Preparation complete.</> Static output is ready for deployment.");
        } else {
            $output->writeln("    <fg=green>Static output was already clean.</> No changes needed.");
        }
        $output->writeln('');

        return Command::SUCCESS;
    }

    /**
     * Replace localhost URLs only in HTML contexts where they would be loaded:
     * - Attribute values: href="...", src="...", content="...", action="...", srcset="...", data-*="..."
     * - Inline CSS: url(...)
     * - JSON-LD / script blocks
     *
     * Plain text mentions (e.g. documentation explaining localhost) are left alone.
     */
    private function replaceInHtmlContexts(string $html, array $replacements): string
    {
        $searches = array_keys($replacements);
        $replaces = array_values($replacements);

        // 1. Replace inside HTML attribute values (href, src, action, content, srcset, poster, data-*)
        //    Matches: attribute="...localhost..." or attribute='...localhost...'
        $html = preg_replace_callback(
            '/(\b(?:href|src|srcset|action|content|poster|data-[\w-]+)\s*=\s*)(["\'])(.*?)\2/si',
            function ($match) use ($searches, $replaces) {
                return $match[1] . $match[2] . str_replace($searches, $replaces, $match[3]) . $match[2];
            },
            $html
        );

        // 2. Replace inside inline CSS url() references
        $html = preg_replace_callback(
            '/url\(\s*(["\']?)(.*?)\1\s*\)/si',
            function ($match) use ($searches, $replaces) {
                return 'url(' . $match[1] . str_replace($searches, $replaces, $match[2]) . $match[1] . ')';
            },
            $html
        );

        // 3. Replace inside <script type="application/ld+json"> blocks (JSON-LD structured data)
        $html = preg_replace_callback(
            '/(<script\b[^>]*type\s*=\s*["\']application\/ld\+json["\'][^>]*>)(.*?)(<\/script>)/si',
            function ($match) use ($searches, $replaces) {
                return $match[1] . str_replace($searches, $replaces, $match[2]) . $match[3];
            },
            $html
        );

        // 4. Replace inside <meta> tags with http-equiv="refresh" (redirect URLs)
        $html = preg_replace_callback(
            '/(<meta\b[^>]*http-equiv\s*=\s*["\']refresh["\'][^>]*content\s*=\s*["\'])([^"\']*?)(["\'][^>]*>)/si',
            function ($match) use ($searches, $replaces) {
                return $match[1] . str_replace($searches, $replaces, $match[2]) . $match[3];
            },
            $html
        );

        return $html;
    }

    /**
     * Count how many replacements were made by comparing original and result.
     */
    private function countReplacements(string $original, string $result, array $replacements, string $ext): int
    {
        if ($ext === 'html') {
            // For HTML, count occurrences of search terms that are no longer present
            // minus occurrences that remain (some may be in plain text, intentionally kept)
            $count = 0;
            foreach ($replacements as $search => $replace) {
                $beforeCount = substr_count($original, $search);
                $afterCount = substr_count($result, $search);
                $count += ($beforeCount - $afterCount);
            }
            return $count;
        }
        // For non-HTML, simple count of search terms in original
        $count = 0;
        foreach ($replacements as $search => $replace) {
            $count += substr_count($original, $search);
        }
        return $count;
    }
}
