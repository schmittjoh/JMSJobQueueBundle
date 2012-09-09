<?php

/*
 * Copyright 2012 Johannes M. Schmitt <schmittjoh@gmail.com>
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace JMS\JobQueueBundle\Event;

use JMS\JobQueueBundle\Entity\Job;
use JMS\JobQueueBundle\Event\JobEvent;

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