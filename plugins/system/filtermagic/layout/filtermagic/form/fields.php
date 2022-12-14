<?php
/**
 *  @package   FilterMagic
 *  @copyright Copyright (c)2022 Nicholas K. Dionysopoulos
 *  @license   GNU General Public License version 3, or later
 */

defined('_JEXEC') || die;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Form\FormHelper;
use Joomla\CMS\Layout\FileLayout;
use Joomla\CMS\WebAsset\WebAssetManager;

/**
 * @var array           $displayData
 * @var FileLayout      $this
 * @var Form            $form
 * @var WebAssetManager $wa
 */

$form    = $displayData['form'];
$filters = $form->getGroup('filter');
$wa      = Factory::getApplication()->getDocument()->getWebAssetManager();

?>
<?php foreach ($filters as $field): ?>
	<?php
	$dataShowOn = $field->showon
		? sprintf(
			" data-showon='%s'",
			json_encode(FormHelper::parseShowOnConditions($field->showon, $field->formControl, $field->group))
		) : '';
	?>
	<div class="filtermagic-field-filter"<?= $dataShowOn ?>>
		<span class="visually-hidden"><?= $field->label ?></span>
		<?= $field->input ?>
	</div>
<?php endforeach; ?>

