<?php

use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpFoundation\Request;
use Silex\Provider\TwigServiceProvider;
use Silex\Application;
use Predis\Client;

if (php_sapi_name() == "cli-server") {
    // when I start the server my local machine vendors are in a different path
    require __DIR__ . '/../vendor/autoload.php';
    // and also I mock VCAP_SERVICES env
    $env   = file_get_contents(__DIR__ . "/../conf/vcap_services.json");
    $debug = true;
} else {
    require 'vendor/autoload.php';
    $env   = $_ENV["VCAP_SERVICES"];
    $debug = false;
}

$vcapServices = json_decode($env, true);

$app = new Application(['debug' => $debug, 'ttl' => 5]);

$app->register(new TwigServiceProvider(), [
    'twig.path' => __DIR__ . '/../views',
]);

$app['db'] = function () use ($vcapServices) {
    $dbConf = $vcapServices['postgresql'][0]['credentials'];
    $dsn    = "pgsql:dbname={$dbConf['dbname']};host={$dbConf['hostname']};port={$dbConf['port']}";
    $dbh    = new PDO($dsn, $dbConf['username'], $dbConf['password']);
    $dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $dbh->setAttribute(PDO::ATTR_CASE, PDO::CASE_UPPER);
    $dbh->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    return $dbh;
};

$app['redis'] = function () use ($vcapServices) {
    $redisConf = $vcapServices['redis'][0]['credentials'];

    return new Client([
        'scheme'   => 'tcp',
        'host'     => $redisConf['hostname'],
        'port'     => $redisConf['port'],
        'password' => $redisConf['password'],
    ]);
};

$app->get("/", function (Application $app) {
    return $app['twig']->render('index.html.twig', [
        'user' => $app['user'],
        'ttl'  => $app['ttl'],
    ]);
});

$app->get("/timestamp", function (Application $app) {
    if (!$app['redis']->exists('timestamp')) {
        $stmt = $app['db']->prepare('SELECT localtimestamp');
        $stmt->execute();
        $app['redis']->set('timestamp', $stmt->fetch()['TIMESTAMP'], 'EX', $app['ttl']);
    }

    return $app->json($app['redis']->get('timestamp'));
});

$app->before(function (Request $request) use ($app) {
    $username = $request->server->get('PHP_AUTH_USER', false);
    $password = $request->server->get('PHP_AUTH_PW');

    $stmt = $app['db']->prepare('SELECT name, surname FROM public.user WHERE username=:USER AND pass=:PASS');
    $stmt->execute(['USER' => $username, 'PASS' => md5($password)]);
    $row = $stmt->fetch();
    if ($row !== false) {
        $app['user'] = $row;
    } else {
        header("WWW-Authenticate: Basic realm='RIS'");
        throw new HttpException(401, 'Please sign in.');
    }
}, 0);

$app->run();
