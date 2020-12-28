<?php

class Loader
{
	public static function autoLoad($class_name){
		//映射
		$map = array(
			'logic' => dirname(__DIR__) . DIRECTORY_SEPARATOR . 'logic',
			'controller' => dirname(__DIR__) . DIRECTORY_SEPARATOR . 'controller',
		);


		$arr = explode('\\', trim($class_name, '\\'));
		$filename_ = '';
		foreach ($arr as $k => $v) {
			if ($k == 0 && !empty($map[$v])) {
				$filename_ .= $map[$v]; 
			} else {
				$filename_ .= DIRECTORY_SEPARATOR . $v; 
			}
		}
		 $filename = $filename_ .'.class.php';  //加class
		 
		 if (is_file($filename)) {
		 	include_once $filename;
		 	return;
		 }
		 $filename = $filename_ .'.php';  //不加class
		 if (is_file($filename)) {
		 	include_once $filename;
		 }
	}
}
spl_autoload_register('\\Loader::autoLoad');
