<?php

declare(strict_types=1);

namespace JMS\JobQueueBundle\Console;

trait ScheduleEveryMinute
{
    use ScheduleInSecondInterval;

    protected function getScheduleInterval(): int
    {
        return 60;
    }
}
