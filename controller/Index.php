<?php

class Index
{
	public function index($param = null){
		// var_dump($param);
		$this->cache($param['user_id'], $param['fd']);
		// var_dump(json_decode('{"action":"Index/index","param":{"user_id":"1","to_user_id":"2","msg":"你好！"}}',1));
		if (isset($param['to_user_id'])) {
			$server = $param['socket'];
			$fd = $this->cache($param['to_user_id']);
			if ($fd) {
				$server->send($fd, $param['msg']);
			}
		}
	}

	public function cache($key, $val = null){
		// {"action":"Index/index","param":{"param1":"123"}}
		$dir = dirname(__DIR__) . '/cache/';
		if (!is_dir($dir)) {
			mkdir($dir, '0777', true);
		}
		$file = $dir . $key . '.cachedata';
		if ($val === null) {
			if (file_exists($file)) {
				return unserialize(file_get_contents($file));
			}
			return '';
		}
		file_put_contents($file, serialize($val));
	}

}

// var_dump(json_decode('{"action":"Index/index","param":{"user_id":"1","to_user_id":"2","msg":"你好！"}}',1));
// (new Index())->index(array());