<?php

namespace JMS\JobQueueBundle\Tests\Functional\TestBundle\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class NeverEndingCommand extends Command
{
    protected function configure()
    {
        $this->setName('jms-job-queue:never-ending');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        while (true) {
            sleep(5);
        }
    }
}