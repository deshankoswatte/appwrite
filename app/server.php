<?php

require_once __DIR__.'/../vendor/autoload.php';

use Appwrite\Swoole\Files;
use Appwrite\Swoole\Request;
use Appwrite\Swoole\Response;
use Swoole\Process;
use Swoole\Http\Server;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use Utopia\App;
use Utopia\CLI\Console;

// xdebug_start_trace('/tmp/trace');

ini_set('memory_limit','512M');
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

sleep(2);

$http = new Server("0.0.0.0", 80);

$http
    ->set([
        'open_http2_protocol' => true,
        // 'document_root' => __DIR__.'/../public',
        // 'enable_static_handler' => true,
        'timeout' => 7,
        'http_compression' => true,
        'http_compression_level' => 6,
        'package_max_length' => 1000000 * 150, // 150MB
    ])
;

$http->on('WorkerStart', function($serv, $workerId) {
    Console::success('Worker '.++$workerId.' started succefully');
});

$http->on('BeforeReload', function($serv, $workerId) {
    Console::success('Starting reload...');
});

$http->on('AfterReload', function($serv, $workerId) {
    Console::success('Reload completed...');
});

$http->on('start', function (Server $http) {
    Console::success('Server started succefully');

    Console::info("Master pid {$http->master_pid}, manager pid {$http->manager_pid}");

    // listen ctrl + c
    Process::signal(2, function () use ($http) {
        Console::log('Stop by Ctrl+C');
        $http->shutdown();
    });
});

Files::load(__DIR__ . '/../public');

include __DIR__ . '/app.php';

$domain = App::getEnv('_APP_DOMAIN', '');

Console::info('Issuing a TLS certificate for the master domain ('.$domain.') in 30 seconds.
    Make sure your domain points to your server IP or restart your Appwrite server to try again.'); // TODO move this to installation script

ResqueScheduler::enqueueAt(\time() + 30, 'v1-certificates', 'CertificatesV1', [
    'document' => [],
    'domain' => $domain,
    'validateTarget' => false,
    'validateCNAME' => false,
]);

$http->on('request', function (SwooleRequest $swooleRequest, SwooleResponse $swooleResponse) {
    $request = new Request($swooleRequest);
    $response = new Response($swooleResponse);

    if(Files::isFileLoaded($request->getURI())) {
        $time = (60 * 60 * 24 * 365 * 2); // 45 days cache

        $response
            ->setContentType(Files::getFileMimeType($request->getURI()))
            ->addHeader('Cache-Control', 'public, max-age='.$time)
            ->addHeader('Expires', \date('D, d M Y H:i:s', \time() + $time).' GMT') // 45 days cache
            ->send(Files::getFileContents($request->getURI()))
        ;

        return;
    }

    $app = new App('Asia/Tel_Aviv');
    
    try {
        $app->run($request, $response);
    } catch (\Throwable $th) {
        if(App::isDevelopment()) {
            var_dump(get_class($th));
            var_dump($th->getMessage());
            var_dump($th->getFile());
            var_dump($th->getLine());
            $swooleResponse->end('error: '.$th->getMessage());
        }
        
        $swooleResponse->end('500: Server Error');
    }
});

$http->start();