<?php

namespace JMS\JobQueueBundle\Tests\Functional\TestBundle\Command;

use JMS\JobQueueBundle\Console\CronCommand;
use JMS\JobQueueBundle\Entity\Job;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'scheduled-every-few-seconds')]
class ScheduledEveryFewSecondsCommand extends Command implements CronCommand
{

    public function shouldBeScheduled(\DateTime $lastRunAt): bool
    {
        return time() - $lastRunAt->getTimestamp() >= 5;
    }

    public function createCronJob(\DateTime $_): Job
    {
        return new Job('scheduled-every-few-seconds');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $output->writeln('Done');
    }
}