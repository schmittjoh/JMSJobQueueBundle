<?php

declare(strict_types=1);

namespace JMS\JobQueueBundle\Console;

trait ScheduleEveryOtherMinute
{
    use ScheduleInSecondInterval;

    protected function getScheduleInterval(): int
    {
        return 120;
    }
}
