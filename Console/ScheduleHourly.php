<?php

namespace JMS\JobQueueBundle\Console;

use JMS\JobQueueBundle\Entity\Job;
use Symfony\Component\Console\Command\Command;

trait ScheduleHourly
{
    use ScheduleInSecondInterval;

    protected function getScheduleInterval()
    {
        return 3600;
    }
}