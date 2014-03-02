Usage
-----

Creating Jobs
=============
Creating jobs is super simple, you just need to persist an instance of ``Job``:

.. code-block :: php

    <?php

    $job = new Job('my-symfony2:command', array('some-args', 'or', '--options="foo"'));
    $em->persist($job);
    $em->flush($job);

Adding Dependencies Between Jobs
================================
If you want to have a job run after another job finishes, you can also achieve this
quite easily:

.. code-block :: php

    <?php

    $job = new Job('a');
    $dependentJob = new Job('b');
    $dependentJob->addDependency($job);
    $em->persist($job);
    $em->persist($dependentJob);
    $em->flush();

Adding Related Entities to Jobs
===============================
If you want to link a job to another entity, for example to find the job more
easily, the job provides a special many-to-any association:

.. code-block :: php

    <?php

    $job = new Job('a');
    $job->addRelatedEntity($anyEntity);
    $em->persist($job);
    $em->flush();

    $em->getRepository('JMSJobQueueBundle:Job')->findJobForRelatedEntity('a', $anyEntity);

Schedule a Jobs
===============
If you want to schedule a job :

.. code-block :: php

    <?php

    $job = new Job('a');
    $date = new DateTime();
    $date->add(new DateInterval('PT30M'));
    $job->setExecuteAfter($date);
    $em->persist($job);
    $em->flush();
    
Fine-grained Concurrency Control through Queues
===============================================
If you would like to better control the concurrency of a specific job type, you can use queues:

.. code-block :: php

    <?php

    $job = new Job('a', array(), true, "aCoolQueue");
    $em->persist($job);
    $em->flush();

Queues allow you to enforce stricter limits as to how many jobs are running per queue. By default,
a queue the jobs per queue are not limited as such queues will have no effect. To define a
limit for a queue, you can use the bundle's configuration:

.. code-block :: yml

    jms_job_queue:
        queue_options_defaults:
            max_concurrent_jobs: 3 # This limit applies to all queues (including the default queue).

        queue_options:
            foo:
                max_concurrent_jobs: 2 # This limit applies only to the "foo" queue.

Prioritizing Jobs
=================
By default, all jobs are executed in the order in which they are scheduled (assuming they are in the same queue).
If you would like to prioritize certain jobs in the same queue, you can set a priority::

    $job = new Job('a', array(), true, Job::DEFAULT_QUEUE, Job::PRIORITY_HIGH);
    $em->persist($job);
    $em->flush();

The priority is a simple integer - the higher the number, the sooner a job is executed.