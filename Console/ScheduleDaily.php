<?php

namespace JMS\JobQueueBundle\Console;

use JMS\JobQueueBundle\Entity\Job;
use Symfony\Component\Console\Command\Command;

trait ScheduleDaily
{
    use ScheduleInSecondInterval;

    protected function getScheduleInterval()
    {
        return 86400;
    }
}