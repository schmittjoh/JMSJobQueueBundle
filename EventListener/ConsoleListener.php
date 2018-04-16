<?php

namespace JMS\JobQueueBundle\EventListener;

declare(ticks = 10000000);

use Doctrine\Bundle\DoctrineBundle\Registry;
use Doctrine\DBAL\Types\Type;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleExceptionEvent;
use Symfony\Component\Debug\Exception\FlattenException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Records debugging information for executed commands.
 *
 * @author Johannes M. Schmitt <schmittjoh@gmail.com>
 */
class ConsoleListener implements EventSubscriberInterface
{
    private $doctrine;
    private $collectStatistics;
    private $jobId;
    private $insertStatStmt;

    /**
     * @var \Exception
     */
    private $exception;

    /**
     * @param Registry $doctrine
     * @param bool     $collectStatistics
     */
    public function __construct(Registry $doctrine, $collectStatistics)
    {
        $this->doctrine = $doctrine;
        $this->collectStatistics = $collectStatistics;

        if ($jobId = getenv('jmsJobId')) {
            $this->jobId = $jobId;
        } elseif (isset($_SERVER['jmsJobId'])) {
            $this->jobId = $_SERVER['jmsJobId'];
        } elseif (isset($_ENV['jmsJobId'])) {
            $this->jobId = $_ENV['jmsJobId'];
        }
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents()
    {
        return array(
            ConsoleEvents::COMMAND   => 'onCommand',
            ConsoleEvents::EXCEPTION => 'onException',
            ConsoleEvents::TERMINATE => 'onTerminate',
        );
    }

    public function onCommand(ConsoleCommandEvent $event)
    {
        if ($this->jobId && $this->collectStatistics) {
            $this->insertStatStmt = $this->getConnection()->prepare("INSERT INTO jms_job_statistics (job_id, characteristic, createdAt, charValue) VALUES (:jobId, :name, :createdAt, :value)");
            register_tick_function(array($this, 'onTick'));
        }
    }

    public function onException(ConsoleExceptionEvent $event)
    {
        if (!$this->jobId) {
            return;
        }

        $ex = $event->getException();
        $this->exception = $ex;
    }

    public function onTerminate()
    {
        if (!$this->jobId) {
            return;
        }

        $this->saveDebugInformation();
    }

    public function onTick()
    {
        $characteristics = array(
            'memory' => memory_get_usage(),
        );

        $this->insertStatStmt->bindValue('jobId', $this->jobId, \PDO::PARAM_INT);
        $this->insertStatStmt->bindValue('createdAt', new \DateTime(), Type::getType('datetime'));

        foreach ($characteristics as $name => $value) {
            $this->insertStatStmt->bindValue('name', $name);
            $this->insertStatStmt->bindValue('value', $value);
            $this->insertStatStmt->execute();
        }
    }

    private function saveDebugInformation()
    {
        $this->getConnection()->executeUpdate("UPDATE jms_jobs SET stackTrace = :trace, memoryUsage = :memoryUsage, memoryUsageReal = :memoryUsageReal WHERE id = :id", array(
            'id' => $this->jobId,
            'memoryUsage' => memory_get_peak_usage(),
            'memoryUsageReal' => memory_get_peak_usage(true),
            'trace' => serialize($this->exception ? FlattenException::create($this->exception) : null),
        ));
    }

    private function getConnection()
    {
        return $this->doctrine->getManagerForClass('JMSJobQueueBundle:Job')->getConnection();
    }
}
