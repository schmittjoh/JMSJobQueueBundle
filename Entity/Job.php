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

namespace JMS\JobQueueBundle\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Mapping as ORM;
use JMS\JobQueueBundle\Exception\InvalidStateTransitionException;
use JMS\JobQueueBundle\Exception\LogicException;
use Symfony\Component\HttpKernel\Exception\FlattenException;

/**
 * @ORM\Entity(repositoryClass = "JMS\JobQueueBundle\Entity\Repository\JobRepository")
 * @ORM\Table(name = "jms_jobs")
 * @ORM\ChangeTrackingPolicy("DEFERRED_EXPLICIT")
 *
 * @author Johannes M. Schmitt <schmittjoh@gmail.com>
 */
class Job
{
    const STATE_NEW = 'new';
    const STATE_PENDING = 'pending';
    const STATE_CANCELED = 'canceled';
    const STATE_RUNNING = 'running';
    const STATE_FINISHED = 'finished';
    const STATE_FAILED = 'failed';
    const STATE_TERMINATED = 'terminated';

    /** @ORM\Id @ORM\GeneratedValue(strategy = "AUTO") @ORM\Column(type = "bigint", options = {"unsigned": true}) */
    private $id;

    /** @ORM\Column(type = "string") */
    private $state;

    /** @ORM\Column(type = "datetime") */
    private $createdAt;

    /** @ORM\Column(type = "datetime", nullable = true) */
    private $startedAt;

    /** @ORM\Column(type = "datetime", nullable = true) */
    private $checkedAt;

    /** @ORM\Column(type = "string") */
    private $command;

    /** @ORM\Column(type = "json_array") */
    private $args;

    /**
     * @ORM\ManyToMany(targetEntity = "Job", fetch = "EAGER")
     * @ORM\JoinTable(name="jms_job_dependencies",
     *     joinColumns = { @ORM\JoinColumn(name = "source_job_id", referencedColumnName = "id") },
     *     inverseJoinColumns = { @ORM\JoinColumn(name = "dest_job_id", referencedColumnName = "id")}
     * )
     */
    private $dependencies;

    /** @ORM\Column(type = "text", nullable = true) */
    private $output;

    /** @ORM\Column(type = "text", nullable = true) */
    private $errorOutput;

    /** @ORM\Column(type = "smallint", nullable = true, options = {"unsigned": true}) */
    private $exitCode;

    /** @ORM\Column(type = "smallint", options = {"unsigned": true}) */
    private $maxRuntime = 0;

    /** @ORM\Column(type = "smallint", options = {"unsigned": true}) */
    private $maxRetries = 0;

    /** @ORM\ManyToOne(targetEntity = "Job", inversedBy = "retryJobs") */
    private $originalJob;

    /** @ORM\OneToMany(targetEntity = "Job", mappedBy = "originalJob", cascade = {"persist", "remove"}) */
    private $retryJobs;

    /** @ORM\Column(type = "object", nullable = true) */
    private $stackTrace;

    public static function create($command, array $args = array(), $confirmed = true)
    {
        return new self($command, $args, $confirmed);
    }

    public function __construct($command, array $args = array(), $confirmed = true)
    {
        $this->command = $command;
        $this->args = $args;
        $this->state = $confirmed ? self::STATE_PENDING : self::STATE_NEW;
        $this->createdAt = new \DateTime();
        $this->dependencies = new ArrayCollection();
        $this->retryJobs = new ArrayCollection();
    }

    public function getId()
    {
        return $this->id;
    }

    public function getState()
    {
        return $this->state;
    }

    public function isStartable()
    {
        foreach ($this->dependencies as $dep) {
            if ($dep->getState() !== self::STATE_FINISHED) {
                return false;
            }
        }

        return true;
    }

    public function setState($newState)
    {
        if ($newState === $this->state) {
            return;
        }

        switch ($this->state) {
            case self::STATE_NEW:
                if ( ! in_array($newState, array(self::STATE_PENDING, self::STATE_CANCELED), true)) {
                    throw new InvalidStateTransitionException($this, $newState, array(self::STATE_PENDING, self::STATE_CANCELED));
                }
                break;

            case self::STATE_PENDING:
                if ( ! in_array($newState, array(self::STATE_RUNNING, self::STATE_CANCELED), true)) {
                    throw new InvalidStateTransitionException($this, $newState, array(self::STATE_RUNNING, self::STATE_CANCELED));
                }

                if ($newState === self::STATE_RUNNING) {
                    $this->startedAt = new \DateTime();
                    $this->checkedAt = new \DateTime();
                }

                break;

            case self::STATE_RUNNING:
                if ( ! in_array($newState, array(self::STATE_FINISHED, self::STATE_FAILED, self::STATE_TERMINATED))) {
                    throw new InvalidStateTransitionException($this, $newState, array(self::STATE_FINISHED, self::STATE_FAILED));
                }
                break;

            case self::STATE_FINISHED:
            case self::STATE_FAILED:
                throw new InvalidStateTransitionException($this, $newState);

            default:
                throw new LogicException('The previous cases were exhaustive. Unknown state: '.$this->state);
        }

        $this->state = $newState;
    }

    public function getCreatedAt()
    {
        return $this->createdAt;
    }

    public function getCommand()
    {
        return $this->command;
    }

    public function getArgs()
    {
        return $this->args;
    }

    public function getDependencies()
    {
        return $this->dependencies;
    }

    public function hasDependency(Job $job)
    {
        return $this->dependencies->contains($job);
    }

    public function addDependency(Job $job)
    {
        if ($this->mightHaveStarted()) {
            throw new \LogicException('You cannot add dependencies to a job which might have been started already.');
        }

        if ($this->dependencies->contains($job)) {
            return;
        }

        $this->dependencies->add($job);
    }

    public function addOutput($output)
    {
        $this->output .= $output;
    }

    public function addErrorOutput($output)
    {
        $this->errorOutput .= $output;
    }

    public function setOutput($output)
    {
        $this->output = $output;
    }

    public function setErrorOutput($output)
    {
        $this->errorOutput = $output;
    }

    public function getOutput()
    {
        return $this->output;
    }

    public function getErrorOutput()
    {
        return $this->errorOutput;
    }

    public function setExitCode($code)
    {
        $this->exitCode = $code;
    }

    public function getExitCode()
    {
        return $this->exitCode;
    }

    public function setMaxRuntime($time)
    {
        $this->maxRuntime = (integer) $time;
    }

    public function getMaxRuntime()
    {
        return $this->maxRuntime;
    }

    public function getStartedAt()
    {
        return $this->startedAt;
    }

    public function getMaxRetries()
    {
        return $this->maxRetries;
    }

    public function setMaxRetries($tries)
    {
        $this->maxRetries = (integer) $tries;
    }

    public function getOriginalJob()
    {
        if (null === $this->originalJob) {
            return $this;
        }

        return $this->originalJob;
    }

    public function setOriginalJob(Job $job)
    {
        if (self::STATE_PENDING !== $this->state) {
            throw new \LogicException($this.' must be in state "PENDING".');
        }

        if (null !== $this->originalJob) {
            throw new \LogicException($this.' already has an original job set.');
        }

        $this->originalJob = $job;
    }

    public function addRetryJob(Job $job)
    {
        if (self::STATE_RUNNING !== $this->state) {
            throw new \LogicException('Retry jobs can only be added to running jobs.');
        }

        $job->setOriginalJob($this);
        $this->retryJobs->add($job);
    }

    public function getRetryJobs()
    {
        return $this->retryJobs;
    }

    public function isRetryJob()
    {
        return null !== $this->originalJob;
    }

    public function checked()
    {
        $this->checkedAt = new \DateTime();
    }

    public function getCheckedAt()
    {
        return $this->checkedAt;
    }

    public function setStackTrace(FlattenException $ex)
    {
        $this->stackTrace = $ex;
    }

    public function getStackTrace()
    {
        return $this->stackTrace;
    }

    public function __toString()
    {
        return sprintf('Job(id = %s, command = "%s")', $this->id, $this->command);
    }

    private function mightHaveStarted()
    {
        if (null === $this->id) {
            return false;
        }

        if (self::STATE_NEW === $this->state) {
            return false;
        }

        if (self::STATE_PENDING === $this->state && ! $this->isStartable()) {
            return false;
        }

        return true;
    }
}