<?php

namespace JMS\JobQueueBundle\Tests\Functional\TestBundle\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ThrowsExceptionCommand extends Command
{
    protected static $defaultName = 'jms-job-queue:throws-exception-cmd';

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $output->writeln('Throwing exception');
        throw new \RuntimeException('Something went wrong.');
    }
}
