<?php

namespace Craft;

class SproutCampaignMonitor_CampaignModel extends BaseModel
{
	public function defineAttributes()
	{
		return array(
			'Subject'   => array(AttributeType::String),
			'Name'      => array(AttributeType::String),
			'FromName'  => array(AttributeType::String),
			'FromEmail' => array(AttributeType::Email),
			'ReplyTo'   => array(AttributeType::String),
			'HtmlUrl'   => array(AttributeType::String),
			'TextUrl'   => array(AttributeType::String),
			'html'      => array(AttributeType::String),
			'text'      => array(AttributeType::String),
			'ListIDs'   => array(AttributeType::Mixed)
		);
	}
}