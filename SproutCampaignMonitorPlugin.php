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
		return true;
	}

	protected function defineSettings()
	{
		return array(
			'clientId' => array(AttributeType::String, 'required' => true),
			'apiKey'    => array(AttributeType::String, 'required' => true)
		);
	}

	public function registerCpRoutes()
	{
		return array(
			'sproutcampaignmonitor/settings' => array( 'action' => 'sproutCampaignMonitor/editSettings' )
		);
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