<?php

namespace JMS\JobQueueBundle\Entity\Listener;
use Doctrine\Bundle\DoctrineBundle\Registry;
use Doctrine\ORM\Event\LifecycleEventArgs;
use JMS\JobQueueBundle\Entity\Job;

/**
 * Provides many-to-any association support for jobs.
 *
 * This listener only implements the minimal support for this feature. For
 * example, currently we do not support any modification of a collection after
 * its initial creation.
 *
 * @see http://docs.jboss.org/hibernate/orm/4.1/javadocs/org/hibernate/annotations/ManyToAny.html
 * @author Johannes M. Schmitt <schmittjoh@gmail.com>
 */
class ManyToAnyListener
{
    private $registry;
    private $ref;

    public function __construct(Registry $registry)
    {
        $this->registry = $registry;
        $this->ref = new \ReflectionProperty('JMS\JobQueueBundle\Entity\Job', 'relatedEntities');
        $this->ref->setAccessible(true);
    }

    public function postLoad(\Doctrine\ORM\Event\LifecycleEventArgs $event)
    {
        $entity = $event->getEntity();
        if ( ! $entity instanceof \JMS\JobQueueBundle\Entity\Job) {
            return;
        }

        $this->ref->setValue($entity, new PersistentRelatedEntitiesCollection($this->registry, $entity));
    }

    public function preRemove(LifecycleEventArgs $event)
    {
        $entity = $event->getEntity();
        if ( ! $entity instanceof Job) {
            return;
        }

        $con = $event->getEntityManager()->getConnection();
        $con->executeUpdate("DELETE FROM jms_job_related_entities WHERE job_id = :id", array(
            'id' => $entity->getId(),
        ));
    }

    public function postPersist(\Doctrine\ORM\Event\LifecycleEventArgs $event)
    {
        $entity = $event->getEntity();
        if ( ! $entity instanceof \JMS\JobQueueBundle\Entity\Job) {
            return;
        }

        $con = $event->getEntityManager()->getConnection();
        foreach ($this->ref->getValue($entity) as $relatedEntity) {
            $relClass = \Doctrine\Common\Util\ClassUtils::getClass($relatedEntity);
            $relId = $this->registry->getManagerForClass($relClass)->getMetadataFactory()->getMetadataFor($relClass)->getIdentifierValues($relatedEntity);
            asort($relId);

            if ( ! $relId) {
                throw new \RuntimeException('The identifier for the related entity "'.$relClass.'" was empty.');
            }

            $con->executeUpdate("INSERT INTO jms_job_related_entities (job_id, related_class, related_id) VALUES (:jobId, :relClass, :relId)", array(
                'jobId' => $entity->getId(),
                'relClass' => $relClass,
                'relId' => json_encode($relId),
            ));
        }
    }

    public function postGenerateSchema(\Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs $event)
    {
        $schema = $event->getSchema();

        // When using multiple entity managers ignore events that are triggered by other entity managers.
        if ($event->getEntityManager()->getMetadataFactory()->isTransient('JMS\JobQueueBundle\Entity\Job')) {
            return;
        }

        $table = $schema->createTable('jms_job_related_entities');
        $table->addColumn('job_id', 'bigint', array('notnull' => true, 'unsigned' => true));
        $table->addColumn('related_class', 'string', array('notnull' => true, 'length' => '150'));
        $table->addColumn('related_id', 'string', array('notnull' => true, 'length' => '100'));
        $table->setPrimaryKey(array('job_id', 'related_class', 'related_id'));
        $table->addForeignKeyConstraint('jms_jobs', array('job_id'), array('id'));
    }
}