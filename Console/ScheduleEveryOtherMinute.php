<?php

declare(strict_types = 1);

namespace JMS\JobQueueBundle\Console;

use JMS\JobQueueBundle\Entity\Job;
use Symfony\Component\Console\Command\Command;

trait ScheduleEveryOtherMinute
{
    use ScheduleInSecondInterval;

    protected function getScheduleInterval(): int
    {
        return 120;
    }
}