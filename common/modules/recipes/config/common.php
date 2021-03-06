<?php

use common\modules\recipes\Recipes;
use proxyProvider\components\FreeProxyListProvider;
use proxyProvider\components\GetProxyListProvider;
use proxyProvider\components\GimmeProxyProvider;
use proxyProvider\components\HideMeProvider;
use proxyProvider\components\ProxyProviderPool;
use yii\log\FileTarget;

return [
	'modules' => [
		'recipes' => [
			'class'      => Recipes::class,
			'components' => [
				'proxyProviderPool' => [
					'class' => ProxyProviderPool::class,
					ProxyProviderPool::ATTR_PROVIDERS_CONFIGS => [
						[
							'class' => FreeProxyListProvider::class,
							'token' => 'demo',
						],
						[
							'class' => GetProxyListProvider::class,
						],
//						[//хрень
//							'class' => \common\modules\recipes\components\proxyProvider\SpinProxiesProvider::class,
//							'key' => '8vmnfdemafuwipo6sa7drwdqmbhmyw',
//						],
						[
							'class' => GimmeProxyProvider::class,
						],
						[
							'class' => HideMeProvider::class,
						],
					],
				],
			],
		],
	],
	'components' => [
		'log' => [
			'flushInterval' => 1,
			'targets' => [
				[
					'class'          => FileTarget::class,
					'levels'         => ['info', 'trace'],
					'categories'     => ['recipes', 'recipes1', 'recipes2'],
					'logFile'        => '@runtime/logs/recipes.log',
					'exportInterval' => 1,
					'logVars'        => [],
					'maxLogFiles'    => 1000,
				],
			],
		],
	],
];