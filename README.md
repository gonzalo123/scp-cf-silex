PHP (Silex) application in SAP Cloud Platform. With PostgreSQL, Redis and Cloud Foundry
======

Keeping on with my study of SAP's cloud platform (SCP) and Cloud Foundry today I'm going to build a simple PHP application with SILEX. This Silex application serves a simple Bootstrap landing page. The application uses a HTTP basic authentication. The credentials are validated against a PostgreSQL database. Is also has a API to retrieve the localtimestamp from database server. I want to play with Redis in the cloud too, so the API request will have a Time To Live (ttl) of 5 seconds. I will use Redis service to do that.

First we create our Services in cloud foundry. I'm using the free layer of SAP cloud foundry for this example. I'm not going to explain here how to do that. It's pretty straightforward within SAP's coopkit. Time ago I played with IBM's cloud foundry too. I remember that it was also very simple too.

Then We create our application (.bp-config/options.json)

```js
{
    "WEBDIR": "www",
    "LIBDIR": "lib",
    "PHP_VERSION": "{PHP_70_LATEST}",
    "PHP_MODULES": ["cli"],
    "WEB_SERVER": "nginx"
}
```

If we want to use our PostgreSQL and Redis services with our PHP Appliacation we need to connect those services to our application. This operation can be done also with SAP's Cockpit.

Now is the turn of PHP application. If you're familiar with Silex micro framework (or another microframework, indeed) you can see that there isn't anything especial.

```php
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
```

Maybe the only especial thing is the way that autoloader is done. We are initializing autoloader in two different ways. One when the application is run in the cloud and another one when the application is run locally with PHP's built-in server. That's because vendors are located in different paths depending on which environment the appliaction lives in. When Cloud Foundry connect services to appliations it inject environment variables with the service configuration (credentials, host, ...). It uses VCAP_SERVICES env var.

I use the built-in server to run the application locally. When I'm doing that I don't have VCAP_SERVICES variable. And also my services information are different than when I'm runing the application in the cloud. I'm using this trick:
 
```php
if (php_sapi_name() == "cli-server") {
    // I'm runing the application locally
} else {
    // I'm in the cloud
}
```
So when I'm locally I mock VCAP_SERVICES with my local values and also, for example, configure Silex application in debug mode.

Sometimes I want to run my application locally but I want to use the cloud services. I cannot connect directly to those services, but we can do it over ssh through our connected application.
For example If our PostgreSQL application is running on 10.11.241.0:48825 we can map this remote port (in a private network) to our local port with this command

```
cf ssh -N -T -L 48825:10.11.241.0:48825 silex
```
Now we can use pgAdmin, for example, in our local machine to connect to cloud server.


We can do the same with Redis
```
cf ssh -N -T -L 54266:10.11.241.9:54266 silex
```

And basically that's all

References:

* https://docs.cloudfoundry.org/devguide/deploy-apps/ssh-services.html
* https://docs.cloudfoundry.org/buildpacks/php/index.html
