<?php
class AddOnConverter {
	
	protected $sourceFile;
	protected $extractDir;
	protected $logMessages = array();


	/**
	 * @var DOMDocument
	 */
	protected $installRdf;
	
	const SEAMONKEY_ID = '{92650c4d-4b8e-4d2a-b7eb-24ecf4f6b63a}';
	protected $minVersionStr = '2.0';

	/**
	 * @param string $sourceFile
	 * @throws Exception
	 */
	public function __construct($sourceFile) {
		$this->sourceFile = $sourceFile;
		
		$zip = new ZipArchive;
		$result = $zip->open($sourceFile);

		if ($result !== true) {
			throw new Exception("Cannot read the XPI file");
		}

		$this->extractDir = dirname($sourceFile) . "/extracted";
		mkdir($this->extractDir);

		if (!$zip->extractTo($this->extractDir)) {
			throw new Exception("Cannot extract archive");
		}
		$zip->close();
		
		if (!is_file($this->extractDir ."/install.rdf")) {
			throw new Exception("install.rdf not found in installer");
		}
		
		$this->installRdf = new DOMDocument();
		$this->installRdf->preserveWhiteSpace = false;
		$this->installRdf->formatOutput = true;
		$result = @$this->installRdf->load($this->extractDir ."/install.rdf");
		
		if (!$result) {
			throw new Exception("Cannot parse install.rdf as XML");
		}
	}
	
	/**
	 * @param string $destDir
	 * @param string $maxVersionStr
	 * @return string|NULL URL path to converted file for download or NULL
	 *    if no conversion was done
	 */
	public function convert($destDir, $maxVersionStr) {
		$modified = false;
		
		$newInstallRdf = $this->convertInstallRdf($this->installRdf, $maxVersionStr);
		
		if ($newInstallRdf) {
			// write modified file
			file_put_contents($this->extractDir ."/install.rdf", $newInstallRdf->saveXML());
			unset($newInstallRdf);
			$modified = true;
		}
		
		
		$filesConverted = $this->convertManifest('chrome.manifest');

		if ($filesConverted > 0) {
			$modified = true;
		}

		if ($modified) {
			// ZIP files
			$filename = $this->createNewFileName($this->sourceFile);
			$destFile = "$destDir/$filename";
			
			$this->zipDir($this->extractDir, $destFile);
			
			return $destFile;
		}
		
		return null;
	}
	
	/**
	 * @param DOMDocument $installRdf
	 * @param string $maxVersionStr
	 * @return DOMDocument|null NULL if document was not changed
	 */
	public function convertInstallRdf(DOMDocument $installRdf, $maxVersionStr) {
		$Descriptions = $installRdf->documentElement->getElementsByTagNameNS("http://www.w3.org/1999/02/22-rdf-syntax-ns#", "Description");
		
		$topDescription = null;

		foreach ($Descriptions as $d) {
			$about = $d->getAttributeNS("http://www.w3.org/1999/02/22-rdf-syntax-ns#", "about");
			if (!$about) {
				$about = $d->getAttribute("about");
			}
			
			if ($about == "urn:mozilla:install-manifest") {
				$topDescription = $d;
				break;
			}
		}

		if (!$topDescription) {
			return null;
		}
		
		$docChanged = false;
		$SM_exists = false;
		
		foreach ($topDescription->getElementsByTagName("targetApplication") as $ta) {
			$Description = $ta->getElementsByTagName("Description")->item(0);
			
			if (!$Description) {
				continue;
			}
			
			$id = $Description->getElementsByTagName("id")->item(0);

			if (!$id) {
				continue;
			}
			
			if ($id->nodeValue == self::SEAMONKEY_ID) {
				// change maxVersion
				$SM_exists = true;
				
				$maxVersion = $Description->getElementsByTagName("maxVersion")->item(0);
				if (!$maxVersion) {
					// maxVersion missing
					$maxVersion = $this->installRdf->createElementNS("http://www.mozilla.org/2004/em-rdf#", "maxVersion", $maxVersionStr);
					
					$this->log("install.rdf: Added missing maxVersion");
					$docChanged = true;
					
				} elseif ($maxVersion && $maxVersion->nodeValue != $maxVersionStr) {
					$this->log("install.rdf: Changed <em>maxVersion</em> from '$maxVersion->nodeValue' to '$maxVersionStr'");
					
					$maxVersion->nodeValue = $maxVersionStr;
					$docChanged = true;
				}
				
				break;
			}
		}
		
		if (!$SM_exists) {
			// add application
			$tApp = $this->installRdf->createElementNS("http://www.mozilla.org/2004/em-rdf#", "targetApplication");
			
			$Description = $this->installRdf->createElementNS("http://www.mozilla.org/2004/em-rdf#", "Description");
			$id = $this->installRdf->createElementNS("http://www.mozilla.org/2004/em-rdf#", "id", self::SEAMONKEY_ID);
			$minVersion = $this->installRdf->createElementNS("http://www.mozilla.org/2004/em-rdf#", "minVersion", $this->minVersionStr);
			$maxVersion = $this->installRdf->createElementNS("http://www.mozilla.org/2004/em-rdf#", "maxVersion", $maxVersionStr);
			
			$Description->appendChild($id);
			$Description->appendChild($minVersion);
			$Description->appendChild($maxVersion);
			
			$tApp->appendChild($Description);
			$topDescription->appendChild($tApp);
			
			$this->log("install.rdf: Added SeaMonkey to list of supported applications");
			$docChanged = true;
		}
		
		return $docChanged ? $installRdf : null;
	}
	
	/**
	 * Convert chrome.manifest and any included manifest files
	 * 
	 * @return int number of converted files
	 */
	protected function convertManifest($manifestFileName) {
		$manifestFile = $this->extractDir ."/$manifestFileName";
		
		if (!is_file($manifestFile)) {
			return 0;
		}
		
		$convertedFilesCount = 0;
		$isConverted = false;
		$newManifest = "";
		
		$fp = fopen($manifestFile, "rb");
		
		while (($line = fgets($fp, 4096)) !== false) {
			$trimLine = trim($line);
			$newLine = "";
			
			if ($trimLine && $trimLine[0] != '#') {
				$segm = preg_split('/\s+/', $trimLine);

				switch ($segm[0]) {
					case 'manifest':
						// included another manifest
						$file = ltrim($segm[1], './\\');
						$convertedFilesCount += $this->convertManifest($file);
						break;;

					case 'overlay':
					case 'override':
						$newLine = $this->createNewManifestLine($trimLine);
						break;
				}
			}
			
			$newManifest .= $line;

			if ($newLine) {
				$newManifest .= $newLine;
				$this->log("Added new line to $manifestFileName: '$newLine'");
				$isConverted = true;
			}
		}
		
		if ($isConverted) {
			file_put_contents($manifestFile, $newManifest);
			$convertedFilesCount++;
		}
		
		return $convertedFilesCount;
	}
	
	/**
	 * Take existing manifest line and if it contants firefox-specific data
	 * then return new seamonkey-specific line. Otherwise, return empty string.
	 * 
	 * @param string $originalLine
	 * @retutn string
	 */
	private function createNewManifestLine($originalLine) {
		$replacements = array(
			'chrome://browser/content/browser.xul' => 'chrome://navigator/content/navigator.xul',
			'chrome://browser/content/pageinfo/pageInfo.xul' => 'chrome://navigator/content/pageinfo/pageInfo.xul',
			'chrome://browser/content/preferences/permissions.xul' => 'chrome://communicator/content/permissions/permissionsManager.xul',
			'chrome://browser/content/bookmarks/bookmarksPanel.xul' => 'chrome://communicator/content/bookmarks/bm-panel.xul',
			'chrome://browser/content/places/places.xul' => 'chrome://communicator/content/bookmarks/bookmarksManager.xul',
		);
		
		$convertedLine = strtr($originalLine, $replacements);
		
		if ($convertedLine != $originalLine) {
			return $convertedLine;
		} else {
			return '';
		}
	}

	protected function log($msg) {
		$this->logMessages[] = $msg;
	}
	
	public function getLogMessages() {
		return $this->logMessages;
	}
	
	protected function createNewFileName($sourceFile) {
		$segm = pathinfo($sourceFile);
		
		return $segm['filename'] .'.' //. '-sm.'
			. $segm['extension'];
	}
	
	/**
	 * ZIP all directory with files and folders
	 * @param string $dir
	 * @param string $destFile
	 * @throws Exception
	 */
	protected function zipDir($dir, $destFile) {
		
		$zip = new ZipArchive;
		$res = $zip->open($destFile, ZIPARCHIVE::CREATE);
		
		if (!$res) {
			throw new Exception("Cannot open ZipArchive");
		}
		
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS),
			RecursiveIteratorIterator::SELF_FIRST);

		$dirLen = strlen($dir);
		
		foreach ($iterator as $pathInfo) {
			$localname = substr($pathInfo->__toString(), $dirLen + 1);
			
			if ($pathInfo->isDir()) {
				$zip->addEmptyDir($localname);
			} else {
				$zip->addFile($pathInfo->__toString(), $localname);
			}
		}
		
		$zip->close();
	}
}
