<?php

// error_reporting(E_ALL); 
// ini_set("display_errors","On");
// ini_set("display_errors","Off");  不起作用

header('Content-type:text/html;charset=utf-8;');
include_once __DIR__ . '/database.class.php';  
include_once dirname(__DIR__)  . '/redis.class.php';
include_once __DIR__ . '/AutoLoad.php';
include_once __DIR__ . '/Common.func.php';

$server = new swoole_websocket_server("0.0.0.0", 8000);

$set = array(
	'worker_num' => 1,
	// 'heartbeat_check_interval' => 5,  //多久轮训一次所有连接
 //    'heartbeat_idle_time' => 10, //连接超过多久没有反应就断开连接
);

if (in_array('-d', $argv)) {  //守护进程
	$set['daemonize'] = 1;
}

$server->set($set); 

$server->on('WorkerStart', function ($server, $worker_id) {
	logs('listen ……');
});

$server->on('open', function (swoole_websocket_server $server, $request) {
	$param   = $request->get;
	$token   = $param['token'];
	$user_id = $param['user_id'];
	$type    = $param['type'];     //

	// echo "server: handshake success with user_id{$user_id}\n";
	logs("server: handshake success with user_id{$user_id}");
	try {
		if (!checkUserToken($user_id, $token)){
			throw new \Exception('token 无效！');
		};

		//token 存入
		cache('chat_user_token_' . $user_id, $token);

		//user_id fd 相互绑定
		cache('chat_user_fd_map:'  . $user_id, $request->fd);
		cache('chat_fd_user_map:'  . $request->fd, $user_id);

		//发送好友上线通知
		$myConn = getMysqlConn();
		\DB::init($myConn);
		$friend_ids = \DB::T('chat_friend')->getFriendsId($user_id);
		$user_info = \DB::T('user')->getOne(array('id' => $user_id));

		// var_dump($friend_ids);
		if ($friend_ids) {
			//整理数据
			$data_obj            = new stdClass();
			$data_obj->server    = $server;
			$data_obj->client_id = $request->fd;
			// $data_obj->user_id   = $server->connection_info($request->fd);
			$data_obj->data      = (object)array();

			
			//跳到相应控制器处理
	    	$obj = '\\controller\\chat\\Base';
	    	$obj = new $obj($data_obj);
	    	$action = 'pushGroup';
	    	$obj->$action($friend_ids, array('code' => 300, 'friend_id' =>  $user_id, 'friend_account' => $user_info['account']));
		}

	} catch (\Exception $e) {
		$server->push($request->fd, $e->getMessage());
		$server->close($request->fd);
	}
});

$server->on('message', function (swoole_websocket_server $server, $frame) {
	// echo "receive from {$frame->fd}:{$frame->data},opcode:{$frame->opcode},fin:{$frame->finish}\n";
    // $server->push($frame->data, "this is server");
    // var_dump($server->connection_info($frame->fd));
    if (cache('chat_fd_user_map:' . $request->fd)){
    	return $server->push($frame->fd, 'user_id error');
    };
    if ($frame->data === 'ping') {
    	return $server->push($frame->fd, 'pong');
    }
    try {
		$myConn = getMysqlConn();
		\DB::init($myConn);

    	$data = json_decode($frame->data);
	    if (empty($data->action)) {
	    	$server->push($frame->fd, "action error");	
	    }
	    $path_arr = explode('.', $data->action);
	    if (is_file(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'controller' . DIRECTORY_SEPARATOR . 'chat' . DIRECTORY_SEPARATOR . ucfirst($path_arr[0]) . '.php')) {
	    	//整理数据
			$data_obj            = new stdClass();
			$data_obj->server    = $server;
			$data_obj->client_id = $frame->fd;
			// $data_obj->user_id   = $server->connection_info($frame->fd);
			$data_obj->data      = empty($data->param) ? (object)array() : $data->param;

			
			//跳到相应控制器处理
	    	$obj = '\\controller\\chat\\' . ucfirst($path_arr[0]);
	    	$obj = new $obj($data_obj);
	    	$action = empty($path_arr[1]) ? 'index' : $path_arr[1];
	    	$obj->$action();
	    } else {
	    	$server->push($frame->fd, "action file error");		
	    }
	    mysql_close($myConn);
    } catch(\Exception $e){
    	// $e->getMessage()
    	mysql_close($myConn);
    	$server->push($frame->fd, json_encode(array('code' => 1000, 'msg' => $e->getMessage())));
    }
    
});

$server->on('close', function ($ser, $fd) {
    // echo "client {$fd} closed\n";
    $user_id = cache('chat_fd_user_map:' . $fd);
    logs("{$user_id} client {$fd} closed");
});

$server->start();