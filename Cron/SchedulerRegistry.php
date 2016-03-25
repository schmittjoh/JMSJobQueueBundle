<?php

namespace JMS\JobQueueBundle\Cron;

class SchedulerRegistry
{
    private $schedulers;

    /**
     * @param JobScheduler[] $schedulers
     */
    public function __construct(array $schedulers)
    {
        $this->schedulers = $schedulers;
    }

    public function getSchedulers()
    {
        return $this->schedulers;
    }
}