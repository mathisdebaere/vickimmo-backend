# php-boilerplate
PHP project folder structure for the courses *Back-end Development* and *Full-stack: Introductory Project*, part of the Professional Bachelor ICT study program.

**main** branch: MySQL 8.4 | PhpMyAdmin | Apache + PHP 8.3 | libs: vlucas/phpdotenv, doctrine/dbal, twig/twig, bramus/router 

**plesk-web-api** branch: MariaDB 10.6.18 | PhpMyAdmin | Apache + PHP 8.3 | libs: vlucas/phpdotenv, doctrine/dbal, bramus/router

**!!!** The multi-container environment performs an **automated install** in the dev env when started:
* the **composer** container performs ```composer install``` creating the ```vendor``` folder
* when ```.env``` is missing, the **run-command** container copies ```.env.dev``` to ```.env```

## Links

* [Course slides](https://ikdoeict.gitlab.io/public/vakken/back-end-development/workshops/)
* [PHP Documentation](https://www.php.net/docs.php)
* [MySQL 8.4 Reference Manual](https://dev.mysql.com/doc/refman/8.4/en/)
* [Doctrine DBAL 4.2 documentation](https://www.doctrine-project.org/projects/doctrine-dbal/en/4.2/index.html)
* [Twig 3.x documentation](https://twig.symfony.com/doc/3.x/)
* [bramus/router documentation](https://github.com/bramus/router)

## Installing and developing your own project based on this boilerplate

1. Create a new completely! empty project (without README) on gitlab.com/ikdoeict, for example my-project
2. Execute following commands on your system (pay attention !)
```shell
mkdir my-project
cd my-project
git init
git pull https://gitlab.com/ikdoeict/public/vakken/back-end-development/php-boilerplate.git
git remote add origin https://gitlab.com/ikdoeict/<your-name>/my-project.git
git push -u origin main
```
3. From now on, you can stage, commit and push as usual.
4. Open the main folder with an IDE (such as PhpStorm or Visual Studio Code)

## Running and stopping the Docker MCE

* Run the environment, using Docker, from your terminal/cmd
```shell
cd <your-project>
docker-compose up
```
* Stop the environment in your terminal/cmd by pressing <code>Ctrl+C</code>
* In order to avoid conflicts with your lab/slides environment, run from your terminal/cmd
```shell
docker-compose down
```

## Development
* Feel free to introduce new environment variables in ```.env``` (not on git), but
  * be gentle and add them to ```.env.dev``` (on git), if necessary with an empty value (e.g. ```API_KEY=```)
  * add them to the ```$dotenv->required``` list in ```index.php```
* In order to install libraries, run from your terminal/cmd
```shell
docker-compose exec php-web bash
$ composer ...
$ exit
```
* Want to know whether the **composer** container still works? Delete the ```vendor``` folder and restart with ```docker compose down/up```

* `composer.json` is configured such that the classes in "src/" (and subfolders) are autoloaded.
  * This means there is no need to require these classes anymore in your `public/*.php` scripts.
  * When you created some new class(es), let composer know from your terminal/cmd:
```shell
docker-compose exec php-web bash
$ composer dump-autoload
$ exit
```

## Recipes and troubleshooting

### <code>docker-compose up</code> does not start one or more containers
* Look at the output of <code>docker-compose up</code>. When a container (fails and) exits, it is shown as the last line of the container output (colored tags by container)
* Alternatively, start another terminal/cmd and inspect the output of <code>docker-compose ps -a</code>. You can see which container exited, exactly when.
* Probably one of the containers fails because TCP/IP port 8000, 8080 or 3307 is already in use on your system. Stop the environment, change the port in <code>docker-compose.yml</code> en rerun <code>docker-compose up</code>.


