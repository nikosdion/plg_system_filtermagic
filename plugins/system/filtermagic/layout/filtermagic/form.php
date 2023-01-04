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

?>
<form name="filtermagic"
	  action="<?= \Joomla\CMS\Uri\Uri::current() ?>"
	  method="post"
	  class="my-2 d-flex flex-row gap-2 align-items-center bg-light border rounded border-1 p-2"
>
	<label class="visually-hidden">
		<?= Text::_('JSEARCH_FILTER_LABEL') ?>
	</label>

	<?= $this->sublayout('fields', $displayData) ?>

	<button type="submit" class="btn btn-primary">
		<span class="fa fa-search" aria-hidden="true"></span>
		<?= Text::_('JSEARCH_FILTER') ?>
	</button>
</form>