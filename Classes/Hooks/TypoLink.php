<?php
/*                                                                        *
 * This script belongs to the TYPO3 extension "tinyurls".                 *
 *                                                                        *
 * It is free software; you can redistribute it and/or modify it under    *
 * the terms of the GNU General Public License, either version 3 of the   *
 * License, or (at your option) any later version.                        *
 *                                                                        *
 * The TYPO3 project - inspiring people to share!                         *
 *                                                                        */

/**
 * Contains a hook for the typolink generation to convert a typolink
 * in a tinyurl. Additionally, it contains a public api for generating
 * a tinyurl in another extension.
 */
class Tx_Tinyurls_Hooks_TypoLink {

	/**
	 * Contains configuration options for the tinyurl generation
	 *
	 * deleteOnUse: If true, the URL will be invalid after it was called the first time (default: false)
	 * validUntil: If set, the URL will only be valid (default: false)
	 * urlKey: If set, this key will be used instead of the auto generated one (default: false)
	 *
	 * @var array
	 */
	protected $tinyurlConfig;

	/**
	 * Contains the default values for the tinyurl configuration
	 *
	 * @var array
	 */
	protected $tinyurlConfigDefaults = array(
		'deleteOnUse' => 0,
		'validUntil' => 0,
		'urlKey' => FALSE,
	);

	/**
	 * Contains the configuration that can be set in the extension manager
	 *
	 * @var array
	 */
	protected $extensionConfiguration;

	/**
	 * The parent content object, that is calling this hook
	 *
	 * @var tslib_cObj
	 */
	protected $contentObject;

	/**
	 * Contains all valid keys for t3lib_div::getIndpEnv(), will be used
	 * for validating the speaking url configuration
	 *
	 * @var array
	 */
	protected $availableIndpEnvKeys = array(
		'REQUEST_URI',
		'HTTP_HOST',
		'SCRIPT_NAME',
		'PATH_INFO',
		'QUERY_STRING',
		'HTTP_REFERER',
		'REMOTE_ADDR',
		'REMOTE_HOST',
		'HTTP_USER_AGENT',
		'HTTP_ACCEPT_LANGUAGE',
		'SCRIPT_FILENAME',
		'TYPO3_HOST_ONLY',
		'TYPO3_PORT',
		'TYPO3_REQUEST_HOST',
		'TYPO3_REQUEST_URL',
		'TYPO3_REQUEST_SCRIPT',
		'TYPO3_REQUEST_DIR',
		'TYPO3_SITE_URL',
		'TYPO3_SITE_PATH',
		'TYPO3_SITE_SCRIPT',
		'TYPO3_DOCUMENT_ROOT',
		'TYPO3_SSL',
		'TYPO3_PROXY',
	);

	/**
	 * Will be called by the typolink hook and replace the original url
	 * with a tinyurl if this was set in the typolink configuration.
	 *
	 * @param array $parameters Configuration array for the typolink containing these keys:
	 *
	 * conf: reference to the typolink configuration array (generated by the TypoScript configuration)
	 * linktxt: reference to the link text
	 * finalTag: reference to the final link tag
	 * finalTagParts: reference to the array that contains the tag parts (aTagParams, url, TYPE, targetParams, TAG)
	 *
	 * @param tslib_cObj $contentObject The parent content object
	 */
	public function convertTypolinkToTinyUrl($parameters, $contentObject) {

		$config = $parameters['conf'];
		$finalTagParts = $parameters['finalTagParts'];

		if ($finalTagParts['TYPE'] === 'mailto') {
			return;
		}

		if (!(array_key_exists('tinyurl', $config) && $config['tinyurl'])) {
			return;
		}

		$targetUrl = $finalTagParts['url'];
		$tinyUrl = $this->getTinyUrl($targetUrl, $contentObject, $config);

		$parameters['finalTag'] = str_replace(htmlspecialchars($targetUrl), htmlspecialchars($tinyUrl), $parameters['finalTag']);
		$parameters['finalTagParts']['url'] = $tinyUrl;
		$contentObject->lastTypoLinkUrl = $tinyUrl;
	}

	/**
	 * This method can be used in other extensions to generate a tinyurl
	 *
	 * @param string $targetUrl The URL that should be minified
	 * @param tslib_cObj $contentObject The parent content object
	 * @param array $config Configuration for the tinyurl generation (see $this->tinyurlConfig)
	 * @return string The generated tinyurl
	 * @api
	 */
	public function getTinyUrl($targetUrl, $contentObject, $config = array()) {

		$this->initializeExtensionConfiguration();
		$this->initializeTinyurlConfig($config, $contentObject);

		$targetUrlHash = Tx_Tinyurls_Utils_UrlUtils::generateTinyurlHash($targetUrl);

		$tinyUrlData = $this->getExistingTinyurl($targetUrlHash);
		if ($tinyUrlData === FALSE) {
			$tinyUrlData = $this->generateNewTinyurl($targetUrl, $targetUrlHash);
		}

		$tinyUrlKey = $tinyUrlData['urlkey'];
		if ($this->extensionConfiguration['createSpeakingURLs']) {
			$tinyUrl = $this->createSpeakingTinyUrl($tinyUrlKey);
		} else {
			$tinyUrl = t3lib_div::getIndpEnv('TYPO3_SITE_URL');
			$tinyUrl .= '?eID=tx_tinyurls&tx_tinyurls[key]=' . $tinyUrlKey;
		}

		return $tinyUrl;
	}

	/**
	 * Checks if there is already an existing tinyurl and returns its data
	 *
	 * @param $targetUrlHash
	 * @return bool|array FALSE if no existing URL was found, otherwise associative array with tinyurl data
	 */
	protected function getExistingTinyurl($targetUrlHash) {

		$result = $GLOBALS['TYPO3_DB']->exec_SELECTquery('*', 'tx_tinyurls_urls', 'target_url_hash=' . $GLOBALS['TYPO3_DB']->fullQuoteStr($targetUrlHash, 'tx_tinyurls_urls'));

		if (!$GLOBALS['TYPO3_DB']->sql_num_rows($result)) {
			return FALSE;
		} else {
			return $GLOBALS['TYPO3_DB']->sql_fetch_assoc($result);
		}
	}

	/**
	 * Inserts a new record in the database
	 *
	 * Does not check, if the url hash already exists! This is done in
	 * getTinyUrl().
	 *
	 * @param string $targetUrl
	 * @param string $targetUrlHash
	 * @return array
	 */
	protected function generateNewTinyurl($targetUrl, $targetUrlHash) {

			// TODO: insert PID configured in extension configuration
		$insertArray = array(
			'target_url' => $targetUrl,
			'target_url_hash' => $targetUrlHash,
			'delete_on_use' => $this->tinyurlConfig['deleteOnUse'],
			'valid_until' => $this->tinyurlConfig['validUntil'],
		);

		$customUrlKey = $this->getCustomUrlKey($targetUrlHash);
		if ($customUrlKey !== FALSE) {
			$insertArray['urlkey'] = $customUrlKey;
		}

		$GLOBALS['TYPO3_DB']->exec_INSERTquery(
			'tx_tinyurls_urls',
			$insertArray
		);

			// if no custom URL key was set, the key is generated using the
			// uid from the database
		if ($customUrlKey === FALSE) {
			$insertedUid = $GLOBALS['TYPO3_DB']->sql_insert_id();
			$tinyUrlKey = Tx_Tinyurls_Utils_UrlUtils::generateTinyurlKeyForUid($insertedUid, $this->extensionConfiguration);
			$GLOBALS['TYPO3_DB']->exec_UPDATEquery('tx_tinyurls_urls', 'uid=' . $insertedUid, array('urlkey' => $tinyUrlKey));
			$insertArray['urlkey'] = $tinyUrlKey;
		}

		return $insertArray;
	}

	/**
	 * Initializes the tinyurl configuration with default values and
	 * if the user set his own values they are parsed through stdWrap
	 *
	 * @param array $config
	 * @param tslib_cObj $contentObject
	 */
	protected function initializeTinyurlConfig($config, $contentObject) {

		$this->contentObject = $contentObject;

		if (!array_key_exists('tinyurl.', $config)) {
			return;
		}

		$tinyUrlConfig = $config['tinyurl.'];
		$newTinyurlConfig = array();

		foreach ($this->tinyurlConfigDefaults as $configKey => $defaultValue) {

			$configValue = $defaultValue;

			if (array_key_exists($configKey, $tinyUrlConfig)) {

				$configValue = $tinyUrlConfig[$configKey];

				if (array_key_exists($configValue . '.', $tinyUrlConfig)) {
					$configValue = $contentObject->stdWrap($configValue, $tinyUrlConfig[$configKey . '.']);
				}
			}

			$newTinyurlConfig[$configKey] = $configValue;
		}

		$this->tinyurlConfig = $newTinyurlConfig;
	}

	/**
	 * Checks the tinyurl config and returns a custom tinyurl key if
	 * one was set
	 *
	 * @param string $targetUrlHash The target url hash is needed to check if the custom key matches the target url
	 * @return bool|string FALSE if no custom key was set, otherwise the custom key
	 * @throws Exception If custom url key was set but empty or if the key already existed with a different URL
	 */
	protected function getCustomUrlKey($targetUrlHash) {

		$customUrlKey = $this->tinyurlConfig['urlKey'];

		if ($customUrlKey === FALSE) {
			return FALSE;
		}

		if (empty($customUrlKey)) {
			throw new Exception('An empty url key was set.');
		}

		$customUrlKeyResult = $GLOBALS['TYPO3_DB']->exec_SELECTquery(
			'target_url',
			'tx_tinyurls_urls',
			'urlkey=' . $GLOBALS['TYPO3_DB']->fullQuoteStr($customUrlKey, 'tx_tinyurls_urls')
		);

		if ($GLOBALS['TYPO3_DB']->sql_num_rows($customUrlKeyResult)) {

			$existingUrlData = $GLOBALS['TYPO3_DB']->sql_fetch_assoc($customUrlKeyResult);

			if ($existingUrlData['target_url_hash'] !== $targetUrlHash) {
				throw new Exception('A url key was set that already exists in the database and points to a different URL.');
			}
		}

		return $customUrlKey;
	}

	/**
	 * Unserializes the extension configuration and loads it into
	 * the matching class variables
	 */
	protected function initializeExtensionConfiguration() {
		$this->extensionConfiguration = Tx_Tinyurls_Utils_ConfigUtils::getExtensionConfiguration();
	}

	/**
	 * Generates a speaking tinyurl based on the speaking url template
	 *
	 * @param $tinyUrlKey
	 * @return string
	 */
	protected function createSpeakingTinyUrl($tinyUrlKey) {

		$speakingUrl = $this->extensionConfiguration['speakingUrlTemplate'];

		foreach ($this->availableIndpEnvKeys as $indpEnvKey) {

			$templateMarker = '###' . strtoupper($indpEnvKey) . '###';

			if (strstr($speakingUrl, $templateMarker)) {
				$speakingUrl = $this->contentObject->substituteMarker($speakingUrl, $templateMarker, t3lib_div::getIndpEnv($indpEnvKey));
			}
		}

		$speakingUrl = $this->contentObject->substituteMarker($speakingUrl, '###TINY_URL_KEY###', $tinyUrlKey);

		return $speakingUrl;
	}
}
