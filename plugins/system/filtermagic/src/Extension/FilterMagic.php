<?php
/**
 * @package   FilterMagic
 * @copyright Copyright (c)2022 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

namespace Dionysopoulos\Plugin\System\FilterMagic\Extension;

defined('_JEXEC') || die;

use Joomla\CMS\Categories\CategoryNode;
use Joomla\CMS\HTML\Registry;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Component\Content\Site\Model\ArticlesModel;
use Joomla\Component\Content\Site\Model\CategoryModel;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Event\Event;
use Joomla\Event\SubscriberInterface;

class FilterMagic extends CMSPlugin implements SubscriberInterface
{
	use DatabaseAwareTrait;

	public static function getSubscribedEvents(): array
	{
		return [
			'onAfterRoute'        => 'prepare',
			'onContentAfterTitle' => 'displayForm',
		];
	}

	public function handleModel($articlesModel, $categoryModel)
	{
		if (!$articlesModel instanceof ArticlesModel || !$categoryModel instanceof CategoryModel)
		{
			return;
		}

		$category = $categoryModel->getCategory();

		if (!$category instanceof CategoryNode)
		{
			return;
		}

		// TODO Check category ID and determine which filter document to load

		// TODO If no filter document: return

		// TODO Collect filters from user state

		// TODO     Process
		//        - Subcategory filters
		//        - Tags filters
		//        - Custom field filters

		// TODO Find article IDs which match the filters

		// TODO Set the model's state
		//$articlesModel->setState('filter.article_id', $whatever);
		//$articlesModel->setState('filter.article_id.include', true);
	}

	/**
	 * Display the filter form in the category page
	 *
	 * @param   Event  $event
	 *
	 * @since   1.0.0
	 */
	public function displayForm(Event $event)
	{
		/**
		 * @var string       $context
		 * @var CategoryNode $item
		 * @var Registry     $params
		 * @var ?int         $limitstart
		 */
		[$context, $item, $params, $limitstart] = $event->getArguments();

		if ($context !== 'com_content.categories')
		{
			return;
		}

		// TODO Check $item->id and determine which filter document to load

		// TODO If no document: return ""

		// TODO Generate and output filter form
	}

	/**
	 * Prepare com_content for this plugin.
	 *
	 * @param   Event  $event
	 *
	 * @return  void
	 * @since   1.0.0
	 */
	public function prepare(Event $event)
	{
		if (!$this->getApplication()->isClient('site'))
		{
			return;
		}

		$input  = $this->getApplication()->input;
		$option = $input->getCmd('option');
		$view   = $input->getCmd('view');
		$format = $input->getCmd('format');

		if (
			$option !== 'com_content'
			|| $view !== 'category'
			|| $format !== 'html'
		)
		{
			return;
		}

		$this->patchModel();
	}

	/**
	 * In-memory patching of com_content's CategoryModel to call back to our plugin.
	 *
	 * @return  void
	 * @since   1.0.0
	 */
	private function patchModel(): void
	{
		require_once __DIR__ . '/../Util/Buffer.php';

		$source     = JPATH_ROOT . '/components/com_content/src/Model/CategoryModel.php';
		$phpContent = file_get_contents($source);
		$phpContent = str_replace(
			'$this->_articles = $model->getItems();',
			<<< PHP

\\Joomla\\CMS\\Factory::getApplication()->bootPlugin('filtermagic', 'system')
	->handleModel(\$model, \$this);

\$this->_articles = \$model->getItems();

PHP
			, $phpContent
		);

		$bufferLocation = 'plgSystemFilterMagic://ContentCategoryModel.php';
		file_put_contents($bufferLocation, $phpContent);
		require_once $bufferLocation;
	}
}