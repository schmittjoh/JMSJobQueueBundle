<?php

namespace JMS\JobQueueBundle\Console;

use JMS\JobQueueBundle\Entity\Job;
use Symfony\Component\Console\Command\Command;

trait ScheduleEveryMinute
{
    use ScheduleInSecondInterval;

    protected function getScheduleInterval()
    {
        return 60;
    }
}