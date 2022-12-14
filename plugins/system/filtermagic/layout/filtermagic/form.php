<?php
/**
 * @package   FilterMagic
 * @copyright Copyright (c)2022 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

defined('_JEXEC') || die;

use Joomla\CMS\Form\Form;
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
	  class="my-2 d-flex flex-row gap-2 align-items-center"
>
	<?= $this->sublayout('fields', $displayData) ?>
</form>