<?php
namespace Craft;

class SproutCampaignMonitorController extends BaseController
{
	public function actionSaveSettings()
	{
		$campaignmonitor = craft()->request->getPost('campaignmonitor');

		$campaignMonitorPlugin = craft()->plugins->getPlugin('sproutCampaignMonitor');

		$settings = $campaignMonitorPlugin->getSettings();

		if ($settings->validate())
		{
			$settings = craft()->plugins->savePluginSettings( $campaignMonitorPlugin, $campaignmonitor );
		}
		else
		{
			craft()->userSession->setError(Craft::t('Unable to save API settings.'));

			craft()->urlManager->setRouteVariables(array(
				'settings' => $settings
			));
		}
	}

	public function actionEditSettings()
	{
		$settings = sproutCampaignMonitor()->getSettings();

		$this->renderTemplate('sproutcampaignmonitor/settings', array(
			'settings' => $settings
		));
	}
}
