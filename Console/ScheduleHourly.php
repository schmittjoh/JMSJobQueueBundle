<?php

declare(strict_types=1);

namespace JMS\JobQueueBundle\Console;

trait ScheduleHourly
{
    use ScheduleInSecondInterval;

    protected function getScheduleInterval(): int
    {
        return 3600;
    }
}
