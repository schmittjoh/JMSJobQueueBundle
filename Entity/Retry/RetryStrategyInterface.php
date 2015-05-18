<?php

namespace JMS\JobQueueBundle\Entity\Retry;

use JMS\JobQueueBundle\Entity\Job;

/**
 * Interface RetryStrategyInterface
 * @package JMS\JobQueueBundle\Entity\Retry
 */
interface RetryStrategyInterface {
  /**
   * Apply the retry strategy.
   *
   * @param Job $original
   *   Original job.
   * @param Job $retry
   *   Retry job.
   */
  public function apply(Job $original, Job $retry);
}