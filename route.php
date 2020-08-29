<?php
//socket 路由
class route
{
	private $param = null;
	private $msg = '';
	private $controller = '';
	private $action = '';

	//初始化
	public function __init($data){
		if (empty($data['action'])) {
			return false;
		}

		$arr = explode('/', trim($data['action'],'/'));
		if (empty($arr[0])) {
			$this->controller = 'Index';
		} else {
			$this->controller = $arr[0];
		}
		if (empty($arr[1])) {
			$this->action = 'index';
		} else {
			$this->action = $arr[1];
		}

		$this->param = $data['param'];
		return true;
	}

	//调度
	public function dispatch($data){
		if ($this->__init($data)){
			if (!file_exists(__DIR__ . '/controller/' . $this->controller . '.php')) {
				$this->msg = __DIR__ . '/controller/' . $this->controller . '.php文件不存在';
				return false;
			}
			include_once __DIR__ . '/controller/' . $this->controller . '.php';
			return call_user_method_array($this->action, new $this->controller,$this->param);
		}
		$this->msg = 'action error';
		return false;
	}

	public function getMsg(){
		return $this->msg;
	}
}