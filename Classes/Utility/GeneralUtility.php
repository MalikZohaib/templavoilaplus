<?php
namespace Extension\Templavoila\Utility;

/**
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

/**
 * Class with static functions for templavoila.
 *
 * @author Steffen Kamper  <info@sk-typo3.de>
 */
final class GeneralUtility {

	/**
	 * @return \TYPO3\CMS\Core\Database\DatabaseConnection
	 */
	static public function getDatabaseConnection() {
		return $GLOBALS['TYPO3_DB'];
	}

	/**
	 * @return \TYPO3\CMS\Core\Authentication\BackendUserAuthentication
	 */
	static public function getBackendUser() {
		return $GLOBALS['BE_USER'];
	}

	/**
	 * @return \TYPO3\CMS\Lang\LanguageService
	 */
	static public function getLanguageService() {
		return $GLOBALS['LANG'];
	}

	/**
	 * Checks if a given string is a valid frame URL to be loaded in the
	 * backend.
	 *
	 * @param string $url potential URL to check
	 *
	 * @return string either $url if $url is considered to be harmless, or an empty string otherwise
	 */
	private static function internalSanitizeLocalUrl($url = '') {
		$sanitizedUrl = '';
		$decodedUrl = rawurldecode($url);
		if ($decodedUrl !== \TYPO3\CMS\Core\Utility\GeneralUtility::removeXSS($decodedUrl)) {
			$decodedUrl = '';
		}
		if (!empty($url) && $decodedUrl !== '') {
			$testAbsoluteUrl = \TYPO3\CMS\Core\Utility\GeneralUtility::resolveBackPath($decodedUrl);
			$testRelativeUrl = \TYPO3\CMS\Core\Utility\GeneralUtility::resolveBackPath(
				\TYPO3\CMS\Core\Utility\GeneralUtility::dirname(\TYPO3\CMS\Core\Utility\GeneralUtility::getIndpEnv('SCRIPT_NAME')) . '/' . $decodedUrl
			);

			// That's what's usually carried in TYPO3_SITE_PATH
			$typo3_site_path = substr(\TYPO3\CMS\Core\Utility\GeneralUtility::getIndpEnv('TYPO3_SITE_URL'), strlen(\TYPO3\CMS\Core\Utility\GeneralUtility::getIndpEnv('TYPO3_REQUEST_HOST')));

			// Pass if URL is on the current host:
			if (self::isValidUrl($decodedUrl)) {
				if (self::isOnCurrentHost($decodedUrl) && strpos($decodedUrl, \TYPO3\CMS\Core\Utility\GeneralUtility::getIndpEnv('TYPO3_SITE_URL')) === 0) {
					$sanitizedUrl = $url;
				}
				// Pass if URL is an absolute file path:
			} elseif (\TYPO3\CMS\Core\Utility\GeneralUtility::isAbsPath($decodedUrl) && \TYPO3\CMS\Core\Utility\GeneralUtility::isAllowedAbsPath($decodedUrl)) {
				$sanitizedUrl = $url;
				// Pass if URL is absolute and below TYPO3 base directory:
			} elseif (strpos($testAbsoluteUrl, $typo3_site_path) === 0 && substr($decodedUrl, 0, 1) === '/') {
				$sanitizedUrl = $url;
				// Pass if URL is relative and below TYPO3 base directory:
			} elseif (strpos($testRelativeUrl, $typo3_site_path) === 0 && substr($decodedUrl, 0, 1) !== '/') {
				$sanitizedUrl = $url;
			}
		}

		if (!empty($url) && empty($sanitizedUrl)) {
			\TYPO3\CMS\Core\Utility\GeneralUtility::sysLog('The URL "' . $url . '" is not considered to be local and was denied.', 'Core', \TYPO3\CMS\Core\Utility\GeneralUtility::SYSLOG_SEVERITY_NOTICE);
		}

		return $sanitizedUrl;
	}

	/**
	 * Checks if a given string is a Uniform Resource Locator (URL).
	 *
	 * @param string $url The URL to be validated
	 *
	 * @return boolean Whether the given URL is valid
	 */
	private static function isValidUrl($url) {
		return (filter_var($url, FILTER_VALIDATE_URL, FILTER_FLAG_SCHEME_REQUIRED) !== FALSE);
	}

	/**
	 * Checks if a given URL matches the host that currently handles this HTTP request.
	 * Scheme, hostname and (optional) port of the given URL are compared.
	 *
	 * @param string $url URL to compare with the TYPO3 request host
	 *
	 * @return boolean Whether the URL matches the TYPO3 request host
	 */
	private static function isOnCurrentHost($url) {
		return (stripos($url . '/', \TYPO3\CMS\Core\Utility\GeneralUtility::getIndpEnv('TYPO3_REQUEST_HOST') . '/') === 0);
	}

	/**
	 * @return array
	 */
	public static function getDenyListForUser() {
		$denyItems = array();
		foreach (static::getBackendUser()->userGroups as $group) {
			$groupDenyItems = \TYPO3\CMS\Core\Utility\GeneralUtility::trimExplode(',', $group['tx_templavoila_access'], TRUE);
			$denyItems = array_merge($denyItems, $groupDenyItems);
		}

		return $denyItems;
	}

	/**
	 * Get a list of referencing elements other than the given pid.
	 *
	 * @param array $element array with tablename and uid for a element
	 * @param integer $pid the suppoed source-pid
	 * @param integer $recursion recursion limiter
	 * @param array &$references array containing a list of the actual references
	 *
	 * @return boolean true if there are other references for this element
	 */
	public static function getElementForeignReferences($element, $pid, $recursion = 99, &$references = NULL) {
		if (!$recursion) {
			return FALSE;
		}
		if (!is_array($references)) {
			$references = array();
		}
		$refrows = static::getDatabaseConnection()->exec_SELECTgetRows(
			'*',
			'sys_refindex',
			'ref_table=' . static::getDatabaseConnection()->fullQuoteStr($element['table'], 'sys_refindex') .
			' AND ref_uid=' . intval($element['uid']) .
			' AND deleted=0'
		);

		if (is_array($refrows)) {
			foreach ($refrows as $ref) {
				if (strcmp($ref['tablename'], 'pages') === 0) {
					$references[$ref['tablename']][$ref['recuid']] = TRUE;
				} else {
					if (!isset($references[$ref['tablename']][$ref['recuid']])) {
						// initialize with false to avoid recursion without affecting inner OR combinations
						$references[$ref['tablename']][$ref['recuid']] = FALSE;
						$references[$ref['tablename']][$ref['recuid']] = self::hasElementForeignReferences(array('table' => $ref['tablename'], 'uid' => $ref['recuid']), $pid, $recursion - 1, $references);
					}
				}
			}
		}

		unset($references['pages'][$pid]);

		return $references;
	}

	/**
	 * Checks if a element is referenced from other pages / elements on other pages than his own.
	 *
	 * @param array $element array with tablename and uid for a element
	 * @param integer $pid the suppoed source-pid
	 * @param integer $recursion recursion limiter
	 * @param array &$references array containing a list of the actual references
	 *
	 * @return boolean true if there are other references for this element
	 */
	public static function hasElementForeignReferences($element, $pid, $recursion = 99, &$references = NULL) {
		$references = self::getElementForeignReferences($element, $pid, $recursion, $references);
		$foreignRefs = FALSE;
		if (is_array($references)) {
			unset($references['pages'][$pid]);
			$foreignRefs = count($references['pages']) || count($references['pages_language_overlay']);
		}

		return $foreignRefs;
	}

}
