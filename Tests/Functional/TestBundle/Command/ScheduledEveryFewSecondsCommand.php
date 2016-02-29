<?php

namespace JMS\JobQueueBundle\Tests\Functional\TestBundle\Command;

use JMS\JobQueueBundle\Console\CronCommand;
use JMS\JobQueueBundle\Entity\Job;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ScheduledEveryFewSecondsCommand extends ContainerAwareCommand implements CronCommand
{
    public function shouldBeScheduled(\DateTime $lastRunAt)
    {
        return time() - $lastRunAt->getTimestamp() >= 5;
    }

    public function createCronJob(\DateTime $_)
    {
        return new Job('scheduled-every-few-seconds');
    }

    protected function configure()
    {
        $this->setName('scheduled-every-few-seconds');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $output->writeln('Done');
    }
}