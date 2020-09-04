<?php
include_once __DIR__ . '/websocket/Server.php';
include_once __DIR__ . '/websocket/ConnectionException.php';

$server = new Server([
    'timeout' => 60, // 1 minute time out
    'port' => 8000,
]);

function wlog($log, $dir = ''){
     $dir = __DIR__ . '/logs/' . $dir;
    if (!is_dir($dir)) {
        mkdir($dir, '0777', true);
    }
    $file = $dir . date('Y-m-d') . '.log';
    $log = date('Y-m-d H:i:s') . ' : ' . $log;
    file_put_contents($file, $log . "\r\n", FILE_APPEND);
}


while ($server->accept()) {
    try {
        while (true) {
            $message = $server->receive();
            echo "Received $message\n\n";

            if ($message === 'exit') {
            echo microtime(true), " Client told me to quit.  Bye bye.\n";
            echo microtime(true), " Close response: ", $server->close(), "\n";
            echo microtime(true), " Close status: ", $server->getCloseStatus(), "\n";
            exit;
            }

            if ($message === 'Dump headers') {
            $server->send(implode("\r\n", $server->getRequest()));
            }
            if ($message === 'ping') {
            $server->send('ping', 'ping', true);
            }
            elseif ($auth = $server->getHeader('Authorization')) {
            $server->send("$auth - $message", 'text', false);
            }
            else {
            $server->send($message, 'text', false);
            }
        }

    } catch (\ConnectionException $e) {
        // Possibly log errors
        // var_dump($e);
        // file_put_contents('./logs/' . date('Y-m-d H:i:s', time()), $e->getMessage());
       wlog($e->getMessage(), 'exception');
    }
}
$server->close();