<?php

namespace AmazonAdvertisingApi;

use Exception;

require_once "Versions.php";
require_once "Regions.php";
require_once "CurlRequest.php";
require_once "SponsoredBrandsRequests.php";
require_once "SponsoredDisplayRequests.php";

/**
 * Class Client
 * Contains requests' wrappers of Amazon Ads API
 */
class Client
{
    use SponsoredBrandsRequests;
    use SponsoredDisplayRequests;
    use ProductEligibilityRequests;

    private $config = [
        "clientId" => null,
        "clientSecret" => null,
        "region" => null,
        "accessToken" => null,
        "refreshToken" => null,
        "sandbox" => false,
        "saveFile" => false,
        "apiVersion" => 'v1'
    ];

    private $apiVersion = null;
    private $applicationVersion = null;
    private $userAgent = null;
    private $endpoint = null;
    private $tokenUrl = null;
    private $requestId = null;
    private $endpoints = null;
    private $versionStrings = null;
    public $campaignTypePrefix;

    public $profileId = null;

    public $headers = [];

    /**
     * Client constructor.
     * @param $config
     * @throws Exception
     */
    public function __construct($config)
    {
        $regions = new Regions();
        $this->endpoints = $regions->endpoints;

        $versions = new Versions();
        $this->versionStrings = $versions->versionStrings;
        $this->apiVersion = $config['apiVersion'] ?? null;

        $this->apiVersion = is_null($this->apiVersion) ? $this->versionStrings["apiVersion"] : $this->apiVersion;
        $this->applicationVersion = $this->versionStrings["applicationVersion"];
        $this->userAgent = "AdvertisingAPI PHP Client Library v{$this->applicationVersion}";

        $this->validateConfig($config);
        $this->validateConfigParameters();
        $this->setEndpoints();

        if (is_null($this->config["accessToken"]) && !is_null($this->config["refreshToken"])) {
            /* convenience */
            $this->doRefreshToken();
        }
    }

    /**
     * @param string $name
     * @param $value
     */
    public function __set(string $name, $value)
    {
        if (isset($this->{$name})) {
            $this->{$name} = $value;
        }
    }

    /**
     * @return array
     * @throws Exception
     */
    public function doRefreshToken()
    {
        $headers = array(
            "Content-Type: application/x-www-form-urlencoded;charset=UTF-8",
            "User-Agent: {$this->userAgent}"
        );

        $refresh_token = rawurldecode($this->config["refreshToken"]);

        $params = array(
            "grant_type" => "refresh_token",
            "refresh_token" => $refresh_token,
            "client_id" => $this->config["clientId"],
            "client_secret" => $this->config["clientSecret"]);

        $data = "";
        foreach ($params as $k => $v) {
            $data .= "{$k}=" . rawurlencode($v) . "&";
        }

        $url = "https://{$this->tokenUrl}";

        $request = new CurlRequest();
        $request->setOption(CURLOPT_URL, $url);
        $request->setOption(CURLOPT_HTTPHEADER, $headers);
        $request->setOption(CURLOPT_USERAGENT, $this->userAgent);
        $request->setOption(CURLOPT_POST, true);
        $request->setOption(CURLOPT_POSTFIELDS, rtrim($data, "&"));

        $response = $this->executeRequest($request);

        $response_array = json_decode($response["response"], true);
        if (is_array($response_array) && array_key_exists("access_token", $response_array)) {
            $this->config["accessToken"] = $response_array["access_token"];
        } else {
            $this->logAndThrow("Unable to refresh token. 'access_token' not found in response. " . print_r($response, true));
        }

        return $response;
    }

    /**
     * @return array
     * @throws Exception
     */
    public function listProfiles()
    {
        return $this->operation("profiles");
    }

    /**
     * @param $data
     * @return array
     * @throws Exception
     */
    public function registerProfile($data)
    {
        return $this->operation("profiles/register", $data, "PUT");
    }

    /**
     * @param $profileId
     * @return array
     * @throws Exception
     */
    public function registerProfileStatus($profileId)
    {
        return $this->operation("profiles/register/{$profileId}/status");
    }

    /**
     * @param $profileId
     * @return array
     * @throws Exception
     */
    public function getProfile($profileId)
    {
        return $this->operation("profiles/{$profileId}");
    }

    /**
     * @param $data
     * @return array
     * @throws Exception
     */
    public function updateProfiles($data)
    {
        return $this->operation("profiles", $data, "PUT");
    }

    /**
     * @param $campaignId
     * @param null $data
     * @return array
     * @throws Exception
     */
    public function getCampaign($campaignId, $data = null)
    {
        $type = $this->campaignTypePrefix == 'hsa' ? 'hsa' : 'sp';
        if ($this->apiVersion == 'v1') {
            $type = null;
        } else {
            $type = $type . "/";
        }
        if (!$type && $this->apiVersion == 'v2') {
            $this->logAndThrow("Unable to perform request. No type is set");
        }

        return $this->operation($type . "campaigns/{$campaignId}");
    }

    /**
     * @param $campaignId
     * @param null $data
     * @return array
     * @throws Exception
     */
    public function getCampaignEx($campaignId, $data = null)
    {
        $type = $this->campaignTypePrefix == 'hsa' ? 'hsa' : 'sp';
        if ($this->apiVersion == 'v1') {
            $type = null;
        } else {
            $type = $type . "/";
        }
        if (!$type && $this->apiVersion == 'v2') {
            $this->logAndThrow("Unable to perform request. No type is set");
        }
        return $this->operation($type . "campaigns/extended/{$campaignId}");
    }

    /**
     * @param $data
     * @return array
     * @throws Exception
     */
    public function createCampaigns($data)
    {
        $type = $this->campaignTypePrefix == 'hsa' ? 'hsa' : 'sp';
        if ($this->apiVersion == 'v1') {
            $type = null;
        } else {
            $type = $type . "/";
        }
        if (!$type && $this->apiVersion == 'v2') {
            $this->logAndThrow("Unable to perform request. No type is set");
        }
        return $this->operation($type . "campaigns", $data, "POST");
    }

    /**
     * @param $data
     * @return array
     * @throws Exception
     */
    public function updateCampaigns($data)
    {
        $type = $this->campaignTypePrefix == 'hsa' ? 'hsa' : 'sp';
        if ($this->apiVersion == 'v1') {
            $type = null;
        } else {
            $type = $type . "/";
        }
        if (!$type && $this->apiVersion == 'v2') {
            $this->logAndThrow("Unable to perform request. No type is set");
        }
        return $this->operation($type . "campaigns", $data, "PUT");
    }

    /**
     * @param $campaignId
     * @param null $data
     * @return array
     * @throws Exception
     */
    public function archiveCampaign($campaignId, $data = null)
    {
        $type = $this->campaignTypePrefix == 'hsa' ? 'hsa' : 'sp';
        if ($this->apiVersion == 'v1') {
            $type = null;
        } else {
            $type = $type . "/";
        }
        if (!$type && $this->apiVersion == 'v2') {
            $this->logAndThrow("Unable to perform request. No type is set");
        }
        return $this->operation($type . "campaigns/{$campaignId}", null, "DELETE");
    }

    /**
     * @param null $data
     * @return array
     * @throws Exception
     */
    public function listCampaigns($data = null)
    {
        $campaignType = is_array($data) && isset($data['campaignType']) ? $data['campaignType'] : 'sponsoredProducts';
        $type = $campaignType == 'sponsoredProducts'
            ? 'sp'
            : ($campaignType == 'sponsoredBrands'
                ? 'hsa'
                : ($this->campaignTypePrefix == 'hsa'
                    ? 'hsa'
                    : 'sp'
                )
            );
        if ($this->apiVersion == 'v1') {
            $type = null;
        } else {
            $type = $type . "/";
        }
        if (isset($data['campaignType']) && $type === 'hsa/') {
            unset($data['campaignType']);
        }

        if (!$type && $this->apiVersion == 'v2') {
            $this->logAndThrow("Unable to perform request. No type is set");
        }
        return $this->operation($type . "campaigns", $data);
    }

    /**
     * @param null $data
     * @return array
     * @throws Exception
     */
    public function listCampaignsEx($data = null)
    {
        $campaignType = is_array($data) && isset($data['campaignType']) ? $data['campaignType'] : 'sponsoredProducts';
        $type = $campaignType == 'sponsoredProducts' ? 'sp' : ($campaignType == 'sponsoredBrands' ? 'hsa' : null);
        if ($this->apiVersion == 'v1') {
            $type = null;
        } else {
            $type = $type . "/";
        }

        if (!$type && $this->apiVersion == 'v2') {
            $this->logAndThrow("Unable to perform request. No type is set");
        }
        return $this->operation($type . "campaigns/extended", $data);
    }

    /**
     * @param $adGroupId
     * @param null $data
     * @return array
     * @throws Exception
     */
    public function getAdGroup($adGroupId, $data = null)
    {
        $adGroupType = is_array($data) && isset($data['campaignType']) ? $data['campaignType'] : 'sponsoredProducts';
        $type = $adGroupType == 'sponsoredProducts' ? 'sp' : ($adGroupType == 'sponsoredBrands' ? 'hsa' : null);
        if ($this->apiVersion == 'v1') {
            $type = null;
        } else {
            $type = $type . "/";
        }

        if (!$type && $this->apiVersion == 'v2') {
            $this->logAndThrow("Unable to perform request. No type is set");
        }
        return $this->operation($type . "adGroups/{$adGroupId}");
    }

    /**
     * @param $adGroupId
     * @param null $data
     * @return array
     * @throws Exception
     */
    public function getAdGroupEx($adGroupId, $data = null)
    {
        $adGroupType = is_array($data) && isset($data['campaignType']) ? $data['campaignType'] : 'sponsoredProducts';
        $type = $adGroupType == 'sponsoredProducts' ? 'sp' : ($adGroupType == 'sponsoredBrands' ? 'hsa' : null);
        if ($this->apiVersion == 'v1') {
            $type = null;
        } else {
            $type = $type . "/";
        }

        if (!$type && $this->apiVersion == 'v2') {
            $this->logAndThrow("Unable to perform request. No type is set");
        }
        return $this->operation($type . "adGroups/extended/{$adGroupId}");
    }

    /**
     * @param $data
     * @return array
     * @throws Exception
     */
    public function createAdGroups($data)
    {
        $adGroupType = is_array($data) && isset($data['campaignType']) ? $data['campaignType'] : 'sponsoredProducts';
        $type = $adGroupType == 'sponsoredProducts' ? 'sp' : ($adGroupType == 'sponsoredBrands' ? 'hsa' : null);

        if ($this->apiVersion == 'v1') {
            $type = null;
        } else {
            $type = $type . "/";
        }

        if (!$type && $this->apiVersion == 'v2') {
            $this->logAndThrow("Unable to perform request. No type is set");
        }
        return $this->operation($type . "adGroups", $data, "POST");
    }

    /**
     * @param $data
     * @return array
     * @throws Exception
     */
    public function updateAdGroups($data)
    {
        $adGroupType = is_array($data) && isset($data['campaignType']) ? $data['campaignType'] : 'sponsoredProducts';
        $type = $adGroupType == 'sponsoredProducts' ? 'sp' : ($adGroupType == 'sponsoredBrands' ? 'hsa' : null);
        if ($this->apiVersion == 'v1') {
            $type = null;
        } else {
            if (isset($data['campaignType'])) {
                unset($data['campaignType']);
                $data = array_values($data);
            }
            $type = $type . "/";
        }

        if (!$type && $this->apiVersion == 'v2') {
            $this->logAndThrow("Unable to perform request. No type is set");
        }
        return $this->operation($type . "adGroups", $data, "PUT");
    }

    /**
     * @param $adGroupId
     * @param null $data
     * @return array
     * @throws Exception
     */
    public function archiveAdGroup($adGroupId, $data = null)
    {
        $adGroupType = is_array($data) && isset($data['campaignType']) ? $data['campaignType'] : 'sponsoredProducts';
        $type = $adGroupType == 'sponsoredProducts' ? 'sp' : ($adGroupType == 'sponsoredBrands' ? 'hsa' : null);
        if ($this->apiVersion == 'v1') {
            $type = null;
        } else {
            $type = $type . "/";
        }

        if (!$type && $this->apiVersion == 'v2') {
            $this->logAndThrow("Unable to perform request. No type is set");
        }
        return $this->operation($type . "adGroups/{$adGroupId}", null, "DELETE");
    }

    /**
     * @param null $data
     * @return array
     * @throws Exception
     */
    public function listAdGroups($data = null)
    {
        $adGroupType = is_array($data) && isset($data['campaignType']) ? $data['campaignType'] : 'sponsoredProducts';
        $type = $adGroupType == 'sponsoredProducts' ? 'sp' : ($adGroupType == 'sponsoredBrands' ? 'hsa' : null);
        if ($this->apiVersion == 'v1') {
            $type = null;
        } else {
            $type = $type . "/";
        }

        if (!$type && $this->apiVersion == 'v2') {
            $this->logAndThrow("Unable to perform request. No type is set");
        }
        return $this->operation($type . "adGroups", $data);
    }

    /**
     * @param null $data
     * @return array
     * @throws Exception
     */
    public function listAdGroupsEx($data = null)
    {
        $adGroupType = is_array($data) && isset($data['campaignType']) ? $data['campaignType'] : 'sponsoredProducts';
        $type = $adGroupType == 'sponsoredProducts' ? 'sp' : ($adGroupType == 'sponsoredBrands' ? 'hsa' : null);
        if ($this->apiVersion == 'v1') {
            $type = null;
        } else {
            $type = $type . "/";
        }

        if (!$type && $this->apiVersion == 'v2') {
            $this->logAndThrow("Unable to perform request. No type is set");
        }
        return $this->operation($type . "adGroups/extended", $data);
    }

    /**
     * @param $keywordId
     * @param null $data
     * @return array
     * @throws Exception
     */
    public function getBiddableKeyword($keywordId, $data = null)
    {
        $type = $this->campaignTypePrefix == 'hsa' ? 'hsa' : 'sp';
        if ($this->apiVersion == 'v1') {
            $type = null;
        } else {
            $type = $type . "/";
        }

        if (!$type && $this->apiVersion == 'v2') {
            $this->logAndThrow("Unable to perform request. No type is set");
        }
        return $this->operation($type . "keywords/{$keywordId}");
    }

    /**
     * @param $keywordId
     * @param null $data
     * @return array
     * @throws Exception
     */
    public function getBiddableKeywordEx($keywordId, $data = null)
    {
        $type = $this->campaignTypePrefix == 'hsa' ? 'hsa' : 'sp';
        if ($this->apiVersion == 'v1') {
            $type = null;
        } else {
            if (isset($data['campaignType'])) {
                unset($data['campaignType']);
            }
            $type = $type . "/";
        }

        if (!$type && $this->apiVersion == 'v2') {
            $this->logAndThrow("Unable to perform request. No type is set");
        }
        return $this->operation($type . "keywords/extended/{$keywordId}");
    }

    /**
     * @param $data
     * @return array
     * @throws Exception
     */
    public function createBiddableKeywords($data)
    {
        $type = $this->campaignTypePrefix == 'hsa' ? 'hsa' : 'sp';
        if ($this->apiVersion == 'v1') {
            $type = null;
        } else {
            if (isset($data['campaignType'])) {
                unset($data['campaignType']);
                $data = array_values($data);
            }
            $type = $type . "/";
        }

        if (!$type && $this->apiVersion == 'v2') {
            $this->logAndThrow("Unable to perform request. No type is set");
        }
        return $this->operation($type . "keywords", $data, "POST");
    }

    /**
     * @param $data
     * @return array
     * @throws Exception
     */
    public function updateBiddableKeywords($data)
    {
        $type = $this->campaignTypePrefix == 'hsa' ? 'hsa' : 'sp';
        if ($this->apiVersion == 'v1') {
            $type = null;
        } else {
            if (isset($data['campaignType'])) {
                unset($data['campaignType']);
                $data = array_values($data);
            }
            $type = $type . "/";
        }

        if (!$type && $this->apiVersion == 'v2') {
            $this->logAndThrow("Unable to perform request. No type is set");
        }
        return $this->operation($type . "keywords", $data, "PUT");
    }

    /**
     * @param $keywordId
     * @param null $data
     * @return array
     * @throws Exception
     */
    public function archiveBiddableKeyword($keywordId, $data = null)
    {
        $type = $this->campaignTypePrefix == 'hsa' ? 'hsa' : 'sp';
        if ($this->apiVersion == 'v1') {
            $type = null;
        } else {
            $type = $type . "/";
        }

        if (!$type && $this->apiVersion == 'v2') {
            $this->logAndThrow("Unable to perform request. No type is set");
        }
        return $this->operation($type . "keywords/{$keywordId}", null, "DELETE");
    }

    /**
     * @param null $data
     * @return array
     * @throws Exception
     */
    public function listBiddableKeywords($data = null)
    {
        $type = $this->campaignTypePrefix == 'hsa' ? 'hsa' : 'sp';
        if ($this->apiVersion == 'v1') {
            $type = null;
        } else {
            if (isset($data['campaignType'])) {
                unset($data['campaignType']);
            }
            $type = $type . "/";
        }

        if (!$type && $this->apiVersion == 'v2') {
            $this->logAndThrow("Unable to perform request. No type is set");
        }
        return $this->operation($type . "keywords", $data);
    }

    /**
     * @param null $data
     * @return array
     * @throws Exception
     */
    public function listBiddableKeywordsEx($data = null)
    {
        $type = $this->campaignTypePrefix == 'hsa' ? 'hsa' : 'sp';
        if ($this->apiVersion == 'v1') {
            $type = null;
        } else {
            $type = $type . "/";
        }

        if (!$type && $this->apiVersion == 'v2') {
            $this->logAndThrow("Unable to perform request. No type is set");
        }
        return $this->operation($type . "keywords/extended", $data);
    }

    /**
     * @param $keywordId
     * @param null $data
     * @return array
     * @throws Exception
     */
    public function getNegativeKeyword($keywordId, $data = null)
    {
        $type = $this->campaignTypePrefix == 'hsa' ? 'hsa' : 'sp';
        if ($this->apiVersion == 'v1') {
            $type = null;
        } else {
            if (isset($data['campaignType'])) {
                unset($data['campaignType']);
            }
            $type = $type . "/";
        }

        if (!$type && $this->apiVersion == 'v2') {
            $this->logAndThrow("Unable to perform request. No type is set");
        }
        return $this->operation($type . "negativeKeywords/{$keywordId}");
    }

    /**
     * @param $keywordId
     * @param null $data
     * @return array
     * @throws Exception
     */
    public function getNegativeKeywordEx($keywordId, $data = null)
    {
        $type = $this->campaignTypePrefix == 'hsa' ? 'hsa' : 'sp';
        if ($this->apiVersion == 'v1') {
            $type = null;
        } else {
            if (isset($data['campaignType'])) {
                unset($data['campaignType']);
            }
            $type = $type . "/";
        }

        if (!$type && $this->apiVersion == 'v2') {
            $this->logAndThrow("Unable to perform request. No type is set");
        }
        return $this->operation($type . "negativeKeywords/extended/{$keywordId}");
    }

    /**
     * @param $data
     * @return array
     * @throws Exception
     */
    public function createNegativeKeywords($data)
    {
        $type = $this->campaignTypePrefix == 'hsa' ? 'hsa' : 'sp';
        if ($this->apiVersion == 'v1') {
            $type = null;
        } else {
            if (isset($data['campaignType'])) {
                unset($data['campaignType']);
                $data = array_values($data);
            }
            $type = $type . "/";
        }

        if (!$type && $this->apiVersion == 'v2') {
            $this->logAndThrow("Unable to perform request. No type is set");
        }
        return $this->operation($type . "negativeKeywords", $data, "POST");
    }

    /**
     * @param $data
     * @return array
     * @throws Exception
     */
    public function updateNegativeKeywords($data)
    {
        $type = $this->campaignTypePrefix == 'hsa' ? 'hsa' : 'sp';
        if ($this->apiVersion == 'v1') {
            $type = null;
        } else {
            if (isset($data['campaignType'])) {
                unset($data['campaignType']);
                $data = array_values($data);
            }
            $type = $type . "/";
        }

        if (!$type && $this->apiVersion == 'v2') {
            $this->logAndThrow("Unable to perform request. No type is set");
        }
        return $this->operation($type . "negativeKeywords", $data, "PUT");
    }

    /**
     * @param $keywordId
     * @param null $data
     * @return array
     * @throws Exception
     */
    public function archiveNegativeKeyword($keywordId, $data = null)
    {
        $type = $this->campaignTypePrefix == 'hsa' ? 'hsa' : 'sp';
        if ($this->apiVersion == 'v1') {
            $type = null;
        } else {
            if (isset($data['campaignType'])) {
                unset($data['campaignType']);
            }
            $type = $type . "/";
        }

        if (!$type && $this->apiVersion == 'v2') {
            $this->logAndThrow("Unable to perform request. No type is set");
        }
        return $this->operation($type . "negativeKeywords/{$keywordId}", null, "DELETE");
    }

    /**
     * @param null $data
     * @return array
     * @throws Exception
     */
    public function listNegativeKeywords($data = null)
    {
        $type = $this->campaignTypePrefix == 'hsa' ? 'hsa' : 'sp';
        if ($this->apiVersion == 'v1') {
            $type = null;
        } else {
            $type = $type . "/";
        }

        if (!$type && $this->apiVersion == 'v2') {
            $this->logAndThrow("Unable to perform request. No type is set");
        }
        return $this->operation($type . "negativeKeywords", $data);
    }

    /**
     * @param null $data
     * @return array
     * @throws Exception
     */
    public function listNegativeKeywordsEx($data = null)
    {
        $type = $this->campaignTypePrefix == 'hsa' ? 'hsa' : 'sp';
        if ($this->apiVersion == 'v1') {
            $type = null;
        } else {
            $type = $type . "/";
        }

        if (!$type && $this->apiVersion == 'v2') {
            $this->logAndThrow("Unable to perform request. No type is set");
        }
        return $this->operation($type . "negativeKeywords/extended", $data);
    }

    /**
     * @param $keywordId
     * @param null $data
     * @return array
     * @throws Exception
     */
    public function getCampaignNegativeKeyword($keywordId, $data = null)
    {
        $type = $this->campaignTypePrefix == 'hsa' ? 'hsa' : 'sp';
        if ($this->apiVersion == 'v1') {
            $type = null;
        } else {
            if (isset($data['campaignType'])) {
                unset($data['campaignType']);
            }
            $type = $type . "/";
        }

        if (!$type && $this->apiVersion == 'v2') {
            $this->logAndThrow("Unable to perform request. No type is set");
        }
        return $this->operation($type . "campaignNegativeKeywords/{$keywordId}");
    }

    /**
     * @param $keywordId
     * @param null $data
     * @return array
     * @throws Exception
     */
    public function getCampaignNegativeKeywordEx($keywordId, $data = null)
    {
        $type = $this->campaignTypePrefix == 'hsa' ? 'hsa' : 'sp';
        if ($this->apiVersion == 'v1') {
            $type = null;
        } else {
            if (isset($data['campaignType'])) {
                unset($data['campaignType']);
            }
            $type = $type . "/";
        }

        if (!$type && $this->apiVersion == 'v2') {
            $this->logAndThrow("Unable to perform request. No type is set");
        }
        return $this->operation($type . "campaignNegativeKeywords/extended/{$keywordId}");
    }

    /**
     * @param $data
     * @return array
     * @throws Exception
     */
    public function createCampaignNegativeKeywords($data)
    {
        $type = $this->campaignTypePrefix == 'hsa' ? 'hsa' : 'sp';
        if ($this->apiVersion == 'v1') {
            $type = null;
        } else {
            if (isset($data['campaignType'])) {
                unset($data['campaignType']);
                $data = array_values($data);
            }
            $type = $type . "/";
        }

        if (!$type && $this->apiVersion == 'v2') {
            $this->logAndThrow("Unable to perform request. No type is set");
        }
        return $this->operation($type . "campaignNegativeKeywords", $data, "POST");
    }

    /**
     * @param $data
     * @return array
     * @throws Exception
     */
    public function updateCampaignNegativeKeywords($data)
    {
        $type = $this->campaignTypePrefix == 'hsa' ? 'hsa' : 'sp';
        if ($this->apiVersion == 'v1') {
            $type = null;
        } else {
            if (isset($data['campaignType'])) {
                unset($data['campaignType']);
                $data = array_values($data);
            }
            $type = $type . "/";
        }

        if (!$type && $this->apiVersion == 'v2') {
            $this->logAndThrow("Unable to perform request. No type is set");
        }
        return $this->operation($type . "campaignNegativeKeywords", $data, "PUT");
    }

    /**
     * @param $keywordId
     * @param null $data
     * @return array
     * @throws Exception
     */
    public function removeCampaignNegativeKeyword($keywordId, $data = null)
    {
        $type = $this->campaignTypePrefix == 'hsa' ? 'hsa' : 'sp';
        if ($this->apiVersion == 'v1') {
            $type = null;
        } else {
            if (isset($data['campaignType'])) {
                unset($data['campaignType']);
            }
            $type = $type . "/";
        }

        if (!$type && $this->apiVersion == 'v2') {
            $this->logAndThrow("Unable to perform request. No type is set");
        }
        return $this->operation($type . "campaignNegativeKeywords/{$keywordId}", null, "DELETE");
    }

    /**
     * @param null $data
     * @return array
     * @throws Exception
     */
    public function listCampaignNegativeKeywords($data = null)
    {
        $type = $this->campaignTypePrefix == 'hsa' ? 'hsa' : 'sp';
        if ($this->apiVersion == 'v1') {
            $type = null;
        } else {
            $type = $type . "/";
        }

        if (!$type && $this->apiVersion == 'v2') {
            $this->logAndThrow("Unable to perform request. No type is set");
        }
        return $this->operation($type . "campaignNegativeKeywords", $data);
    }

    /**
     * @param null $data
     * @return array
     * @throws Exception
     */
    public function listCampaignNegativeKeywordsEx($data = null)
    {
        $type = $this->campaignTypePrefix == 'hsa' ? 'hsa' : 'sp';
        if ($this->apiVersion == 'v1') {
            $type = null;
        } else {
            $type = $type . "/";
        }

        if (!$type && $this->apiVersion == 'v2') {
            $this->logAndThrow("Unable to perform request. No type is set");
        }
        return $this->operation($type . "campaignNegativeKeywords/extended", $data);
    }

    /**
     * @param $productAdId
     * @param null $data
     * @return array
     * @throws Exception
     */
    public function getProductAd($productAdId, $data = null)
    {
        $productAdType = is_array($data) && isset($data['campaignType']) ? $data['campaignType'] : 'sponsoredProducts';
        $type = $productAdType == 'sponsoredProducts' ? 'sp' : ($productAdType == 'sponsoredBrands' ? 'hsa' : null);
        if ($this->apiVersion == 'v1') {
            $type = null;
        } else {
            if (isset($data['campaignType'])) {
                unset($data['campaignType']);
            }
            $type = $type . "/";
        }

        if (!$type && $this->apiVersion == 'v2') {
            $this->logAndThrow("Unable to perform request. No type is set");
        }
        return $this->operation($type . "productAds/{$productAdId}");
    }

    /**
     * @param $productAdId
     * @param null $data
     * @return array
     * @throws Exception
     */
    public function getProductAdEx($productAdId, $data = null)
    {
        $productAdType = is_array($data) && isset($data['campaignType']) ? $data['campaignType'] : 'sponsoredProducts';
        $type = $productAdType == 'sponsoredProducts' ? 'sp' : ($productAdType == 'sponsoredBrands' ? 'hsa' : null);
        if ($this->apiVersion == 'v1') {
            $type = null;
        } else {
            if (isset($data['campaignType'])) {
                unset($data['campaignType']);
            }
            $type = $type . "/";
        }

        if (!$type && $this->apiVersion == 'v2') {
            $this->logAndThrow("Unable to perform request. No type is set");
        }
        return $this->operation("productAds/extended/{$productAdId}");
    }

    /**
     * @param $data
     * @return array
     * @throws Exception
     */
    public function createProductAds($data)
    {
        $productAdType = is_array($data) && isset($data['campaignType']) ? $data['campaignType'] : 'sponsoredProducts';
        $type = $productAdType == 'sponsoredProducts' ? 'sp' : ($productAdType == 'sponsoredBrands' ? 'hsa' : null);
        if ($this->apiVersion == 'v1') {
            $type = null;
        } else {
            if (isset($data['campaignType'])) {
                unset($data['campaignType']);
                $data = array_values($data);
            }
            $type = $type . "/";
        }

        if (!$type && $this->apiVersion == 'v2') {
            $this->logAndThrow("Unable to perform request. No type is set");
        }
        return $this->operation($type . "productAds", $data, "POST");
    }

    /**
     * @param $data
     * @return array
     * @throws Exception
     */
    public function updateProductAds($data)
    {
        $productAdType = is_array($data) && isset($data['campaignType']) ? $data['campaignType'] : 'sponsoredProducts';
        $type = $productAdType == 'sponsoredProducts' ? 'sp' : ($productAdType == 'sponsoredBrands' ? 'hsa' : null);
        if ($this->apiVersion == 'v1') {
            $type = null;
        } else {
            if (isset($data['campaignType'])) {
                unset($data['campaignType']);
                $data = array_values($data);
            }
            $type = $type . "/";
        }

        if (!$type && $this->apiVersion == 'v2') {
            $this->logAndThrow("Unable to perform request. No type is set");
        }
        return $this->operation($type . "productAds", $data, "PUT");
    }

    /**
     * @param $productAdId
     * @param null $data
     * @return array
     * @throws Exception
     */
    public function archiveProductAd($productAdId, $data = null)
    {
        $productAdType = is_array($data) && isset($data['campaignType']) ? $data['campaignType'] : 'sponsoredProducts';
        $type = $productAdType == 'sponsoredProducts' ? 'sp' : ($productAdType == 'sponsoredBrands' ? 'hsa' : null);
        if ($this->apiVersion == 'v1') {
            $type = null;
        } else {
            if (isset($data['campaignType'])) {
                unset($data['campaignType']);
            }
            $type = $type . "/";
        }

        if (!$type && $this->apiVersion == 'v2') {
            $this->logAndThrow("Unable to perform request. No type is set");
        }
        return $this->operation($type . "productAds/{$productAdId}", null, "DELETE");
    }

    /**
     * @param null $data
     * @return array
     * @throws Exception
     */
    public function listProductAds($data = null)
    {
        $productAdType = is_array($data) && isset($data['campaignType']) ? $data['campaignType'] : 'sponsoredProducts';
        $type = $productAdType == 'sponsoredProducts' ? 'sp' : ($productAdType == 'sponsoredBrands' ? 'hsa' : null);
        if ($this->apiVersion == 'v1') {
            $type = null;
        } else {
            $type = $type . "/";
        }

        if (!$type && $this->apiVersion == 'v2') {
            $this->logAndThrow("Unable to perform request. No type is set");
        }
        return $this->operation("productAds", $data);
    }

    /**
     * @param null $data
     * @return array
     * @throws Exception
     */
    public function listProductAdsEx($data = null)
    {
        $productAdType = is_array($data) && isset($data['campaignType']) ? $data['campaignType'] : 'sponsoredProducts';
        $type = $productAdType == 'sponsoredProducts' ? 'sp' : ($productAdType == 'sponsoredBrands' ? 'hsa' : null);
        if ($this->apiVersion == 'v1') {
            $type = null;
        } else {
            $type = $type . "/";
        }

        if (!$type && $this->apiVersion == 'v2') {
            $this->logAndThrow("Unable to perform request. No type is set");
        }
        return $this->operation($type . "productAds/extended", $data);
    }

    /**
     * @param $adGroupId
     * @return array
     * @throws Exception
     */
    public function getAdGroupBidRecommendations($adGroupId)
    {
        return $this->operation("adGroups/{$adGroupId}/bidRecommendations");
    }

    /**
     * @param $keywordId
     * @return array
     * @throws Exception
     */
    public function getKeywordBidRecommendations($keywordId)
    {
        return $this->operation("keywords/{$keywordId}/bidRecommendations");
    }

    /**
     * @param $adGroupId
     * @param $data
     * @return array
     * @throws Exception
     */
    public function bulkGetKeywordBidRecommendations($adGroupId, $data)
    {
        $data = array(
            "adGroupId" => $adGroupId,
            "keywords" => $data);
        return $this->operation("keywords/bidRecommendations", $data, "POST");
    }

    /**
     * @param $data
     * @return array
     * @throws Exception
     */
    public function getAdGroupKeywordSuggestions($data)
    {
        $adGroupId = $data["adGroupId"];
        unset($data["adGroupId"]);
        return $this->operation("adGroups/{$adGroupId}/suggested/keywords", $data);
    }

    /**
     * @param $data
     * @return array
     * @throws Exception
     */
    public function getAdGroupKeywordSuggestionsEx($data)
    {
        $adGroupId = $data["adGroupId"];
        unset($data["adGroupId"]);
        return $this->operation("adGroups/{$adGroupId}/suggested/keywords/extended", $data);
    }

    /**
     * @param $data
     * @return array
     * @throws Exception
     */
    public function getAsinKeywordSuggestions($data)
    {
        $asin = $data["asin"];
        unset($data["asin"]);
        return $this->operation("asins/{$asin}/suggested/keywords", $data);
    }

    /**
     * @param $data
     * @return array
     * @throws Exception
     */
    public function bulkGetAsinKeywordSuggestions($data)
    {
        return $this->operation("asins/suggested/keywords", $data, "POST");
    }

    /**
     * GET /v2/stores
     * @param array|null $data
     * @return array
     * @throws Exception
     */
    public function getStores($data = null)
    {
        return $this->operation("stores", $data);
    }

    /**
     * GET /v2stores/{$brandEntityId}
     * @param int $brandEntityId
     * @return array
     * @throws Exception
     */
    public function getStoresByBrandEntityId(int $brandEntityId)
    {
        return $this->operation("stores/{$brandEntityId}");
    }

    /**
     * @param $recordType
     * @param null $data
     * @return array
     * @throws Exception
     */
    public function requestSnapshot($recordType, $data = null)
    {
        return $this->operation("{$recordType}/snapshot", $data, "POST");
    }

    /**
     * @param $snapshotId
     * @return array
     * @throws Exception
     */
    public function getSnapshot($snapshotId)
    {
        $req = $this->operation("snapshots/{$snapshotId}");
        if ($req["success"]) {
            $json = json_decode($req["response"], true);
            if ($json["status"] == "SUCCESS") {
                return $this->download($json["location"]);
            }
        }
        return $req;
    }

    /**
     * @param $recordType
     * @param null $data
     * @return array
     * @throws Exception
     */
    public function requestReport($recordType, $data = null)
    {
        $type = $this->getCampaignTypeForReportRequest($data);
        if ($this->apiVersion == 'v1') {
            $type = null;
        } else {
            $type = $type . "/";
            if (is_array($data) && isset($data['reportType'])) {
                unset($data['reportType']);
            }
        }
        if (!$type && $this->apiVersion == 'v2') {
            $this->logAndThrow("Unable to perform request. No type is set");
        }
        return $this->operation($type . "{$recordType}/report", $data, "POST");
    }

    /**
     * @param array|null $data
     * @return string
     * @throws Exception
     */
    private function getCampaignTypeForReportRequest(?array $data): string
    {
        $reportType = is_array($data) && isset($data['reportType'])
            ? $data['reportType']
            : 'sponsoredProducts';
        if ($reportType === 'sponsoredProducts') {
            return 'sp';
        } elseif ($reportType === 'sponsoredBrands') {
            return 'sb';
        } elseif ($reportType === 'sponsoredDisplay') {
            return 'sd';
        } else {
            throw new Exception("Invalid reportType $reportType");
        }
    }

    /**
     * @param $reportId
     * @return array
     * @throws Exception
     */
    public function getReport($reportId)
    {
        $req = $this->operation("reports/{$reportId}");
        if ($req["success"]) {
            $json = json_decode($req["response"], true);
            if ($json["status"] == "SUCCESS") {
                return $this->download($json["location"]);
            }
        }
        return $req;
    }

    //portfolios part

    /**
     * @param null|array $data
     * @return array
     * @throws Exception
     */
    public function listPortfolios($data = null)
    {
        return $this->operation("portfolios", $data);
    }

    /**
     * @param null|array $data
     * @return array
     * @throws Exception
     */
    public function listPortfoliosEx($data = null)
    {
        return $this->operation("portfolios/extended", $data);
    }

    /**
     * @param int $portfolioId
     * @return array
     * @throws Exception
     */
    public function getPortfolio(int $portfolioId)
    {
        return $this->operation('portfolios/' . $portfolioId);
    }

    /**
     * @param int $portfolioId
     * @return array
     * @throws Exception
     */
    public function getPortfolioEx(int $portfolioId)
    {
        return $this->operation('portfolios/extended/' . $portfolioId);
    }

    /**
     * @param array $data
     * @return array
     * @throws Exception
     */
    public function createPortfolios(array $data)
    {
        return $this->operation('portfolios', $data, 'POST');
    }

    /**
     * @param array $data
     * @return array
     * @throws Exception
     */
    public function updatePortfolios(array $data)
    {
        return $this->operation('portfolios', $data, 'PUT');
    }

    //end of portfolios

    //start of Product Attribute Targeting

    /**
     * POST https://advertising-api.amazon.com/v2/sp/targets/productRecommendations
     * @see https://advertising.amazon.com/API/docs/v2/reference/product_attribute_targeting#createTargetRecommendations
     *
     * @param array $data [pageSize => int(1-50), pageNumber => int, asins: string[]]
     * @return array
     * @throws Exception
     */
    public function generateTargetsProductRecommendations(array $data): array
    {
        return $this->operation("sp/targets/productRecommendations", $data, 'POST');
    }

    /**
     * GET https://advertising-api.amazon.com/v2/sp/targets/{targetId}
     * @see https://advertising.amazon.com/API/docs/v2/reference/product_attribute_targeting#getTargetingClause
     *
     * @param int $targetId
     * @return array
     * @throws Exception
     */
    public function getTargetingClause(int $targetId): array
    {
        return $this->operation("sp/targets/" . $targetId);
    }

    /**
     * GET https://advertising-api.amazon.com/v2/sp/targets/extended/{targetId}
     * @see https://advertising.amazon.com/API/docs/v2/reference/product_attribute_targeting#getTargetingClauseEx
     *
     * @param int $targetId
     * @return array
     * @throws Exception
     */
    public function getTargetingClauseEx(int $targetId): array
    {
        return $this->operation("sp/targets/extended/" . $targetId);
    }

    /**
     * GET https://advertising-api.amazon.com/v2/sp/targets
     * @see https://advertising.amazon.com/API/docs/v2/reference/product_attribute_targeting#listTargetingClauses
     *
     * @param array|null $data
     * @return array
     * @throws Exception
     */
    public function listTargetingClauses($data = null): array
    {
        return $this->operation("sp/targets", $data);
    }

    /**
     * GET https://advertising-api.amazon.com/v2/sp/targets/extended
     * @see https://advertising.amazon.com/API/docs/v2/reference/product_attribute_targeting#listTargetingClausesEx
     *
     * @param array|null $data
     * @return array
     * @throws Exception
     */
    public function listTargetingClausesEx($data = null): array
    {
        return $this->operation("sp/targets/extended", $data);
    }

    /**
     * POST https://advertising-api.amazon.com/v2/sp/targets
     * @see https://advertising.amazon.com/API/docs/v2/reference/product_attribute_targeting#createTargetingClauses
     *
     * @param array $data
     * @return array
     * @throws Exception
     */
    public function createTargetingClauses(array $data): array
    {
        return $this->operation("sp/targets", $data, 'POST');
    }

    /**
     * PUT https://advertising-api.amazon.com/v2/sp/targets
     * @see https://advertising.amazon.com/API/docs/v2/reference/product_attribute_targeting#updateTargetingClauses
     *
     * @param array $data
     * @return array
     * @throws Exception
     */
    public function updateTargetingClauses(array $data): array
    {
        return $this->operation("sp/targets", $data, 'PUT');
    }

    /**
     * DELETE https://advertising-api.amazon.com/v2/sp/targets
     * @see https://advertising.amazon.com/API/docs/v2/reference/product_attribute_targeting#archiveTargetingClause
     *
     * @param int $targetId
     * @return array
     * @throws Exception
     */
    public function archiveTargetingClause(int $targetId): array
    {
        return $this->operation("sp/targets/" . $targetId, 'DELETE');
    }


    /**
     * GET https://advertising-api.amazon.com/v2/sp/targets/categories
     * @see https://advertising.amazon.com/API/docs/v2/reference/product_attribute_targeting#getTargetingCategories
     *
     * @param array $data
     * @return array
     * @throws Exception
     */
    public function getTargetingCategories(array $data): array
    {
        return $this->operation("sp/targets/categories", $data);
    }

    /**
     * GET https://advertising-api.amazon.com/v2/sp/targets/brands
     * @see https://advertising.amazon.com/API/docs/v2/reference/product_attribute_targeting#getBrandRecommendations
     *
     * @param array $data
     * @return array
     * @throws Exception
     */
    public function getBrandRecommendations(array $data): array
    {
        return $this->operation("sp/targets/brands", $data);
    }

    /**
     * GET https://advertising-api.amazon.com/v2/sp/targets/{targetId}
     * @see https://advertising.amazon.com/API/docs/v2/reference/product_attribute_targeting#getNegativeTargetingClause
     *
     * @param int $targetId
     * @return array
     * @throws Exception
     */
    public function getNegativeTargetingClause(int $targetId): array
    {
        return $this->operation("sp/negativeTargets/" . $targetId);
    }

    /**
     * GET https://advertising-api.amazon.com/v2/sp/negativeTargets/extended/{targetId}
     * @see https://advertising.amazon.com/API/docs/v2/reference/product_attribute_targeting#getNegativeTargetingClauseEx
     *
     * @param int $targetId
     * @return array
     * @throws Exception
     */
    public function getNegativeTargetingClauseEx(int $targetId): array
    {
        return $this->operation("sp/negativeTargets/extended/" . $targetId);
    }

    /**
     * GET https://advertising-api.amazon.com/v2/sp/negativeTargets
     * @see https://advertising.amazon.com/API/docs/v2/reference/product_attribute_targeting#listNegativeTargetingClauses
     *
     * @param array|null $data
     * @return array
     * @throws Exception
     */
    public function listNegativeTargetingClauses($data = null): array
    {
        return $this->operation("sp/negativeTargets", $data);
    }

    /**
     * GET https://advertising-api.amazon.com/v2/sp/negativeTargets/extended
     * @see https://advertising.amazon.com/API/docs/v2/reference/product_attribute_targeting#listNegativeTargetingClausesEx
     *
     * @param array|null $data
     * @return array
     * @throws Exception
     */
    public function listNegativeTargetingClausesEx($data = null): array
    {
        return $this->operation("sp/negativeTargets/extended", $data);
    }

    //

    /**
     * POST https://advertising-api.amazon.com/v2/sp/negativeTargets
     * @see https://advertising.amazon.com/API/docs/v2/reference/product_attribute_targeting#createNegativeTargetingClauses
     *
     * @param array $data
     * @return array
     * @throws Exception
     */
    public function createNegativeTargetingClauses(array $data): array
    {
        return $this->operation("sp/negativeTargets", $data, 'POST');
    }

    /**
     * PUT https://advertising-api.amazon.com/v2/sp/negativeTargets
     * @see https://advertising.amazon.com/API/docs/v2/reference/product_attribute_targeting#updateNegativeTargetingClauses
     *
     * @param array $data
     * @return array
     * @throws Exception
     */
    public function updateNegativeTargetingClauses(array $data): array
    {
        return $this->operation("sp/negativeTargets", $data, 'PUT');
    }

    /**
     * DELETE https://advertising-api.amazon.com/v2/sp/negativeTargets
     * @see https://advertising.amazon.com/API/docs/v2/reference/product_attribute_targeting#archiveNegativeTargetingClause
     *
     * @param int $targetId
     * @return array
     * @throws Exception
     */
    public function archiveNegativeTargetingClause(int $targetId): array
    {
        return $this->operation("sp/negativeTargets/" . $targetId, 'DELETE');
    }

    //end of PAT

    //SB v3

    /**
     * GET /brands
     * @see https://advertising.amazon.com/API/docs/en-us/sponsored-brands/3-0/openapi#/Brands/getBrands
     * @param array|null $data
     * @return array
     * @throws Exception
     */
    public function getBrands($data = null): array
    {
        return $this->operation("brands", $data);
    }

    /**
     * GET /stores/assets
     * @see https://advertising.amazon.com/API/docs/en-us/sponsored-brands/3-0/openapi#/Stores/listAssets
     * @param array|null $data
     * @return array
     * @throws Exception
     */
    public function getStoreAssets($data = null): array
    {
        return $this->operation("/stores/assets", $data);
    }

    /**
     * GET /pageAsins
     * @see https://advertising.amazon.com/API/docs/en-us/sponsored-brands/3-0/openapi#/Landing%20page%20asins/listAsins
     * @param array $data
     * @return array
     * @throws Exception
     */
    public function getPageAsins(array $data): array
    {
        if (!isset($data['pageUrl'])) {
            throw new Exception("pageUrl should be set as GET param");
        }
        return $this->operation("pageAsins", $data);
    }

    /**
     * @see https://advertising.amazon.com/API/docs/en-us/sponsored-brands/3-0/openapi#/Campaigns/listCampaigns
     * @param null $data
     * @return array
     * @throws Exception
     */
    public function listSponsoredBrandCampaigns($data = null): array
    {
        return $this->operation("sb/campaigns", $data);
    }

    /**
     * @see https://advertising.amazon.com/API/docs/en-us/sponsored-brands/3-0/openapi#/Campaigns/createCampaigns
     * @param array $data
     * @return array
     * @throws Exception
     */
    public function createSponsoredBrandCampaigns(array $data): array
    {
        return $this->operation("sb/campaigns", $data, 'POST');
    }

    /**
     * @see https://advertising.amazon.com/API/docs/en-us/sponsored-brands/3-0/openapi#/Campaigns/updateCampaigns
     * @param array $data
     * @return array
     * @throws Exception
     */
    public function updateSponsoredBrandCampaigns(array $data): array
    {
        return $this->operation("sb/campaigns", $data, 'PUT');
    }


    /**
     * @see https://advertising.amazon.com/API/docs/en-us/sponsored-brands/3-0/openapi#/Campaigns/getCampaign
     * @param int $campaignId
     * @return array
     * @throws Exception
     */
    public function getSponsoredBrandCampaign(int $campaignId): array
    {
        return $this->operation("sb/campaigns/{$campaignId}");
    }

    /**
     * @see https://advertising.amazon.com/API/docs/en-us/sponsored-brands/3-0/openapi#/Campaigns/archiveCampaign
     * @param int $campaignId
     * @return array
     * @throws Exception
     */
    public function archiveSponsoredBrandCampaign(int $campaignId): array
    {
        return $this->operation("sb/campaigns/{$campaignId}", null, 'DELETE');
    }



    //end of SB v3

    /**
     * @param $location
     * @param bool $gunzip
     * @return array
     */
    private function download($location, $gunzip = false)
    {
        $headers = array();

        if (!$gunzip) {
            /* only send authorization header when not downloading actual file */
            array_push($headers, "Authorization: bearer {$this->config["accessToken"]}");
        }

        if (!is_null($this->profileId)) {
            array_push($headers, "Amazon-Advertising-API-Scope: {$this->profileId}");
        }

        $request = new CurlRequest();
        $request->setOption(CURLOPT_URL, $location);
        $request->setOption(CURLOPT_HTTPHEADER, $headers);
        $request->setOption(CURLOPT_USERAGENT, $this->userAgent);
        if ($this->config['saveFile'] && $gunzip) {
            return $this->saveDownloaded($request);
        }

        if ($gunzip) {
            $response = $this->executeRequest($request);
            $response["response"] = gzdecode($response["response"]);
            return $response;
        }

        return $this->executeRequest($request);
    }

    /**
     * Save *.json.gz file, extract it, remove .gz file
     * and set into response path to json file
     * @param CurlRequest $request
     * @return array
     */
    protected function saveDownloaded(CurlRequest $request): array
    {
        $filePath = '/tmp/' . uniqid(microtime(true) . '_amzn_ads_') . '.json.gz';
        $tmpFile = fopen($filePath, 'w+');
        $request->setOption(CURLOPT_HEADER, 0);
        $request->setOption(CURLOPT_FOLLOWLOCATION, 1);
        $request->setOption(CURLOPT_FILE, $tmpFile);
        $response = $this->executeRequest($request);
        if ($response['success']) {
            $extractedFile = $this->extractFile($filePath);
            fclose($tmpFile);
            unlink($filePath);
            $response['response_type'] = 'file';
            $response["response"] = $extractedFile;
            return $response;
        } else {
            fclose($tmpFile);
            unlink($filePath);
            return $response;
        }
    }

    /**
     * @param string $filePath
     * @return string
     */
    protected function extractFile(string $filePath): string
    {
        $bufferSize = 4096; // read 4kb at a time
        $unzipFilePath = str_replace('.gz', '', $filePath);
        $file = gzopen($filePath, 'rb');
        $unzippedFile = fopen($unzipFilePath, 'wb');

        while (!gzeof($file)) {
            fwrite($unzippedFile, gzread($file, $bufferSize));
        }
        fclose($unzippedFile);
        gzclose($file);

        return $unzipFilePath;
    }

    /**
     * @param $interface
     * @param array $params
     * @param string $method
     * @return array
     * @throws Exception
     */
    private function operation($interface, $params = [], $method = "GET")
    {
        $headers = array(
            "Authorization: bearer {$this->config["accessToken"]}",
            "Content-Type: application/json",
            "User-Agent: {$this->userAgent}",
            "Amazon-Advertising-API-ClientId: {$this->config["clientId"]}"
        );

        if (!is_null($this->profileId)) {
            array_push($headers, "Amazon-Advertising-API-Scope: {$this->profileId}");
        }

        $this->headers = $headers;

        $request = new CurlRequest();
        $this->endpoint = trim($this->endpoint, "/");
        $url = "{$this->endpoint}/{$interface}";
        $this->requestId = null;
        $data = "";

        switch (strtolower($method)) {
            case "get":
                if (!empty($params)) {
                    $url .= "?";
                    foreach ($params as $k => $v) {
                        $url .= "{$k}=" . rawurlencode($v) . "&";
                    }
                    $url = rtrim($url, "&");
                }
                break;
            case "put":
            case "post":
            case "delete":
                if (!empty($params)) {
                    $data = json_encode($params);
                    $request->setOption(CURLOPT_POST, true);
                    $request->setOption(CURLOPT_POSTFIELDS, $data);
                }
                break;
            default:
                $this->logAndThrow("Unknown verb {$method}.");
        }

        $request->setOption(CURLOPT_URL, $url);
        $request->setOption(CURLOPT_HTTPHEADER, $headers);
        $request->setOption(CURLOPT_USERAGENT, $this->userAgent);
        $request->setOption(CURLOPT_CUSTOMREQUEST, strtoupper($method));
        return $this->executeRequest($request);
    }

    /**
     * @param CurlRequest $request
     * @return array
     */
    protected function executeRequest(CurlRequest $request)
    {
        $response = $request->execute();
        $this->requestId = $request->requestId;
        $response_info = $request->getInfo();
        $request->close();

        if ($response_info["http_code"] == 307) {
            /* application/octet-stream */
            return $this->download($response_info["redirect_url"], true);
        }

        if (!preg_match("/^(2|3)\d{2}$/", $response_info["http_code"])) {
            $requestId = 0;
            $json = json_decode($response, true);
            if (!is_null($json)) {
                if (array_key_exists("requestId", $json)) {
                    $requestId = json_decode($response, true)["requestId"];
                }
            }
            return array("success" => false,
                "code" => $response_info["http_code"],
                "response" => $response,
                'responseInfo' => $response_info,
                "requestId" => $requestId);
        } else {
            return array("success" => true,
                "code" => $response_info["http_code"],
                'responseInfo' => $response_info,
                "response" => $response,
                "requestId" => $this->requestId);
        }
    }

    /**
     * @param $config
     * @return bool
     * @throws Exception
     */
    private function validateConfig($config)
    {
        if (is_null($config)) {
            $this->logAndThrow("'config' cannot be null.");
        }

        foreach ($config as $k => $v) {
            if (array_key_exists($k, $this->config)) {
                $this->config[$k] = $v;
            } else {
                $this->logAndThrow("Unknown parameter '{$k}' in config.");
            }
        }
        return true;
    }

    /**
     * @return bool
     * @throws Exception
     */
    private function validateConfigParameters()
    {
        foreach ($this->config as $k => $v) {
            if (is_null($v) && $k !== "accessToken" && $k !== "refreshToken") {
                $this->logAndThrow("Missing required parameter '{$k}'.");
            }
            switch ($k) {
                case "clientId":
                    if (!preg_match("/^amzn1\.application-oa2-client\.[a-z0-9]{32}$/i", $v)) {
                        $this->logAndThrow("Invalid parameter value for clientId.");
                    }
                    break;
                case "clientSecret":
                    if (!preg_match("/^[a-z0-9]{64}$/i", $v)) {
                        $this->logAndThrow("Invalid parameter value for clientSecret.");
                    }
                    break;
                case "accessToken":
                    if (!is_null($v)) {
                        if (!preg_match("/^Atza(\||%7C|%7c).*$/", $v)) {
                            $this->logAndThrow("Invalid parameter value for accessToken.");
                        }
                    }
                    break;
                case "refreshToken":
                    if (!is_null($v)) {
                        if (!preg_match("/^Atzr(\||%7C|%7c).*$/", $v)) {
                            $this->logAndThrow("Invalid parameter value for refreshToken.");
                        }
                    }
                    break;
                case "sandbox":
                    if (!is_bool($v)) {
                        $this->logAndThrow("Invalid parameter value for sandbox.");
                    }
                    break;
                case "saveFile":
                    if (!is_bool($v)) {
                        $this->logAndThrow("Invalid parameter value for saveFile.");
                    }
                    break;
            }
        }
        return true;
    }

    /**
     * @return bool
     * @throws Exception
     */
    private function setEndpoints()
    {
        /* check if region exists and set api/token endpoints */
        if (array_key_exists(strtolower($this->config["region"]), $this->endpoints)) {
            $region_code = strtolower($this->config["region"]);
            if ($this->config["sandbox"]) {
                $this->endpoint = "https://{$this->endpoints[$region_code]["sandbox"]}/{$this->apiVersion}";
            } else {
                $this->endpoint = "https://{$this->endpoints[$region_code]["prod"]}/{$this->apiVersion}";
            }
            $this->tokenUrl = $this->endpoints[$region_code]["tokenUrl"];
        } else {
            $this->logAndThrow("Invalid region.");
        }
        return true;
    }

    /**
     * @param $message
     * @throws Exception
     */
    private function logAndThrow($message)
    {
        error_log($message, 0);
        throw new Exception($message);
    }
}
