<?php

namespace console\controllers;

/**
 * console 主入口
 * Class MainController
 * @package console\controllers
 */
class MainController extends BaseController
{
    /**
     * 运行
     */
    public function actionIndex(){
        $this->checkCli();
        $this->showHelp();
        $this->command();
    }

    private function checkCli(){
        if(php_sapi_name()!=="cli"){
            exit("服务只能运行在cli sapi模式下");
        }
    }

    private function showHelp(){

        echo PHP_EOL;
        echo '|          php ./yii main          |'.PHP_EOL;
        echo '|----------------------------------|'.PHP_EOL;
        echo '|    1. start    启动服务          |'.PHP_EOL;
        echo '|    2. reload   重启服务          |'.PHP_EOL;
        echo '|    3. stop     关闭服务          |'.PHP_EOL;
        echo '|----------------------------------|'.PHP_EOL;
        echo PHP_EOL;
    }

    private function command(){
        global $argv;
        $command=$argv[2];
        switch ($command){
            case "start":
                $this->workerStart();
                break;
            case "reload":
                $this->workerReload();
                break;
            case "stop":
               $this->workerStop();
                break;
            default:
                echo "Bad Command".PHP_EOL;
        }
    }

    private function workerStart(){

    }

    private function workerReload(){

    }

    private function workerStop(){

    }





}