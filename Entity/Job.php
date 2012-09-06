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

use JMS\JobQueueBundle\Exception\LogicException;
use JMS\JobQueueBundle\Exception\InvalidStateTransitionException;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass = "JMS\JobQueueBundle\Entity\Repository\JobRepository")
 * @ORM\Table(name = "jms_jobs")
 * @ORM\ChangeTrackingPolicy("DEFERRED_EXPLICIT")
 *
 * @author Johannes M. Schmitt <schmittjoh@gmail.com>
 */
class Job
{
    const STATE_PENDING = 'pending';
    const STATE_CANCELED = 'canceled';
    const STATE_RUNNING = 'running';
    const STATE_FINISHED = 'finished';
    const STATE_FAILED = 'failed';
    const STATE_TERMINATED = 'terminated';

    /** @ORM\Id @ORM\GeneratedValue(strategy = "AUTO") @ORM\Column(type = "bigint", options = {"unsigned": true}) */
    private $id;

    /** @ORM\Column(type = "string") */
    private $state = self::STATE_PENDING;

    /** @ORM\Column(type = "datetime") */
    private $createdAt;

    /** @ORM\Column(type = "datetime", nullable = true) */
    private $startedAt;

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
    private $jobDependencies;

    /** @ORM\Column(type = "text", nullable = true) */
    private $output;

    /** @ORM\Column(type = "text", nullable = true) */
    private $errorOutput;

    /** @ORM\Column(type = "smallint", nullable = true, options = {"unsigned": true}) */
    private $exitCode;

    /** @ORM\Column(type = "smallint", options = {"unsigned": true}) */
    private $maxRuntime = 0;

    public static function create($command, array $args = array())
    {
        return new self($command, $args);
    }

    public function __construct($command, array $args = array())
    {
        $this->command = $command;
        $this->args = $args;
        $this->createdAt = new \DateTime();
        $this->jobDependencies = new ArrayCollection();
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
        foreach ($this->jobDependencies as $dep) {
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
            case self::STATE_PENDING:
                if ( ! in_array($newState, array(self::STATE_RUNNING, self::STATE_CANCELED), true)) {
                    throw new InvalidStateTransitionException($this, $newState, array(self::STATE_RUNNING, self::STATE_CANCELED));
                }

                if ($newState === self::STATE_RUNNING) {
                    $this->startedAt = new \DateTime();
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

    public function getJobDependencies()
    {
        return $this->jobDependencies;
    }

    public function addJobDependency(Job $job)
    {
        $this->jobDependencies->add($job);
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

    public function __toString()
    {
        return sprintf('Job(id = %s, command = "%s")', $this->id, $this->command);
    }
}