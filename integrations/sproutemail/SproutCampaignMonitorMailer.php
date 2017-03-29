<?php
namespace Craft;

class SproutCampaignMonitorMailer extends SproutEmailBaseMailer implements SproutEmailCampaignEmailSenderInterface
{
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
		$plugin = craft()->plugins->getPlugin('sproutCampaignMonitor');

		return $plugin->getSettings();
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

			// @todo - update to use new listSettings
			$lists   = array();
			$listIds = array();

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

			$result = $service->sendCampaignEmail($mailModel);
		}
		catch (\Exception $e)
		{
			throw $e;
		}

		$response             = new SproutEmail_ResponseModel();
		$response->emailModel = $result['emailModel'];
		$response->success    = true;
		$response->content    = craft()->templates->render('sproutcampaignmonitor/_modals/sendEmailConfirmation', array(
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
			foreach ($listSettings['listIds'] as $list)
			{
				$currentList = $this->getListById($list);

				array_push($lists, $currentList);
			}
		}

		return craft()->templates->render('sproutcampaignmonitor/_modals/sendEmailPrepare', array(
			'campaignEmail' => $campaignEmail,
			'campaignType'  => $campaignType,
			'lists'         => $lists
		));
	}

	/**
	 * @param SproutEmail_CampaignEmailModel $campaignEmail
	 *
	 * @return Craft\SproutEmail_MailerService
	 */
	public function prepareLists(SproutEmail_CampaignEmailModel $campaignEmail)
	{
		// @todo - update to use new $listSetttings
		$lists = array();

		return $lists;
	}

	/**
	 * @return array
	 */
	public function getLists()
	{
		$lists = array();

		try
		{
			$client = new \CS_REST_Clients($this->settings['clientId'], $this->getPostParams());
			$result = $client->get_lists();

			if ($result->was_successful())
			{
				return $result->response;
			}
			else
			{
				sproutCampaignMonitor()->error('Unable to get lists', array(
					'result' => $result
				));
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
	public function getListsHtml(array $values = null)
	{
		$lists    = $this->getLists();
		$options  = array();
		$selected = array();
		$errors   = array();

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
		else
		{
			$errors[] = Craft::t('No lists found. Create your first list in Campaign Monitor.');
		}

		if (is_array($values) && count($values))
		{
			foreach ($values as $value)
			{
				$selected[] = $value->list;
			}
		}

		return craft()->templates->render('sproutcampaignmonitor/_settings/lists', array(
			'options' => $options,
			'values'  => $selected,
			'errors'  => $errors
		));
	}

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
}
