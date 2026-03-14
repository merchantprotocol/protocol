<?php
namespace Gitcd\Plugins\cloudflare\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
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

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $repoDir = Git::getGitLocalFolder() ?: WORKING_DIR;
        $staticDir = CloudflareHelper::staticDir($repoDir);
        $localOrigin = CloudflareHelper::localOrigin($repoDir);
        $productionUrl = CloudflareHelper::productionUrl($repoDir);

        $output->writeln('');
        $output->writeln('<fg=cyan>  ── Cloudflare · Prepare Static Output ─────────────────────</>');
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
                copy($fourOhFourIndex, $fourOhFour);
                $output->writeln("    <fg=green>✓</> Copied 404/index.html → 404.html");
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

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($staticDir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $ext = strtolower(pathinfo($file->getFilename(), PATHINFO_EXTENSION));
            if (!in_array($ext, self::SCANNABLE_EXTENSIONS)) {
                continue;
            }

            $contents = file_get_contents($file->getPathname());
            $newContents = $contents;

            foreach ($replacements as $search => $replace) {
                $newContents = str_replace($search, $replace, $newContents);
            }

            if ($newContents !== $contents) {
                file_put_contents($file->getPathname(), $newContents);
                $fileReplacements = 0;
                foreach ($replacements as $search => $replace) {
                    $fileReplacements += substr_count($contents, $search);
                }
                $totalReplacements += $fileReplacements;
                $filesChanged++;
            }
        }

        if ($filesChanged > 0) {
            $output->writeln("    <fg=green>✓</> Replaced {$totalReplacements} occurrences across {$filesChanged} files");
        } else {
            $output->writeln("    <fg=green>✓</> No localhost URLs found — output is clean");
        }

        $steps += $filesChanged;

        // ── Summary ──────────────────────────────────────────────────
        $output->writeln('');
        if ($steps > 0) {
            $output->writeln("    <fg=green;options=bold>Preparation complete.</> Static output is ready for deployment.");
        } else {
            $output->writeln("    <fg=green>Static output was already clean.</> No changes needed.");
        }
        $output->writeln('');

        return Command::SUCCESS;
    }
}
