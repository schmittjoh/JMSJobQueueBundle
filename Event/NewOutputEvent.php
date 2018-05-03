<?php

namespace JMS\JobQueueBundle\Event;

use JMS\JobQueueBundle\Entity\Job;

class NewOutputEvent extends JobEvent
{
    const TYPE_STDOUT = 1;
    const TYPE_STDERR = 2;

    private $newOutput;
    private $type;

    public function __construct(Job $job, $newOutput, $type = self::TYPE_STDOUT)
    {
        parent::__construct($job);
        $this->newOutput = $newOutput;
        $this->type = $type;
    }

    public function getNewOutput()
    {
        return $this->newOutput;
    }

    public function setNewOutput($output)
    {
        $this->newOutput = $output;
    }

    public function getType()
    {
        return $this->type;
    }
}