<?php

use React\Http\Middleware\LimitConcurrentRequestsMiddleware;
use React\Http\Middleware\RequestBodyBufferMiddleware;

require 'vendor/autoload.php';

$loop = \React\EventLoop\Factory::create();
$filesystem = \React\Filesystem\Filesystem::create($loop);
$mysql = new \React\MySQL\Factory($loop);
$db = $mysql->createLazyConnection('root:root@mysql/react_db');

$trimMiddleware = function (\Psr\Http\Message\ServerRequestInterface $request, callable $next) {
    $params = $request->getParsedBody();
    $filteredParams = [];

    if (is_array($params)) {
        foreach ($params as $key => $value) {
            $filteredParams[trim($key)] = trim($value);
        }
    }

    $request = $request->withParsedBody($filteredParams);

    return $next($request);
};

$server = new \React\Http\Server([
        $trimMiddleware,
        function (\Psr\Http\Message\ServerRequestInterface $request) use ($filesystem, $db) {
        $path = $request->getUri()->getPath();
        $method = $request->getMethod();
        $params = $request->getParsedBody();

        if ($path === '/upload') {
            $files = $request->getUploadedFiles();

            if (!empty($files['file'])) {
                $uploadedFile = $files['file'];
                $pathInfo = pathinfo($uploadedFile->getClientFilename());
                $pathToSave = './files/' . time() . '.' . $pathInfo['extension'];
                $file = $filesystem->file($pathToSave);

                $file->open('cw')->then(function(React\Stream\WritableStreamInterface $stream) use ($uploadedFile) {
                    $fileContent = $uploadedFile->getStream()->getContents();
                    $stream->write($fileContent);
                    $stream->end();
                    echo "Data was written\n";
                });

                return new \React\Http\Response(200,['Content-Type' => 'text/plain'], "File with size: {$uploadedFile->getSize()} bytes uploaded");
            }


            $stream = $request->getBody();
        }


        if ($path === '/posts' && $method === 'POST') {

            if (!empty($params['title']) && !empty($params['text'])) {
                return $db->query('INSERT INTO posts(title, text) VALUES (?, ?)', [$params['title'], $params['text']])
                    ->then(function () {
                            return new \React\Http\Response(200,['Content-Type' => 'text/plain'], 'Post added');
                        },
                        function (\Exception $e) {
                            return new \React\Http\Response(400,['Content-Type' => 'text/plain'], 'Error: '.$e->getMessage());
                        }
                    );
            }

            return new \React\Http\Response(400,['Content-Type' => 'text/plain'], 'Fields title and text are required');
        }

            if ($path === '/posts' && $method === 'GET') {

                    return $db->query('SELECT * FROM posts')
                        ->then(function (\React\MySQL\QueryResult $queryResult) {
                                $results = $queryResult->resultRows;
                                $body = json_encode(['data' => $results]);
                                return new \React\Http\Response(200,['Content-Type' => 'application/json'], $body);
                            },
                            function (\Exception $e) {
                                $body = json_encode(['error' => $e->getMessage()]);
                                return new \React\Http\Response(400,['Content-Type' => 'application/json'], $body);
                            }
                        );


            }

        return new \React\Http\Response(200,['Content-Type' => 'text/plain'], 'Hello world\n');
    }
]);

$socket = new React\Socket\Server('0.0.0.0:8000', $loop);
$server->listen($socket);

$loop->run();


