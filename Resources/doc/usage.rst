Usage
-----

Preparing Console Commands
==========================
For your regular console commands to work with JMSJobQueueBundle, you need to
switch the command's base class to extends
``JMS\JobQueueBundle\Command\ContainerAwareCommand`` instead of Symfony2's own
class.

This will allow us to log the stack traces should exceptions occur inside your
command which will greatly help you with debugging.

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
    $dependentJob->addJobDependency($job);
    $em->persist($job);
    $em->persist($dependentJob);
    $em->flush();

