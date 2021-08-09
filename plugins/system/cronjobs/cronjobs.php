<?php
/**
 * @package         Joomla.Plugin
 * @subpackage      System.Cronjobs
 *
 * @copyright   (C) 2021 Open Source Matters, Inc. <https://www.joomla.org>
 * @license         GPL v3
 */

// Restrict direct access
defined('_JEXEC') or die;

use Joomla\CMS\Application\CMSApplication;
use Joomla\CMS\Event\AbstractEvent;
use Joomla\CMS\Extension\MVCComponent;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Component\Cronjobs\Administrator\Helper\ExecRuleHelper;
use Joomla\Component\Cronjobs\Administrator\Model\CronjobsModel;
use Joomla\Database\DatabaseInterface;
use Joomla\Event\Event;
use Joomla\Event\SubscriberInterface;

/**
 * The plugin class for Plg_System_Cronjobs.
 *
 * @since __DEPLOY_VERSION__
 */
class PlgSystemCronjobs extends CMSPlugin implements SubscriberInterface
{

	/**
	 * Exit Code For no time to run
	 *
	 * @since  __DEPLOY_VERSION__
	 */
	public const JOB_NO_TIME = 1;

	/**
	 * Exit Code For lock failure
	 *
	 * @since  __DEPLOY_VERSION__
	 */
	public const JOB_NO_LOCK = 2;

	/**
	 * Exit Code For execution failure
	 *
	 * @since  __DEPLOY_VERSION__
	 */
	public const JOB_NO_RUN = 3;

	/**
	 * Exit Code For execution success
	 *
	 * @since  __DEPLOY_VERSION__
	 */
	public const JOB_OK_RUN = 0;

	/**
	 * Replacement exit code for job with no exit code
	 * ! Removal due
	 *
	 * @since __DEPLOY_VERSION__
	 */
	public const JOB_NO_EXIT = -1;

	/**
	 * @var CMSApplication
	 * @since __DEPLOY_VERSION__
	 */
	protected $app;

	/**
	 * @var DatabaseInterface
	 * @since __DEPLOY_VERSION__
	 */
	protected $db;

	/**
	 * @var boolean
	 * @since __DEPLOY_VERSION__
	 */
	protected $autoloadLanguage = true;

	/**
	 * Stores the pseudo-cron status
	 *
	 * @var string[]
	 * @since __DEPLOY_VERSION__
	 */
	protected $snapshot = [];

	/**
	 * Returns event subscriptions
	 *
	 * @return string[]
	 *
	 * @since __DEPLOY_VERSION__
	 */
	public static function getSubscribedEvents(): array
	{
		return [
			'onAfterRespond' => 'executeDueJob'
		];
	}

	/**
	 * @param   Event  $event  The onAfterRespond event
	 *
	 * @return void
	 * @throws Exception
	 * @since __DEPLOY_VERSION__
	 */
	public function executeDueJob(Event $event): void
	{
		/** @var MVCComponent $component */
		$component = $this->app->bootComponent('com_cronjobs');

		/** @var CronjobsModel $model */
		$model = $component->getMVCFactory()->createModel('Cronjobs', 'Administrator');

		$dueJob = $this->getDueJobs($model);

		if ($dueJob)
		{
			$this->runJob($dueJob[0]);
		}

		return;
	}

	/**
	 * Fetches due jobs from CronjobsModel
	 * ! Orphan filtering + pagination issues in the Model will break this if orphaned jobs exist [TODO]
	 *
	 * @param   CronjobsModel  $model   The CronjobsModel
	 * @param   boolean        $single  If true, only a single job is returned
	 *
	 * @return object[]
	 * @throws Exception
	 * @since __DEPLOY_VERSION__
	 */
	private function getDueJobs(CronjobsModel $model, bool $single = true): array
	{
		$model->set('__state_set', true);

		$model->setState('list.select',
			'a.id, a.title, a.type, a.next_execution, a.times_executed, a.times_failed, a.params, a.cron_rules'
		);

		$model->setState('list.start', 0);

		if ($single)
		{
			$model->setState('list.limit', 1);
		}

		$model->setState('filter.state', '1');

		$model->setState('filter.due', 1);

		$model->setState('filter.show_orphaned', 0);

		$model->setState('list.ordering', 'a.next_execution');
		$model->setState('list.direction', 'ASC');

		// Get a class with a smarter class
		$model->setState('list.customClass', true);

		return $model->getItems();
	}

	/**
	 * @param   object   $cronjob     The cronjob entry
	 * @param   boolean  $scheduling  Respect scheduling settings and state
	 *                                ! Does nothing
	 *
	 * @return boolean True on success
	 *
	 * @since __DEPLOY_VERSION__
	 */
	private function runJob(object $cronjob, bool $scheduling = true): bool
	{
		$this->snapshot['jobId'] = $cronjob->id;
		$this->snapshot['jobTitle'] = $cronjob->title;
		$this->snapshot['status'] = self::JOB_NO_TIME;
		$this->snapshot['duration'] = 0;

		if (!$setlock = $this->setLock($cronjob))
		{
			$this->snapshot['status'] = self::JOB_NO_LOCK;

			return false;
		}

		$app = $this->app;
		$results = [];
		$event = AbstractEvent::create(
			'onCronRun',
			[
				'subject' => $this,
				'jobId' => $cronjob->type,
				'params' => $cronjob->params,
				'results' => &$results
			]
		);

		// TODO: test
		PluginHelper::importPlugin('job');
		$app->getDispatcher()->dispatch('onCronRun', $event);

		if (!$this->releaseLock($cronjob))
		{
			$this->snapshot['status'] = self::JOB_NO_RUN;
			$this->snapshot['duration'] = 0;

			return false;
		}

		return true;
	}

	/**
	 * @param   object  $cronjob  The cronjob entry
	 *
	 * @return boolean  True on success
	 *
	 * @since __DEPLOY_VERSION__
	 */
	private function setLock(object $cronjob): bool
	{
		$db = $this->db;
		$query = $db->getQuery(true);

		$query->update($db->qn('#__cronjobs', 'j'))
			->set('j.locked = 1')
			->where($db->qn('j.id') . ' = :jobId')
			->where($db->qn('j.locked') . ' = 0')
			->bind(':jobId', $cronjob->id);
		$db->setQuery($query)->execute();

		if (!$affRow = $db->getAffectedRows())
		{
			return false;
		}

		return true;
	}

	/**
	 * @param   object   $cronjob     The cronjob entry
	 * @param   boolean  $scheduling  Respect scheduling settings and state
	 *                                ! Does nothing
	 *
	 * @return boolean  True if success, else failure
	 *
	 * @throws Exception
	 * @since __DEPLOY_VERSION__
	 */
	private function releaseLock(object $cronjob, bool $scheduling = true): bool
	{
		$db = $this->db;

		$releaseQuery = $db->getQuery(true);
		$releaseQuery->update($db->qn('#__cronjobs', 'j'))
			->set('locked = 0')
			->where($db->qn('id') . ' = :jobId')
			->where($db->qn('locked') . ' = 1')
			->bind(':jobId', $cronjob->id);
		$db->setQuery($releaseQuery)->execute();

		if (!$affRow = $db->getAffectedRows())
		{
			// Log?
			return false;
		}

		$updateQuery = $db->getQuery(true);

		$jobId = $cronjob->get('id');
		$ruleType = $cronjob->get('cron_rules');
		$nextExec = (new ExecRuleHelper($cronjob))->nextExec();
		$exitCode = null ?? self::JOB_NO_EXIT;
		$now = Factory::getDate('now', 'GMT')->toSql();

		/*
		 * [TODO] We are not aware of job exit code at the moment. That's due in the API.
		 * [TODO] Failed status
		 */
		$updateQuery->update($db->qn('#__cronjobs', 'j'))
			->set(
				[
					'j.last_execution = :now',
					'j.next_execution = :nextExec',
					'j.last_exit_code = :exitCode',
					'j.times_executed = j.times_executed + 1'
				]
			)
			->where('j.id = :jobId')
			->bind(':nextExec', $nextExec)
			->bind(':exitCode', $exitCode)
			->bind(':now', $now)
			->bind(':jobId', $jobId);
		$db->setQuery($updateQuery)->execute();

		if (!$affRow = $db->getAffectedRows())
		{
			// Log ?
			return false;
		}

		return true;
	}
}