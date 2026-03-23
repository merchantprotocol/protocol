<?php
namespace Gitcd\Plugins\cloudflare\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Gitcd\Plugins\cloudflare\CloudflareHelper;
use Gitcd\Commands\Init\DotMenuTrait;
use Gitcd\Helpers\Git;
use Gitcd\Helpers\Shell;
use Gitcd\Utils\Json;

class CloudflareInit extends Command
{
    use DotMenuTrait;

    protected static $defaultName = 'cf:init';
    protected static $defaultDescription = 'Set up Cloudflare Pages deployment for this project';

    /**
     * Files/directories commonly found in project roots that shouldn't be deployed.
     */
    const KNOWN_NON_DEPLOY = [
        'docker-compose.yml',
        'docker-compose.yaml',
        'Dockerfile',
        'Makefile',
        'justfile',
        'Justfile',
        'cloudflare.sh',
        'protocol.json',
        '.env.deployment',
        '.protocol/',
        'composer.json',
        'composer.lock',
        'package.json',
        'package-lock.json',
        'yarn.lock',
        'node_modules/',
        '.git/',
        '.backups/',
        '.wrangler/',
        '.DS_Store',
        'nginx.d/',
        'cron.d/',
        'supervisor.d/',
        'functions/',
        'vendor/',
        'README.md',
        'LICENSE',
    ];

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $repoDir = Git::getGitLocalFolder() ?: WORKING_DIR;
        $helper = $this->getHelper('question');
        $totalSteps = 5;

        $this->writeBanner($output);

        // Detect if already configured
        $existingProject = Json::read('cloudflare.project_name', null, $repoDir);
        if ($existingProject) {
            $output->writeln("    <fg=gray>Cloudflare is already configured for this project.</>");
            $output->writeln("    <fg=gray>Project:</> <fg=white>{$existingProject}</>");
            $output->writeln('');

            $reconfigure = $this->askWithDots($input, $output, $helper, [
                'reconfigure' => 'Reconfigure — update settings',
                'exit'        => 'Exit without changes',
            ], 'reconfigure');

            if ($reconfigure === 'exit') {
                $output->writeln('');
                $output->writeln('    <fg=gray>No changes made.</>');
                $output->writeln('');
                return Command::SUCCESS;
            }
            $output->writeln('');
        }

        // ── Step 1: Wrangler Authentication ─────────────────────────
        $this->writeStep($output, 1, $totalSteps, 'Wrangler Authentication');

        // Ensure wrangler is installed globally
        $output->writeln("    <fg=gray>Checking for Wrangler CLI...</>");
        $output->writeln('');

        $wranglerInstalled = false;
        Shell::run('which wrangler 2>/dev/null', $returnVar);
        if ($returnVar === 0) {
            $wranglerVersion = trim(Shell::run('wrangler --version 2>&1', $rv));
            $output->writeln("    <fg=green>✓</> Wrangler installed: <fg=gray>{$wranglerVersion}</>");
            $wranglerInstalled = true;
        } else {
            // Check if npx can find it
            Shell::run('npx wrangler --version 2>/dev/null', $returnVar);
            if ($returnVar === 0) {
                $output->writeln("    <fg=yellow>!</> Wrangler available via npx but not installed globally");
            } else {
                $output->writeln("    <fg=red>✖</> Wrangler not found");
            }
            $output->writeln('');

            $question = new ConfirmationQuestion(
                '    Install Wrangler globally? [<fg=green>Y</>/n] ', true
            );
            if ($helper->ask($input, $output, $question)) {
                $output->writeln('');
                $output->writeln("    <fg=gray>Installing wrangler globally via npm...</>");
                Shell::passthru('npm install -g wrangler');
                $output->writeln('');

                Shell::run('which wrangler 2>/dev/null', $returnVar);
                if ($returnVar === 0) {
                    $wranglerVersion = trim(Shell::run('wrangler --version 2>&1', $rv));
                    $output->writeln("    <fg=green>✓</> Wrangler installed: <fg=gray>{$wranglerVersion}</>");
                    $wranglerInstalled = true;
                } else {
                    $output->writeln("    <fg=yellow>!</> Global install may require restarting your shell");
                }
            }
        }
        $output->writeln('');

        $output->writeln("    <fg=gray>Checking if Wrangler is authenticated with Cloudflare...</>");
        $output->writeln('');

        $whoami = Shell::run('npx wrangler whoami 2>&1', $returnVar);
        $isAuthenticated = ($returnVar === 0 && !str_contains($whoami, 'not authenticated'));

        if ($isAuthenticated) {
            // Extract account info from whoami output
            $output->writeln("    <fg=green>✓</> Wrangler is authenticated");
            // Show the relevant account line
            foreach (explode("\n", $whoami) as $line) {
                $line = trim($line);
                if (str_contains($line, 'account') || str_contains($line, 'Account') || str_contains($line, '@')) {
                    if (!str_contains($line, 'wrangler') && !str_contains($line, '--')) {
                        $output->writeln("    <fg=gray>{$line}</>");
                    }
                }
            }
        } else {
            $output->writeln("    <fg=yellow>!</> Wrangler is not authenticated");
            $output->writeln('');
            $output->writeln("    <fg=gray>Run the following command to log in:</>");
            $output->writeln("    <fg=white>protocol cf:login</>");
            $output->writeln('');

            $question = new ConfirmationQuestion(
                '    Open Wrangler login now? [<fg=green>Y</>/n] ', true
            );
            if ($helper->ask($input, $output, $question)) {
                $output->writeln('');
                // Use cf:login to request all scopes
                $loginCommand = $this->getApplication()->find('cf:login');
                $loginCommand->run(new \Symfony\Component\Console\Input\ArrayInput([]), $output);
                $output->writeln('');

                // Re-check
                $whoami = Shell::run('npx wrangler whoami 2>&1', $returnVar);
                if ($returnVar !== 0) {
                    $output->writeln("    <fg=red>FAIL:</> Authentication failed. Run <fg=white>npx wrangler login</> manually and try again.");
                    $output->writeln('');
                    return Command::FAILURE;
                }
                $output->writeln("    <fg=green>✓</> Wrangler is now authenticated");
            } else {
                $output->writeln('');
                $output->writeln("    <fg=yellow>!</> Continuing without authentication — deploy will fail until you log in.");
            }
        }

        // ── Step 2: Cloudflare Pages Project ────────────────────────
        $this->writeStep($output, 2, $totalSteps, 'Cloudflare Pages Project');

        $output->writeln("    <fg=gray>Fetching your Cloudflare Pages projects...</>");
        $output->writeln('');

        $projectList = Shell::run('npx wrangler pages project list 2>&1', $returnVar);
        $projects = [];
        $projectDomains = []; // project_name => [domain1, domain2, ...]
        if ($returnVar === 0 && $projectList) {
            foreach (explode("\n", $projectList) as $line) {
                $line = trim($line);
                // Wrangler outputs a table with │ delimiters — extract project name and domains
                // Skip header separators (─), the header row (Project Name), and empty lines
                if ($line && str_contains($line, '│') && !str_contains($line, '─') && !str_contains($line, 'Project Name')) {
                    $cols = explode('│', $line);
                    // First real column is index 1 (name), index 2 is domains
                    if (isset($cols[1])) {
                        $name = trim($cols[1]);
                        if ($name && preg_match('/^[a-z0-9][a-z0-9-]*$/', $name)) {
                            $projects[] = $name;
                            // Parse domains from second column
                            if (isset($cols[2])) {
                                $domains = array_map('trim', explode(',', trim($cols[2])));
                                $projectDomains[$name] = array_filter($domains);
                            }
                        }
                    }
                }
            }
        }

        $projectOptions = [];
        foreach ($projects as $p) {
            // Show custom domain in the menu if available
            $customDomain = '';
            if (!empty($projectDomains[$p])) {
                foreach ($projectDomains[$p] as $d) {
                    if (!str_ends_with($d, '.pages.dev')) {
                        $customDomain = $d;
                        break;
                    }
                }
            }
            $projectOptions[$p] = $customDomain ? "{$p}  ({$customDomain})" : $p;
        }
        $projectOptions['__create__'] = 'Create a new project';

        $defaultProject = $existingProject ?: basename($repoDir);
        // If the default matches an existing project, pre-select it
        if (!isset($projectOptions[$defaultProject])) {
            $defaultProject = !empty($projects) ? $projects[0] : '__create__';
        }

        if (!empty($projects)) {
            $output->writeln("    <fg=gray>Found " . count($projects) . " project(s). Select one or create new:</>");
            $output->writeln('');
        } else {
            $output->writeln("    <fg=gray>No existing projects found. Let's create one.</>");
            $output->writeln('');
            $defaultProject = '__create__';
        }

        $selectedProject = $this->askWithDots($input, $output, $helper, $projectOptions, $defaultProject);

        if ($selectedProject === '__create__') {
            $output->writeln('');
            $suggestedName = preg_replace('/[^a-z0-9-]/', '-', strtolower(basename($repoDir)));
            $question = new Question(
                "    Project name [<fg=green>{$suggestedName}</>]: ",
                $suggestedName
            );
            $newProjectName = $helper->ask($input, $output, $question);
            $newProjectName = preg_replace('/[^a-z0-9-]/', '-', strtolower($newProjectName));

            $output->writeln('');
            $output->writeln("    <fg=gray>Creating Cloudflare Pages project:</> <fg=white>{$newProjectName}</>");
            $output->writeln('');

            Shell::passthru("npx wrangler pages project create " . escapeshellarg($newProjectName) . " --production-branch=main");

            $selectedProject = $newProjectName;
            $output->writeln('');
        }

        $output->writeln("    <fg=green>✓</> Project: <fg=white;options=bold>{$selectedProject}</>");

        // ── Step 3: Configuration ───────────────────────────────────
        $this->writeStep($output, 3, $totalSteps, 'Project Configuration');

        // Production URL — prefer custom domain from Cloudflare if available
        $existingUrl = Json::read('cloudflare.production_url', '', $repoDir);
        $suggestedUrl = $existingUrl;
        if (!$suggestedUrl && !empty($projectDomains[$selectedProject])) {
            // Prefer a custom domain (non-pages.dev) over the default
            foreach ($projectDomains[$selectedProject] as $d) {
                if (!str_ends_with($d, '.pages.dev')) {
                    $suggestedUrl = "https://{$d}";
                    break;
                }
            }
        }
        if (!$suggestedUrl) {
            $suggestedUrl = "https://{$selectedProject}.pages.dev";
        }
        $question = new Question(
            "    Production URL [<fg=green>{$suggestedUrl}</>]: ",
            $suggestedUrl
        );
        $productionUrl = $helper->ask($input, $output, $question);
        $output->writeln("    <fg=green>✓</> URL: <fg=white>{$productionUrl}</>");
        $output->writeln('');

        // Local origin
        $existingOrigin = Json::read('cloudflare.local_origin', 'https://localhost', $repoDir);
        $question = new Question(
            "    Local dev origin [<fg=green>{$existingOrigin}</>]: ",
            $existingOrigin
        );
        $localOrigin = $helper->ask($input, $output, $question);
        $output->writeln("    <fg=green>✓</> Local origin: <fg=white>{$localOrigin}</>");
        $output->writeln('');

        // Static directory — detect common build output directories
        $suggestedDir = '.';
        $buildDirCandidates = [
            './static-output',   // WordPress / Simply Static
            './website/build',   // Docusaurus (monorepo)
            './build',           // Docusaurus / CRA / generic
            './dist',            // Vite / Vue / Angular
            './public',          // Hugo / some static generators
            './_site',           // Jekyll
            './out',             // Next.js static export
        ];
        foreach ($buildDirCandidates as $candidate) {
            $candidatePath = rtrim($repoDir, '/') . '/' . ltrim($candidate, './');
            if (is_dir($candidatePath)) {
                $suggestedDir = $candidate;
                break;
            }
        }
        $existingDir = Json::read('cloudflare.static_dir', $suggestedDir, $repoDir);
        $question = new Question(
            "    Static directory [<fg=green>{$existingDir}</>]: ",
            $existingDir
        );
        $staticDir = $helper->ask($input, $output, $question);
        $output->writeln("    <fg=green>✓</> Static dir: <fg=white>{$staticDir}</>");
        $output->writeln('');

        // Min files
        $resolvedStaticDir = CloudflareHelper::staticDir($repoDir);
        if ($staticDir === '.') {
            // For root deploys, count non-infrastructure files as a rough guide
            $suggestedMin = 10;
        } elseif (is_dir($resolvedStaticDir)) {
            $currentCount = CloudflareHelper::countFiles($resolvedStaticDir);
            $suggestedMin = max(10, intval($currentCount * 0.8));
        } else {
            $suggestedMin = 10;
        }
        $existingMin = Json::read('cloudflare.min_files', $suggestedMin, $repoDir);
        $question = new Question(
            "    Minimum file count for verification [<fg=green>{$existingMin}</>]: ",
            $existingMin
        );
        $minFiles = (int) $helper->ask($input, $output, $question);
        $output->writeln("    <fg=green>✓</> Min files: <fg=white>{$minFiles}</>");

        // ── Step 4: .cfignore ───────────────────────────────────────
        $this->writeStep($output, 4, $totalSteps, 'Deploy Exclusions');

        $staticDirAbsolute = $staticDir === '.'
            ? rtrim($repoDir, '/')
            : rtrim($repoDir, '/') . '/' . ltrim($staticDir, './');

        $cfIgnorePath = $staticDirAbsolute . '/.cfignore';
        $existingIgnore = file_exists($cfIgnorePath);

        // Scan for non-deploy files
        $detected = [];
        foreach (self::KNOWN_NON_DEPLOY as $pattern) {
            $checkPath = $staticDirAbsolute . '/' . rtrim($pattern, '/');
            if (file_exists($checkPath) || is_dir($checkPath)) {
                $detected[] = $pattern;
            }
        }

        if ($existingIgnore) {
            $output->writeln("    <fg=green>✓</> .cfignore already exists");
            $output->writeln('');
            $currentContents = file_get_contents($cfIgnorePath);
            $output->writeln("    <fg=gray>Current contents:</>");
            foreach (explode("\n", trim($currentContents)) as $line) {
                $output->writeln("      <fg=white>{$line}</>");
            }
            $output->writeln('');

            if (!empty($detected)) {
                // Check for entries not already in the ignore file
                $missing = [];
                foreach ($detected as $d) {
                    if (!str_contains($currentContents, rtrim($d, '/'))) {
                        $missing[] = $d;
                    }
                }
                if (!empty($missing)) {
                    $output->writeln("    <fg=yellow>!</> Found " . count($missing) . " additional file(s) that may need excluding:");
                    foreach ($missing as $m) {
                        $output->writeln("      <fg=yellow>+</> {$m}");
                    }
                    $output->writeln('');
                    $question = new ConfirmationQuestion(
                        '    Add these to .cfignore? [<fg=green>Y</>/n] ', true
                    );
                    if ($helper->ask($input, $output, $question)) {
                        $append = "\n# Auto-detected\n" . implode("\n", $missing) . "\n";
                        file_put_contents($cfIgnorePath, $append, FILE_APPEND);
                        $output->writeln("    <fg=green>✓</> Updated .cfignore");
                    }
                }
            }
        } elseif (!empty($detected)) {
            $output->writeln("    <fg=gray>Detected " . count($detected) . " non-deploy file(s) in your static directory:</>");
            $output->writeln('');
            foreach ($detected as $d) {
                $output->writeln("      <fg=yellow>·</> {$d}");
            }
            $output->writeln('');

            $question = new ConfirmationQuestion(
                '    Create .cfignore with these exclusions? [<fg=green>Y</>/n] ', true
            );
            if ($helper->ask($input, $output, $question)) {
                $ignoreContent = "# Cloudflare Pages deploy exclusions\n";
                $ignoreContent .= "# Files listed here are excluded from deploy, verify, and prepare\n\n";
                $ignoreContent .= implode("\n", $detected) . "\n";
                file_put_contents($cfIgnorePath, $ignoreContent);
                $output->writeln('');
                $output->writeln("    <fg=green>✓</> Created .cfignore (" . count($detected) . " exclusions)");
            }
        } else {
            $output->writeln("    <fg=green>✓</> No non-deploy files detected — .cfignore not needed");
        }

        // ── Step 5: Save & Verify ───────────────────────────────────
        $this->writeStep($output, 5, $totalSteps, 'Save & Verify');

        // Write config to protocol.json
        Json::write('cloudflare.project_name', $selectedProject, $repoDir);
        Json::write('cloudflare.production_url', $productionUrl, $repoDir);
        Json::write('cloudflare.local_origin', $localOrigin, $repoDir);
        Json::write('cloudflare.static_dir', $staticDir, $repoDir);
        Json::write('cloudflare.min_files', $minFiles, $repoDir);
        Json::save($repoDir);

        $output->writeln("    <fg=green>✓</> Saved configuration to protocol.json");
        $output->writeln('');

        // Ensure .backups/ is in .gitignore
        if (Git::isInitializedRepo($repoDir)) {
            Git::addIgnore('.backups/', $repoDir);
            $output->writeln("    <fg=green>✓</> .backups/ added to .gitignore");
            $output->writeln('');
        }

        // Run cf:verify
        $output->writeln("    <fg=gray>Running verification...</>");
        $output->writeln('');

        $verifyCommand = $this->getApplication()->find('cf:verify');
        $verifyCommand->run(new \Symfony\Component\Console\Input\ArrayInput([]), $output);

        // ── Completion ──────────────────────────────────────────────
        $output->writeln('');
        $output->writeln('<fg=cyan>  ┌─────────────────────────────────────────────────────────┐</>');
        $output->writeln('<fg=cyan>  │</>                                                         <fg=cyan>│</>');
        $output->writeln('<fg=cyan>  │</>   <fg=green;options=bold>✓  Cloudflare Pages Ready!</>                            <fg=cyan>│</>');
        $output->writeln('<fg=cyan>  │</>                                                         <fg=cyan>│</>');
        $output->writeln('<fg=cyan>  │</>   <fg=white;options=bold>Next steps:</>                                            <fg=cyan>│</>');
        $output->writeln('<fg=cyan>  │</>                                                         <fg=cyan>│</>');
        $output->writeln('<fg=cyan>  │</>   <fg=yellow>1.</> <fg=white>protocol cf:prepare</>      <fg=gray>Fix URLs for deploy</>   <fg=cyan>│</>');
        $output->writeln('<fg=cyan>  │</>   <fg=yellow>2.</> <fg=white>protocol cf:deploy</>       <fg=gray>Full deploy pipeline</> <fg=cyan>│</>');
        $output->writeln('<fg=cyan>  │</>                                                         <fg=cyan>│</>');
        $output->writeln('<fg=cyan>  └─────────────────────────────────────────────────────────┘</>');
        $output->writeln('');
        $output->writeln("    <fg=gray>Project:</>     <fg=white>{$selectedProject}</>");
        $output->writeln("    <fg=gray>URL:</>         <fg=white>{$productionUrl}</>");
        $output->writeln("    <fg=gray>Static dir:</>  <fg=white>{$staticDir}</>");
        $output->writeln("    <fg=gray>Min files:</>   <fg=white>{$minFiles}</>");
        $output->writeln('');

        return Command::SUCCESS;
    }

    // ─── Display helpers ─────────────────────────────────────────

    protected function writeBanner(OutputInterface $output): void
    {
        fwrite(STDOUT, "\033[2J\033[H");
        $output->writeln('');
        $output->writeln('<fg=cyan>  ┌─────────────────────────────────────────────────────────┐</>');
        $output->writeln('<fg=cyan>  │</>                                                         <fg=cyan>│</>');
        $output->writeln('<fg=cyan>  │</>   <fg=white;options=bold>CLOUDFLARE PAGES</> <fg=gray>·</> <fg=yellow>Setup Wizard</>                     <fg=cyan>│</>');
        $output->writeln('<fg=cyan>  │</>   <fg=gray>Deploy static sites to Cloudflare Pages via Protocol</>  <fg=cyan>│</>');
        $output->writeln('<fg=cyan>  │</>                                                         <fg=cyan>│</>');
        $output->writeln('<fg=cyan>  └─────────────────────────────────────────────────────────┘</>');
        $output->writeln('');
    }

    protected function writeStep(OutputInterface $output, int $step, int $total, string $title): void
    {
        fwrite(STDOUT, "\033[2J\033[H");
        $this->writeBanner($output);
        $output->writeln("<fg=cyan>  ── </><fg=white;options=bold>[{$step}/{$total}] {$title}</><fg=cyan> ──────────────────────────────────────</>");
        $output->writeln('');
    }
}
