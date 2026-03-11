<?php
/**
 * Check Wazuh SIEM agent status.
 */
namespace Gitcd\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Gitcd\Helpers\Shell;

class SiemStatus extends Command
{
    protected static $defaultName = 'siem:status';
    protected static $defaultDescription = 'Check the status of the Wazuh SIEM agent on this node';

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('');
        $output->writeln('<fg=white;options=bold>  Wazuh SIEM Agent Status</>');
        $output->writeln('');

        // Check if installed
        $installed = is_dir('/var/ossec') || is_dir('/Library/Ossec');
        if (!$installed) {
            $output->writeln('  <fg=yellow>Not installed.</> Run <fg=cyan>protocol siem:install</> to set up.');
            $output->writeln('');
            return Command::SUCCESS;
        }

        $output->writeln('  Installed: <fg=green>Yes</>');

        // Get agent status
        $controlBin = is_file('/Library/Ossec/bin/wazuh-control')
            ? '/Library/Ossec/bin/wazuh-control'
            : '/var/ossec/bin/wazuh-control';

        if (is_file($controlBin)) {
            $status = Shell::run("sudo {$controlBin} status 2>/dev/null");
            if ($status) {
                $output->writeln('');
                foreach (explode("\n", $status) as $line) {
                    $line = trim($line);
                    if (!$line) continue;
                    if (str_contains($line, 'running')) {
                        $output->writeln("  <fg=green>{$line}</>");
                    } elseif (str_contains($line, 'stopped')) {
                        $output->writeln("  <fg=red>{$line}</>");
                    } else {
                        $output->writeln("  {$line}");
                    }
                }
            }
        } elseif (trim(Shell::run('which systemctl 2>/dev/null'))) {
            $status = Shell::run('systemctl is-active wazuh-agent 2>/dev/null');
            $active = trim($status) === 'active';
            $output->writeln('  Service: ' . ($active ? '<fg=green>active</>' : '<fg=red>inactive</>'));
        }

        // Show manager connection
        $configFile = is_file('/var/ossec/etc/ossec.conf')
            ? '/var/ossec/etc/ossec.conf'
            : '/Library/Ossec/etc/ossec.conf';

        if (is_file($configFile)) {
            $config = file_get_contents($configFile);
            if (preg_match('/<address>(.*?)<\/address>/', $config, $matches)) {
                $output->writeln("  Manager: <fg=cyan>{$matches[1]}</>");
            }
        }

        // Check Protocol log forwarding
        $protocolLogConfigured = false;
        if (is_file($configFile)) {
            $config = file_get_contents($configFile);
            $protocolLogConfigured = str_contains($config, 'Protocol deployment audit log');
        }
        $output->writeln('  Protocol log forwarding: ' . ($protocolLogConfigured ? '<fg=green>configured</>' : '<fg=yellow>not configured</>'));

        $output->writeln('');
        return Command::SUCCESS;
    }
}
