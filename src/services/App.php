<?php

namespace barrelstrength\sproutcampaignmonitor\services;

use barrelstrength\sproutcampaignmonitor\SproutCampaignMonitor;
use craft\mail\Message;
use barrelstrength\sproutmailchimp\models\CampaignModel;
use craft\base\Component;
use craft\base\Model;
use Craft;

class App extends Component
{
    protected $settings;

    /**
     * @var \Mailchimp
     */
    protected $client = null;

    /**
     * @throws \Mailchimp_Error
     */
    public function init()
    {
        parent::init();

        $this->settings = $this->getSettings();

        if (isset($this->settings['apiKey'])) {
            $apiKey = $this->settings['apiKey'];

            $client = new \Mailchimp($apiKey);

            $this->client = $client;
        }
    }

    /**
     * @return Model
     */
    public function getSettings()
    {
        $file = Craft::$app->getConfig()->getConfigFromFile('sprout-email');

        $settings = [];

        if ($file != null && isset($file['campaignmonitor'])) {
            $settings = $file['campaignmonitor'];
        } else {
            $plugin = SproutCampaignMonitor::getInstance()->getSettings();

            if ($plugin) {
                $settings = $plugin->getAttributes();
            }
        }

        return $settings;
    }

    /**
     * @param $id
     *
     * @return mixed
     * @throws \Exception
     */
    public function getListStatsById($id)
    {
        $params = ['list_id' => $id];

        try {
            $lists = $this->client->lists->getList($params);

            $stats = $lists['data'][0]['stats'];

            return $stats;
        } catch (\Exception $e) {
            throw $e;
        }
    }

    /**
     * @param CampaignModel $mailChimpModel
     * @param array         $campaignIds
     *
     * @return array
     * @throws \Exception
     */
    public function sendCampaignEmail(CampaignModel $mailChimpModel, array $campaignIds)
    {
        if (count($campaignIds)) {
            foreach ($campaignIds as $mailchimpCampaignId) {
                try {
                    $this->send($mailchimpCampaignId);
                } catch (\Exception $e) {
                    throw $e;
                }
            }
        }

        $message = new Message();

        $message->setSubject($mailChimpModel->title);
        $message->setFrom([$mailChimpModel->from_email => $mailChimpModel->from_name]);

        $message->setTextBody($mailChimpModel->text);
        $message->setHtmlBody($mailChimpModel->html);

        if (!empty($recipients)) {
            $recipients = implode(', ', $recipients);
            $message->setTo($recipients);
        }

        return ['ids' => $campaignIds, 'emailModel' => $message];
    }

    /**
     * @param CampaignModel $mailChimpModel
     * @param               $emails
     * @param array         $campaignIds
     *
     * @return array
     * @throws \Exception
     */
    public function sendTestEmail(CampaignModel $mailChimpModel, $emails, array $campaignIds)
    {
        if (count($campaignIds)) {
            // Send only one email by getting the first campaign ID for testing purpose only.
            $firstCampaignId = $campaignIds[0];

            try {
                $this->client->campaigns->sendTest($firstCampaignId, $emails);
            } catch (\Exception $e) {
                throw $e;
            }
        }

        $message = new Message();

        $message->setSubject($mailChimpModel->title);
        $message->setFrom([$mailChimpModel->from_email => $mailChimpModel->from_name]);

        $message->setTextBody($mailChimpModel->text);
        $message->setHtmlBody($mailChimpModel->html);

        if (!empty($recipients)) {
            $recipients = implode(', ', $recipients);
            $message->setTo($recipients);
        }

        return ['ids' => $campaignIds, 'emailModel' => $message];
    }

    /**
     * @param CampaignModel $mailChimpModel
     *
     * @return array
     * @throws \Exception
     */
    public function createCampaign(CampaignModel $mailChimpModel)
    {
        $lists = $mailChimpModel->lists;
        
        $campaignIds = [];

        if ($lists && count($lists)) {
            $type = 'regular';
            $options = [
                'title' => $mailChimpModel->title,
                'subject' => $mailChimpModel->subject,
                'from_name' => $mailChimpModel->from_name,
                'from_email' => $mailChimpModel->from_email,
                'tracking' => [
                    'opens' => true,
                    'html_clicks' => true,
                    'text_clicks' => false
                ],
            ];

            $content = [
                'html' => $mailChimpModel->html,
                'text' => $mailChimpModel->text
            ];

            foreach ($lists as $list) {
                $options['list_id'] = $list;

                if ($this->settings['inlineCss']) {
                    $options['inline_css'] = true;
                }

                try {
                    $campaignType = $this->client->campaigns->create($type, $options, $content);

                    $campaignIds[] = $campaignType['id'];
                } catch (\Exception $e) {
                    throw $e;
                }
            }
        }

        return $campaignIds;
    }

    public function getCampaignIdsIfExists(array $campaignIds = [])
    {
        $apiIds = [];

        if (!empty($campaignIds)) {
            foreach ($campaignIds as $campaignId) {
                // If it returns an error it means campaign has been deleted, Do nothing instead of throwing an error.
                try {
                    $campaign = $this->client->campaigns->ready($campaignId);

                    if (!empty($campaign) && $campaign['is_ready'] == true) {
                        $apiIds[] = $campaignId;
                    }
                } catch (\Exception $exception) {

                }
            }
        }

        return $apiIds;
    }

    public function updateCampaignContent($campaignId, $mailChimpModel)
    {
        $options = [
            'title' => $mailChimpModel->title,
            'subject' => $mailChimpModel->subject,
            'from_name' => $mailChimpModel->from_name,
            'from_email' => $mailChimpModel->from_email,
            'tracking' => [
                'opens' => true,
                'html_clicks' => true,
                'text_clicks' => false
            ],
        ];

        $content = [
            'html' => $mailChimpModel->html,
            'text' => $mailChimpModel->text
        ];

        $this->client->campaigns->update($campaignId, 'options', $options);
        $this->client->campaigns->update($campaignId, 'content', $content);
    }

    /**
     * Sends a previously created/exported campaign via its mail chimp campaign id
     *
     * @param string $mailchimpCampaignId
     *
     * @throws \Exception
     * @return true
     */
    public function send($mailchimpCampaignId)
    {
        try {
            $this->client->campaigns->send($mailchimpCampaignId);

            return true;
        } catch (\Exception $e) {
            throw $e;
        }
    }

    /**
     * @param $apiKey
     *
     * @return bool|mixed
     * @throws \Mailchimp_Error
     */
    public function validateApiKey($apiKey)
    {
        $client = new \Mailchimp($apiKey, ['ssl_verifypeer' => false]);

        try {
            $result = $client->call('helper/ping', []);
        } catch (\Exception $e) {
            $result = false;
        }

        return $result;
    }
}
