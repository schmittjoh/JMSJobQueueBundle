<?php

namespace JMS\JobQueueBundle\Event;

use JMS\JobQueueBundle\Entity\Job;
use Symfony\Component\EventDispatcher\Event;

abstract class JobEvent extends Event
{
    private $job;

    public function __construct(Job $job)
    {
        $this->job = $job;
    }

    public function getJob()
    {
        return $this->job;
    }
}