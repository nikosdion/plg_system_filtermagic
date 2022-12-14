<?php
/**
 * @package   FilterMagic
 * @copyright Copyright (c)2022 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

namespace Dionysopoulos\Plugin\System\FilterMagic\Extension;

defined('_JEXEC') || die;

use DOMElement;
use JetBrains\PhpStorm\ArrayShape;
use Joomla\CMS\Application\SiteApplication;
use Joomla\CMS\Categories\CategoryNode;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Form\FormField;
use Joomla\CMS\Form\FormHelper;
use Joomla\CMS\HTML\Registry;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Component\Content\Site\Model\ArticlesModel;
use Joomla\Component\Content\Site\Model\CategoryModel;
use Joomla\Component\Fields\Administrator\Helper\FieldsHelper;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Event\Event;
use Joomla\Event\SubscriberInterface;
use Joomla\Utilities\ArrayHelper;

class FilterMagic extends CMSPlugin implements SubscriberInterface
{
	use DatabaseAwareTrait;

	private static ?array $allFields = null;

	private array $forms = [];

	private array $flatFields = [];

	/**
	 * Returns an array of events this subscriber will listen to.
	 *
	 * @return  array
	 *
	 * @since   1.0.0
	 */
	public static function getSubscribedEvents(): array
	{
		return [
			'onAfterRoute'        => 'prepare',
			'onContentAfterTitle' => 'displayForm',
		];
	}

	/**
	 * Applies custom filtering to the articles retrieved by a CategoryModel.
	 *
	 * This is called directly by the CategoryModel after we hot-patch it in memory.
	 *
	 * @param   object  $articlesModel  The ArticlesModel instance created by the CategoryModel
	 * @param   object  $categoryModel  The CategoryModel initiating the articles fetch
	 *
	 * @since        1.0.0
	 * @noinspection PhpUnused
	 */
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

		// Check category ID and determine which filter document to load
		$form = $this->getForm($category->id);

		if ($form === null)
		{
			return;
		}

		// Get the filter state and make sure we need to apply filtering
		$filters    = (array) $form->getData()->get('filter');
		$hasFilters = array_reduce($filters, fn($carry, $value) => $carry || !empty($value), false);

		if (!$hasFilters)
		{
			return;
		}

		/**
		 * Get a filtering query.
		 *
		 * Note that all filters but the subcategory (which is a simple index match) use the MySQL EXISTS() operator
		 * inside WHERE clauses. This is faster than using subqueries, or performing additional queries to use their
		 * results in whereIn() clauses. This pushes down trigger expressions which inform the MySQL optimiser on the
		 * correlation between the inner and outermost queries, allows it to perform half-joins if necessary, and
		 * returns results in a fraction of time compared to using LEFT JOIN or subqueries. Furthermore, not performing
		 * additional queries allows us to avoid transferring large amounts of information between the application and
		 * database server which is not only slow, but can also result in a query that's too big for the server to
		 * execute (i.e. it overflows the max_packet_size).
		 *
		 * @see https://dev.mysql.com/doc/refman/8.0/en/exists-and-not-exists-subqueries.html
		 * @see https://dev.mysql.com/doc/refman/8.0/en/subquery-optimization-with-exists.html
		 */
		$db    = $this->getDatabase();
		$query = $db->getQuery(true)
			->select($db->quoteName('id'))
			->from($db->quoteName('#__content', 'c'));

		// -- Filter by subcategory
		$subCatId = $filters['catid'] ?? null;

		if (!empty($subCatId))
		{
			if (!is_array($subCatId))
			{
				$query->where($db->quoteName('catid') . ' = :catid')
					->bind(':catid', $subCatId);
			}
			else
			{
				$subCatId = ArrayHelper::toInteger($subCatId);
				$query->whereIn($db->quoteName('catid'), $subCatId);
			}
		}

		// -- Filter by tag
		$tags = $filters['tag'] ?? '';

		if (!empty($tags))
		{
			$tags = is_array($tags) ? $tags : explode(',', $tags);
			$tags = ArrayHelper::toInteger($tags);

			// Unfortunately, you cannot use whereIn in subqueries, so we have to fake it. Boo.
			$tags = implode(',', array_map([$db, 'quote'], $tags));

			$subQuery = $db->getQuery(true)
				->select('1')
				->from($db->quoteName('#__contentitem_tag_map', 't'))
				->where($db->quoteName('t.type_alias') . ' = ' . $db->quote('com_content.article'))
				->where($db->quoteName('t.tag_id') . ' IN(' . $tags . ')')
				->where($db->quoteName('t.content_item_id') . ' = ' . $db->quoteName('c.id'));
			$query->where('EXISTS(' . $subQuery . ')');
		}

		// Filter by custom fields. You are not expected to understand this.
		$customFields = $this->getFlatFieldsList($category->id);

		foreach ($customFields as $field)
		{
			if (!isset($filters[$field->name]) || $filters[$field->name] === '')
			{
				continue;
			}

			$value    = $filters[$field->name];
			$value    = is_array($value) ? $value : [$value];
			$tableKey = 'fv' . $field->id;
			$fieldId  = isset($field->parent_field_id) && !empty($field->parent_field_id)
				? $field->parent_field_id
				: $field->id;

			$subQuery = $db->getQuery(true)
				->select('1')
				->from($db->quoteName('#__fields_values', $tableKey))
				->where($db->quoteName('c.id') . ' = ' . $db->quoteName($tableKey . '.item_id'))
				->where($db->quoteName($tableKey . '.field_id') . ' = ' . $db->quote($fieldId));

			if (isset($field->parent_field_id) && !empty($field->parent_field_id))
			{
				/**
				 * Subform field. look for "field123":"value"
				 *
				 * In case of multiple values we need to apply inclusive disjunction (OR) across all constituent values.
				 * Since the LIKE operator does not have an inclusive disjunction form (in the way that the equals
				 * operator has that in the form of IN) we have to use extendWhere() with its third parameter set to OR.
				 *
				 * Externally, the value clause is conjunctive (AND) to the rest of the conditions, hence the use of
				 * 'AND' as the first argument to extendWhere().
				 */
				$conditions = array_map(
					fn($wrappedvalue) => $db->quoteName($tableKey . '.value') . ' LIKE ' . $db->quote($wrappedvalue),
					array_map(
						fn($v) => '%' . trim(json_encode(['field' . $field->id => $v]), '{}') . '%',
						$value
					)
				);

				$subQuery->extendWhere('AND', $conditions, 'OR');
			}
			else
			{
				/**
				 * Whole field value.
				 *
				 * The condition is externally conjunctive and internally inclusively disjunctive. We use IN() to
				 * denote inclusive disjunction instead of multiple WHERE clauses for better performance. Externally,
				 * where() uses the 'AND' operator making it conjunctive.
				 */
				$value = array_map([$db, 'quote'], $value);
				$subQuery->where($db->quoteName($tableKey . '.value') . ' IN(' . implode(',', $value) . ')');
			}

			$query->where('EXISTS(' . $subQuery . ')');
		}

		// Find article IDs which match the filters
		$articleIds = $db->setQuery($query)->loadColumn() ?: [];

		// Set the model's state
		$articlesModel->setState('filter.article_id', $articleIds ?: -1);
		$articlesModel->setState('filter.article_id.include', true);
	}

	/**
	 * Display the filter form in the category page
	 *
	 * @param   Event  $event
	 *
	 * @since        1.0.0
	 * @noinspection PhpUnused
	 */
	public function displayForm(Event $event)
	{
		/**
		 * @var string       $context
		 * @var CategoryNode $item
		 * @var Registry     $params
		 */
		[$context, $item, $params,] = $event->getArguments();

		if ($context !== 'com_content.categories')
		{
			return;
		}

		// Try to load a filter document
		$form = $this->getForm($item->id);

		if ($form === null)
		{
			return;
		}

		// Generate and output filter form
		$ret = LayoutHelper::render(
			'filtermagic.form',
			[
				'form' => $form,
			],
			JPATH_PLUGINS . '/system/filtermagic/layout'
		);

		$result   = $event->getArgument('result', []) ?: [];
		$result   = is_array($result) ? $result : [$result];
		$result[] = $ret;
		$event->setArgument('result', $result);
	}

	/**
	 * Prepare com_content for this plugin.
	 *
	 * @param   Event  $event
	 *
	 * @return  void
	 * @since        1.0.0
	 * @noinspection PhpUnused
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
		$format = $input->getCmd('format', 'html');

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

	/**
	 * Gets a flat list of fields (including subform fields) for a content category
	 *
	 * @param   int  $catId  The category ID
	 *
	 * @return  object[]  The list of fields, including subform sub-fields (but NOT the subforms themselves)
	 *
	 * @throws  \Exception
	 * @since   1.0.0
	 */
	private function getFlatFieldsList(int $catId): array
	{
		if (isset($this->flatFields[$catId]))
		{
			return $this->flatFields[$catId];
		}

		// Get all fields applicable to a category
		$fields = FieldsHelper::getFields('com_content.article', ['catid' => $catId], false);

		// Key fields by ID
		$keys   = array_map(fn(object $f) => $f->id, $fields);
		$fields = array_combine($keys, $fields);

		// Resolve subforms and inception fields
		return $this->flatFields[$catId] = $this->resolveSubformFields($fields);
	}

	/**
	 * Resolve subform and inception fields, listing their individual fields
	 *
	 * @param   array  $fields  The array of field definitions returned by FieldsHelper
	 *
	 * @return  array
	 *
	 * @throws  \Exception
	 * @since   1.0.0
	 */
	private function resolveSubformFields(array $fields): array
	{
		// Make sure we have a cache of all known fields.
		if (self::$allFields === null)
		{
			self::$allFields = FieldsHelper::getFields('com_content.article', null, false, null, true);
			$keys            = array_map(fn(object $f) => $f->id, self::$allFields);
			self::$allFields = array_combine($keys, self::$allFields);
		}

		$remove = [];
		$add    = [];

		foreach ($fields as $field)
		{
			// If the field is not a subform or inception field we have nothing to resolve.
			if (!in_array($field->type, ['subform', 'inception']))
			{
				continue;
			}

			// Mark the original subform/inception field for removal
			$remove[] = $field->id;

			// Get the subfields
			$subFields = array_map(
				fn(int $fieldId) => self::$allFields[$fieldId] ?? null,
				array_map(
					fn(object $o) => (int) $o->customfield,
					(array) $field->fieldparams->get('options')
				)
			);

			// Remove unresolvable fields
			$subFields = array_filter($subFields);

			// Mark the new fields for adding to the list
			foreach ($subFields as $f)
			{
				// We add a parent_field_id so we can use this easily for searching
				$f->parent_field_id = $field->id;
				$add[$f->id]        = $f;
			}
		}

		// Remove the subform/inception fields we processed
		foreach ($remove as $id)
		{
			if (isset($fields[$id]))
			{
				unset($fields[$id]);
			}
		}

		// Add any new fields we discovered in the subforms
		foreach ($add as $addField)
		{
			$fields[$addField->id] = $addField;
		}

		// If we did resolve any subform / inception fields, resolve any possibly nested subforms
		if (!empty($add))
		{
			$fields = $this->resolveSubformFields($fields);
		}

		return $fields;
	}

	/**
	 * Get the filter form for a category
	 *
	 * @param   int  $catId
	 *
	 * @return  Form|null
	 *
	 * @since   1.0.0
	 */
	private function getForm(int $catId): ?Form
	{
		if (isset($this->forms[$catId]))
		{
			return $this->forms[$catId];
		}

		/** @var SiteApplication $app */
		$app             = $this->getApplication();
		$template        = $app->getTemplate();
		$possibleFolders = [
			JPATH_THEMES . '/' . $template . '/filters',
			JPATH_PLUGINS . '/system/filtermagic/filters',
		];
		$filename        = 'filter_' . $catId . '.xml';

		foreach ($possibleFolders as $folder)
		{
			$formPath = $folder . '/' . $filename;

			if (file_exists($formPath) && is_file($formPath))
			{
				break;
			}

			$formPath = null;
		}

		if ($formPath === null)
		{
			return $this->forms[$catId] = null;
		}

		$form = new Form('filter_' . $catId, [
			'control' => 'filtermagic',
		]);

		$form->loadFile($formPath);

		// Get the form fields which need to be replaced
		$filterCustomFieldNames = [];

		foreach ($form->getGroup('filter') as $subkey => $field)
		{
			if ($field->getAttribute('customfield', null) !== '1')
			{
				continue;
			}

			$filterCustomFieldNames[] = $field->fieldname;
		}

		$this->transplantCustomFields($form, $catId, $filterCustomFieldNames);

		// Get data from the request
		$outerData = $app->input->getString('filtermagic') ?: [];
		$innerData = is_array($outerData) ? ($outerData['filter'] ?? []) : [];
		$data      = [];
		$prefix    = 'filtermagic.' . $catId . '.';

		// Process each field, adding options to custom field filters and collecting form data
		foreach ($form->getGroup('filter') as $subkey => $field)
		{
			// Get the data from the request, falling back to the user state. Save to user state.
			$datum = $innerData[$field->fieldname] ??
				$app->getUserState($prefix . $subkey, '');
			$app->setUserState($prefix . $subkey, $datum);
			$data[$field->fieldname] = $datum;
		}

		$form->bind(['filter' => $data]);

		return $this->forms[$catId] = $form;
	}

	/**
	 * Replaces the dummies in the filter form with the custom field controls
	 *
	 * @param   Form   $form
	 * @param   int    $catId
	 * @param   array  $fieldNames
	 *
	 *
	 * @throws  \Exception
	 * @since   1.0.0
	 */
	private function transplantCustomFields(Form $form, int $catId, array $fieldNames): void
	{
		$fields = $this->getFlatFieldsList($catId);

		if (empty($fields))
		{
			return;
		}

		$fieldTypes = FieldsHelper::getFieldTypes();

		$xml        = new \DOMDocument('1.0', 'UTF-8');
		$fieldsNode = $xml->appendChild(new DOMElement('form'))->appendChild(new DOMElement('fields'));
		$fieldsNode->setAttribute('name', 'filter');

		// Filter custom fields, keeping only the ones we need
		$fields = array_filter(
			$fields,
			function ($field) use ($fieldTypes, $fieldNames, $form) {
				// Skip over if the field type is not available
				if (!array_key_exists($field->type, $fieldTypes))
				{
					return false;
				}

				// Only use the specific fields we were asked to include
				if (!in_array($field->name, $fieldNames))
				{
					return false;
				}

				// Skip over if the field is not defined in the form
				$formField = $form->getField($field->name, 'filter');

				if (empty($formField))
				{
					return false;
				}

				// Add the lookup path for the field and rule, if available
				if ($path = $fieldTypes[$field->type]['path'])
				{
					FormHelper::addFieldPath($path);
				}

				if ($path = $fieldTypes[$field->type]['rules'])
				{
					FormHelper::addRulePath($path);
				}

				return true;
			}
		);

		if (empty($fields))
		{
			return;
		}

		// Add the fields
		$model = Factory::getApplication()->bootComponent('com_fields')
			->getMVCFactory()
			->createModel('Groups', 'Administrator', ['ignore_request' => true]);
		$model->setState('filter.context', 'com_content.article');

		foreach ($fields as $field)
		{
			try
			{
				Factory::getApplication()->triggerEvent('onCustomFieldsPrepareDom', [$field, $fieldsNode, $form]);
			}
			catch (\Exception $e)
			{
				Factory::getApplication()->enqueueMessage($e->getMessage(), 'error');
			}
		}

		// Post-process the generated XML, injecting attributes and options from the filter form
		/** @var DOMElement $domNode */
		foreach ($fieldsNode->childNodes as $domNode)
		{
			// Get the attributes defined in the form, except 'name' and 'customfield'
			/** @var FormField $formField */
			$formField = $form->getField($domNode->getAttribute('name'), 'filter');
			extract($this->extractOverrides($formField));

			$attributes = array_diff_key(
				$attributes,
				[
					'name'        => null,
					'customfield' => null,
				]
			);

			// Apply the attributes defined in the form
			foreach ($attributes as $k => $v)
			{
				$domNode->setAttribute($k, $v);
			}

			// Apply extra options
			foreach ($options as $optionDefinition)
			{
				[$value, $label] = $optionDefinition;
				$option = $domNode->appendChild(new DOMElement('option'));
				$option->textContent = $label;
				$option->setAttribute('value', $value);
			}
		}

		// Loading the XML fields string into the form
		$form->load($xml->saveXML());
	}

	/**
	 * Extracts the overrides from the original XML form field.
	 *
	 * @param   FormField  $field
	 *
	 * @return  array[]
	 *
	 * @since   1.0.0
	 */
	#[ArrayShape(['attributes' => "array", 'options' => "array"])]
	private function extractOverrides(FormField $field): array
	{
		$refObj  = new \ReflectionObject($field);
		$refProp = $refObj->getProperty('element');
		$refProp->setAccessible(true);
		/** @var \SimpleXMLElement $element */
		$element = $refProp->getValue($field);

		$ret = [
			'attributes' => [],
			'options'    => [],
		];

		foreach ($element->attributes() as $key => $value)
		{
			$ret['attributes'][(string) $key] = (string) $value;
		}

		foreach ($element->xpath('option') as $option)
		{
			$ret['options'][] = [
				(string) $option['value'],
				(string) $option
			];
		}

		return $ret;
	}
}