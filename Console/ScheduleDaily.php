<?php

declare(strict_types=1);

namespace JMS\JobQueueBundle\Console;

trait ScheduleDaily
{
    use ScheduleInSecondInterval;

    protected function getScheduleInterval(): int
    {
        return 86400;
    }
}
