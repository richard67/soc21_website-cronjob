<?php
/**
 * @package         Joomla.Administrator
 * @subpackage      com_scheduler
 *
 * @copyright   (C) 2021 Open Source Matters, Inc. <https://www.joomla.org>
 * @license         GNU General Public License version 2 or later; see LICENSE.txt
 *
 * phpcs:ignoreFile
 */

// Restrict direct access
defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;
use Joomla\Component\Scheduler\Administrator\View\Tasks\HtmlView;

/** @var  HtmlView  $this*/

HTMLHelper::_('behavior.multiselect');

Text::script('COM_SCHEDULER_TEST_RUN_TITLE');
Text::script('COM_SCHEDULER_TEST_RUN_TASK');

try
{
	$app = Factory::getApplication();
} catch (Exception $e)
{
	die('Failed to get app');
}

$user = $app->getIdentity();
$userId = $user->get('id');
$listOrder = $this->escape($this->state->get('list.ordering'));
$listDirn = $this->escape($this->state->get('list.direction'));
$saveOrder = $listOrder == 'a.ordering';
$section = null;
$mode = false;


if ($saveOrder && !empty($this->items))
{
	$saveOrderingUrl = 'index.php?option=com_scheduler&task=tasks.saveOrderAjax&tmpl=component&' . Session::getFormToken() . '=1';
	HTMLHelper::_('draggablelist.draggable');
}

$app->getDocument()->getWebAssetManager()->usePreset('com_scheduler.test-task');
?>

<form action="<?php echo Route::_('index.php?option=com_scheduler&view=tasks'); ?>" method="post" name="adminForm"
	  id="adminForm">
	<div id="j-main-container" class="j-main-container">
		<?php
		// Search tools bar
		echo LayoutHelper::render('joomla.searchtools.default', array('view' => $this));
		?>

		<!-- If no tasks -->
		<?php if (empty($this->items)): ?>
			<!-- No tasks -->
			<div class="alert alert-info">
				<span class="icon-info-circle" aria-hidden="true"></span><span
						class="visually-hidden"><?php echo Text::_('INFO'); ?></span>
				<?php echo Text::_('JGLOBAL_NO_MATCHING_RESULTS'); ?>
			</div>
		<?php endif; ?>

		<!-- If there are tasks, we start with the table -->
		<?php if (!empty($this->items)): ?>
			<!-- Tasks table starts here -->
			<table class="table" id="categoryList">

				<caption class="visually-hidden">
					<?php echo Text::_('COM_SCHEDULER_TABLE_CAPTION'); ?>,
					<span id="orderedBy"><?php echo Text::_('JGLOBAL_SORTED_BY'); ?> </span>,
					<span id="filteredBy"><?php echo Text::_('JGLOBAL_FILTERED_BY'); ?></span>
				</caption>

				<!-- Tasks table header -->
				<thead>
				<tr>

					<!-- Select all -->
					<td class="w-1 text-center">
						<?php echo HTMLHelper::_('grid.checkall'); // "Select all" checkbox
						?>
					</td>

					<!-- Ordering?-->
					<th scope="col" class="w-1 d-none d-md-table-cell text-center">
						<!-- Might need to adjust method args here -->
						<?php echo HTMLHelper::_('searchtools.sort', '', 'a.ordering', $listDirn, $listOrder, null, 'asc', 'JGRID_HEADING_ORDERING', 'icon-sort'); ?>
					</th>
					<!-- Task State -->
					<th scope="col" class="w-1 text-center">
						<?php echo HTMLHelper::_('searchtools.sort', 'JSTATUS', 'a.state', $listDirn, $listOrder); ?>
					</th>

					<!-- Task title header -->
					<th scope="col">
						<?php echo HTMLHelper::_('searchtools.sort', 'JGLOBAL_TITLE', 'a.title', $listDirn, $listOrder); ?>
					</th>

					<!-- Task type header -->
					<th scope="col" class="d-none d-md-table-cell">
						<?php echo HTMLHelper::_('searchtools.sort', 'COM_SCHEDULER_TASK_TYPE', 'j.type_title', $listDirn, $listOrder) ?>
					</th>

					<!-- Test task -->
					<th scope="col">
						<?php echo Text::_('COM_SCHEDULER_TEST_TASK'); ?>
					</th>

					<!-- Task ID -->
					<th scope="col" class="w-5 d-none d-md-table-cell">
						<?php echo HTMLHelper::_('searchtools.sort', 'JGRID_HEADING_ID', 'a.id', $listDirn, $listOrder); ?>
					</th>
				</tr>
				</thead>

				<!-- Table body begins -->
				<tbody <?php if ($saveOrder): ?>
					class="js-draggable" data-url="<?php echo $saveOrderingUrl; ?>" data-direction="<?php echo strtolower($listDirn); ?>" data-nested="true" <?php endif; ?>>
				<?php foreach ($this->items as $i => $item):
					$canCreate = $user->authorise('core.create', 'com_scheduler');
					$canEdit = $user->authorise('core.edit', 'com_scheduler');
					$canChange = $user->authorise('core.edit.state', 'com_scheduler');
					?>

					<!-- Row begins -->
					<tr class="row<?php echo $i % 2; ?>"
						data-draggable-group="none"
					>
						<!-- Item Checkbox -->
						<td class="text-center">
							<?php echo HTMLHelper::_('grid.id', $i, $item->id, false, 'cid', 'cb', $item->title); ?>
						</td>

						<!-- Draggable handle -->
						<td class="text-center d-none d-md-table-cell">
							<?php
							$iconClass = '';
							if (!$canChange)
							{
								$iconClass = ' inactive';
							} elseif (!$saveOrder)
							{
								$iconClass = ' inactive" title="' . Text::_('JORDERINGDISABLED');
							}
							?>

							<span class="sortable-handler <?php echo $iconClass ?>">
									<span class="icon-ellipsis-v"></span>
							</span>

							<?php if ($canChange && $saveOrder): ?>
								<input type="text" class="hidden text-area-order" name="order[]" size="5"
									   value="<?php echo $item->ordering; ?>"
								>
							<?php endif; ?>
						</td>

						<!-- Item State -->
						<td class="text-center">
							<?php echo HTMLHelper::_('jgrid.published', $item->state, $i, 'tasks.', $canChange); ?>
						</td>

						<!-- Item name, edit link, and note (@todo: should it be moved?) -->
						<th scope="row">
							<?php if ($canEdit): ?>
								<a href="<?php echo Route::_('index.php?option=com_scheduler&task=task.edit&id=' . $item->id); ?>"
								   title="<?php echo Text::_('JACTION_EDIT'); ?> <?php echo $this->escape($item->title); ?>"> <?php echo $this->escape($item->title); ?></a>
							<?php else: ?>
								<?php echo $this->escape($item->title); ?>
							<?php endif; ?>

							<span class="small">
								<?php if (empty($item->note)): ?>
									<?php echo Text::_('COM_SCHEDULER_NO_NOTE'); ?>
								<?php else: ?>
									<?php echo Text::sprintf('JGLOBAL_LIST_NOTE', $this->escape($item->note)); ?>
								<?php endif; ?>
							</span>
						</th>

						<!-- Item type -->
						<td class="small d-none d-md-table-cell">
							<?php echo $this->escape($item->safeTypeTitle); ?>
						</td>

						<!-- Test task -->
						<td class="small d-none d-md-table-cell">
							<button type="button" class="btn btn-sm btn-warning" data-id="<?php echo (int) $item->id; ?>" data-bs-toggle="modal" data-bs-target="#scheduler-test-modal">
								<span class="fa fa-play fa-sm mr-2"></span>
								<?php echo Text::_('COM_SCHEDULER_TEST_RUN'); ?>
							</button>
						</td>

						<!-- Item ID -->
						<td class="d-none d-md-table-cell">
							<?php echo (int) $item->id; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<?php
				// Load the pagination. (@todo: testing)
				echo $this->pagination->getListFooter();

				// Modal for test runs
				$modalparams = [
					'title' => Text::_('COM_SCHEDULER_TEST_RUN_TITLE')
				];

				$modalbody = '<div class="p-3">';
					$modalbody .= '<h4>' . Text::_('COM_SCHEDULER_TEST_RUN_TASK') . '</h4>';
					$modalbody .= '<ul class="list-unstyled">';
						$modalbody .= '<li>' . Text::_('COM_SCHEDULER_TEST_RUN_STATUS_STARTED') . '</li>';
						$modalbody .= '<li class="mt-3 text-center"><span class="fa fa-spinner fa-spin fa-lg"></span></li>';
					$modalbody .= '</ul>';
				$modalbody .= '</div>';

				echo HTMLHelper::_('bootstrap.renderModal', 'scheduler-test-modal', $modalparams, $modalbody);

			?>

		<?php endif; ?>

		<input type="hidden" name="task" value="">
		<input type="hidden" name="boxchecked" value="0">
		<?php echo HTMLHelper::_('form.token'); ?>
	</div>
</form>
