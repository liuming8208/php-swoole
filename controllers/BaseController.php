<?php

namespace console\controllers;

use yii\console\Controller;

class BaseController extends Controller
{
    public $from_id;
    public $cmd;

    public $key_id="bee_id_";
    public $key_fd="bee_fd_";
    public $sedKey;

    public static $ONLINE="online";
    public static $OFFLINE="offline";

    public static $FROM_ID_ERROR="发送人错误";
    public static $METHOD_NOT_EXIST="请求方法不存在";

}