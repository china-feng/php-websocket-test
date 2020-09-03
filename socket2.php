<?php
 
/**
* 多个进程监听同个端口
*/
class Server
{
    protected $ip = '127.0.0.1';
    protected $port = 5000;
    protected $sock = null;
 
    public function main()
    {
        if(($this->sock = socket_create(AF_INET,SOCK_STREAM,SOL_TCP)) < 0) {
            echo "socket_create() 失败的原因是:".socket_strerror($sock)."\n";
            return ;
        }
        if(($ret = socket_bind($this->sock,$this->ip,$this->port)) < 0) {
            echo "socket_bind() 失败的原因是:".socket_strerror($ret)."\n";
            return ;
        }
        if(($ret = socket_listen($this->sock,4)) < 0) {
            echo "socket_listen() 失败的原因是:".socket_strerror($ret)."\n";
            return ;
        }
        for ($i=0; $i<3; $i++)
        {
            $pid = pcntl_fork();
            if (-1 === $pid) {
                throw new Exception("fork fail");
            } elseif (0 === $pid) {
                echo "fork pid:".getmypid()."\n";
                while (1) {
                    if(($msgsock = socket_accept($this->sock)) < 0) {
                        echo "socket_accept() failed: reason: " . socket_strerror($msgsock) . " ,pid: ".getmypid()."\n";
                        break;
                    }else{
                        $msg ="测试成功 ! \n";
                        echo $msg."pid: ".getmypid()."\n";
                        socket_write($msgsock, $msg, strlen($msg));
                    }
                }
            }    
        }
        while(1)
        {
            $status = 0;
            $pid = pcntl_wait($status,WUNTRACED);    
            if($pid > 0)
            {
                echo "pid:$pid exit,status:$status";
            }        
        }
 
    }
 
}
 
$server = new Server();
$server->main();