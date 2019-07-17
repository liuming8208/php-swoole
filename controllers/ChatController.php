<?php

namespace console\controllers;

use console\models\Customer;
use console\models\CustomerChat;
use console\models\CustomerFriend;
use console\models\Response;
use console\models\unit\Sed;
use Yii;

class ChatController extends BaseController
{
    private static $server;
    private static $instance;
    private $redis;
    private $messageHandler; //处理消息对象

    const redis_time_out=259200; //redis数据过期时间3天

    //配置redis 开机自动启动redis-server端
    //启动 php ./yii chat
    //查看端口 lsof -i:9912 (kill -9 port)
    //虚拟机测试地址：192.168.159.100/chat/test?open_id=oVzAK426c06Gvs_Ba3wtx6Bm-L4w&token=
    //进入redis:  redis-cli

    public function __construct()
    {
        self::$server = new \swoole_websocket_server("0.0.0.0", Yii::$app->params['chat'],SWOOLE_PROCESS, SWOOLE_SOCK_TCP | SWOOLE_SSL);
        self::$server ->set([
            'daemonize' => 0, //守护进程化。
            'heartbeat_check_interval'=>300, //心跳检查时间(秒)
            'heartbeat_idle_time'=>600,
            //配置SSL证书和密钥路径
            'ssl_cert_file' => Yii::$app->params["ssl_cert_file"],
            'ssl_key_file'  => Yii::$app->params["ssl_key_file"]
        ]);

        //注册事件
        self::$server->on("open", [$this, "onOpen"]);
        self::$server->on("message", [$this, "onMessage"]);
        self::$server->on("close", [$this, "onClose"]);
        self::$server->on("workerStart", [$this, "onWorkerStart"]);
    }

    /**
     * 单一模式
     */
    public static function getInstance()
    {
        if (!self::$instance instanceof self) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 开始
     */
    public function start()
    {
        self::$server->start();
    }

    /**
     * 打开连接
     */
    public function onOpen($server, $req)
    {
        parse_str($req->server['query_string'],$get);

        //是否存在发送人
        if($get['from_id']){
            $from_id=Sed::decode($get['from_id'],Yii::$app->params["sedKey"]);
            $model=new Customer();
            $customer=$model->findOneById($from_id);
            if($customer){
                //加入redis中
                $key_id=$this->key_id.$customer->id;
                $key_fd=$this->key_fd.$req->fd;

                $value=['fd'=>$req->fd,'from_id'=>$from_id];
                $this->redis->setEx($key_id,self::redis_time_out,json_encode($value));
                $this->redis->setEx($key_fd,self::redis_time_out,$key_id);

                $response=new Response();
                $res=$response->getChat($customer,self::$ONLINE,date("H:i"),'right',"connect",$get['from_id']);
                self::$server->push($req->fd,json_encode($res));

                //通知好友上线
                $this->notice($customer,$get['from_id'],self::$ONLINE);
            }
        }

    }

    /**
     * 发送消息体
     */
    public function onMessage($server, $frame){
        $data = json_decode($frame->data, true);
        $this->cmd=$data["cmd"];
        $this->from_id=$data['from_id'];

        if($this->from_id){
            if (method_exists($this->messageHandler,$this->cmd)) {
                 call_user_func([$this->messageHandler,$this->cmd],self::$server,$this->redis,$data);
            }
            else{
                $this->getError();
            }
        }
    }

    /**
     * 断开链接
     */
    public function onClose($server, $fd){
        $key_fd=$this->key_fd.$fd;
        $key_customer= $this->redis->get($key_fd);

        //断开链接，给好友发送离线通知
        $customer_id=$this->getCustomerId($key_customer);
        if($customer_id){
            $this->noticeOffline($fd,$customer_id);
        }

        $this->redis->del($key_customer);
        $this->redis->del($key_fd);
    }

    /**
     * 初始化对象
     */
    public function onWorkerStart(){
        $this->messageHandler =new MessageHandler();
        $this->redis=new \Redis();
        $this->redis->connect("127.0.0.1",6379);
    }

    /**
     * 下线通知
     */
    private function noticeOffline($my_fd,$my_customer_id){

        $model=new Customer();
        $customer=$model->findOneById($my_customer_id);
        if(!$customer){
            return null;
        }

        $to_id=Sed::encode($customer->id,Yii::$app->params["sedKey"]);
        //给自己也推送一条
        $response=new Response();
        $res=$response->getChat($customer,self::$OFFLINE,date("H:i"),'right',"connect",$to_id);
        self::$server->push($my_fd,json_encode($res));

        //更新自己已经离线
        $customer->login_at=date("Y-m-d H:i:s");
        $customer->login_status=0;
        $customer->save();

        //通知
        $this->notice($customer,$to_id,self::$OFFLINE);
    }

    /**
     * 通知
     */
    private function notice($customer,$to_id,$message){
        //查找用户好友
        $frient_model=new CustomerFriend();
        $frient_data=$frient_model->findAllAddCustomerFriend($customer->id);
        if(!$frient_data){
            return null;
        }

        //循环在线的人群中，是否有我的好友
        foreach(self::$server->connections as $fd){

            $key_fd=$this->key_fd.$fd;
            $key_customer= $this->redis->get($key_fd);
            $customer_id=$this->getCustomerId($key_customer);

            //只给好友推送
            foreach ($frient_data as $key=>$value){
                if($customer_id==$value['my_customer_id']){
                    $response=new Response();
                    $res=$response->getChat($customer,$message,date("H:i"),'left',"connect",$to_id);
                    self::$server->push($fd,json_encode($res));
                    break;
                }
            }
        }
    }

    /**
     * 获取redis里面的Customer_id
     */
    private function getCustomerId($key){

        $redis_json=$this->redis->get($key);
        if($redis_json) {
            $info = json_decode($redis_json, true);
            if($info){
                return $info['from_id'];
            }
        }
        return 0;
    }

    /**
     * 错误信息
     */
    private function getError(){
        $from_id=Sed::decode($this->from_id,Yii::$app->params["sedKey"]);
        $model=new Customer();
        $customer=$model->findOneById($from_id);
        if($customer){
            $my_key_id=$this->key_id.$customer->id;
            $redis_json=$this->redis->get($my_key_id);
            if($redis_json) {
                $info = json_decode($redis_json, true);
                if($info){
                    $error=['cmd'=>$this->cmd,'from_id'=>$this->from_id,'message'=>self::$METHOD_NOT_EXIST];
                    self::$server->push($info['fd'],json_encode($error));
                }
            }
        }
    }

}

ChatController::getInstance()->start();