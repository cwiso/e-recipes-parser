<?php

namespace common\modules\recipes\components;

use common\modules\recipes\models\Flavor;
use common\modules\recipes\models\FlavorBrand;
use common\modules\recipes\models\FlavorSourceLink;
use common\modules\recipes\models\Recipe;
use common\modules\recipes\models\RecipeFlavor;
use common\modules\recipes\models\Source;
use phpQuery;
use phpQueryObject;
use yii\base\Exception;
use yiiCustom\logger\LoggerStream;

/**
 * Компонет граббинга.
 */
class ELiquidRecipesGrabber extends AbstractGrabber {

	/** Идентификатор источника */
	const SOURCE_ID = Source::E_LIQUID_RECIPES_ID;

	/** @var string Начальная страница */
	public $startUrl;

	/** @var LoggerStream Логгер */
	public $logger;
	const ATTR_LOGGER = 'logger';

	/** @var string[] Бренды ароматизаторов: имя бренда => id */
	protected $flavorsBrandsIdsInKeys;

	/**
	 * @inheritdoc
	 */
	public function start() {
		$this->cookieFile = tempnam("/tmp", "e-liquid_recipes_cookie_file");

		if ($this->startUrl === null) {
			$this->startUrl = $this->source->url;
		}

		//инициализируем список брендров ароматизаторов
		$this->flavorsBrandsIdsInKeys = FlavorBrand::find()
			->select([FlavorBrand::ATTR_ID])
			->indexBy(FlavorBrand::ATTR_TITLE)
			->column();

		$firstPageHtml = $this->getListPage();

		if ($firstPageHtml === null) {
			$this->logger->log('Не удалось получить первую страницу, прерываем выполнение', LoggerStream::TYPE_ERROR);
		}

		$firstPage = phpQuery::newDocumentHTML($firstPageHtml);/** @var phpQueryObject $firstPage */

		$this->processRecipesLinksPage($firstPage);

		$maxPageNumber = $this->getMaxPageNumber($firstPage);

		for ($pageNumber = 2; $pageNumber <= $maxPageNumber; $pageNumber++) {
			//получаем страницу списка рецептов
			$pageHtml = $this->getListPage($pageNumber);
			$page = phpQuery::newDocumentHTML($pageHtml);/** @var phpQueryObject $page */

			if ($pageHtml === null) {
				$this->logger->log('Не удалось получить страницу, прерываем выполнение', LoggerStream::TYPE_ERROR);

				return ;
			}

			$processResult = $this->processRecipesLinksPage($page);

			if ($processResult === false) {
				$this->logger->log('Произошла ошибка при обработке рецептов', LoggerStream::TYPE_ERROR);

				return ;
			}

			$this->logger->log('Рецепт успешно обработан', LoggerStream::TYPE_ERROR);

			phpQuery::unloadDocuments();
		}

		//удаляем файл куки, если он есть
		if (file_exists($this->cookieFile)) {
			unlink($this->cookieFile);
		}
	}

	/**
	 * Получение страницы списка рецептов.
	 *
	 * @param int $pageNumber
	 *
	 * @return string|null
	 */
	protected function getListPage($pageNumber = 1) {
		$url = $this->startUrl . '/?page=' . $pageNumber;
		$this->logger->log('Получаем страницу ' . $url);

		return $this->load($url);
	}

	/**
	 * Получение списка ссылок на рецепты на странице.
	 *
	 * @param phpQueryObject $page
	 *
	 * @return string[]
	 */
	protected function getRecipesLinks(phpQueryObject $page) {
		$this->logger->log('Парсим ссылки рецептов');

		/** @var string[] $links */
		$links = [];

		foreach ($page->find('table.recipelist tr') as $tr) {
			$href = phpQuery::pq($tr)->find('td:first a');

			if ($href->count() === 0) {
				continue;
			}

			$links[] = $href->attr('href');
		}

		$this->logger->log('Получено ссылок: ' . count($links));

		return $links;
	}

	/**
	 * Получение максимального номера страницы пагинации.
	 *
	 * @param phpQueryObject $page
	 *
	 * @return int
	 */
	protected function getMaxPageNumber(phpQueryObject $page) {
		$this->logger->log('Получаем максимальный номер страницы');

		$liList = $page->find('div.pagination li');
		$a = $liList->eq($liList->length - 2)->find('a');
		$number = (int) $a->text();

		$this->logger->log('Результат: ' . $number);

		return $number;
	}

	/**
	 * Обработка страницы ссылок на рецепты.
	 *
	 * @param phpQueryObject $page
	 *
	 * @throws Exception
	 */
	protected function processRecipesLinksPage(phpQueryObject $page) {
		foreach ($this->getRecipesLinks($page) as $recipeUrl) {
			$this->logger->log('Обрабатываем рецепт по ссылке ' . $recipeUrl);

			//обрабатываем сам рецепт
			$recipeSiteId = null;

			$fromSourceId = null;
			if (preg_match('/recipe\/([0-9]+)\//i', $recipeUrl, $result)) {
				$fromSourceId = $result[1];
			}

			if ($fromSourceId === null) {
				$this->logger->log('Ошибка при парсинге URL рецепта: ' . $recipeUrl, LoggerStream::TYPE_ERROR);

				return;
			}

			$recipePageHtml = $this->load($recipeUrl);

			if ($recipePageHtml === null) {
				throw new Exception('Не удалось загрузить страницу: ' . $recipeUrl);
			}

			$page = phpQuery::newDocumentHTML($recipePageHtml);/** @var phpQueryObject $page */
			$name  = $page->find('#recipecontent #rname')->text();
			$notes = $page->find('#recipecontent #rnotes')->text();

			$recipe = Recipe::findOne([
				Recipe::ATTR_SOURCE_ID        => static::SOURCE_ID,
				Recipe::ATTR_SOURCE_RECIPE_ID => $fromSourceId,
			]);/** @var Recipe $recipe */

			if ($recipe === null) {
				$recipe = new Recipe();
				$this->logger->log('Добавляем рецепт ' . $name . ' (' . $recipeUrl . ')');
			}
			else {
				$this->logger->log('Обновляем рецепт ' . $recipe->id . ' ' . $name . ' (' . $recipeUrl . ')');
			}

			$recipe->title            = $name;
			$recipe->notes            = $notes;
			$recipe->source_id        = static::SOURCE_ID;
			$recipe->source_recipe_id = $fromSourceId;

			if ($recipe->save() === false) {
				$this->logger->log('Ошибка при сохранении рецепта: ' . print_r($recipe->errors, true), LoggerStream::TYPE_ERROR);

				return;
			}

			$this->processFlavorsOnRecipePage($recipe->id, $page);
		}
	}

	/**
	 * Обработка ароматизаторов на странцие рецептов.
	 *
	 * @param int            $recipeId Идентификатор рецепта
	 * @param phpQueryObject $page     Страница
	 */
	protected function processFlavorsOnRecipePage($recipeId, phpQueryObject $page) {
		//обрабатываем ароматизаторы
		//идентификаторы ароматизаторов
		$flavorsSiteIds = [];

		//обрабатываем все ароматизаторы на странице рецепта
		foreach ($page->find('#recipecontent #recflavor .recline') as $flavorLine) {
			$flavorHref = phpQuery::pq($flavorLine)->find('.rlab a');
			$flavorLink = $flavorHref->attr('href');
			$flavorName = $flavorHref->text();

			$fromSourceId = null;
			if (preg_match('/flavor\/([0-9]+)\/?/i', $flavorLink, $result)) {
				$fromSourceId = $result[1];
			}

			if ($fromSourceId === null) {
				$this->logger->log('Ошибка при парсинге URL ароматизатора: ' . $flavorHref, LoggerStream::TYPE_ERROR);

				return;
			}

			$flavor = Flavor::find()
				->joinWith(Flavor::REL_SOURCE_LINKS)
				->where([
					FlavorSourceLink::tableName() . '.' . FlavorSourceLink::ATTR_SOURCE_ID        => static::SOURCE_ID,
					FlavorSourceLink::tableName() . '.' . FlavorSourceLink::ATTR_SOURCE_FLAVOR_ID => $fromSourceId,
				])
				->one();/** @var Flavor $flavor */

			$isNew = false;
			if ($flavor === null) {
				$isNew = true;
			}

			if ($isNew === true || $this->isNeedToUpdateFlavors === true) {
				if ($isNew === true) {
					$flavor = new Flavor();
					$this->logger->log('Создаём ароматизатор ' . $flavorName . ' (' . $flavorLink . ')');
				}
				else {
					$this->logger->log('Обновляем ароматизатор ' . $flavorName . ' (' . $flavorLink . ')');
				}

				$flavor->title = $flavorName;

				//добавляем бренд
				$brandName = $flavorHref->find('abbr')->attr('title');

				$brandId = null;

				if ($brandName !== '') {
					if (array_key_exists($brandName, $this->flavorsBrandsIdsInKeys) === false) {
						$flavorBrand = new FlavorBrand();

						$flavorBrand->title = $brandName;

						if ($flavorBrand->save() === true) {
							$this->flavorsBrandsIdsInKeys[$brandName] = $flavorBrand->id;

							$brandId = $flavorBrand->id;
						}
						else {
							$this->logger->log('Ошибка при сохранении бренда ароматизатора: ' . print_r($flavorBrand->errors, true), LoggerStream::TYPE_ERROR);
						}
					}
					else {
						$brandId = $this->flavorsBrandsIdsInKeys[$brandName];
					}
				}

				if ($brandId !== null) {
					$flavor->brand_id = $brandId;
				}

				if ($flavor->save() === false) {
					$this->logger->log('Ошибка при сохранении ароматизатора: ' . print_r($flavor->errors, true), LoggerStream::TYPE_ERROR);

					return;
				}

				if ($isNew === true) {
					//добавляем связку с источником
					$link = new FlavorSourceLink();

					$link->flavor_id        = $flavor->id;
					$link->source_id        = static::SOURCE_ID;
					$link->source_flavor_id = $fromSourceId;

					if ($link->save() === false) {
						$this->logger->log('Ошибка при сохранении связки ароматизатора с источником: ' . print_r($link->errors,
								true), LoggerStream::TYPE_ERROR);

						return;
					}
				}

			}

			$flavorsSiteIds[] = $flavor->id;
		}

		//далее обновляем список аром у рецепта
		$currentFlavors = RecipeFlavor::find()
			->where([
				RecipeFlavor::ATTR_RECIPE_ID => $recipeId,
			])
			->select(RecipeFlavor::ATTR_FLAVOR_ID)
			->column();

		$newFlavors = array_diff($flavorsSiteIds, $currentFlavors);
		$oldFlavors = array_diff($currentFlavors, $flavorsSiteIds);

		//добавляем новые
		foreach ($newFlavors as $flavorId) {
			$recipeFlavor = new RecipeFlavor();

			$recipeFlavor->recipe_id = $recipeId;
			$recipeFlavor->flavor_id = $flavorId;

			if ($recipeFlavor->save() === false) {
				$this->logger->log('Ошибка при сохранении связи между рецептом и ароматизатором: ' . print_r($recipeFlavor->errors, true), LoggerStream::TYPE_ERROR);

				return;
			}
		}

		//и удаляем старые
		foreach ($oldFlavors as $flavorId) {
			RecipeFlavor::deleteAll([
				RecipeFlavor::ATTR_RECIPE_ID => $recipeId,
				RecipeFlavor::ATTR_FLAVOR_ID => $flavorId
			]);
		}
	}
}