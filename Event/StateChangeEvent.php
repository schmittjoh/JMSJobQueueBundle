<?php

namespace JMS\JobQueueBundle\Event;

use JMS\JobQueueBundle\Entity\Job;

class StateChangeEvent extends JobEvent
{
    private $newState;

    public function __construct(Job $job, $newState)
    {
        parent::__construct($job);

        $this->newState = $newState;
    }

    public function getNewState()
    {
        return $this->newState;
    }

    public function setNewState($state)
    {
        $this->newState = $state;
    }

    public function getOldState()
    {
        return $this->getJob()->getState();
    }
}