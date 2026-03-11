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
use Gitcd\Helpers\AuditLog;

Class DeployLog extends Command {

    protected static $defaultName = 'deploy:log';
    protected static $defaultDescription = 'View the deployment audit log';

    protected function configure(): void
    {
        $this
            ->setHelp(<<<HELP
            Displays recent deployment audit log entries.

            HELP)
        ;
        $this
            ->addOption('limit', 'l', InputOption::VALUE_OPTIONAL, 'Number of entries to show', 20)
        ;
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = (int) $input->getOption('limit');
        $entries = AuditLog::read($limit);

        if (empty($entries)) {
            $output->writeln('<comment>No deployment log entries found.</comment>');
            return Command::SUCCESS;
        }

        $output->writeln("<info>Deployment Audit Log</info> (last {$limit} entries)");
        $output->writeln("<comment>Log file: " . AuditLog::logPath() . "</comment>");
        $output->writeln('');

        foreach ($entries as $entry) {
            $output->writeln("  {$entry}");
        }
        $output->writeln('');

        return Command::SUCCESS;
    }
}
