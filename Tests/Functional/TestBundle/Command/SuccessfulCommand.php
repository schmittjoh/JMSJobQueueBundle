<?php

namespace JMS\JobQueueBundle\Tests\Functional\TestBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputInterface;

#[AsCommand(name: 'jms-job-queue:successful-cmd')]
class SuccessfulCommand extends Command
{
    protected function configure()
    {
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
    }
}