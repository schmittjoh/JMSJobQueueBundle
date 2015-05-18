<?php

namespace JMS\JobQueueBundle\Entity\Retry;

use JMS\JobQueueBundle\Entity\Job;

/**
 * Class FixedIntervalStrategy
 * @package JMS\JobQueueBundle\Entity\Retry
 */
class FixedIntervalStrategy implements RetryStrategyInterface {
  private $config;

  /**
   * Constructor.
   *
   * @param array $config
   *   Array of configuration.
   */
  public function __construct($config = NULL) {
    $this->config = $config;
  }

  /**
   * Apply the strategy.
   *
   * @param Job $original
   * @param Job $retry
   */
  public function apply(Job $original, Job $retry) {
    $interval = "+15 seconds";

    if (isset($this->config['interval'])) {
      $interval = $this->config['interval'];
    }

    // getExecuteAfter() is always in initialized to current time.
    $retry->getExecuteAfter()->modify($interval);
  }
}