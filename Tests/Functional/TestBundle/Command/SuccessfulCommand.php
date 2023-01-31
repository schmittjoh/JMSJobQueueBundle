<?php

namespace JMS\JobQueueBundle\Tests\Functional\TestBundle\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputInterface;

class SuccessfulCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('jms-job-queue:successful-cmd')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
    }
}