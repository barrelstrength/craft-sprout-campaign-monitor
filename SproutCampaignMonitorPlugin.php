<?php

namespace Craft;

class SproutCampaignMonitorPlugin extends BasePlugin
{
	public function getName()
	{
		return 'Sprout Campaign Monitor';
	}

	public function getVersion()
	{
		return '0.5.0';
	}

	public function getDeveloper()
	{
		return 'Barrel Strength Design';
	}

	public function getDeveloperUrl()
	{
		return 'http://barrelstrengthdesign.com';
	}

	public function hasCpSection()
	{
		return false;
	}

	public function init()
	{
		// Load Campaign Monitor API library
		require_once dirname(__FILE__) . '/vendor/autoload.php';
	}

	protected function defineSettings()
	{
		return array(
			'clientId' => array(AttributeType::String, 'required' => true),
			'apiKey'    => array(AttributeType::String, 'required' => true)
		);
	}

	public function getSettingsHtml()
	{
		return craft()->templates->render('sproutcampaignmonitor/_settings/plugin', array(
			'settings' => $this->getSettings()
		));
	}

	public function defineSproutEmailMailers()
	{
		Craft::import("plugins.sproutcampaignmonitor.integrations.sproutemail.SproutCampaignMonitorMailer");

		return array(
			'campaignmonitor' => new SproutCampaignMonitorMailer()
		);
	}
}

function sproutCampaignMonitor()
{
	return Craft::app()->getComponent('sproutCampaignMonitor');
}