<?php
include_once dirname(__DIR__) . '/redis.class.php';
include_once dirname(__DIR__) . '/database.class.php';  //mysql 连接

include_once dirname(__DIR__) . '/phplibrary/usertoken.php';

include_once __DIR__ . '/AutoLoad.php';
include_once __DIR__ . '/WebsocketServer2.php';

// error_reporting(E_ERROR | E_PARSE); 
ini_set("display_errors","off");

$service = new WebsocketServer('0.0.0.0','8000');

$service->on('onConnect',function($websocket_server, $client_id){
    // var_dump($client_id . ' connect');
    $websocket_server->send($client_id, "完成了连接：{$client_id}");
});

$service->on('onMessage',function($websocket_server, $client_id, $data){ //客服端消息事件
    /*var_dump(count($websocket_server->clients));
    $websocket_server->send($client_id, 'success');
    return false;*/
    var_dump(count($websocket_server->clients));
    var_dump($client_id);
    $websocket_server->send($client_id, $data);
    return false;
    // var_dump($data);
    global $myConn, $redisConn;
    // error_reporting(E_ERROR | E_PARSE); 
    // ini_set("display_errors","On");

    //mysql 断线重连----------------------start
    // if (empty($myConn)) {  
    //     var_dump('mysql 断线');
    //     //断线重连3次
    //     $i = 0;
    //     while ($i < 3 && empty($myConn)) {
    //         ++$i;
    //         $host       = '192.168.1.186';
    //         $dbuser     = "root" ;
    //         $dbpass     = "root" ;
    //         $database   = "ucenter" ;
    //         $myConn = @mysql_connect($host,$dbuser,$dbpass);
    //     } 
    //     if (empty($myConn)) {
    //         return false;
    //     }
    // }
    // $iden = true;
    // $i = 0;
    // while ($i < 3 && $iden) {
    //     ++$i;
    //     if (!mysql_ping($myConn)){
    //         var_dump('ping fail');
    //         mysql_close($myConn);    
    //         $host       = '192.168.1.186';
    //         $dbuser     = "root" ;
    //         $dbpass     = "root" ;
    //         $database   = "ucenter" ;
    //         $myConn = @mysql_connect($host,$dbuser,$dbpass);
    //         mysql_select_db($database, $myConn);
    //         mysql_query('SET NAMES utf8', $myConn);
    //     } else {
    //         $iden = false;
    //     }
    // }
    //mysql 断线重连----------------------end
    
    //redis 断线重连----------------------start
    // if (!$redisConn->ping()){
    //     $redisConn->reConnetct();
    // }
    //redis 断线重连----------------------end
    
    // $redisConn->set('20200929', 'redis:1');
    // var_dump($redisConn->get('20200929'));
    // \DB::init($myConn);
    // var_dump(\DB::fetch_row("SELECT * FROM chat_friend LIMIT 1"));
    // var_dump(expression)
    // var_dump(\DB);
    // $rediscfg = array('host'=>'192.168.1.186','port'=>6379,'auth'=>''); //redis 连接
    // $redisConn = new RedisConn($rediscfg);

    $data = json_decode($data);
    if (empty($data->action)) {
    	$websocket_server->throwException('action 为空');
    }
    $path_arr = explode('.', $data->action);
    if (is_file(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'controller' . DIRECTORY_SEPARATOR . 'chat' . DIRECTORY_SEPARATOR . ucfirst($path_arr[0]) . '.php')) {
    	//整理数据
		$data_obj            = new stdClass();
		$data_obj->server    = $websocket_server;
		$data_obj->client_id = $client_id;
		$data_obj->data      = empty($data->param) ? array() : $data->param;

		
		//跳到相应控制器处理
    	$obj = '\\controller\\chat\\' . ucfirst($path_arr[0]);
    	$obj = new $obj($data_obj);
    	$action = empty($path_arr[1]) ? 'index' : $path_arr[1];
    	$obj->$action();

    } else {
    	$websocket_server->throwException(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'controller' . DIRECTORY_SEPARATOR . 'chat' . DIRECTORY_SEPARATOR . ucfirst($path_arr[0]) . '.php 文件没找到');
    }
});

$service->on('onClose',function($websocket_server, $client_id){
    // include_once dirname(__DIR__) . '/database.class.php';

    var_dump($client_id . '  close');

    //整理数据
    // $data_obj            = new stdClass();
    // $data_obj->server    = $websocket_server;
    // $data_obj->client_id = $client_id;

    //跳到相应控制器处理
    // $obj = '\\controller\\chat\\Base';
    // $obj = new $obj($data_obj);
    // $action = 'onClose';
    // $obj->$action();
});


$service->run();