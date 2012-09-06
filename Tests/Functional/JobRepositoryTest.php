<?php

namespace JMS\JobQueueBundle\Tests\Functional;

use JMS\JobQueueBundle\Event\StateChangeEvent;
use JMS\JobQueueBundle\Entity\Job;

class JobRepositoryTest extends BaseTestCase
{
    private $em;
    private $repo;
    private $dispatcher;

    public function testFindPendingJob()
    {
        $this->assertNull($this->repo->findPendingJob());

        $a = new Job('a');
        $a->setState('running');
        $b = new Job('b');
        $this->em->persist($a);
        $this->em->persist($b);
        $this->em->flush();

        $this->assertSame($b, $this->repo->findPendingJob());
        $this->assertNull($this->repo->findPendingJob(array($b->getId())));
    }

    public function testFindStartableJob()
    {
        $this->assertNull($this->repo->findStartableJob());

        $a = new Job('a');
        $a->setState('running');
        $b = new Job('b');
        $c = new Job('c');
        $b->addJobDependency($c);
        $this->em->persist($a);
        $this->em->persist($b);
        $this->em->persist($c);
        $this->em->flush();

        $excludedIds = array();
        $this->assertSame($c, $this->repo->findStartableJob($excludedIds));
        $this->assertEquals(array($b->getId()), $excludedIds);
    }

    public function testCloseJob()
    {
        $a = new Job('a');
        $a->setState('running');
        $b = new Job('b');
        $b->addJobDependency($a);
        $this->em->persist($a);
        $this->em->persist($b);
        $this->em->flush();

        $this->dispatcher->expects($this->at(0))
            ->method('dispatch')
            ->with('jms_job_queue.job_state_change', new StateChangeEvent($b, 'canceled'));
        $this->dispatcher->expects($this->at(1))
            ->method('dispatch')
            ->with('jms_job_queue.job_state_change', new StateChangeEvent($a, 'terminated'));

        $this->assertEquals('running', $a->getState());
        $this->assertEquals('pending', $b->getState());
        $this->repo->closeJob($a, 'terminated');
        $this->assertEquals('terminated', $a->getState());
        $this->assertEquals('canceled', $b->getState());
    }

    protected function setUp()
    {
        $this->createClient();
        $this->importDatabaseSchema();

        $this->dispatcher = $this->getMock('Symfony\Component\EventDispatcher\EventDispatcherInterface');
        $this->em = self::$kernel->getContainer()->get('doctrine')->getManagerForClass('JMSJobQueueBundle:Job');
        $this->repo = $this->em->getRepository('JMSJobQueueBundle:Job');
        $this->repo->setDispatcher($this->dispatcher);
    }
}