<?php

declare(strict_types=1);

namespace DigitalTolk\TmsConnector\Command;

use DigitalTolk\TmsConnector\Service\SyncService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Core\Bootstrap;
use TYPO3\CMS\Core\Locking\Exception\LockAcquireWouldBlockException;
use TYPO3\CMS\Core\Locking\LockFactory;
use TYPO3\CMS\Core\Locking\LockingStrategyInterface;

final class SyncCommand extends Command
{
    public function __construct(
        private readonly SyncService $sync,
        private readonly LockFactory $locks,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Send queued packages to DigitalTolk, poll open packages, download and import translations.')
            ->addOption('poll-all', null, InputOption::VALUE_NONE, 'Poll every open package now, not only the due ones.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $lock = $this->locks->createLocker('dt_tms_connector_sync', LockingStrategyInterface::LOCK_CAPABILITY_EXCLUSIVE | LockingStrategyInterface::LOCK_CAPABILITY_NOBLOCK);
        try {
            if (!$lock->acquire(LockingStrategyInterface::LOCK_CAPABILITY_EXCLUSIVE | LockingStrategyInterface::LOCK_CAPABILITY_NOBLOCK)) {
                $output->writeln('Another sync run is active.');

                return Command::SUCCESS;
            }
        } catch (LockAcquireWouldBlockException) {
            $output->writeln('Another sync run is active.');

            return Command::SUCCESS;
        }
        try {
            Bootstrap::initializeBackendAuthentication();
            foreach ($this->sync->run((bool)$input->getOption('poll-all')) as $line) {
                $output->writeln($line);
            }
        } finally {
            $lock->release();
        }

        return Command::SUCCESS;
    }
}
