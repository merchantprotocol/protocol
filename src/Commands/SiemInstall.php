<?php
/**
 * Install and configure Wazuh SIEM agent on the node.
 */
namespace Gitcd\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Gitcd\Helpers\Shell;
use Gitcd\Helpers\AuditLog;
use Gitcd\Helpers\Dir;
use Gitcd\Helpers\Git;

class SiemInstall extends Command
{
    protected static $defaultName = 'siem:install';
    protected static $defaultDescription = 'Install and configure Wazuh SIEM agent for centralized security monitoring';

    protected function configure(): void
    {
        $this
            ->setHelp(<<<'HELP'
Installs the Wazuh SIEM agent on this node and configures it to report
to your Wazuh manager. This provides:

  - Real-time file integrity monitoring (FIM)
  - Log collection and forwarding (audit logs, syslog, auth)
  - Rootkit and malware detection
  - Vulnerability detection
  - Regulatory compliance dashboards (SOC 2, PCI DSS, HIPAA)

The agent connects to your Wazuh manager server over an encrypted channel.
You will need the manager IP/hostname and optionally a registration password.

Usage:
  protocol siem:install --manager=10.0.0.1
  protocol siem:install --manager=wazuh.example.com --password=MyRegistrationPass
  protocol siem:install --manager=10.0.0.1 --agent-name=prod-node-1
  protocol siem:status
  protocol siem:install --uninstall
HELP
            )
            ->addOption('manager', 'm', InputOption::VALUE_OPTIONAL, 'Wazuh manager IP or hostname')
            ->addOption('password', 'p', InputOption::VALUE_OPTIONAL, 'Agent registration password')
            ->addOption('agent-name', null, InputOption::VALUE_OPTIONAL, 'Custom agent name (defaults to hostname)')
            ->addOption('dir', 'd', InputOption::VALUE_OPTIONAL, 'Directory Path', Git::getGitLocalFolder())
            ->addOption('uninstall', null, InputOption::VALUE_NONE, 'Uninstall the Wazuh agent');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('uninstall')) {
            return $this->uninstall($output, $input->getOption('dir'));
        }

        $manager = $input->getOption('manager');
        $password = $input->getOption('password');
        $agentName = $input->getOption('agent-name');
        $repoDir = $input->getOption('dir');

        $helper = $this->getHelper('question');

        if (!$manager) {
            $question = new Question('  Wazuh manager IP or hostname: ');
            $manager = $helper->ask($input, $output, $question);
            if (!$manager) {
                $output->writeln('<fg=red>  Manager address is required.</>');
                return Command::FAILURE;
            }
        }

        if (!$password) {
            $question = new Question('  Registration password (leave empty if not required): ', '');
            $password = $helper->ask($input, $output, $question);
        }

        if (!$agentName) {
            $agentName = trim(Shell::run('hostname'));
        }

        $output->writeln('');
        $output->writeln('<fg=white;options=bold>  Wazuh SIEM Agent Installation</>');
        $output->writeln('');

        // Detect OS
        $os = $this->detectOS();
        if (!$os) {
            $output->writeln('<fg=red>  Unsupported operating system. Wazuh agent supports Linux (apt/yum), macOS, and Windows.</>');
            return Command::FAILURE;
        }

        $output->writeln("  OS detected: <fg=cyan>{$os}</>");
        $output->writeln("  Manager: <fg=cyan>{$manager}</>");
        $output->writeln("  Agent name: <fg=cyan>{$agentName}</>");
        $output->writeln('');

        // Check if already installed
        if ($this->isInstalled()) {
            $output->writeln('  <fg=yellow>Wazuh agent is already installed.</> Reconfiguring...');
            $this->configureAgent($output, $manager, $agentName, $password);
            $this->restartAgent($output);

            AuditLog::logConfig($repoDir ?: '.', 'siem-reconfigure', "manager={$manager} agent={$agentName}");
            return Command::SUCCESS;
        }

        // Install based on OS
        $output->writeln('  Installing Wazuh agent...');
        $exitCode = $this->installAgent($output, $os, $manager, $agentName, $password);

        if ($exitCode !== 0) {
            $output->writeln('<fg=red>  Installation failed. Check the output above for details.</>');
            AuditLog::logConfig($repoDir ?: '.', 'siem-install', "status=failure manager={$manager}");
            return Command::FAILURE;
        }

        // Configure Protocol-specific monitoring
        $this->configureProtocolMonitoring($output);

        // Start the agent
        $this->restartAgent($output);

        $output->writeln('');
        $output->writeln('  <fg=green>✓</> Wazuh agent installed and running.');
        $output->writeln('');
        $output->writeln('  The agent will report to your Wazuh manager at ' . $manager);
        $output->writeln('  Check your Wazuh dashboard to verify this agent appears.');
        $output->writeln('');

        AuditLog::logConfig($repoDir ?: '.', 'siem-install', "status=success manager={$manager} agent={$agentName}");

        return Command::SUCCESS;
    }

    private function detectOS(): ?string
    {
        $uname = strtolower(Shell::run('uname -s'));

        if (str_contains($uname, 'linux')) {
            if (is_file('/etc/debian_version') || is_file('/etc/lsb-release')) {
                return 'debian';
            }
            if (is_file('/etc/redhat-release') || is_file('/etc/centos-release')) {
                return 'rhel';
            }
            // Try to detect via package manager
            if (trim(Shell::run('which apt-get 2>/dev/null'))) {
                return 'debian';
            }
            if (trim(Shell::run('which yum 2>/dev/null')) || trim(Shell::run('which dnf 2>/dev/null'))) {
                return 'rhel';
            }
            return 'linux-unknown';
        }

        if (str_contains($uname, 'darwin')) {
            return 'macos';
        }

        return null;
    }

    private function isInstalled(): bool
    {
        // Check common install locations
        if (is_dir('/var/ossec')) return true;
        if (is_dir('/Library/Ossec')) return true;
        if (trim(Shell::run('which wazuh-agent 2>/dev/null'))) return true;

        return false;
    }

    private function installAgent(OutputInterface $output, string $os, string $manager, string $agentName, string $password): int
    {
        $env = "WAZUH_MANAGER=" . escapeshellarg($manager);
        $env .= " WAZUH_AGENT_NAME=" . escapeshellarg($agentName);
        if ($password) {
            $env .= " WAZUH_REGISTRATION_PASSWORD=" . escapeshellarg($password);
        }

        switch ($os) {
            case 'debian':
                $commands = [
                    'curl -s https://packages.wazuh.com/key/GPG-KEY-WAZUH | gpg --no-default-keyring --keyring gnupg-ring:/usr/share/keyrings/wazuh.gpg --import 2>/dev/null && chmod 644 /usr/share/keyrings/wazuh.gpg',
                    'echo "deb [signed-by=/usr/share/keyrings/wazuh.gpg] https://packages.wazuh.com/4.x/apt/ stable main" | tee /etc/apt/sources.list.d/wazuh.list',
                    'apt-get update -q',
                    "{$env} apt-get install -y wazuh-agent",
                    'systemctl daemon-reload',
                    'systemctl enable wazuh-agent',
                ];
                break;

            case 'rhel':
                $commands = [
                    'rpm --import https://packages.wazuh.com/key/GPG-KEY-WAZUH',
                    'cat > /etc/yum.repos.d/wazuh.repo << EOF
[wazuh]
gpgcheck=1
gpgkey=https://packages.wazuh.com/key/GPG-KEY-WAZUH
enabled=1
name=EL-\$releasever - Wazuh
baseurl=https://packages.wazuh.com/4.x/yum/
protect=1
EOF',
                    "{$env} yum install -y wazuh-agent",
                    'systemctl daemon-reload',
                    'systemctl enable wazuh-agent',
                ];
                break;

            case 'macos':
                $output->writeln('  <fg=yellow>macOS: Download the Wazuh agent PKG from the Wazuh dashboard or:</>');
                $output->writeln('  curl -so wazuh-agent.pkg https://packages.wazuh.com/4.x/macos/wazuh-agent-4.9.0-1.intel64.pkg');
                $output->writeln("  sudo {$env} installer -pkg wazuh-agent.pkg -target /");
                $output->writeln('');
                $output->writeln('  Then run: <fg=cyan>protocol siem:install --manager=' . $manager . '</>');
                $output->writeln('  to configure the agent after manual installation.');
                return 0;

            default:
                $output->writeln('  <fg=red>Unsupported Linux distribution. Install manually from https://documentation.wazuh.com</>');
                return 1;
        }

        foreach ($commands as $cmd) {
            $output->writeln("  > {$cmd}");
            Shell::passthru($cmd);
        }

        return 0;
    }

    private function configureAgent(OutputInterface $output, string $manager, string $agentName, string $password): void
    {
        $configFile = $this->getConfigPath();
        if (!$configFile || !is_file($configFile)) {
            $output->writeln('  <fg=yellow>Could not locate ossec.conf. Agent may need manual configuration.</>');
            return;
        }

        $config = file_get_contents($configFile);

        // Update manager address
        $config = preg_replace(
            '/<address>.*?<\/address>/',
            '<address>' . htmlspecialchars($manager) . '</address>',
            $config
        );

        file_put_contents($configFile, $config);
        $output->writeln('  Manager address updated in ossec.conf');
    }

    private function configureProtocolMonitoring(OutputInterface $output): void
    {
        $configFile = $this->getConfigPath();
        if (!$configFile || !is_file($configFile)) return;

        $protocolDir = ($_SERVER['HOME'] ?? getenv('HOME')) . '/.protocol';
        $logPath = $protocolDir . '/deployments.log';

        // Add Protocol-specific monitoring rules
        $protocolConfig = <<<XML

  <!-- Protocol deployment audit log monitoring -->
  <localfile>
    <log_format>syslog</log_format>
    <location>{$logPath}</location>
  </localfile>

  <!-- File integrity monitoring for Protocol encryption keys -->
  <syscheck>
    <directories check_all="yes" realtime="yes">{$protocolDir}</directories>
  </syscheck>
XML;

        $config = file_get_contents($configFile);

        // Only add if not already configured
        if (str_contains($config, 'Protocol deployment audit log')) {
            $output->writeln('  Protocol monitoring already configured.');
            return;
        }

        // Insert before closing </ossec_config> tag
        $config = str_replace('</ossec_config>', $protocolConfig . "\n</ossec_config>", $config);
        file_put_contents($configFile, $config);

        $output->writeln('  <fg=green>✓</> Protocol audit log forwarding configured');
        $output->writeln('  <fg=green>✓</> File integrity monitoring for ~/.protocol/ configured');
    }

    private function restartAgent(OutputInterface $output): void
    {
        if (is_file('/Library/Ossec/bin/wazuh-control')) {
            Shell::passthru('sudo /Library/Ossec/bin/wazuh-control restart');
        } elseif (trim(Shell::run('which systemctl 2>/dev/null'))) {
            Shell::passthru('systemctl restart wazuh-agent');
        } else {
            Shell::passthru('/var/ossec/bin/wazuh-control restart');
        }

        $output->writeln('  Agent restarted.');
    }

    private function uninstall(OutputInterface $output, ?string $repoDir): int
    {
        $output->writeln('');
        $output->writeln('<fg=white;options=bold>  Uninstalling Wazuh SIEM Agent</>');
        $output->writeln('');

        if (!$this->isInstalled()) {
            $output->writeln('  Wazuh agent is not installed.');
            return Command::SUCCESS;
        }

        $os = $this->detectOS();

        switch ($os) {
            case 'debian':
                Shell::passthru('systemctl stop wazuh-agent');
                Shell::passthru('apt-get remove -y wazuh-agent');
                break;
            case 'rhel':
                Shell::passthru('systemctl stop wazuh-agent');
                Shell::passthru('yum remove -y wazuh-agent');
                break;
            case 'macos':
                Shell::passthru('sudo /Library/Ossec/bin/wazuh-control stop');
                $output->writeln('  <fg=yellow>Run: sudo /bin/rm -rf /Library/Ossec to fully remove.</>');
                break;
        }

        $output->writeln('  <fg=green>✓</> Wazuh agent removed.');
        AuditLog::logConfig($repoDir ?: '.', 'siem-uninstall', 'status=success');

        return Command::SUCCESS;
    }

    private function getConfigPath(): ?string
    {
        $paths = [
            '/var/ossec/etc/ossec.conf',
            '/Library/Ossec/etc/ossec.conf',
        ];

        foreach ($paths as $path) {
            if (is_file($path)) return $path;
        }

        return null;
    }
}
