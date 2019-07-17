<?php

namespace console\controllers;
use console\models\Customer;
use console\models\CustomerChat;
use console\models\Response;
use console\models\unit\Sed;
use Yii;

class MessageHandler
{
    private $key_id="bee_id_";

    /**
     * 聊天
     */
    public function chat($server,$redis,$params){

        $result=[];
        if(!$params['from_id'] || !$params["message"]){
            return $result;
        }

        $message=substr($params["message"],0,74);

        $check_message=$this->msgSecCheck($message);
        if($check_message && $check_message["errmsg"]=="ok") {

            $from_id = Sed::decode($params['from_id'], $this->getSedKey());
            //发送人用户信息是否存在
            $model = new Customer();
            $customer = $model->findOneById($from_id);
            if (!$customer) {
                return $result;
            }

            $my_key_id = $this->key_id . $customer->id;
            $my_fd = $this->getfd($redis, $my_key_id);

            //接收人是否存在
            $is_exist_to_id = false;
            $response = new Response();

            if (isset($params["to_id"])) {
                $to_id = Sed::decode($params['to_id'], $this->getSedKey());
                $customer_receive = $model->findOneById($to_id);

                if ($customer_receive) {
                    //私人消息
                    $key_id = $this->key_id . $customer_receive->id;
                    $fd = $this->getfd($redis, $key_id);

                    if (!$fd) {
                        //未读
                        $this->customerChat($customer->id, $customer_receive->id, $message, 1);
                    }

                    if ($fd && $fd != $my_fd) {
                        //推送给别人
                        $res = $response->getChat($customer, $message, '', 'left', $params["cmd"], $params['to_id']);
                        $server->push($fd, json_encode($res));
                        //已读
                        $this->customerChat($customer->id, $customer_receive->id, $message, 0);
                    }

                    //给自己也推送
                    $res = $response->getChat($customer, $message, '', 'right', $params["cmd"], $params['to_id']);
                    $server->push($my_fd, json_encode($res));
                    $is_exist_to_id = true;
                }
            }

            if (!$is_exist_to_id && !$params['to_id']) {
                //世界喊话
                foreach ($server->connections as $fd) {
                    //给自己也推送
                    if ($my_fd == $fd) {
                        $res = $response->getChat($customer, $message, '', 'right', $params["cmd"], null);
                        $server->push($fd, json_encode($res));
                    } else {
                        $res = $response->getChat($customer, $message, '', 'left', $params["cmd"], null);
                        $server->push($fd, json_encode($res));
                    }
                }
                //已读
                $this->customerChat($customer->id, 0, $message, 0);
            }

        }
    }

    /**
     * 用户是否在活动(心跳)
     */
    public function heart($server,$redis,$params){

        $result=[];
        if(!$params['from_id']){
            return $result;
        }

        $from_id=Sed::decode($params['from_id'],$this->getSedKey());
        //发送人用户信息是否存在
        $model=new Customer();
        $customer=$model->findOneById($from_id);
        if(!$customer){
            return $result;
        }

        $my_key_id=$this->key_id.$customer->id;
        $my_fd=$this->getfd($redis,$my_key_id);
        if($my_fd){
            $result['cmd']=$params["cmd"];
            $result['from_id']=$params["from_id"];
            $result['message']='activating';
            $server->push($my_fd,json_encode($result));
        }
    }

    /**
     * 订单索要推送
     * @param $server
     * @param $redis
     * @param $params
     */
    public function help($server,$redis,$params){

        if(!$params['from_id'] || !$params["message"] || !$params["to_id"]){
            die;
        }

        $from_id=Sed::decode($params['from_id'],$this->getSedKey());
        $to_ids=$params["to_id"];
        $message=json_encode($params["message"]);

        //发送人用户信息是否存在
        $model=new Customer();
        $customer=$model->findOneById($from_id);
        if(!$customer){
           die;
        }

        $my_key_id=$this->key_id.$customer->id;
        $my_fd=$this->getfd($redis,$my_key_id);

        //循环发送消息
        foreach ($to_ids as $to_id){
            $key_id=$this->key_id.$to_id;
            $fd=$this->getfd($redis,$key_id);
            $s_to_id=Sed::encode($to_id,$this->getSedKey());
            $response=new Response();
            if(!$fd){
               //未读
                $this->customerChat($customer->id,$to_id,$message,1,1);
            }
            else{
                if($fd && $fd!=$my_fd){

                    //推送给别人
                    $res=$response->getChat($customer,$message,'','left','chat',$s_to_id,1);
                    $server->push($fd,json_encode($res));

                    //已读
                    $this->customerChat($customer->id,$to_id,$message,0,1);
                }
            }

            //给自己也推送
            $res=$response->getChat($customer,$message,'','right',"chat",$s_to_id,1);
            $server->push($my_fd,json_encode($res));

        }
    }

    /**
     * 获取加解密key值
     */
    private function getSedKey(){
            return Yii::$app->params["sedKey"];
    }

    /**
     * 获取redis里面的fd
     */
    private function getfd($redis,$key){

        $redis_json=$redis->get($key);
        if($redis_json) {
            $info = json_decode($redis_json, true);
            if($info){
                return $info['fd'];
            }
        }
        return 0;
    }

    /**
     * 用户聊天记录
     */
    private function customerChat($my_customer_id,$customer_id,$message,$status,$type=0){

        $model=new CustomerChat();
        $model->my_customer_id=$my_customer_id;
        $model->customer_id=$customer_id;
        $model->message=$message;
        $model->status=$status; //(0:已读,1:未读)
        $model->type=$type;
        $model->save();
    }

    /**
     * 消息内容检查
     */
    private function msgSecCheck($content){

        $token=$this->getAccessToken();
        if($token){

            $request_url="https://api.weixin.qq.com/wxa/msg_sec_check?access_token=$token";
            $context = stream_context_create(
                [
                  'http'=>[
                      'method'=>'POST',
                      'header' => 'Content-Type:application/x-www-form-urlencoded',
                      'content' =>'{"content":"'.$content.'"}',
                  ]
                ]
            );

            $result=file_get_contents($request_url, false, $context);
            $result=json_decode($result,true);

            return $result;
        }
    }

    /**
     * 获取access token
     */
    private function getAccessToken(){

        $app_id=Yii::$app->params['appId'];
        $app_secret=Yii::$app->params['appSecret'];
        $host_url=str_replace("controllers","runtime/access_token.json",__DIR__);

        if(file_exists($host_url)){
            $file=file_get_contents($host_url,true);
            $result = json_decode($file,true);
            if(!$result || time()>$result['expires']){
                $request_url="https://api.weixin.qq.com/cgi-bin/token?grant_type=client_credential&appid=".$app_id."&secret=".$app_secret;
                $response=file_get_contents($request_url);
                $result=json_decode($response,true);

                $data['access_token']=$result['access_token'];
                $data['expires']=time()+7000;
                $jsonStr = json_encode($data);

                $fp = fopen($host_url, "w");
                fwrite($fp, $jsonStr);
                fclose($fp);
            }
            return $result['access_token'];
        }
        else{
            var_dump("not exist file");
            return "";
        }
    }

}