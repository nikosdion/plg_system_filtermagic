<?php
/**
 * @package   FilterMagic
 * @copyright Copyright (c)2022-2023 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

defined('_JEXEC') || die;

use Joomla\CMS\Form\Form;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\FileLayout;

/**
 * @var array           $displayData
 * @var FileLayout      $this
 * @var Form            $form
 */

$form    = $displayData['form'];
$filters = $form->getGroup('filter');

if (empty($filters))
{
	return;
}

$formName = 'plgSystemFilterMagicClear_' . md5(random_bytes(30));

?>
<form name="filtermagic"
	  action="<?= \Joomla\CMS\Uri\Uri::current() ?>"
	  method="post"
	  class="my-2 d-flex flex-row gap-2 align-items-center bg-light border rounded border-1 p-2"
	  id="<?= $formName ?>"
>
	<label class="visually-hidden">
		<?= Text::_('JSEARCH_FILTER_LABEL') ?>
	</label>

	<?= $this->sublayout('fields', $displayData) ?>

	<input type="hidden" name="filtermagic[reset]" id="<?= $formName ?>_reset" value="0" />

	<button type="submit" class="btn btn-primary">
		<span class="fa fa-search" aria-hidden="true"></span>
		<?= Text::_('JSEARCH_FILTER') ?>
	</button>

	<button type="reset" class="btn btn-secondary plgSystemFilterMagicClear" data-form="<?= $formName ?>">
		<?= Text::_('JSEARCH_FILTER_CLEAR') ?>
	</button>
</form>