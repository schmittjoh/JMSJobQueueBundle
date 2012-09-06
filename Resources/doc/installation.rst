Installation
------------

Installing JMSJobQueueBundle
============================

You can easily install JMSJobQueueBundle with composer. Just add the following
to your `composer.json`file:

.. code-block :: js

    // composer.json
    {
        // ...
        require: {
            // ...
            "jms/job-queue-bundle": "dev-master"
        }
    }

.. note ::

    Please replace `dev-master` in the snippet above with the latest stable
    branch, for example ``1.0.*``.
    
Then, you can install the new dependencies by running composer's ``update``
command from the directory where your ``composer.json`` file is located:

.. code-block :: bash

    composer update jms/job-queue-bundle

Now, Composer will automatically download all required files, and install them
for you. All that is left to do is to update your ``AppKernel.php`` file, and
register the new bundle:

.. code-block :: php

    <?php

    // in AppKernel::registerBundles()
    $bundles = array(
        // ...
        new JMS\JobQueueBundle\JMSJobQueueBundle(),
        // ...
    );

Now use the ``vendors`` script to clone the newly added repositories 
into your project:

.. code-block :: bash

    php bin/vendors install

Setting Up supervisord
======================
For this bundle to work, you have to make sure that one (and only one) 
instance of the console command ``jms-job-queue:run`` is running at all
times. You can easily achieve this by using supervisord_.

A sample supervisord config might look like this:

.. code-block ::

    [program:jms_job_queue_runner]
    command=php %kernel.root_dir%/console jms-job-queue:run --env=prod --verbose
    process_name=%(program_name)s
    numprocs=1
    directory=/tmp
    autostart=true
    autorestart=true
    startsecs=5
    startretries=10
    user=www-data
    redirect_stderr=false
    stdout_logfile=%capistrano.shared_dir%/jms_job_queue_runner.out.log
    stdout_capture_maxbytes=1MB
    stderr_logfile=%capistrano.shared_dir%/jms_job_queue_runner.error.log
    stderr_capture_maxbytes=1MB

.. tip ::

    For testing, or development, you can of course also run the command manually,
    but it will auto-exit after 15 minutes by default.

.. _supervisord: http://supervisord.org/