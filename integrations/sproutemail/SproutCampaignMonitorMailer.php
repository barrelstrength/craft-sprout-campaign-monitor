<?php

namespace Craft;

class SproutCampaignMonitorMailer extends SproutEmailBaseMailer implements SproutEmailCampaignEmailSenderInterface
{
	public function __construct()
	{
		$this->settings = $this->getSettings();
	}

	/**
	 * @return string
	 */
	public function getName()
	{
		return 'campaignmonitor';
	}

	/**
	 * @return string
	 */
	public function getTitle()
	{
		return 'Campaign Monitor';
	}

	/**
	 * @return null|string
	 */
	public function getDescription()
	{
		return Craft::t('Send your email campaigns via Campaign Monitor.');
	}

	/**
	 * @return string
	 */
	public function getCpSettingsUrl()
	{
		return UrlHelper::getCpUrl('settings/plugins/sproutcampaignmonitor');
	}

	/**
	 * @return array
	 */
	public function defineSettings()
	{
		return array(
			'clientId' => array(AttributeType::String, 'required' => true),
			'apiKey'   => array(AttributeType::String, 'required' => true),
		);
	}

	/**
	 * @return BaseModel
	 */
	public function getSettings()
	{
		$general = craft()->config->get('sproutEmail');

		if ($general != null && isset($general['campaignmonitor']))
		{
			$settings = $general['campaignmonitor'];
		}
		else
		{
			$plugin = craft()->plugins->getPlugin('sproutCampaignMonitor');

			$settings = $plugin->getSettings()->getAttributes();
		}

		return $settings;
	}

	/**
	 * @param array $settings
	 *
	 * @return \Twig_Markup
	 */
	public function getSettingsHtml(array $settings = array())
	{
		$settings = isset($settings['settings']) ? $settings['settings'] : $this->getSettings();

		$html = craft()->templates->render('sproutcampaignmonitor/settings', array(
			'settings' => $settings
		));

		return TemplateHelper::getRaw($html);
	}

	/**
	 * @param SproutEmail_CampaignEmailModel $campaignEmail
	 * @param SproutEmail_CampaignTypeModel  $campaignType
	 *
	 * @return SproutEmail_ResponseModel
	 * @throws \Exception
	 */
	public function sendCampaignEmail(SproutEmail_CampaignEmailModel $campaignEmail, SproutEmail_CampaignTypeModel $campaignType)
	{
		try
		{
			// Load service first to get proper API settings
			$service = sproutCampaignMonitor();

			$mailModel = $this->prepareMailModel($campaignEmail, $campaignType);

			$campaignId = $this->getCampaignId($campaignEmail, $mailModel);

			$sentCampaign = $service->sendCampaignEmail($mailModel, $campaignId);

			if (!empty($sentCampaign['id']))
			{
				sproutEmail()->campaignEmails->saveEmailSettings($campaignEmail, array(
					'campaignId' => $sentCampaign['id']
				));
			}
		}
		catch (\Exception $e)
		{
			throw $e;
		}

		$response             = new SproutEmail_ResponseModel();
		$response->emailModel = $sentCampaign['emailModel'];
		$response->success    = true;
		$response->content    = craft()->templates->render('sproutemail/_modals/response', array(
			'email'   => $campaignEmail,
			'success'  => true,
			'response' => $response
		));

		return $response;
	}

	/**
	 * @param SproutEmail_CampaignEmailModel $campaignEmail
	 * @param SproutEmail_CampaignTypeModel  $campaignType
	 *
	 * @return mixed
	 */
	public function getPrepareModalHtml(SproutEmail_CampaignEmailModel $campaignEmail, SproutEmail_CampaignTypeModel $campaignType)
	{
		$listSettings = $campaignEmail->listSettings;

		$lists = array();

		if (!isset($listSettings['listIds']))
		{
			throw new Exception(Craft::t('No list settings found. <a href="{cpEditUrl}">Add a list</a>', array(
				'cpEditUrl' => $campaignEmail->getCpEditUrl()
			)));
		}

		if (is_array($listSettings['listIds']) && count($listSettings['listIds']))
		{
			foreach ($listSettings['listIds'] as $key => $list)
			{
				$currentList = sproutCampaignMonitor()->getDetails($list);

				$total = sproutCampaignMonitor()->getListStats($currentList->ListID)->TotalActiveSubscribers;

				$lists[$key]['title'] = $currentList->Title;
				$lists[$key]['total'] = $total;
			}
		}

		return craft()->templates->render('sproutcampaignmonitor/_modals/prepareEmailSnapshot', array(
			'mailer'       => $this,
			'email'        => $campaignEmail,
			'campaignType' => $campaignType,
			'lists'        => $lists
		));
	}

	/**
	 * @param SproutEmail_CampaignEmailModel $campaignEmail
	 *
	 * @return array
	 */
	public function prepareLists(SproutEmail_CampaignEmailModel $campaignEmail)
	{
		$lists = array();

		return $lists;
	}

	/**
	 * @return array|mixed
	 */
	public function getLists()
	{
		$lists = array();

		try
		{
			$params = sproutCampaignMonitor()->getPostParams();

			$client = new \CS_REST_Clients($this->settings['clientId'], $params);

			$result = $client->get_lists();

			if ($result->was_successful())
			{
				return $result->response;
			}
			else
			{
				sproutCampaignMonitor()->error($result->response->Message, 'invalid-list');
			}
		}
		catch (\Exception $e)
		{
			sproutCampaignMonitor()->error($e->getMessage());
		}

		return $lists;
	}

	/**
	 * Renders the recipient list UI for this mailer
	 *
	 * @param SproutEmail_CampaignEmailModel []|null $values
	 *
	 * @return string|\Twig_Markup
	 */
	public function getListsHtml($values = null)
	{
		$lists = $this->getLists();

		$errors = sproutCampaignMonitor()->getErrors();

		$options  = array();
		$selected = array();

		if (count($lists))
		{
			foreach ($lists as $list)
			{
				$options[] = array(
					'label' => sprintf('%s (%d)', $list->Name, sproutCampaignMonitor()->getListStats($list->ListID)->TotalActiveSubscribers),
					'value' => $list->ListID
				);
			}
		}
		elseif (empty($errors))
		{
			$errors[] = Craft::t('No lists found. Create your first list in Campaign Monitor.');
		}

		if (!empty($values['listIds']) && is_array($values['listIds']))
		{
			foreach ($values['listIds'] as $value)
			{
				$selected[] = $value;
			}
		}

		return craft()->templates->render('sproutcampaignmonitor/_settings/lists', array(
			'options' => $options,
			'values'  => $selected,
			'errors'  => $errors
		));
	}

	/**
	 * @param $emailId
	 * @param $template
	 *
	 * @return mixed
	 */
	public function getCampaignEmailUrls($emailId, $template)
	{
		// @todo: make sure these URLs are getting assigned
		// to a live, outside accessible URL
		// Assign html/text URLs for Campaign Monitor to scrape
		$urls['html'] = UrlHelper::getActionUrl('sproutEmail/campaignEmails/shareCampaignEmail?emailId=' . $emailId . '&template=html');

		// Determine if a text template exists
		$urls['hasText'] = sproutEmail()->doesSiteTemplateExist($template . '.txt');

		if ($urls['hasText'])
		{
			$urls['text'] = UrlHelper::getActionUrl('sproutEmail/campaignEmails/shareCampaignEmail?emailId=' . $emailId . '&template=text');
		}

		return $urls;
	}

	/**
	 * Auto add url to campaign monitor entry because sendCampaignEmail does not work without url
	 *
	 * @param SproutEmail_CampaignTypeModel $model
	 *
	 * @return SproutEmail_CampaignTypeModel
	 */
	public function prepareSave(SproutEmail_CampaignTypeModel $model)
	{
		$handle = $model->handle;

		$model->hasUrls = 1;

		if (empty($model->urlFormat))
		{
			$model->urlFormat = "$handle/{slug}";
		}

		return $model;
	}

	public function sendTestEmail(SproutEmail_CampaignEmailModel $campaignEmail, SproutEmail_CampaignTypeModel
	$campaignType, $emails = array())
	{
		$response = new SproutEmail_ResponseModel();

		try
		{
			$mailModel = $this->prepareMailModel($campaignEmail, $campaignType);

			$campaignId = $this->getCampaignId($campaignEmail, $mailModel);

			$sentCampaign = sproutCampaignMonitor()->sendTestEmail($mailModel, $emails, $campaignId);

			if (!empty($sentCampaign['id']))
			{
				sproutEmail()->campaignEmails->saveEmailSettings($campaignEmail, array(
					'campaignId' => $sentCampaign['id']
				));
			}

			$response->emailModel = $sentCampaign['emailModel'];

			$response->success = true;
			$response->message = Craft::t('Test Campaign successfully sent to {emails}.', array(
				'emails' => implode(", ", $emails)
			));
		}
		catch (\Exception $e)
		{
			$response->success = false;
			$response->message = $e->getMessage();
			sproutEmail()->error($e->getMessage());
		}

		$response->content = craft()->templates->render('sproutemail/_modals/response', array(
			'email'   => $campaignEmail,
			'success' => $response->success,
			'message' => $response->message
		));

		return $response;
	}

	private function prepareMailModel(SproutEmail_CampaignEmailModel $campaignEmail, SproutEmail_CampaignTypeModel
	$campaignType)
	{
		$listIds = $campaignEmail->listSettings['listIds'];

		$params = array(
			'email'     => $campaignEmail,
			'campaign'  => $campaignType,
			'recipient' => array(
				'firstName' => 'First',
				'lastName'  => 'Last',
				'email'     => 'user@domain.com'
			),

			// @deprecate - in favor of `email` in v3
			'entry'     => $campaignEmail
		);

		$mailModel            = new SproutCampaignMonitor_CampaignModel;
		$mailModel->Subject   = $campaignEmail->subjectLine;
		$mailModel->Name      = $campaignType->name . ': ' . $campaignEmail->subjectLine;
		$mailModel->FromName  = $campaignEmail->fromName;
		$mailModel->FromEmail = $campaignEmail->fromEmail;
		$mailModel->ReplyTo   = $campaignEmail->replyToEmail;
		$mailModel->HtmlUrl   = $campaignEmail->getUrl();
		$mailModel->TextUrl   = $campaignEmail->getUrl() . '?type=text';
		$mailModel->html      = sproutEmail()->renderSiteTemplateIfExists($campaignType->template, $params);
		$mailModel->text      = sproutEmail()->renderSiteTemplateIfExists($campaignType->template . '.txt', $params);
		$mailModel->ListIDs   = $listIds;

		return $mailModel;
	}

	private function getCampaignId($campaignEmail, $mailChimpModel)
	{
		if ($campaignEmail->emailSettings != null AND !empty($campaignEmail->emailSettings['campaignId']))
		{
			$emailSettingsId = $campaignEmail->emailSettings['campaignId'];

			if (!empty($emailSettingsId))
			{
				sproutCampaignMonitor()->deleteCampaignIdIfExists($emailSettingsId);
			}
		}

		$campaignId = sproutCampaignMonitor()->createCampaign($mailChimpModel);

		return $campaignId;
	}
}
