<?php
defined('YII_DEBUG') or define('YII_DEBUG', false);
defined('YII_ENV') or define('YII_ENV', 'dev');

defined('LOG_LOCAL4') or define('LOG_LOCAL4', LOG_USER); // windows quickfix

require_once(__DIR__ . '/../vendor/autoload.php');
require(__DIR__ . '/../vendor/yiisoft/yii2/Yii.php');
$config = require(__DIR__ . '/../src/config/console.php');
$config['components']['log']['targets'] = [];

$application = new \yii\console\Application($config);