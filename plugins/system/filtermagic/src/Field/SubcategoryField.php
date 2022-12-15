<?php
/**
 * @package   FilterMagic
 * @copyright Copyright (c)2022 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

namespace Dionysopoulos\Plugin\System\FilterMagic\Field;

defined('_JEXEC') || die;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\Field\ListField;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\Database\DatabaseDriver;
use Joomla\Database\ParameterType;
use Joomla\Utilities\ArrayHelper;

class SubcategoryField extends ListField
{
	/**
	 * The form field type.
	 *
	 * @var    string
	 * @since  1.0.0
	 */
	public $type = 'Subcategory';

	/**
	 * Method to get the field options for category
	 * Use the extension attribute in a form to specify the.specific extension for
	 * which categories should be displayed.
	 * Use the show_root attribute to specify whether to show the global category root in the list.
	 *
	 * @return  array    The field option objects.
	 *
	 * @since   1.6
	 */
	protected function getOptions()
	{
		$options   = [];
		$published = (string) $this->element['published'] ?? 1;
		$language  = (string) $this->element['language'] ?? '';
		$root      = (string) $this->element['root'] ?? 0;

		$filters = [];

		if ($published)
		{
			$filters['filter.published'] = explode(',', $published);
		}

		if ($language)
		{
			$filters['filter.language'] = explode(',', $language);
		}

		if ($root)
		{
			$filters['filter.root'] = (int) $root;
		}

		$options = $this->options($filters);

		// Merge any additional options in the XML definition.
		$initialOptions = parent::getOptions();

		if (empty($initialOptions))
		{
			$initialOptions = [
				HTMLHelper::_('select.option', '', '- ' . Text::_('JCATEGORY') . ' -')
			];
		}

		$options  = array_merge($initialOptions, $options);

		return $options;
	}

	private function options(array $filters)
	{
		/** @var DatabaseDriver $db */
		$db     = Factory::getContainer()->get('DatabaseDriver');
		$user   = Factory::getApplication()->getIdentity();
		$groups = $user->getAuthorisedViewLevels();

		$query = $db->getQuery(true)
			->select(
				[
					$db->quoteName('a.id'),
					$db->quoteName('a.title'),
					$db->quoteName('a.level'),
					$db->quoteName('a.language'),
				]
			)
			->from($db->quoteName('#__categories', 'a'))
			->where($db->quoteName('a.extension') . ' = ' . $db->quote('com_content'))
			->whereIn($db->quoteName('a.access'), $groups);

		if (isset($filters['filter.root']))
		{
			$subQuery = $db->getQuery(true)
				->select($db->quoteName('lft'))
				->from($db->quoteName('#__categories'))
				->where($db->quoteName('id') . ' = ' . $db->quote((int) $filters['filter.root']));

			$query->where($db->quoteName('lft') . ' > (' . $subQuery . ')');
		}
		else
		{
			$query->where($db->quoteName('a.parent_id') . ' > 0');
		}

		// Filter on the published state
		if (isset($filters['filter.published']))
		{
			if (is_numeric($filters['filter.published']))
			{
				$query->where($db->quoteName('a.published') . ' = :published')
					->bind(':published', $filters['filter.published'], ParameterType::INTEGER);
			}
			elseif (is_array($filters['filter.published']))
			{
				$filters['filter.published'] = ArrayHelper::toInteger($filters['filter.published']);
				$query->whereIn($db->quoteName('a.published'), $filters['filter.published']);
			}
		}

		// Filter on the language
		if (isset($filters['filter.language']))
		{
			if (is_string($filters['filter.language']))
			{
				$query->where($db->quoteName('a.language') . ' = :language')
					->bind(':language', $filters['filter.language']);
			}
			elseif (is_array($filters['filter.language']))
			{
				$query->whereIn($db->quoteName('a.language'), $filters['filter.language'], ParameterType::STRING);
			}
		}

		// Filter on the access
		if (isset($filters['filter.access']))
		{
			if (is_numeric($filters['filter.access']))
			{
				$query->where($db->quoteName('a.access') . ' = :access')
					->bind(':access', $filters['filter_access'], ParameterType::INTEGER);
			}
			elseif (is_array($filters['filter.access']))
			{
				$filters['filter.access'] = ArrayHelper::toInteger($filters['filter.access']);
				$query->whereIn($db->quoteName('a.access'), $filters['filter.access']);
			}
		}

		$query->order($db->quoteName('a.lft'));

		$db->setQuery($query);
		$items = $db->loadObjectList();

		// Assemble the list options.
		$options = [];

		foreach ($items as &$item)
		{
			$repeat      = ($item->level - 1 >= 0) ? $item->level - 1 : 0;
			$item->title = str_repeat('- ', $repeat) . $item->title;

			if ($item->language !== '*')
			{
				$item->title .= ' (' . $item->language . ')';
			}

			$options[] = HTMLHelper::_('select.option', $item->id, $item->title);
		}

		return $options;
	}
}