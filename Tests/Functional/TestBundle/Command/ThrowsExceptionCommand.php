<?php

namespace JMS\JobQueueBundle\Tests\Functional\TestBundle\Command;

use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ThrowsExceptionCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this->setName('jms-job-queue:throws-exception-cmd');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        throw new RuntimeException('Something went wrong.');
    }
}