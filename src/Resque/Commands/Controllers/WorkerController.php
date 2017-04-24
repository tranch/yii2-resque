<?php
/**
 * Created by PhpStorm.
 * User: tranch
 * Date: 17/4/22
 * Time: 下午4:12
 */

namespace Resque\Commands;


use Resque\Worker;
use Yii;
use yii\console\Controller;

class WorkerController extends Controller
{
    /**
     * Cancel job on a running worker. If no worker id set then cancels all workers
     *
     * @param null $id The id of the worker to cancel it's running job (optional; if not present cancels all workers).
     */
    public function actionCancel($id = null)
    {
        $worker = new Worker('*');
        $worker->cleanup();

        if ($id) {
            if (false === ($worker = Worker::hostWorker($id))) {
                Yii::error('There is no worker with id "'.$id.'".');
                return;
            }

            $workers = array($worker);

        } else {
            $workers = Worker::hostWorkers();
        }

        if (!count($workers)) {
            Yii::info('<warn>There are no workers on this host</warn>');
        }

        foreach ($workers as $worker) {
            $packet  = $worker->getPacket();
            $job_pid = (int)$packet['job_pid'];

            if ($job_pid and posix_kill($job_pid, 0)) {
                if (posix_kill($job_pid, SIGUSR1)) {
                    Yii::info('Worker <pop>'.$worker.'</pop> running job SIGUSR1 signal sent.');

                } else {
                    Yii::info('Worker <pop>'.$worker.'</pop> <error>running job SIGUSR1 signal could not be sent.</error>');
                }

            } else {
                Yii::info('Worker <pop>'.$worker.'</pop> has no running job.');
            }
        }
    }

    /**
     * Pause a running worker. If no worker id set then pauses all workers
     *
     * @param string|null $id The id of the worker to pause (optional; if not present pauses all workers).
     */
    public function actionPause($id = null)
    {
        // Do a cleanup
        $worker = new Worker('*');
        $worker->cleanup();

        if ($id) {
            if (false === ($worker = Worker::hostWorker($id))) {
                Yii::info("There is no worker with id '$id'");
                return;
            }

            $workers = array($worker);

        } else {
            $workers = Worker::hostWorkers();
        }

        if (!count($workers)) {
            Yii::info('<warn>There are no workers on this host.<warn>');
        }

        foreach ($workers as $worker) {
            if (posix_kill($worker->getPid(), SIGUSR2)) {
                Yii::info('Worker <pop>'.$worker.'</pop> USR2 signal sent.');

            } else {
                Yii::info('Worker <pop>'.$worker.'</pop> <error>could not send USR2 signal.</error>');
            }
        }
    }

    /**
     * Resume a running worker. If no worker id set then resumes all workers
     *
     * @param string|null $id The id of the worker to resume (optional; if not present resumes all workers).
     */
    public function actionResume($id = null)
    {
        // Do a cleanup
        $worker = new Worker('*');
        $worker->cleanup();

        if ($id) {
            if (false === ($worker = Worker::hostWorker($id))) {
                Yii::error('There is no worker with id "'.$id.'".');
                return;
            }

            $workers = array($worker);

        } else {
            $workers = Worker::hostWorkers();
        }

        if (!count($workers)) {
            Yii::info('<warn>There are no workers on this host</warn>');
        }

        foreach ($workers as $worker) {
            if (posix_kill($worker->getPid(), SIGCONT)) {
                Yii::info('Worker <pop>'.$worker.'</pop> CONT signal sent.');

            } else {
                Yii::info('Worker <pop>'.$worker.'</pop> <error>could not send CONT signal.</error>');
            }
        }
    }

    /**
     * Restart a running worker. If no worker id set then restarts all workers
     *
     * @param null|string $id The id of the worker to restart (optional; if not present restarts all workers).
     */
    public function actionRestart($id = null)
    {
        $worker = new Worker('*');
        $worker->cleanup();

        if ($id) {
            if (false === ($worker = Worker::hostWorker($id))) {
                Yii::error('There is no worker with id "'.$id.'".');
                return;
            }

            $workers = array($worker);

        } else {
            $workers = Worker::hostWorkers();
        }

        if (!count($workers)) {
            Yii::warning('<warn>There are no workers on this host</warn>');
        }

        foreach ($workers as $worker) {
            if (posix_kill($worker->getPid(), SIGTERM)) {

                $child = pcntl_fork();

                // Failed
                if ($child == -1) {
                    Yii::error('Unable to fork, worker '.$worker.' has been stopped.');

                    // Parent
                } elseif ($child > 0) {
                    Yii::info('Worker <pop>'.$worker.'</pop> restarted.');
                    continue;

                    // Child
                } else {
                    $new_worker = new Worker($worker->getQueues(), $worker->getBlocking());
                    $new_worker->setInterval($worker->getInterval());
                    $new_worker->setTimeout($worker->getTimeout());
                    $new_worker->setMemoryLimit($worker->getMemoryLimit());
                    $new_worker->setLogger();
                    $new_worker->work();

                    Yii::info('Worker <pop>'.$worker.'</pop> restarted as <pop>'.$new_worker.'</pop>.');
                }

            } else {
                Yii::info('Worker <pop>'.$worker.'</pop> <error>could not send TERM signal.</error>');
            }
        }

        Yii::$app->end();
    }

    /**
     * Polls for jobs on specified queues and executes job when found
     *
     * @param $queue string The queue(s) to listen on, comma separated.
     * @param $blocking bool Use Redis pop blocking or time interval.
     * @param $interval int Blocking timeout/interval speed in seconds.
     * @param $timeout int Seconds a job may run before timing out.
     * @param $memory int The memory limit in megabytes.
     * @param $pidFile string Absolute path to PID file, must be writeable by worker.
     */
    public function actionStart($queue = '*', $blocking = true, $interval = 10, $timeout = 60, $memory = 128, $pidFile = null)
    {
        // Create worker instance
        $worker = new Worker($queue, $blocking);
        $worker->setLogger();

        if ($pidFile) {
            $worker->setPidFile($pidFile);
        }

        if ($interval) {
            $worker->setInterval($interval);
        }

        if ($timeout) {
            $worker->setTimeout($timeout);
        }

        // The memory limit is the amount of memory we will allow the script to occupy
        // before killing it and letting a process manager restart it for us, which
        // is to protect us against any memory leaks that will be in the scripts.
        if ($memory) {
            $worker->setMemoryLimit($memory);
        }

        $worker->work();
    }

    /**
     * Stop a running worker. If no worker id set then stops all workers
     *
     * @param string $id The id of the worker to stop (optional; if not present stops all workers).
     * @param string|null $force Force worker to stop, cancelling any current job.
     */
    public function actionStop($id, $force = null)
    {
        // Do a cleanup
        $worker = new Worker('*');
        $worker->cleanup();

        if ($id) {
            if (false === ($worker = Worker::hostWorker($id))) {
                Yii::error("There is no worker with id '$id'.");
                return;
            }

            $workers = array($worker);

        } else {
            $workers = Worker::hostWorkers();
        }

        if (!count($workers)) {
            Yii::warning('There are no workers on this host');
        }

        $sig = $force ? 'TERM' : 'QUIT';

        foreach ($workers as $worker) {
            if (posix_kill($worker->getPid(), constant('SIG' . $sig))) {
                Yii::info('Worker <pop>' . $worker . '</pop> ' . $sig . ' signal sent.');

            } else {
                Yii::error("Worker <pop>$worker</pop> could not send $sig signal.");
            }
        }
    }
}