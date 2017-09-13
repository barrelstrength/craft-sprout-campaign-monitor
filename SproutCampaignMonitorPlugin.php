<?php

namespace Craft;

class SproutCampaignMonitorPlugin extends BasePlugin
{
	/**
	 * @return string
	 */
	public function getName()
	{
		return 'Sprout Campaign Monitor';
	}

	/**
	 * @return string
	 */
	public function getDescription()
	{
		return 'Integrate Campaign Monitor into your Craft CMS workflow with Sprout Email.';
	}

	/**
	 * @return string
	 */
	public function getVersion()
	{
		return '0.6.1';
	}

	/**
	 * @return string
	 */
	public function getSchemaVersion()
	{
		return '0.6.0';
	}

	/**
	 * @return string
	 */
	public function getDeveloper()
	{
		return 'Barrel Strength Design';
	}

	/**
	 * @return string
	 */
	public function getDeveloperUrl()
	{
		return 'http://barrelstrengthdesign.com';
	}

	/**
	 * @return bool
	 */
	public function hasCpSection()
	{
		return false;
	}

	/**
	 *
	 */
	public function init()
	{
		// Load Campaign Monitor API library
		require_once dirname(__FILE__) . '/vendor/autoload.php';
	}

	/**
	 * @return array
	 */
	protected function defineSettings()
	{
		return array(
			'clientId' => array(AttributeType::String, 'required' => true),
			'apiKey'   => array(AttributeType::String, 'required' => true)
		);
	}

	/**
	 * @return string
	 */
	public function getSettingsHtml()
	{
		return craft()->templates->render('sproutcampaignmonitor/_settings/plugin', array(
			'settings' => $this->getSettings()
		));
	}

	/**
	 * @return array
	 */
	public function defineSproutEmailMailers()
	{
		Craft::import("plugins.sproutcampaignmonitor.integrations.sproutemail.SproutCampaignMonitorMailer");

		return array(
			'campaignmonitor' => new SproutCampaignMonitorMailer()
		);
	}
}

/**
 * @return sproutCampaignMonitorService
 */
function sproutCampaignMonitor()
{
	return Craft::app()->getComponent('sproutCampaignMonitor');
}