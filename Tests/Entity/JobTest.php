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

namespace JMS\JobQueueBundle\Tests\Entity;

use JMS\JobQueueBundle\Entity\Job;

class JobTest extends \PHPUnit_Framework_TestCase
{
    public function testConstruct()
    {
        $job = new Job('a:b', array('a', 'b', 'c'));

        $this->assertEquals('a:b', $job->getCommand());
        $this->assertEquals(array('a', 'b', 'c'), $job->getArgs());
        $this->assertNotNull($job->getCreatedAt());
        $this->assertEquals('pending', $job->getState());
        $this->assertNull($job->getStartedAt());

        return $job;
    }

    /**
     * @depends testConstruct
     * @expectedException JMS\JobQueueBundle\Exception\InvalidStateTransitionException
     */
    public function testInvalidTransition(Job $job)
    {
        $job->setState('failed');
    }

    /**
     * @depends testConstruct
     */
    public function testStateToRunning(Job $job)
    {
        $job->setState('running');
        $this->assertEquals('running', $job->getState());
        $this->assertNotNull($startedAt = $job->getStartedAt());
        $job->setState('running');
        $this->assertSame($startedAt, $job->getStartedAt());

        return $job;
    }

    /**
     * @depends testStateToRunning
     */
    public function testStateToFailed(Job $job)
    {
        $job = clone $job;
        $job->setState('failed');
        $this->assertEquals('failed', $job->getState());
    }

    /**
     * @depends testStateToRunning
     */
    public function testStateToTerminated(Job $job)
    {
        $job = clone $job;
        $job->setState('terminated');
        $this->assertEquals('terminated', $job->getState());
    }

    /**
     * @depends testStateToRunning
     */
    public function testStateToFinished(Job $job)
    {
        $job = clone $job;
        $job->setState('finished');
        $this->assertEquals('finished', $job->getState());
    }

    public function testAddOutput()
    {
        $job = new Job('foo');
        $this->assertNull($job->getOutput());
        $job->addOutput('foo');
        $this->assertEquals('foo', $job->getOutput());
        $job->addOutput('bar');
        $this->assertEquals('foobar', $job->getOutput());
    }

    public function testAddErrorOutput()
    {
        $job = new Job('foo');
        $this->assertNull($job->getErrorOutput());
        $job->addErrorOutput('foo');
        $this->assertEquals('foo', $job->getErrorOutput());
        $job->addErrorOutput('bar');
        $this->assertEquals('foobar', $job->getErrorOutput());
    }

    public function testSetOutput()
    {
        $job = new Job('foo');
        $this->assertNull($job->getOutput());
        $job->setOutput('foo');
        $this->assertEquals('foo', $job->getOutput());
        $job->setOutput('bar');
        $this->assertEquals('bar', $job->getOutput());
    }

    public function testSetErrorOutput()
    {
        $job = new Job('foo');
        $this->assertNull($job->getErrorOutput());
        $job->setErrorOutput('foo');
        $this->assertEquals('foo', $job->getErrorOutput());
        $job->setErrorOutput('bar');
        $this->assertEquals('bar', $job->getErrorOutput());
    }

    public function testAddJobDependency()
    {
        $a = new Job('a');
        $b = new Job('b');
        $this->assertCount(0, $a->getJobDependencies());
        $this->assertCount(0, $b->getJobDependencies());

        $a->addJobDependency($b);
        $this->assertCount(1, $a->getJobDependencies());
        $this->assertCount(0, $b->getJobDependencies());
        $this->assertSame($b, $a->getJobDependencies()->first());
    }
}