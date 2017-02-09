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
	 * @var Model|null
	 */
	protected $settings;
	
	/**
	 * @var
	 */
	protected $error;

	public function init()
	{
		parent::init();

		$this->settings = $this->getSettings();
	}

	public function getSettings()
	{
		$plugin = craft()->plugins->getPlugin( 'sproutCampaignMonitor' );

		return $plugin->getSettings();
	}

	public function getSettingsUrl()
	{
		return UrlHelper::getCpUrl(sprintf('settings/plugins/%s', 'sproutcampaignmonitor'));
	}

	/**
	 * @throws \Exception
	 *
	 * @return array
	 */
	public function getRecipientLists()
	{
		$lists = array();

		try
		{
			$client = new CS_REST_Clients($this->settings['clientId'], $this->getPostParams());
			$result = $client->get_lists();

			if ($result->was_successful())
			{
				return $result->response;
			}
			else
			{
				sproutCampaignMonitor()->error('Unable to get lists', array('result' => $result));
			}
		}
		catch (\Exception $e)
		{
			sproutCampaignMonitor()->error($e->getMessage());
		}

		return $lists;
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
			$auth   = $this->getPostParams();
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

			if (!empty($toEmails))
			{
				$email->toEmail = implode(', ', $toEmails);
			}

			// Conditional return for success/fail response from Campaign Monitor
			if (!$response->was_successful())
			{
				sproutCampaignMonitor()->error('Error creating campaign in Campaign Monitor: ' . $response->http_status_code . ' - ' . $response->response->Message);

				throw new Exception(Craft::t('{code}: {msg}', array('code' => $response->http_status_code, 'msg' => $response->response->Message)));
			}
			else
			{
				sproutCampaignMonitor()->info('Successfully created campaign in Campaign Monitor with ID: ' . $response->response);

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

	/*
	 * @return true|false according to the success of the request
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
				sproutCampaignMonitor()->info('Successfully sent campaign through Campaign Monitor with ID: ' . $campaignTypeId);

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

		/*
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

	/*
	 * @return JSON containing recipient list stats
	 */
	public function getListStats($listId)
	{
		// Get stats belonging to a list with the given ID
		$list     = new CS_REST_Lists($listId, $this->getPostParams());
		$response = $list->get_stats()->response;

		return $this->tryJsonResponse($response);
	}

	/*
	 * @return JSON containing recipient list details
	 */
	public function getDetails($listId)
	{
		// Get stats belonging to a list with the given ID
		$list     = new CS_REST_Lists($listId, $this->getPostParams());

		$response = $list->get()->response;

		return $this->tryJsonResponse($response);
	}

	public function getPostParams(array $extra = array())
	{
		$params = array(
			'api_key' => $this->settings['apiKey']
		);

		return array_merge($params, $extra);
	}

	private function tryJsonResponse($response)
	{
		try
		{
			$items = $response;

			return $items;
		}
		catch (\Exception $e)
		{
			return array();
		}
	}

	public function getListLabel($id)
	{
		$details = $this->getDetails($id);

		$stats = $this->getListStats($id)->TotalActiveSubscribers;

		return sprintf('%s (%d)', $details->Title, $stats);
	}

	/**
	 * Logs an info message to the plugin logs
	 *
	 * @param mixed $message
	 * @param array $variables
	 */
	public function info($message, array $variables = array())
	{
		if (is_string($message))
		{
			$message = Craft::t($message, $variables);
		}
		else
		{
			$message = print_r($message, true);
		}

		SproutEmailPlugin::log($message, LogLevel::Info);
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
}
