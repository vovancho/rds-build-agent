<?php
use RdsSystem\Message;
use RdsSystem\lib\CommandExecutor;
use RdsSystem\lib\CommandExecutorException;

/**
 * @example dev/services/deploy/misc/tools/runner.php --tool=Deploy_Deploy -vv
 */
class Cronjob_Tool_Deploy_Deploy extends RdsSystem\Cron\RabbitDaemon
{
    const PREPROD_TIMEOUT = 300;

    private $gid;
    private $taskId;
    private $version;

    /** @var \RdsSystem\Model\Rabbit\MessagingRdsMs */
    private $model;

    /** @var \RdsSystem\Model\Rabbit\MessagingRdsMs */
    private $preprodModel;

    /** @var \RdsSystem\Message\BuildTask */
    private $currentTask;

    /**
     * Use this function to get command line spec for cronjob
     * @return array
     */
    public static function getCommandLineSpec()
    {
        return [] + parent::getCommandLineSpec();
    }

    /**
     * Performs actual work
     */
    public function run(\Cronjob\ICronjob $cronJob)
    {
        $workerName = \Config::getInstance()->workerName;
        $this->model  = $this->getMessagingModel($cronJob);

        $rdsSystem = new RdsSystem\Factory($this->debugLogger);
        $this->preprodModel = $rdsSystem->getMessagingRdsMsModel('preprod');

        $this->gid = posix_getpgid(posix_getpid());

        $this->model->getBuildTask($workerName, false, function(\RdsSystem\Message\BuildTask $task) use ($workerName) {
            $this->currentTask = $task;
            $this->debugLogger->message("Task received: ".json_encode($task));

            posix_setpgid(posix_getpid(), posix_getpid());
            $commandExecutor = new CommandExecutor($this->debugLogger);
            $project = $task->project;
            $this->taskId = $taskId = $task->id;
            $this->version = $version = $task->version;
            $release = $task->release;
            $lastBuildTag = $task->lastBuildTag;

            $semaphore = new \Semaphore($this->debugLogger, \Config::getInstance()->semaphore_dir."/merge_deploy.smp");

            $basePidFilename = \Config::getInstance()->pid_dir."/{$workerName}_deploy_$taskId.php";
            file_put_contents("$basePidFilename.pid", posix_getpid());
            file_put_contents("$basePidFilename.pgid", posix_getpgid(posix_getpid()));

            register_shutdown_function(function() use ($basePidFilename){
                unlink("$basePidFilename.pid");
                unlink("$basePidFilename.pgid");
            });

            $projectDir = "/home/release/buildroot/$project-$version/var/pkg/$project-$version/";

            if (Config::getInstance()->debug) {
                $projectDir = $project == 'comon' ? "/home/an/dev/$project/" : "/home/an/dev/services/$project/";
            }

            $currentOperation = "none";
            try {
                //an: Сигнализируем о начале работы
                $currentOperation = "send status 'building'";
                $this->sendStatus('building');

                //an: Собираем проект
                $command = "env VERBOSE=y bash bash/rebuild-package.sh $project $version $release $taskId ".Config::getInstance()->rdsDomain." ".Config::getInstance()->createTag." $lastBuildTag 2>&1";

                if (Config::getInstance()->debug) {
                    $command = "php bash/fakeRebuild.php $project $version";
                }

                $currentOperation = "building";

                $this->debugLogger->message("Locking semaphore");
                $semaphore->lock();
                $this->debugLogger->message("Locked semaphore");
                $text = $commandExecutor->executeCommand($command);
                $semaphore->unlock();
                $this->debugLogger->message("Unlocked semaphore");

                $output = '';
                //an: хак, для словаря мы ничего не тегаем и патч не отправляем, пока что
                if ($project != 'dictionary') {
                    //an: Отправляем на сервер какие тикеты были в этом билде
                    $currentOperation = "getting_build_patch";
                    $srcDir="/home/release/build/$project";

                    if ($lastBuildTag) {
                        $command = "(cd $srcDir/lib; node /home/release/git-tools/alias/git-all.js \"git log $lastBuildTag..$project-$version --pretty='%H|%s|/%an/'\")";
                    } else {
                        $command = "(cd $srcDir/lib; node /home/release/git-tools/alias/git-all.js \"git log $lastBuildTag --pretty='%H|%s|/%an/'\")";
                    }

                    if (Config::getInstance()->debug) {
                        $command = "cat /home/an/log.txt";
                    }

                    try {
                        $output = $commandExecutor->executeCommand($command);
                    } catch (CommandExecutorException $e) {
                        //an: 128 - это когда нет какого-то тега в прошлом.
                        //@todo подумать как это корректо обрабатывать такую ситуацию и реализовать
                        $output = $e->output;
                        if ($e->getCode() != 128) {
                            throw $e;
                        }
                    }
                }

                $currentOperation = "sending_build_patch";

                $this->debugLogger->message("Sending building patch, length=".strlen($output));
                $this->model->sendBuildPatch(
                    new Message\ReleaseRequestBuildPatch($project, $version, $output)
                );

                //an: Сигнализируем все что собрали и начинаем раскладывать по серверам
                $this->sendStatus('built', $version);

                //an: Должно быть такое же, как в rebuild-package.sh
                $filename = "$projectDir/misc/tools/migration.php";
                $migrations = array();
                if (file_exists($filename)) {
                    //an: Проект с миграциями
                    foreach (array('pre', 'post', 'hard') as $type) {
                        $command = "php $filename migration --type=$type --project=$project new 100000 --interactive=0";
                        $text = $commandExecutor->executeCommand($command);
                        if (!preg_match('~Found (\d+) new migration~', $text, $ans)) {
                            continue;
                        }

                        //an: Текст, начиная с Found (\d+) new migration
                        $subtext = substr($text, strpos($text, $ans[0]));
                        $subtext = str_replace('\\', '/', $subtext);
                        $lines = explode("\n", str_replace("\r", "", $subtext));
                        array_shift($lines);
                        $migrations = array_slice($lines, 0, $ans[1]);
                        $migrations = array_map('trim', $migrations);
                        $this->model->sendMigrations(
                            new Message\ReleaseRequestMigrations($project, $version, $migrations, $type)
                        );
                    }
                }

                if (\Config::getInstance()->debug) {
                    $migrations = ["Y2014_2/m140905_090321_add_new_func_get_trade_transaction_list_by_account_and_operation_types","Y2014_2/m140908_193018_add_col__trade_repeater__payment__status","Y2014_2/m140908_194447_new_sp__trade_repeater__add_payment","Y2014_2/m140908_195905_new_sp__trade_repeater__update_payment_transfer_status"];
                    $this->model->sendMigrations(
                        new Message\ReleaseRequestMigrations($project, $version, $migrations, 'pre')
                    );
                    $migrations = ["Y2014_2/m140804_121502_rds_test #WTA-67"];
                    $this->model->sendMigrations(
                        new Message\ReleaseRequestMigrations($project, $version, $migrations, 'hard')
                    );
                }


                $currentOperation = "installing";
                //an: Раскладываем собранный проект по серверам
                $command = "bash bash/deploy.sh install $project $version 2>&1";

                if (Config::getInstance()->debug) {
                    $command = "php bash/fakeRebuild.php $project $version";
                }
                $text = $commandExecutor->executeCommand($command);

                if (\Config::getInstance()->installToPreprod && $task->installToPreProd) {
                    $this->installToPreprod($this->model, $taskId, $version, $project);
                } else {
                    $this->debugLogger->message("Skip installing to preprod");
                }

                //an: Отправляем новые сгенерированные /etc/cron.d конфиги
                $cronConfig = "";
                if (!Config::getInstance()->debug) {
                    foreach (glob("$projectDir/misc/cronjobs/cronjob-*") as $file) {
                        $cronConfig .= "#       ".preg_replace('~^.*/~', '', $file)."\n\n";
                        $cronConfig .= file_get_contents($file);
                        $cronConfig .= "\n\n";
                    }
                } elseif (file_exists("/home/an/cronjob-$project")) {
                    $cronConfig = file_get_contents("/home/an/cronjob-$project");
                }

                $this->model->sendCronConfig(
                    new Message\ReleaseRequestCronConfig($taskId, $cronConfig)
                );
                //an: Сигнализируем все что сделали
                $this->sendStatus('installed', $version, $text);
            } catch (CommandExecutorException $e) {
                $text = $e->output;
                echo "\n=======================\n";
                $title = "Failed to execute '$currentOperation'";
                echo "$title\n";

                $this->sendStatus('failed', $version, $text);
            } catch (Exception $e) {
                $this->debugLogger->error("Unknown error: ".$e->getMessage());
                $this->sendStatus('failed', $version, $e->getMessage());
            }

            $this->debugLogger->message("Accepting message $task->deliveryTag");
            $task->accepted();

            $this->debugLogger->message("Restoring pgid");
            posix_setpgid(posix_getpid(), $this->gid);
        });

        $this->waitForMessages($this->model, $cronJob);
    }

    /**
     * Отправляет на сервер текущий статус сборки на конкретной машине сборщике
     *
     * @param $status
     * @param null $version
     * @param null $attach
     */
    private function sendStatus($status, $version = null, $attach = null)
    {
        $this->debugLogger->message("Task status changed: status=$status, version=$version, attach_length=".strlen($attach));
        $this->model->sendTaskStatusChanged(
            new Message\TaskStatusChanged($this->taskId, $status, $version, $attach)
        );
    }

    public function onTerm($signo)
    {
        $this->debugLogger->message("Caught signal $signo");;
        if ($signo == SIGTERM || $signo == SIGINT) {
            $this->currentTask->accepted();

            $this->debugLogger->message("Cancelling...");
            $this->sendStatus('cancelled', $this->version);
            $this->debugLogger->message("Cancelled...");


            CoreLight::getInstance()->getFatalWatcher()->stop();

            //an: выходим со статусом 0, что бы periodic не останавливался
            exit(0);
        }

        return parent::onTerm($signo);
    }

    private function installToPreprod($model, $taskId, $version, $project)
    {
        $this->debugLogger->message("Installing to preprod");
        //an: хардкод, тут нужно перебрать всех воркеров preprod контура
        $worker = 'debian';

        //an: генерируем рамдомную строчку, нам главное проверить что ответ пришел на нужным нам запрос
        $releaseRequestId = uniqid();

        $model->sendTaskStatusChanged(new Message\TaskStatusChanged($taskId, 'preprod_using'));


        $this->debugLogger->message("Sending use task to preprod: project=$project, version=$version");

        $this->preprodModel->sendUseTask($worker, new Message\UseTask($project, $releaseRequestId, $version, 'used'));

        $this->preprodModel->readUsedVersion(false, function (Message\ReleaseRequestUsedVersion $message) use ($project, $version, $worker, $model, $taskId) {
            if ($message->version != $version || $message->project != $project || $message->worker != $worker) {
                $this->debugLogger->message("Skip used message as $message->version != $version || $message->project != $project || $message->worker != $worker");
                $message->accepted();
                return;
            }

            $message->accepted();
            if ($message->status == 'used') {
                $this->debugLogger->message("Sending migration task to preprod: project=$project, version=$version");
                $model->sendTaskStatusChanged(new Message\TaskStatusChanged($taskId, 'preprod_migrations'));
                $this->preprodModel->sendMigrationTask(new Message\MigrationTask($project, $version, 'pre'));
            } else {
                throw new Exception("Failed to use $project-$version on preprod " . json_encode($message));
            }
        });

        $this->preprodModel->readUseError(false, function (Message\ReleaseRequestUseError $message) use ($releaseRequestId, $model, $taskId) {
            if ($message->releaseRequestId != $releaseRequestId) {
                $this->debugLogger->message("Skip use error message as $message->releaseRequestId != $releaseRequestId");
                $message->accepted();
                return;
            }

            $message->accepted();

            $model->sendTaskStatusChanged(new Message\TaskStatusChanged($taskId, 'failed'));
            $this->debugLogger->error("Failed to use version on preprod, " . json_decode($message));
            $this->preprodModel->stopReceivingMessages();

            throw new \Exception("Failed to use version on preprod, " . json_decode($message));
        });

        $this->preprodModel->readOldVersion(false, function (Message\ReleaseRequestOldVersion $message) use ($releaseRequestId, $model, $taskId) {
            if ($message->releaseRequestId != $releaseRequestId) {
                $this->debugLogger->message("Skip readOldVersion message as $message->releaseRequestId != $releaseRequestId");
                $message->accepted();
                return;
            }

            //an: Просто вычитываем очередь, что бы вообщения не скапливались
            $message->accepted();
        });

        $this->preprodModel->readMigrationStatus(false, function (Message\ReleaseRequestMigrationStatus $message) use ($project, $version, $worker) {
            $message->accepted();
            if ($message->version == $version && $message->project == $project && $message->type == 'pre') {
                if ($message->status == 'up') {
                    $this->debugLogger->message("Migrations are up to date, exiting");
                    $this->preprodModel->stopReceivingMessages();
                } else {
                    throw new Exception("Failed to migrate on preprod " . json_encode($message->errorText));
                }
            }
        });


        try {
            $this->preprodModel->waitForMessages(null, null, self::PREPROD_TIMEOUT);
        } catch (\PhpAmqpLib\Exception\AMQPTimeoutException $e) {
            $this->debugLogger->error("Failed to use version on preprod as timeout");
            $model->sendTaskStatusChanged(new Message\TaskStatusChanged($taskId, 'failed'));
            throw $e;
        }
    }
}
