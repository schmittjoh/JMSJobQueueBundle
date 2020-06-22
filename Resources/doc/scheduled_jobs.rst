Scheduled Jobs
==============

This bundle also allows you to have scheduled jobs which are executed in certain intervals. This can either be achieved
by implementing ``JMS\JobQueueBundle\Console\CronCommand`` in your command then tag the concrete class with ``['jms_job_queue.cron_command']``, or implementing ``JMS\JobQueueBundle\Cron\JobScheduler``
in a service and tagging the service with ``jms_job_queue.scheduler``.

The jobs are then scheduled with the ``jms-job-queue:schedule`` command that is run as an additional background process.
You can also run multiple instances of this command to ensure high availability and avoid a single point of failure.

Implement CronCommand
---------------------

.. code-block :: php

    class MyScheduledCommand extends ContainerAwareCommand implements CronCommand
    {
        // configure, execute, etc. ...

        public function shouldBeScheduled(\DateTime $lastRunAt)
        {
            return time() - $lastRunAt->getTimestamp() >= 60; // Executed at most every minute.
        }

        public function createCronJob(\DateTime $lastRunAt)
        {
            return new Job('my-scheduled-command');
        }
    }
    
For common intervals, you can also use one of the provided traits:

.. code-block :: php

    class MyScheduledCommand extends ContainerAwareCommand implements CronCommand
    {
        use ScheduleEveryMinute;
    
        // ...
    }

Implement JobScheduler
----------------------

This is useful if you want to run a third-party command or a Symfony command as a scheduled command via this bundle.

.. code-block :: php

    class MyJobScheduler implements JobScheduler
    {
        public function getCommands(): array
        {
            return ['my-command'];
        }

        public function shouldSchedule($commandName, \DateTime $lastRunAt)
        {
            return time() - $lastRunAt->getTimestamp() >= 60; // Executed at most every minute.
        }

        public function createJob($commandName, \DateTime $lastRunAt)
        {
            return new Job('my-command');
        }
    }
