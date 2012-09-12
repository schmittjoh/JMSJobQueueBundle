<?php

namespace JMS\JobQueueBundle\Tests\Functional;

use Symfony\Component\EventDispatcher\EventDispatcher;
use Doctrine\ORM\EntityManager;
use JMS\JobQueueBundle\Entity\Repository\JobRepository;
use JMS\JobQueueBundle\Event\StateChangeEvent;
use JMS\JobQueueBundle\Entity\Job;

class JobRepositoryTest extends BaseTestCase
{
    /** @var EntityManager */
    private $em;

    /** @var JobRepository */
    private $repo;

    /** @var EventDispatcher */
    private $dispatcher;

    public function testGetOne()
    {
        $a = new Job('a', array('foo'));
        $a2 = new Job('a');
        $this->em->persist($a);
        $this->em->persist($a2);
        $this->em->flush();

        $this->assertSame($a, $this->repo->getJob('a', array('foo')));
        $this->assertSame($a2, $this->repo->getJob('a'));
    }

    /**
     * @expectedException RuntimeException
     * @expectedExceptionMessage Found no job for command
     */
    public function testGetOneThrowsWhenNotFound()
    {
        $this->repo->getJob('foo');
    }

    public function getOrCreateIfNotExists()
    {
        $a = $this->repo->getOrCreateIfNotExists('a');
        $this->assertSame($a, $this->repo->getOrCreateIfNotExists('a'));
        $this->assertNotSame($a, $this->repo->getOrCreateIfNotExists('a', array('foo')));
    }

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
        $b->addDependency($c);
        $this->em->persist($a);
        $this->em->persist($b);
        $this->em->persist($c);
        $this->em->flush();

        $excludedIds = array();
        $this->assertSame($c, $this->repo->findStartableJob($excludedIds));
        $this->assertEquals(array($b->getId()), $excludedIds);
    }

    public function testFindJobByRelatedEntity()
    {
        $a = new Job('a');
        $b = new Job('b');
        $b->addRelatedEntity($a);
        $b2 = new Job('b');
        $this->em->persist($a);
        $this->em->persist($b);
        $this->em->persist($b2);
        $this->em->flush();
        $this->em->clear();

        $this->assertFalse($this->em->contains($b));

        $reloadedB = $this->repo->findJobForRelatedEntity('b', $a);
        $this->assertNotNull($reloadedB);
        $this->assertEquals($b->getId(), $reloadedB->getId());
        $this->assertCount(1, $reloadedB->getRelatedEntities());
        $this->assertEquals($a->getId(), $reloadedB->getRelatedEntities()->first()->getId());
    }

    public function testFindStartableJobDetachesNonStartableJobs()
    {
        $a = new Job('a');
        $b = new Job('b');
        $a->addDependency($b);
        $this->em->persist($a);
        $this->em->persist($b);
        $this->em->flush();

        $this->assertTrue($this->em->contains($a));
        $this->assertTrue($this->em->contains($b));

        $excludedIds = array();
        $this->assertEquals($b->getId(), $this->repo->findStartableJob($excludedIds)->getId());
        $this->assertEquals(array($a->getId()), $excludedIds);
        $this->assertFalse($this->em->contains($a));
        $this->assertTrue($this->em->contains($b));
    }

    public function testCloseJob()
    {
        $a = new Job('a');
        $a->setState('running');
        $b = new Job('b');
        $b->addDependency($a);
        $this->em->persist($a);
        $this->em->persist($b);
        $this->em->flush();

        $this->dispatcher->expects($this->at(0))
            ->method('dispatch')
            ->with('jms_job_queue.job_state_change', new StateChangeEvent($a, 'terminated'));
        $this->dispatcher->expects($this->at(1))
            ->method('dispatch')
            ->with('jms_job_queue.job_state_change', new StateChangeEvent($b, 'canceled'));

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