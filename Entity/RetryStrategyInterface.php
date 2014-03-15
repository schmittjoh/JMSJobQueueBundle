<?php
namespace JMS\JobQueueBundle\Entity;

interface RetryStrategyInterface
{
    public function apply(Job $original, Job $retry);
}
