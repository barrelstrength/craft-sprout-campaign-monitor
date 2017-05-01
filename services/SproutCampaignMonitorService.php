<?php

namespace Craft;

use CS_REST_Campaigns;
use CS_REST_Clients;
use CS_REST_Lists;
use CS_REST_Subscribers;

/**
 * Class SproutMailchimpService
 *
 * @package Craft
 */
class SproutCampaignMonitorService extends BaseApplicationComponent
{
	/**
	 * @var
	 */
	public $error;

	/**
	 * @var Model|null
	 */
	protected $settings;

	public function init()
	{
		parent::init();

		$this->settings = craft()->plugins->getPlugin('sproutCampaignMonitor')->getSettings();
	}

	/**
	 * @param SproutEmail_CampaignEmailModel $campaignEmail
	 * @param SproutEmail_CampaignTypeModel  $campaignType
	 *
	 * @return array
	 * @throws \Exception
	 */
	public function sendCampaignEmail(SproutCampaignMonitor_CampaignModel $campaignModel)
	{
		try
		{
			$auth = $this->getPostParams();

			$params = array(
				'Subject'   => $campaignModel->Subject,
				'Name'      => $campaignModel->Name . ': ' . $campaignModel->Subject,
				'FromName'  => $campaignModel->FromName,
				'FromEmail' => $campaignModel->FromEmail,
				'ReplyTo'   => $campaignModel->ReplyTo,
				'HtmlUrl'   => $campaignModel->HtmlUrl,
				'TextUrl'   => $campaignModel->TextUrl,
				'ListIDs'   => $campaignModel->ListIDs
			);

			// Set up API call to create a draft campaign and assign the response to $response
			$draftCampaign = new CS_REST_Campaigns(null, $auth);
			$response      = $draftCampaign->create($this->settings['clientId'], $params);

			$email = new EmailModel();

			$email->subject   = $campaignModel->Subject;
			$email->fromName  = $campaignModel->FromName;
			$email->fromEmail = $campaignModel->FromEmail;
			$email->body      = $campaignModel->text;
			$email->htmlBody  = $campaignModel->html;

			// Conditional return for success/fail response from Campaign Monitor
			if (!$response->was_successful())
			{
				sproutCampaignMonitor()->error('Error creating campaign in Campaign Monitor: ' . $response->http_status_code . ' - ' . $response->response->Message);

				throw new Exception(Craft::t('{code}: {msg}', array('code' => $response->http_status_code, 'msg' => $response->response->Message)));
			}
			else
			{
				SproutEmailPlugin::log(Craft::t('Successfully created campaign in Campaign Monitor with ID: ' . $response->response), LogLevel::Info);

				$this->sendEmailViaService($campaignModel->ReplyTo, $response->response, $auth);

				return array('id' => $response->response, 'emailModel' => $email);
			}
		}
		catch (\Exception $e)
		{
			sproutCampaignMonitor()->error('Error creating campaign in Campaign Monitor: ' . $e->getMessage());

			throw $e;
		}
	}

	/**
	 * @param $confirmationEmail
	 * @param $campaignTypeId
	 * @param $auth
	 *
	 * @return bool
	 * @throws \Exception
	 */
	public function sendEmailViaService($confirmationEmail, $campaignTypeId, $auth)
	{
		// Access the newly created draft campaign
		$campaignToSend = new CS_REST_Campaigns($campaignTypeId, $auth);

		// Try to send the campaign
		try
		{
			$response = $campaignToSend->send(
				array(
					'ConfirmationEmail' => $confirmationEmail,
					'SendDate'          => 'immediately'
				)
			);

			if (!$response->was_successful())
			{
				sproutCampaignMonitor()->error('Error sending campaign through Campaign Monitor: ' . $response->http_status_code . ' - ' . $response->response->Message);

				return false;
			}
			else
			{
				SproutEmailPlugin::log(Craft::t('Successfully sent campaign through Campaign Monitor with ID: ' . $campaignTypeId), LogLevel::Info);

				return true;
			}
		}
		catch (\Exception $e)
		{
			sproutCampaignMonitor()->error('Error sending campaign through Campaign Monitor: ' . $e->getMessage());

			throw $e;
		}
	}

	/**
	 * Adds or updates the user to a subscriber list
	 *
	 * @param null      $listId
	 * @param           $apiKey
	 * @param BaseModel $subscriber
	 * @param bool      $update
	 *
	 * @return array
	 * @throws Exception
	 */
	public function addOrUpdateSubscriber($listId = null, $apiKey, BaseModel $subscriber, $update = false)
	{
		/*
		 * @todo make sure $apiKey is coming from settings, not passed in as an argument
		 */
		if (is_null($listId))
		{
			throw new Exception(Craft::t('List ID cannot be null.'));
		}
		if (is_null($apiKey))
		{
			throw new Exception(Craft::t('API Key cannot be null.'));
		}

		$csRestSubscribers      = new CS_REST_Subscribers($listId, $apiKey);
		$subscriberCustomFields = array();

		/**
		 * Properties of the model passed in to this function will be
		 * parsed from "camelCase" to "Normal Case" for the field's
		 * Campaign Monitor key
		 *
		 * i.e. (BaseModel)$subscriber->postalCode should have a matching
		 * custom field in Campaign Monitor of "Postal Code"
		 */
		$subscriber->prefix = $subscriber->prefix->value;
		foreach ($subscriber->getAttributes() as $key => $value)
		{
			array_push($subscriberCustomFields, array(
				'Key'   => ucfirst($this->parseCamelCase($key)),
				'Value' => $value
			));
		}

		$subscriber = array(
			'EmailAddress'                           => $subscriber->email,
			'Name'                                   => $subscriber->firstName . ' ' . $subscriber->lastName,
			'CustomFields'                           => $subscriberCustomFields,
			'Resubscribe'                            => true,
			'RestartSubscriptionBasedAutoresponders' => true,
		);

		if ($update)
		{
			$csRestSubscribers->update($subscriber['EmailAddress'], $subscriber);
		}
		else
		{
			$csRestSubscribers->add($subscriber);
		}

		return $this->tryJsonResponse($csRestSubscribers);
	}

	/**
	 * Converts "camelCase" to Normal Case
	 *
	 * @param $string
	 *
	 * @return string
	 */
	function parseCamelCase($string)
	{
		return preg_replace('/(?!^)[A-Z]{2,}(?=[A-Z][a-z])|[A-Z][a-z]|[0-9]{1,}/', ' $0', $string);
	}

	/**
	 * @return JSON containing recipient list stats
	 */
	public function getListStats($listId)
	{
		// Get stats belonging to a list with the given ID
		$list     = new CS_REST_Lists($listId, $this->getPostParams());
		$response = $list->get_stats()->response;

		return $this->tryJsonResponse($response);
	}

	/**
	 * @return JSON containing recipient list details
	 */
	public function getDetails($listId)
	{
		// Get stats belonging to a list with the given ID
		$list = new CS_REST_Lists($listId, $this->getPostParams());

		$response = $list->get()->response;

		return $this->tryJsonResponse($response);
	}

	/**
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

	/**
	 * @param $response
	 *
	 * @return array
	 */
	private function tryJsonResponse($response)
	{
		try
		{
			return $response;
		}
		catch (\Exception $e)
		{
			return array();
		}
	}

	/**
	 * Logs an error in cases where it makes more sense than to throw an exception
	 *
	 * @param mixed $message
	 * @param array $variables
	 */
	public function error($message, $key = '', array $variables = array())
	{
		if (is_string($message))
		{
			$message = Craft::t($message, $variables);
		}
		else
		{
			$message = print_r($message, true);
		}

		if (!empty($key))
		{
			$this->error[$key] = $message;
		}
		else
		{
			$this->error = $message;
		}

		SproutEmailPlugin::log($message, LogLevel::Error);
	}

	/**
	 * @return mixed
	 */
	public function getErrors()
	{
		return $this->error;
	}
}
