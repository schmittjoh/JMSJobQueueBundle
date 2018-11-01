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
use PHPUnit\Framework\TestCase;

class JobTest extends TestCase
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
        $job->setState('running');
        $job->setState('failed');
        $this->assertEquals('failed', $job->getState());
    }

    /**
     * @depends testStateToRunning
     */
    public function testStateToTerminated(Job $job)
    {
        $job = clone $job;
        $job->setState('running');
        $job->setState('terminated');
        $this->assertEquals('terminated', $job->getState());
    }

    /**
     * @depends testStateToRunning
     */
    public function testStateToFinished(Job $job)
    {
        $job = clone $job;
        $job->setState('running');
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

    public function testAddDependency()
    {
        $a = new Job('a');
        $b = new Job('b');
        $this->assertCount(0, $a->getDependencies());
        $this->assertCount(0, $b->getDependencies());

        $a->addDependency($b);
        $this->assertCount(1, $a->getDependencies());
        $this->assertCount(0, $b->getDependencies());
        $this->assertSame($b, $a->getDependencies()->first());
    }

    /**
     * @expectedException \LogicException
     * @expectedExceptionMessage You cannot add dependencies to a job which might have been started already.
     */
    public function testAddDependencyToRunningJob()
    {
        $job = new Job('a');
        $job->setState(Job::STATE_RUNNING);
        $this->setField($job, 'id', 1);
        $job->addDependency(new Job('b'));
    }

    public function testAddRetryJob()
    {
        $a = new Job('a');
        $a->setState(Job::STATE_RUNNING);
        $b = new Job('b');
        $a->addRetryJob($b);

        $this->assertCount(1, $a->getRetryJobs());
        $this->assertSame($b, $a->getRetryJobs()->get(0));

        return $a;
    }

    /**
     * @depends testAddRetryJob
     */
    public function testIsRetryJob(Job $a)
    {
        $this->assertFalse($a->isRetryJob());
        $this->assertTrue($a->getRetryJobs()->get(0)->isRetryJob());
    }

    /**
     * @depends testAddRetryJob
     */
    public function testGetOriginalJob(Job $a)
    {
        $this->assertSame($a, $a->getOriginalJob());
        $this->assertSame($a, $a->getRetryJobs()->get(0)->getOriginalJob());
    }

    public function testCheckedAt()
    {
        $job = new Job('a');
        $this->assertNull($job->getCheckedAt());

        $job->checked();
        $this->assertInstanceOf('DateTime', $checkedAtA = $job->getCheckedAt());

        $job->checked();
        $this->assertInstanceOf('DateTime', $checkedAtB = $job->getCheckedAt());
        $this->assertNotSame($checkedAtA, $checkedAtB);
    }

    public function testSameDependencyIsNotAddedTwice()
    {
        $a = new Job('a');
        $b = new Job('b');

        $this->assertCount(0, $a->getDependencies());
        $a->addDependency($b);
        $this->assertCount(1, $a->getDependencies());
        $a->addDependency($b);
        $this->assertCount(1, $a->getDependencies());
    }

    public function testHasDependency()
    {
        $a = new Job('a');
        $b = new Job('b');

        $this->assertFalse($a->hasDependency($b));
        $a->addDependency($b);
        $this->assertTrue($a->hasDependency($b));
    }

    public function testIsRetryAllowed()
    {
        $job = new Job('a');
        $this->assertFalse($job->isRetryAllowed());

        $job->setMaxRetries(1);
        $this->assertTrue($job->isRetryAllowed());

        $job->setState('running');
        $retry = new Job('a');
        $job->addRetryJob($retry);
        $this->assertFalse($job->isRetryAllowed());
    }

    public function testCloneDoesNotChangeQueue()
    {
        $job = new Job('a', array(), true, 'foo');
        $clonedJob = clone $job;

        $this->assertEquals('foo', $clonedJob->getQueue());
    }

    private function setField($obj, $field, $value)
    {
        $ref = new \ReflectionProperty($obj, $field);
        $ref->setAccessible(true);
        $ref->setValue($obj, $value);
    }
}