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
	 * @return mixed
	 */
	public function getRecipientLists()
	{
		return sproutCampaignMonitor()->getRecipientLists();
	}

	/**
	 * Renders the recipient list UI for this mailer
	 *
	 * @param SproutEmail_CampaignEmailModel []|null $values
	 *
	 * @return string|\Twig_Markup
	 */
	public function getRecipientListsHtml(array $values = null)
	{
		$lists    = $this->getRecipientLists();
		$options  = array();
		$selected = array();

		if (!count($lists))
		{
			return craft()->templates->render('sproutemail/settings/mailers/campaignmonitor/recipientlists/norecipientlists');
		}

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

		if (is_array($values) && count($values))
		{
			foreach ($values as $value)
			{
				$selected[] = $value->list;
			}
		}

		$html = craft()->templates->renderMacro(
			'_includes/forms', 'checkboxGroup', array(
				array(
					'id'      => 'recipientLists',
					'name'    => 'recipient[recipientLists]',
					'options' => $options,
					'values'  => $selected,
				)
			)
		);

		return TemplateHelper::getRaw($html);
	}

	/**
	 * @param SproutEmail_CampaignEmailModel $campaignEmail
	 *
	 * @return Craft\SproutEmail_MailerService
	 */
	public function prepareRecipientLists(SproutEmail_CampaignEmailModel $campaignEmail)
	{
		$ids   = craft()->request->getPost('recipient.recipientLists');
		$lists = array();

		if ($ids)
		{
			foreach ($ids as $id)
			{
				$model = new SproutEmail_RecipientListRelationsModel();

				$model->setAttribute('emailId', $campaignEmail->id);
				$model->setAttribute('mailer', $this->getId());
				$model->setAttribute('list', $id);

				$lists[] = $model;
			}
		}

		return $lists;
	}

	/**
	 * @param SproutEmail_CampaignEmailModel $campaignEmail
	 * @param SproutEmail_CampaignTypeModel  $campaignType
	 *
	 * @return mixed
	 */
	public function getPrepareModalHtml(SproutEmail_CampaignEmailModel $campaignEmail, SproutEmail_CampaignTypeModel $campaignType)
	{
		// Create an array of all recipient list titles
		$lists          = sproutEmail()->campaignEmails->getRecipientListsByEmailId($campaignEmail->id);
		$recipientLists = array();

		if (is_array($lists) && count($lists))
		{
			foreach ($lists as $list)
			{
				array_push($recipientLists, sproutCampaignMonitor()->getDetails($list->list)->Title);
			}
		}

		return craft()->templates->render('sproutemail/settings/mailers/campaignmonitor/sendEmailPrepare', array(
			'campaignEmail'  => $campaignEmail,
			'campaignType'   => $campaignType,
			'recipientLists' => $recipientLists
		));
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

			$lists          = SproutEmail_RecipientListRelationsRecord::model()->findAllByAttributes(array(
				'emailId' => $campaignEmail->id
			));

			$recipientLists = array();
			$toEmails       = array();

			foreach ($lists as $list)
			{
				array_push($recipientLists, $list->list);
				$toEmails[] = sproutCampaignMonitor()->getListLabel($list->list);
			}

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


			$mailModel = new SproutCampaignMonitor_CampaignModel;
			$mailModel->Subject   = $campaignEmail->subjectLine;
			$mailModel->Name      = $campaignType->name . ': ' . $campaignEmail->subjectLine;
			$mailModel->FromName  = $campaignEmail->fromName;
			$mailModel->FromEmail = $campaignEmail->fromEmail;
			$mailModel->ReplyTo   = $campaignEmail->replyToEmail;
			$mailModel->HtmlUrl   = $campaignEmail->getUrl();
			$mailModel->TextUrl   = $campaignEmail->getUrl() . '?type=text';
			$mailModel->html      = sproutEmail()->renderSiteTemplateIfExists($campaignType->template, $params);
			$mailModel->text      = sproutEmail()->renderSiteTemplateIfExists($campaignType->template . '.txt', $params);
			$mailModel->ListIDs   = $recipientLists;

			$result = $service->sendCampaignEmail($mailModel);
		}
		catch (\Exception $e)
		{
			throw $e;
		}

		$response             = new SproutEmail_ResponseModel();
		$response->emailModel = $result['emailModel'];
		$response->success    = true;
		$response->content    = craft()->templates->render(
			'sproutemail/settings/mailers/campaignmonitor/sendEmailConfirmation',
			array(
				'success'  => true,
				'response' => $response
			)
		);

		return $response;
	}

	public function getCampaignEmailUrls($emailId, $template)
	{
		// @todo: make sure these URLs are getting assigned
		// to a live, outside accessible URL
		// Assign html/text URLs for Campaign Monitor to scrape
		$urls = array(
			'html' => craft()->siteUrl . 'index.php/admin/actions/sproutEmail/campaignEmails/shareCampaignEmail?emailId=' . $emailId . '&template=html'
		);

		// Determine if a text template exists
		$urls['hasText'] = sproutEmail()->doesSiteTemplateExist($template . '.txt');
		if ($urls['hasText'])
		{
			$urls['text'] = craft()->siteUrl . 'index.php/admin/actions/sproutEmail/campaignEmails/shareCampaignEmail?emailId=' . $emailId . '&template=text';
		}

		return $urls;
	}

	/**
	 * @todo - confirm if this is in use
	 *
	 * @param array $extra
	 *
	 * @return array
	 */
	public function getPostParams(array $extra = array())
	{
		$params = array(
			'api_key' => $this->settings['apiKey']
		);

		return array_merge($params, $extra);
	}
}
