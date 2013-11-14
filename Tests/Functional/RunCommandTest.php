<?php

namespace JMS\JobQueueBundle\Tests\Functional;

use JMS\JobQueueBundle\Entity\Job;
use Symfony\Component\Console\Output\Output;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Bundle\FrameworkBundle\Console\Application;

class RunCommandTest extends BaseTestCase
{
    private $app;
    private $em;

    public function testRun()
    {
        $a = new Job('a');
        $b = new Job('b', array('foo'));
        $b->addDependency($a);
        $this->em->persist($a);
        $this->em->persist($b);
        $this->em->flush();

        $output = $this->doRun(array('--max-runtime' => 5));
        $expectedOutput = "Started Job(id = 1, command = \"a\").\n"
                         ."Job(id = 1, command = \"a\") finished with exit code 1.\n";
        $this->assertEquals($expectedOutput, $output);
        $this->assertEquals('failed', $a->getState());
        $this->assertEquals('', $a->getOutput());
        $this->assertContains('Command "a" is not defined.', $a->getErrorOutput());
        $this->assertEquals('canceled', $b->getState());
    }

    public function testExitsAfterMaxRuntime()
    {
        $time = time();
        $output = $this->doRun(array('--max-runtime' => 1));
        $this->assertEquals('', $output);

        $runtime = time() - $time;
        $this->assertTrue($runtime >= 2 && $runtime < 8);
    }

    public function testSuccessfulCommand()
    {
        $job = new Job('jms-job-queue:successful-cmd');
        $this->em->persist($job);
        $this->em->flush($job);

        $this->doRun(array('--max-runtime' => 1));
        $this->assertEquals('finished', $job->getState());
    }

    /**
     * @group retry
     */
    public function testRetry()
    {
        $job = new Job('jms-job-queue:sometimes-failing-cmd', array(time()));
        $job->setMaxRetries(5);
        $this->em->persist($job);
        $this->em->flush($job);

        $this->doRun(array('--max-runtime' => 12, '--verbose' => null));
        $this->assertEquals('finished', $job->getState());
        $this->assertCount(2, $job->getRetryJobs());
        $this->assertEquals(1, $job->getExitCode());
        $this->assertEquals('failed', $job->getRetryJobs()->get(0)->getState());
        $this->assertEquals(1, $job->getRetryJobs()->get(0)->getExitCode());
        $this->assertEquals('finished', $job->getRetryJobs()->get(1)->getState());
        $this->assertEquals(0, $job->getRetryJobs()->get(1)->getExitCode());
    }

    public function testJobIsTerminatedIfMaxRuntimeIsExceeded()
    {
        $this->markTestSkipped('Requires a patched Process class (see symfony/symfony#5030).');

        $job = new Job('jms-job-queue:never-ending');
        $job->setMaxRuntime(1);
        $this->em->persist($job);
        $this->em->flush($job);

        $this->doRun(array('--max-runtime' => 1));
        $this->assertEquals('terminated', $job->getState());
    }

    /**
     * @group exception
     */
    public function testExceptionStackTraceIsSaved()
    {
        $job = new Job('jms-job-queue:throws-exception-cmd');
        $this->em->persist($job);
        $this->em->flush($job);

        $this->assertNull($job->getStackTrace());
        $this->assertNull($job->getMemoryUsage());
        $this->assertNull($job->getMemoryUsageReal());

        $this->doRun(array('--max-runtime' => 1));

        $this->assertNotNull($job->getStackTrace());
        $this->assertNotNull($job->getMemoryUsage());
        $this->assertNotNull($job->getMemoryUsageReal());
    }

    protected function setUp()
    {
        $this->createClient(array('config' => 'persistent_db.yml'));

        if (is_file($databaseFile = self::$kernel->getCacheDir().'/database.sqlite')) {
            unlink($databaseFile);
        }

        $this->importDatabaseSchema();

        $this->app = new Application(self::$kernel);
        $this->app->setAutoExit(false);
        $this->app->setCatchExceptions(false);

        $this->em = self::$kernel->getContainer()->get('doctrine')->getManagerForClass('JMSJobQueueBundle:Job');
    }

    private function doRun(array $args = array())
    {
        array_unshift($args, 'jms-job-queue:run');
        $output = new MemoryOutput();
        $this->app->run(new ArrayInput($args), $output);

        return $output->getOutput();
    }
}

class MemoryOutput extends Output
{
    private $output;

    protected function doWrite($message, $newline)
    {
        $this->output .= $message;

        if ($newline) {
            $this->output .= "\n";
        }
    }

    public function getOutput()
    {
        return $this->output;
    }
}