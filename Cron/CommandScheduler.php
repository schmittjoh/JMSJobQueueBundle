<?php

namespace JMS\JobQueueBundle\Cron;

use JMS\JobQueueBundle\Console\CronCommand;

class CommandScheduler implements JobScheduler
{
    private $command;

    public function __construct(CronCommand $command)
    {
        $this->command = $command;
    }

    public function shouldSchedule($_, \DateTimeInterface $lastRunAt)
    {
        return $this->command->shouldBeScheduled($lastRunAt);
    }

    public function createJob($_, \DateTimeInterface $lastRunAt)
    {
        return $this->command->createCronJob($lastRunAt);
    }
}